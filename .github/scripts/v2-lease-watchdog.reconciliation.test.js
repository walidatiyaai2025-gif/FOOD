'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { parseClaim, claimIdentity, activityDecision, listPages, run } = require('./v2-lease-watchdog');

const now = Date.parse('2026-11-10T10:00:00Z');
function state(id, created, owner='worker-a', status='ACTIVE') {
  return { id, created_at: created, user: { login: owner },
    body: '<!-- foodex-worker-state:v1 -->\nSTATE: '+status+'\nOWNER: '+owner+
      '\nBRANCH: feat/2929-lease-tests\nHEARTBEAT: 2026-11-10T09:59:00Z' };
}
test('repeated worker checkpoints cannot silently renew a claim lease', () => {
  const c = parseClaim([state(1,'2026-11-10T09:20:00Z'),
    state(2,'2026-11-10T09:59:00Z','worker-a','WAITING_CI')]);
  assert.equal(c.createdMs, Date.parse('2026-11-10T09:20:00Z'));
  assert.equal(c.commentId, 2);
  assert.equal(claimIdentity(c), '1:'+Date.parse('2026-11-10T09:20:00Z'));
  assert.equal(activityDecision({nowMs:now,claim:c,latestCommitMs:0,
    latestPrCommitMs:0,ciRunning:false}).release, true);
});
test('a handoff marker resets the claim origin for a successor', () => {
  const c=parseClaim([state(1,'2026-11-10T09:00:00Z'),
    {id:2,created_at:'2026-11-10T09:30:00Z',
      body:'<!-- foodex-watchdog:handoff issue=2929 -->'},
    state(3,'2026-11-10T09:50:00Z')]);
  assert.equal(c.createdMs, Date.parse('2026-11-10T09:50:00Z'));
  assert.equal(c.claimId,3);
});
test('fully integrated issues are not considered active claims', () => {
  assert.equal(parseClaim([state(1,'2026-11-10T09:00:00Z'),
    state(2,'2026-11-10T09:30:00Z','unassigned','INTEGRATED_PENDING_ACCEPTANCE')]), null);
});
test('GitHub Search item pagination and REST array pagination both work', async () => {
  assert.deepEqual(await listPages(async()=>({data:{items:[{number:2929}]}}),{},2),[{number:2929}]);
  assert.deepEqual(await listPages(async()=>({data:[{number:2929}]}),{},2),[{number:2929}]);
});
test('malformed GitHub API payload fails closed rather than silently skipping', async () => {
  await assert.rejects(listPages(async()=>({data:{total_count:1}}),{},2),/pagination payload/);
});
test('incomplete GitHub API page cap fails closed', async () => {
  await assert.rejects(listPages(async()=>({data:Array.from({length:100},(_,i)=>i)}),{},2),/pagination limit/);
});

function harness(opts={}) {
  const issue = { number:2929, title:'V2 | AUTO-LEASE example', state:'open',
    labels: [{name:'worker:active'}, ...(opts.humanGate ? [{name:'gate:human'}] : [])] };
  const mutations=[]; let branchReads=0, commentReads=0;
  const oldState = state(1,'2026-11-10T09:20:00Z');
  const freshState = state(2,'2026-11-10T09:59:00Z','worker-b');
  const github = { rest: {
    search:{issuesAndPullRequests:async()=>({data:{items:[issue]}})},
    pulls:{list:async()=>({data:[]})},
    issues:{
      listComments:async()=>{commentReads++;return {data:commentReads>1&&opts.newClaim?[oldState,freshState]:[oldState]};},
      get:async()=>({data:issue}),
      removeLabel:async args=>{mutations.push('remove:'+args.name);return {data:{}};},
      addLabels:async args=>{mutations.push('add:'+args.labels.join(','));return {data:{}};},
      createComment:async()=>{mutations.push('comment');return {data:{}};},
    },
    repos:{
      getBranch:async()=>{branchReads++;if(opts.apiError)throw Object.assign(new Error('permission denied'),{status:403});
        return {data:{commit:{sha:opts.branchMoves&&branchReads>1?'new-head':'old-head'}}};},
      getCommit:async()=>({data:{commit:{committer:{date:opts.recentCommit?
        '2026-11-10T09:50:00Z':'2026-11-10T09:20:00Z'}}}}),
    },
    checks:{listForRef:async()=>({data:{check_runs:opts.queuedCi?[{name:'required-ci-gate',status:'queued'}]:[]}})},
    actions:{listWorkflowRunsForRepo:async()=>({data:{workflow_runs:opts.runningWorkflow?
      [{name:'FOODEX Required CI Gate',head_sha:'old-head',status:'in_progress'}]:[]}})},
  }};
  return {github, mutations, getBranchReads:()=>branchReads};
}
async function sweep(opts={}) {
  const fixture=harness(opts);
  const result=await run({github:fixture.github,context:{repo:{owner:'example',repo:'FOOD'}},
    core:{info:()=>{}},nowMs:opts.nowMs??now});
  return {...fixture,result};
}
test('stale issue releases original labels, preserves branch/PR and records audit', async () => {
  const out=await sweep();
  assert.deepEqual(out.result,{scanned:1,released:1,active:0});
  assert.deepEqual(out.mutations,['remove:worker:active','add:worker:handoff-ready','comment']);
  assert.equal(out.getBranchReads(),2);
});
test('29-minute boundary keeps worker reserved', async () => {
  const out=await sweep({nowMs:Date.parse('2026-11-10T09:49:59Z')});
  assert.equal(out.result.released,0);
  assert.deepEqual(out.mutations,[]);
});
test('new branch commit in last 30 minutes protects worker', async () => {
  const out=await sweep({recentCommit:true});
  assert.equal(out.result.released,0);
});
test('queued check or workflow on exact head protects worker', async () => {
  for(const opts of [{queuedCi:true},{runningWorkflow:true}]) {
    const out=await sweep(opts);
    assert.equal(out.result.released,0);
    assert.deepEqual(out.mutations,[]);
  }
});
test('a worker claim made during scan aborts release', async () => {
  const out=await sweep({newClaim:true});
  assert.equal(out.result.released,0);
  assert.deepEqual(out.mutations,[]);
});
test('changed branch SHA between checks aborts release', async () => {
  const out=await sweep({branchMoves:true});
  assert.equal(out.result.released,0);
  assert.deepEqual(out.mutations,[]);
});
test('human approval gate is never auto-released', async () => {
  const out=await sweep({humanGate:true});
  assert.equal(out.result.released,0);
  assert.deepEqual(out.mutations,[]);
});
test('GitHub permission errors fail closed without mutation', async () => {
  const h=harness({apiError:true});
  await assert.rejects(run({github:h.github,context:{repo:{owner:'example',repo:'FOOD'}},
    core:{info:()=>{}},nowMs:now}),/permission denied/);
  assert.deepEqual(h.mutations,[]);
});
