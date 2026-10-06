'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const {
  STATE_MARKER,
  branchFromText,
  classify,
  executionActivityMillis,
  handoffComment,
  linkedIssueNumbers,
  parseWorkerState,
  statusLabel,
  summarizeCheckRuns,
  summarizeCommitStatuses,
  summarizeWorkflowRuns,
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

test('coordination chatter cannot renew a lease without execution evidence', () => {
  const oldCommit = Date.parse('2026-10-01T05:00:00Z');
  const recentCoordinatorComment = Date.parse('2026-10-01T05:59:30Z');

  // The recent comment is intentionally not an input to execution activity.
  assert.equal(
    executionActivityMillis({
      commitActivity: oldCommit,
      stateHeartbeat: 0,
    }),
    oldCommit,
  );
  assert.ok(recentCoordinatorComment > oldCommit);

  assert.deepEqual(
    classify({
      nowMs: NOW,
      managed: true,
      workerState: null,
      hasOpenPr: true,
      hasBranch: true,
      latestActivityMs: oldCommit,
      ciRunning: false,
      ciConclusion: null,
      mergeable: true,
    }),
    { status: 'handoff-ready', reason: 'stale-lease' },
  );
});

test('fresh explicit machine heartbeat keeps lease active', () => {
  const activity = executionActivityMillis({
    commitActivity: Date.parse('2026-10-01T05:00:00Z'),
    stateHeartbeat: Date.parse('2026-10-01T05:59:00Z'),
  });
  assert.deepEqual(
    classify({
      nowMs: NOW,
      managed: true,
      workerState: { state: 'WORKING', blocker: 'none' },
      hasOpenPr: true,
      hasBranch: true,
      latestActivityMs: activity,
      ciRunning: false,
      ciConclusion: null,
      mergeable: true,
    }),
    { status: 'active', reason: 'fresh-lease' },
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

test('fresh red CI becomes handoff-ready immediately without stale timeout', () => {
  for (const conclusion of [
    'failure',
    'timed_out',
    'cancelled',
    'action_required',
    'startup_failure',
    'stale',
  ]) {
    assert.deepEqual(
      classify({
        nowMs: NOW,
        managed: true,
        workerState: { state: 'WORKING', blocker: 'none' },
        hasOpenPr: true,
        hasBranch: true,
        latestActivityMs: Date.parse('2026-10-01T05:59:30Z'),
        ciRunning: false,
        ciConclusion: conclusion,
        mergeable: true,
      }),
      { status: 'handoff-ready', reason: `ci-${conclusion}` },
    );
  }
});

test('red CI beats another running workflow on the same head', () => {
  assert.deepEqual(
    classify({
      nowMs: NOW,
      managed: true,
      workerState: { state: 'WORKING', blocker: 'none' },
      hasOpenPr: true,
      hasBranch: true,
      latestActivityMs: Date.parse('2026-10-01T05:59:30Z'),
      ciRunning: true,
      ciConclusion: 'failure',
      mergeable: true,
    }),
    { status: 'handoff-ready', reason: 'ci-failure' },
  );
});

test('fresh merge conflict becomes handoff-ready immediately', () => {
  assert.deepEqual(
    classify({
      nowMs: NOW,
      managed: true,
      workerState: { state: 'WORKING', blocker: 'none' },
      hasOpenPr: true,
      hasBranch: true,
      latestActivityMs: Date.parse('2026-10-01T05:59:30Z'),
      ciRunning: false,
      ciConclusion: 'success',
      mergeable: false,
    }),
    { status: 'handoff-ready', reason: 'merge-conflict' },
  );
});

test('newer rerun replaces old red run for the same workflow', () => {
  assert.deepEqual(
    summarizeWorkflowRuns([
      {
        id: 1,
        workflow_id: 10,
        status: 'completed',
        conclusion: 'failure',
        created_at: '2026-10-01T05:00:00Z',
        updated_at: '2026-10-01T05:10:00Z',
      },
      {
        id: 2,
        workflow_id: 10,
        status: 'in_progress',
        conclusion: null,
        created_at: '2026-10-01T05:50:00Z',
        updated_at: '2026-10-01T05:55:00Z',
      },
    ]),
    { running: true, conclusion: null },
  );
});

test('red latest workflow is surfaced even while another workflow is running', () => {
  assert.deepEqual(
    summarizeWorkflowRuns([
      {
        id: 3,
        workflow_id: 10,
        status: 'completed',
        conclusion: 'failure',
        created_at: '2026-10-01T05:55:00Z',
        updated_at: '2026-10-01T05:56:00Z',
      },
      {
        id: 4,
        workflow_id: 11,
        status: 'in_progress',
        conclusion: null,
        created_at: '2026-10-01T05:57:00Z',
        updated_at: '2026-10-01T05:58:00Z',
      },
    ]),
    { running: true, conclusion: 'failure' },
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

test('red CI overrides a nominal external gate until repo-local red is fixed', () => {
  assert.deepEqual(
    classify({
      nowMs: NOW,
      managed: true,
      workerState: { state: 'BLOCKED_EXTERNAL', blocker: 'deploy' },
      hasOpenPr: true,
      hasBranch: true,
      latestActivityMs: Date.parse('2026-10-01T05:59:30Z'),
      ciRunning: false,
      ciConclusion: 'failure',
      mergeable: true,
    }),
    { status: 'handoff-ready', reason: 'ci-failure' },
  );
});

test('check-run red state is detected immediately', () => {
  assert.deepEqual(
    summarizeCheckRuns([
      {
        id: 1,
        name: 'lint',
        app: { id: 10 },
        status: 'completed',
        conclusion: 'failure',
        started_at: '2026-10-01T05:50:00Z',
        completed_at: '2026-10-01T05:51:00Z',
      },
    ]),
    { running: false, conclusion: 'failure' },
  );
});

test('watchdog self-cancellation is ignored and cannot create a handoff loop', () => {
  assert.deepEqual(
    summarizeCheckRuns([
      {
        id: 99,
        name: 'Detect stale workers and external gates',
        app: { id: 10 },
        status: 'completed',
        conclusion: 'cancelled',
        started_at: '2026-10-01T05:50:00Z',
        completed_at: '2026-10-01T05:51:00Z',
      },
    ]),
    { running: false, conclusion: null },
  );
});

test('commit status failure is detected immediately', () => {
  assert.deepEqual(
    summarizeCommitStatuses([
      {
        id: 1,
        context: 'external-ci',
        state: 'failure',
        created_at: '2026-10-01T05:50:00Z',
        updated_at: '2026-10-01T05:51:00Z',
      },
    ]),
    { running: false, conclusion: 'failure' },
  );
});

test('real deployment gate is reserved for human intervention after repo-local state is clear', () => {
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


test('watchdog workflow bounds queue fan-out and suppresses self-trigger cascades', () => {
  const workflowPath = path.join(__dirname, '..', 'workflows', 'worker-watchdog.yml');
  const workflow = fs.readFileSync(workflowPath, 'utf8');

  assert.match(workflow, /cron: "\*\/10 \* \* \* \*"/);
  assert.match(workflow, /workflow_dispatch:/);
  assert.match(workflow, /types: \[completed\]/);
  assert.doesNotMatch(workflow, /types: \[[^\]]*requested[^\]]*\]/);
  assert.doesNotMatch(workflow, /types: \[[^\]]*in_progress[^\]]*\]/);
  assert.doesNotMatch(workflow, /types: \[[^\]]*labeled[^\]]*\]/);
  assert.doesNotMatch(workflow, /types: \[[^\]]*unlabeled[^\]]*\]/);
  assert.doesNotMatch(workflow, /\n\s*push:\s*\n/);

  // All actionable events share one latest-state slot. A newer event supersedes
  // stale watchdog work instead of consuming another hosted runner queue slot.
  assert.match(workflow, /cancel-in-progress:\s*true/);
  assert.match(workflow, /\|\| 'worker-watchdog'/);

  // Comments written by the watchdog itself are no-op runs with isolated keys:
  // they neither execute the job nor cancel the parent reconciliation.
  assert.match(workflow, /worker-watchdog-self-\{0\}/);
  assert.match(
    workflow,
    /if: \$\{\{ github\.event_name != 'issue_comment' \|\| !contains\(github\.event\.comment\.body, '<!-- foodex-watchdog:'\) \}\}/,
  );
});
