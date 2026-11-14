'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { LIMIT_MS, isV2, parseClaim, activityDecision, claimIdentity } = require('./v2-lease-watchdog');
const nowMs = Date.parse('2026-11-10T10:00:00Z');
const claim = {
  owner: 'worker-a',
  branch: 'feat/2700-example',
  createdMs: Date.parse('2026-11-10T09:20:00Z'),
  commentId: 18,
};
test('exact 30 minutes of no verifiable work releases lease', () => {
  assert.deepEqual(activityDecision({nowMs,claim,latestCommitMs:0,latestPrCommitMs:0,ciRunning:false}),{
    release:true, why:'idle-over-30m',workMs:claim.createdMs
  });
});
test('work committed within 30m protects active worker', () => {
  assert.deepEqual(activityDecision({nowMs,claim,latestCommitMs:Date.parse('2026-11-10T09:40:00Z'),latestPrCommitMs:0,ciRunning:false}),{
    release:false,why:'within-30-minute-window'
  });
});
test('CI still running protects worker', () => {
  assert.equal(activityDecision({nowMs,claim,latestCommitMs:0,latestPrCommitMs:0,ciRunning:true}).release,false);
});
test('self-declared heartbeat/comment without commit does not extend lease', () => {
  const parsed=parseClaim([{
    id:18,created_at:'2026-11-10T09:20:00Z',user:{login:'worker-a'},
    body:'<!-- foodex-worker-state:v1 -->\nSTATE: ACTIVE\nOWNER: worker-a\nBRANCH: feat/2700-example\nHEARTBEAT: 2026-11-10T09:59:00Z'
  }]);
  assert.equal(parsed.heartbeatMs,Date.parse('2026-11-10T09:59:00Z'));
  assert.equal(activityDecision({nowMs,claim:parsed,latestCommitMs:0,latestPrCommitMs:0,ciRunning:false}).release,true);
});
test('inactive or handoff is not treated as a live claim', () => {
  assert.equal(parseClaim([{id:1,created_at:'2026-11-10T09:00:00Z',body:'<!-- foodex-worker-state:v1 -->\nSTATE: HANDOFF'}]),null);
});
test('newer claim takes priority', () => {
  const c=parseClaim([
    {id:1,created_at:'2026-11-10T08:00:00Z',body:'<!-- foodex-worker-state:v1 -->\nSTATE: ACTIVE\nOWNER: old'},
    {id:2,created_at:'2026-11-10T09:50:00Z',body:'<!-- foodex-worker-state:v1 -->\nSTATE: ACTIVE\nOWNER: new'},
  ]);
  assert.equal(c.owner,'new');
  assert.equal(claimIdentity(c),'2:' + Date.parse('2026-11-10T09:50:00Z'));
});
test('only open V2 issues are in scope, not PRs', () => {
  assert.equal(isV2({title:'V2 | RBAC fix',state:'open'}),true);
  assert.equal(isV2({title:'V2 | RBAC fix',state:'closed'}),false);
  assert.equal(isV2({title:'V2 | RBAC fix',state:'open',pull_request:{url:'x'}}),false);
  assert.equal(isV2({title:'FOODEX feature',state:'open'}),false);
});
test('automated sweep has 30 minute exact boundary', () => {
  assert.equal(LIMIT_MS,30*60000);
});

test('terminal and approval statuses supersede an old ACTIVE claim without a handoff', () => {
  const active = { id: 101, created_at: '2026-11-10T09:00:00Z',
    body: '<!-- foodex-worker-state:v1 -->\\nSTATE: ACTIVE\\nOWNER: worker-a\\nBRANCH: feat/2700-example' };
  for (const state of [
    'GATE_APPROVAL / WORKER_RELEASED',
    'WORKER_RELEASED',
    'BLOCKED_DESIGN_DEPENDENCY',
    'MERGED_PENDING_ACCEPTANCE',
    'INTEGRATED_PARTIAL / HANDOFF_READY',
    'RELEASED',
    'HANDOFF_READY',
    'UNKNOWN_STATE',
    '',
  ]) {
    const stateLine = state ? 'STATE: ' + state + '\\n' : '';
    const checkpoint = { id: 102, created_at: '2026-11-10T09:10:00Z',
      body: '<!-- foodex-worker-state:v1 -->\\n' + stateLine
        + 'OWNER: worker-a\\nBRANCH: feat/2700-example' };
    assert.equal(parseClaim([active, checkpoint]), null, state || '(missing STATE)');
  }
});

test('only explicit execution states are eligible for idle-leasing', () => {
  for (const state of [
    'ACTIVE',
    'ACTIVE / REQUIRED_CI_RUNNING',
    'WAITING_CI',
    'WAITING_EXACT_HEAD_CI',
    'WAITING_REQUIRED_CI',
    'CI_FIX',
    'CI_RUNNING',
    'REQUIRED_CI_RUNNING',
  ]) {
    const claim = parseClaim([{ id: 120, created_at: '2026-11-10T09:15:00Z',
      body: '<!-- foodex-worker-state:v1 -->\\nSTATE: ' + state
        + '\\nOWNER: worker-a\\nBRANCH: feat/2700-example' }]);
    assert.equal(claim.owner, 'worker-a', state);
    assert.equal(claim.createdMs, Date.parse('2026-11-10T09:15:00Z'), state);
    assert.equal(activityDecision({
      nowMs, claim, latestCommitMs: 0, latestPrCommitMs: 0, ciRunning: false
    }).release, true, state);
  }
});

test('gated issue requires a fresh ACTIVE claim before automatic handoff may occur', () => {
  const records = [
    { id: 201, created_at: '2026-11-10T08:30:00Z',
      body: '<!-- foodex-worker-state:v1 -->\\nSTATE: ACTIVE\\nOWNER: first' },
    { id: 202, created_at: '2026-11-10T08:50:00Z',
      body: '<!-- foodex-worker-state:v1 -->\\nSTATE: GATE_APPROVAL / WORKER_RELEASED\\nOWNER: first' },
  ];
  assert.equal(parseClaim(records), null);
  records.push({ id: 203, created_at: '2026-11-10T09:40:00Z',
    body: '<!-- foodex-worker-state:v1 -->\\nSTATE: ACTIVE / CLAIMED\\nOWNER: successor' });
  const next = parseClaim(records);
  assert.equal(next.owner, 'successor');
  assert.equal(next.claimId, 203);
  assert.equal(activityDecision({
    nowMs, claim: next, latestCommitMs: 0, latestPrCommitMs: 0, ciRunning: false
  }).release, false);
  assert.equal(activityDecision({
    nowMs, claim: next, latestCommitMs: 0, latestPrCommitMs: 0, ciRunning: true
  }).release, false);
});
