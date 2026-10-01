'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');

const {
  STATE_MARKER,
  branchFromText,
  classify,
  handoffComment,
  linkedIssueNumbers,
  parseWorkerState,
  statusLabel,
} = require('./worker-watchdog');

const NOW = Date.parse('2026-10-01T06:00:00Z');

test('parses machine-readable worker state', () => {
  const state = parseWorkerState(`
${STATE_MARKER}
STATE: WAITING_CI
OWNER: worker-2
BRANCH: fix/591-driver-version-policy-observability
PR: #599
HEAD: abc123
HEARTBEAT: 2026-10-01T05:50:00Z
BLOCKER: ci
NEXT_ACTION: inspect failed Driver tests
`);
  assert.deepEqual(state, {
    state: 'WAITING_CI',
    owner: 'worker-2',
    branch: 'fix/591-driver-version-policy-observability',
    pr: '#599',
    head: 'abc123',
    heartbeat: '2026-10-01T05:50:00Z',
    blocker: 'ci',
    nextAction: 'inspect failed Driver tests',
  });
});

test('extracts only closing-linked issues from PR body', () => {
  assert.deepEqual(
    linkedIssueNumbers('Parent #589\nCloses #591\nFixes #592\nRelated #512'),
    [591, 592],
  );
});

test('extracts existing branch from legacy claim comment', () => {
  assert.equal(
    branchFromText('Owner: worker\nBranch: `fix/512-live-map-production-reliability`\nLease: active'),
    'fix/512-live-map-production-reliability',
  );
});

test('unclaimed managed issue is ready', () => {
  assert.deepEqual(
    classify({
      nowMs: NOW,
      managed: true,
      workerState: null,
      hasOpenPr: false,
      hasBranch: false,
      latestActivityMs: 0,
      ciRunning: false,
      ciConclusion: null,
      mergeable: null,
    }),
    { status: 'ready', reason: 'unclaimed' },
  );
});

test('fresh worker lease remains active', () => {
  assert.deepEqual(
    classify({
      nowMs: NOW,
      managed: true,
      workerState: { state: 'WORKING', blocker: 'none' },
      hasOpenPr: true,
      hasBranch: true,
      latestActivityMs: Date.parse('2026-10-01T05:45:00Z'),
      ciRunning: false,
      ciConclusion: null,
      mergeable: null,
    }),
    { status: 'active', reason: 'fresh-lease' },
  );
});

test('running CI is never declared stale', () => {
  assert.deepEqual(
    classify({
      nowMs: NOW,
      managed: true,
      workerState: { state: 'WAITING_CI', blocker: 'ci' },
      hasOpenPr: true,
      hasBranch: true,
      latestActivityMs: Date.parse('2026-10-01T04:00:00Z'),
      ciRunning: true,
      ciConclusion: null,
      mergeable: null,
    }),
    { status: 'waiting-ci', reason: 'ci-running' },
  );
});

test('stale red CI becomes handoff-ready, not human-blocked', () => {
  assert.deepEqual(
    classify({
      nowMs: NOW,
      managed: true,
      workerState: { state: 'WAITING_CI', blocker: 'ci' },
      hasOpenPr: true,
      hasBranch: true,
      latestActivityMs: Date.parse('2026-10-01T05:00:00Z'),
      ciRunning: false,
      ciConclusion: 'failure',
      mergeable: false,
    }),
    { status: 'handoff-ready', reason: 'ci-failure' },
  );
});

test('stale green mergeable PR becomes handoff-ready for another worker', () => {
  assert.deepEqual(
    classify({
      nowMs: NOW,
      managed: true,
      workerState: { state: 'READY_TO_MERGE', blocker: 'none' },
      hasOpenPr: true,
      hasBranch: true,
      latestActivityMs: Date.parse('2026-10-01T05:00:00Z'),
      ciRunning: false,
      ciConclusion: 'success',
      mergeable: true,
    }),
    { status: 'handoff-ready', reason: 'merge-ready-stale' },
  );
});

test('real deployment gate is reserved for human intervention', () => {
  assert.deepEqual(
    classify({
      nowMs: NOW,
      managed: true,
      workerState: { state: 'BLOCKED_EXTERNAL', blocker: 'deploy' },
      hasOpenPr: false,
      hasBranch: true,
      latestActivityMs: Date.parse('2026-10-01T03:00:00Z'),
      ciRunning: false,
      ciConclusion: null,
      mergeable: null,
    }),
    {
      status: 'human-gate',
      reason: 'deploy',
      labels: ['gate:human', 'gate:deploy'],
    },
  );
});

test('repository-local blocker is takeover eligible after lease timeout', () => {
  assert.deepEqual(
    classify({
      nowMs: NOW,
      managed: true,
      workerState: { state: 'BLOCKED_REPO', blocker: 'repo' },
      hasOpenPr: false,
      hasBranch: true,
      latestActivityMs: Date.parse('2026-10-01T05:00:00Z'),
      ciRunning: false,
      ciConclusion: null,
      mergeable: null,
    }),
    { status: 'handoff-ready', reason: 'stale-lease' },
  );
});

test('explicit handoff does not wait for stale timeout', () => {
  assert.deepEqual(
    classify({
      nowMs: NOW,
      managed: true,
      workerState: { state: 'HANDOFF', blocker: 'none' },
      hasOpenPr: true,
      hasBranch: true,
      latestActivityMs: Date.parse('2026-10-01T05:59:00Z'),
      ciRunning: false,
      ciConclusion: null,
      mergeable: null,
    }),
    { status: 'handoff-ready', reason: 'explicit-handoff' },
  );
});

test('unmanaged issue is ignored', () => {
  assert.deepEqual(
    classify({
      nowMs: NOW,
      managed: false,
      workerState: null,
      hasOpenPr: false,
      hasBranch: false,
      latestActivityMs: 0,
      ciRunning: false,
      ciConclusion: null,
      mergeable: null,
    }),
    { status: 'ignored', reason: 'not-managed' },
  );
});

test('status maps to one queue label', () => {
  assert.equal(statusLabel('ready'), 'worker:ready');
  assert.equal(statusLabel('active'), 'worker:active');
  assert.equal(statusLabel('waiting-ci'), 'worker:waiting-ci');
  assert.equal(statusLabel('handoff-ready'), 'worker:handoff-ready');
});

test('handoff comment forces reuse of existing branch and PR', () => {
  const body = handoffComment({
    issueNumber: 591,
    branch: 'fix/591-driver-version-policy-observability',
    prNumber: 599,
    head: 'abc123',
    reason: 'ci-failure',
    ciConclusion: 'failure',
    nextAction: 'fix the failing test',
  });
  assert.match(body, /same Issue\/branch\/PR/);
  assert.match(body, /#599/);
  assert.match(body, /fix\/591-driver-version-policy-observability/);
  assert.match(body, /fix the failing test/);
});
