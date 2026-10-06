'use strict';

const STATE_MARKER = '<!-- foodex-worker-state:v1 -->';
const MANAGED_MARKER = '<!-- foodex-worker:managed -->';
const WATCHDOG_MARKER = '<!-- foodex-watchdog:';
const STALE_MINUTES = 30;

const RED_CI_CONCLUSIONS = new Set([
  'failure',
  'timed_out',
  'cancelled',
  'action_required',
  'startup_failure',
  'stale',
]);

const SELF_WATCHDOG_WORKFLOW_NAMES = new Set(['Worker Watchdog']);
const SELF_WATCHDOG_CHECK_NAMES = new Set(['Detect stale workers and external gates']);

const STATUS_LABELS = [
  'worker:ready',
  'worker:active',
  'worker:waiting-ci',
  'worker:handoff-ready',
];

const GATE_LABELS = [
  'gate:human',
  'gate:deploy',
  'gate:production',
  'gate:credentials',
  'gate:device',
  'gate:approval',
];

const HUMAN_BLOCKERS = new Set([
  'deploy',
  'production',
  'credentials',
  'device',
  'approval',
  'human',
]);

const LABELS = {
  'worker:ready': ['1D76DB', 'Atomic repository-local work is ready for a worker'],
  'worker:active': ['0E8A16', 'A worker has an active lease on this issue'],
  'worker:waiting-ci': ['FBCA04', 'Worker is waiting for CI/actions on the current branch head'],
  'worker:handoff-ready': ['F97316', 'Immediate takeover needed for red CI/conflict, stale lease, or explicit handoff; continue the same branch/PR'],
  'gate:human': ['B60205', 'Real external intervention is required; workers must not fabricate or bypass it'],
  'gate:deploy': ['D93F0B', 'Requires real deployment/environment action'],
  'gate:production': ['D93F0B', 'Requires real production evidence or production-side action'],
  'gate:credentials': ['B60205', 'Requires credentials or secrets unavailable to repository workers'],
  'gate:device': ['B60205', 'Requires a real device/environment not available to repository workers'],
  'gate:approval': ['B60205', 'Requires explicit human approval before proceeding'],
};

function isRateLimitError(error) {
  if (!error || error.status !== 403) return false;
  const headers = error.response?.headers || {};
  const remaining = headers['x-ratelimit-remaining'] ?? headers['X-RateLimit-Remaining'];
  const message = String(error.response?.data?.message || error.message || '').toLowerCase();
  return String(remaining) === '0' || message.includes('rate limit exceeded');
}

function parseWorkerState(text) {
  if (!text || !text.includes(STATE_MARKER)) return null;
  const after = text.slice(text.lastIndexOf(STATE_MARKER) + STATE_MARKER.length);
  const fields = {};
  for (const rawLine of after.split(/\r?\n/)) {
    const match = rawLine.match(/^\s*([A-Z_]+)\s*:\s*(.*?)\s*$/);
    if (!match) continue;
    fields[match[1]] = match[2];
  }
  return {
    state: (fields.STATE || '').toUpperCase(),
    owner: fields.OWNER || '',
    branch: fields.BRANCH || '',
    pr: fields.PR || '',
    head: fields.HEAD || '',
    heartbeat: fields.HEARTBEAT || '',
    blocker: (fields.BLOCKER || 'none').toLowerCase(),
    nextAction: fields.NEXT_ACTION || '',
  };
}

function linkedIssueNumbers(body) {
  const text = body || '';
  const result = new Set();
  const closing = /\b(?:close[sd]?|fix(?:e[sd])?|resolve[sd]?|implement(?:s|ed)?)\s+#(\d+)\b/gi;
  let match;
  while ((match = closing.exec(text)) !== null) result.add(Number(match[1]));
  return [...result];
}

function branchFromText(text) {
  if (!text) return '';
  const patterns = [
    /\bBranch\s*:\s*[`'"]?([A-Za-z0-9._/-]+)[`'"]?/i,
    /\bbranch\s+[`'"]([A-Za-z0-9._/-]+)[`'"]/i,
  ];
  for (const pattern of patterns) {
    const match = text.match(pattern);
    if (match) return match[1].replace(/[.,;:]$/, '');
  }
  return '';
}

function asMillis(value) {
  const ms = Date.parse(value || '');
  return Number.isFinite(ms) ? ms : 0;
}

function newestMillis(...values) {
  return Math.max(0, ...values.flat().map(value => {
    if (typeof value === 'number') return Number.isFinite(value) ? value : 0;
    return asMillis(value);
  }));
}

function minutesSince(nowMs, activityMs) {
  if (!activityMs) return Infinity;
  return Math.max(0, (nowMs - activityMs) / 60000);
}

function executionActivityMillis({ commitActivity, stateHeartbeat }) {
  // Deliberately exclude ordinary Issue/PR comments and PR metadata timestamps.
  // Coordination chatter must not renew a worker lease.
  return newestMillis(commitActivity, stateHeartbeat);
}

function gateLabel(blocker) {
  return HUMAN_BLOCKERS.has(blocker) && blocker !== 'human'
    ? `gate:${blocker}`
    : null;
}

function classify({
  nowMs,
  managed,
  workerState,
  hasOpenPr,
  hasBranch,
  latestActivityMs,
  ciRunning,
  ciConclusion,
  mergeable,
}) {
  if (!managed) return { status: 'ignored', reason: 'not-managed' };

  const blocker = workerState?.blocker || 'none';
  const explicitState = workerState?.state || '';
  if (explicitState === 'HANDOFF') {
    return { status: 'handoff-ready', reason: 'explicit-handoff' };
  }

  // Red repository state is actionable work, not inactivity. It bypasses the
  // stale-worker timer and also outranks an external gate until repo-local red
  // work is cleared.
  if (hasOpenPr && RED_CI_CONCLUSIONS.has(ciConclusion)) {
    return { status: 'handoff-ready', reason: `ci-${ciConclusion}` };
  }

  if (hasOpenPr && mergeable === false) {
    return { status: 'handoff-ready', reason: 'merge-conflict' };
  }

  if (HUMAN_BLOCKERS.has(blocker)) {
    return {
      status: 'human-gate',
      reason: blocker,
      labels: ['gate:human', ...(gateLabel(blocker) ? [gateLabel(blocker)] : [])],
    };
  }

  if (ciRunning) {
    return { status: 'waiting-ci', reason: 'ci-running' };
  }

  const hasClaim = Boolean(workerState || hasOpenPr || hasBranch);
  if (!hasClaim) {
    return { status: 'ready', reason: 'unclaimed' };
  }

  const stale = minutesSince(nowMs, latestActivityMs) >= STALE_MINUTES;
  if (!stale) {
    return { status: 'active', reason: 'fresh-lease' };
  }

  if (hasOpenPr && ciConclusion === 'success' && mergeable === true) {
    return { status: 'handoff-ready', reason: 'merge-ready-stale' };
  }

  if (explicitState === 'READY_TO_MERGE') {
    return { status: 'handoff-ready', reason: 'ready-to-merge' };
  }

  return { status: 'handoff-ready', reason: 'stale-lease' };
}

function statusLabel(status) {
  const mapping = {
    ready: 'worker:ready',
    active: 'worker:active',
    'waiting-ci': 'worker:waiting-ci',
    'handoff-ready': 'worker:handoff-ready',
  };
  return mapping[status] || null;
}

function isWatchdogComment(body) {
  return Boolean(body && body.includes(WATCHDOG_MARKER));
}

function handoffComment({ issueNumber, branch, prNumber, head, reason, ciConclusion, nextAction }) {
  const reasonText = {
    'merge-ready-stale': 'the existing PR is green/mergeable but the worker lease went stale',
    'stale-lease': 'there has been no repository-visible worker activity for at least 30 minutes',
    'explicit-handoff': 'the previous worker explicitly handed the task off',
    'ready-to-merge': 'the task was marked ready to merge but has no active worker',
    'merge-conflict': 'the current PR has an immediate repository-local merge conflict/blocker',
  }[reason] || (reason.startsWith('ci-')
    ? `the latest branch head is red in CI (${reason.slice(3)}); no stale-worker timeout applies`
    : reason);

  return `<!-- foodex-watchdog:handoff issue=${issueNumber} head=${head || 'none'} reason=${reason} -->
## Worker Watchdog — AUTO-HANDOFF READY

This task is repository-local and is ready for another worker to continue because **${reasonText}**.

- Issue: #${issueNumber}
- Existing branch: \`${branch || 'resolve from the existing Issue/PR before creating anything'}\`
- Existing PR: ${prNumber ? `#${prNumber}` : 'none yet'}
- Latest head: \`${head || 'unknown'}\`
- Last CI conclusion: \`${ciConclusion || 'none'}\`
- Next action: ${nextAction || 'continue from the latest remote head, inspect CI/current state, and finish the same task'}

**Takeover rule:** continue the same Issue/branch/PR. Do not create a replacement branch or duplicate PR. Add/update a \`${STATE_MARKER}\` state block when taking over.
`;
}

async function ensureLabels(github, owner, repo, core) {
  for (const [name, [color, description]] of Object.entries(LABELS)) {
    try {
      await github.rest.issues.getLabel({ owner, repo, name });
    } catch (error) {
      if (error.status !== 404) throw error;
      core.info(`Creating label ${name}`);
      await github.rest.issues.createLabel({ owner, repo, name, color, description });
    }
  }
}

async function replaceStatusLabels(github, owner, repo, issue, desired) {
  const current = new Set(issue.labels.map(label => typeof label === 'string' ? label : label.name).filter(Boolean));
  const desiredSet = new Set(desired);

  for (const label of STATUS_LABELS) {
    if (current.has(label) && !desiredSet.has(label)) {
      try {
        await github.rest.issues.removeLabel({ owner, repo, issue_number: issue.number, name: label });
      } catch (error) {
        if (error.status !== 404) throw error;
      }
    }
  }

  for (const label of desiredSet) {
    if (!current.has(label)) {
      await github.rest.issues.addLabels({ owner, repo, issue_number: issue.number, labels: [label] });
    }
  }

  for (const label of GATE_LABELS) {
    if (!current.has(label) || desiredSet.has(label)) continue;
    try {
      await github.rest.issues.removeLabel({ owner, repo, issue_number: issue.number, name: label });
    } catch (error) {
      if (error.status !== 404) throw error;
    }
  }
}

async function latestCommitMillis(github, owner, repo, ref) {
  if (!ref) return 0;
  try {
    const { data } = await github.rest.repos.getCommit({ owner, repo, ref });
    return newestMillis(data.commit?.committer?.date, data.commit?.author?.date);
  } catch (_) {
    return 0;
  }
}

async function branchExists(github, owner, repo, branch) {
  if (!branch) return false;
  try {
    await github.rest.repos.getBranch({ owner, repo, branch });
    return true;
  } catch (error) {
    if (error.status === 404) return false;
    throw error;
  }
}

function summarizeWorkflowRuns(runs) {
  const newestByWorkflow = new Map();

  for (const run of runs) {
    if (SELF_WATCHDOG_WORKFLOW_NAMES.has(run.name)) continue;
    const key = run.workflow_id ?? run.name ?? run.id;
    const runMs = newestMillis(
      run.run_started_at,
      run.created_at,
      run.updated_at,
      typeof run.id === 'number' ? run.id : 0,
    );
    const previous = newestByWorkflow.get(key);
    if (!previous || runMs >= previous.ms) {
      newestByWorkflow.set(key, { run, ms: runMs });
    }
  }

  const latest = [...newestByWorkflow.values()].map(entry => entry.run);
  const red = latest.find(
    run => run.status === 'completed' && RED_CI_CONCLUSIONS.has(run.conclusion),
  );
  const running = latest.some(run => run.status !== 'completed');

  // A red latest run for any workflow wins immediately, even while another
  // workflow is still queued/running on the same head.
  if (red) {
    return { running, conclusion: red.conclusion };
  }

  if (running) {
    return { running: true, conclusion: null };
  }

  const meaningful = latest
    .map(run => run.conclusion)
    .filter(conclusion => conclusion && conclusion !== 'skipped');

  if (meaningful.length === 0) {
    return { running: false, conclusion: null };
  }

  if (meaningful.every(conclusion => conclusion === 'success' || conclusion === 'neutral')) {
    return {
      running: false,
      conclusion: meaningful.includes('success') ? 'success' : 'neutral',
    };
  }

  return { running: false, conclusion: meaningful[0] };
}

function summarizeCheckRuns(checkRuns) {
  const newestByCheck = new Map();
  for (const check of checkRuns) {
    if (SELF_WATCHDOG_CHECK_NAMES.has(check.name)) continue;
    const key = `${check.app?.id || 'app'}:${check.name || check.id}`;
    const ms = newestMillis(check.started_at, check.completed_at);
    const previous = newestByCheck.get(key);
    if (!previous || ms >= previous.ms) newestByCheck.set(key, { check, ms });
  }

  const latest = [...newestByCheck.values()].map(entry => entry.check);
  const red = latest.find(
    check => check.status === 'completed' && RED_CI_CONCLUSIONS.has(check.conclusion),
  );
  const running = latest.some(check => check.status !== 'completed');

  if (red) return { running, conclusion: red.conclusion };
  if (running) return { running: true, conclusion: null };
  return { running: false, conclusion: null };
}

function summarizeCommitStatuses(statuses) {
  const newestByContext = new Map();
  for (const status of statuses) {
    const key = status.context || status.id;
    const ms = newestMillis(status.updated_at, status.created_at);
    const previous = newestByContext.get(key);
    if (!previous || ms >= previous.ms) newestByContext.set(key, { status, ms });
  }

  const latest = [...newestByContext.values()].map(entry => entry.status);
  const red = latest.find(status => status.state === 'failure' || status.state === 'error');
  const running = latest.some(status => status.state === 'pending');

  if (red) return { running, conclusion: 'failure' };
  if (running) return { running: true, conclusion: null };
  return { running: false, conclusion: null };
}

function combineCiStates(states) {
  const red = states.find(state => RED_CI_CONCLUSIONS.has(state.conclusion));
  const running = states.some(state => state.running);
  if (red) return { running, conclusion: red.conclusion };
  if (running) return { running: true, conclusion: null };

  const successful = states.find(state => state.conclusion === 'success');
  return { running: false, conclusion: successful ? 'success' : null };
}

async function workflowState(github, owner, repo, pr) {
  if (!pr) return { running: false, conclusion: null };

  const [workflowResponse, checksResponse] = await Promise.all([
    github.rest.actions.listWorkflowRunsForRepo({
      owner,
      repo,
      branch: pr.head.ref,
      per_page: 100,
    }),
    github.rest.checks.listForRef({
      owner,
      repo,
      ref: pr.head.sha,
      per_page: 100,
    }),
  ]);

  const matching = workflowResponse.data.workflow_runs.filter(
    run => run.head_sha === pr.head.sha,
  );
  const primary = combineCiStates([
    summarizeWorkflowRuns(matching),
    summarizeCheckRuns(checksResponse.data.check_runs || []),
  ]);

  // Commit statuses are legacy/external fallback only. Do not spend a third
  // API call when Actions/check-runs already provide current CI state.
  if (primary.running || primary.conclusion) return primary;

  const statusesResponse = await github.rest.repos.listCommitStatusesForRef({
    owner,
    repo,
    ref: pr.head.sha,
    per_page: 100,
  });
  return combineCiStates([
    primary,
    summarizeCommitStatuses(statusesResponse.data || []),
  ]);
}

async function latestIssueComments(github, owner, repo, issue) {
  if (!issue?.comments) return [];
  const perPage = 100;
  const page = Math.max(1, Math.ceil(issue.comments / perPage));
  const { data } = await github.rest.issues.listComments({
    owner,
    repo,
    issue_number: issue.number,
    per_page: perPage,
    page,
  });
  return data;
}

function targetIssueNumbersFromContext(context) {
  const payload = context.payload || {};
  if (context.eventName === 'issues' || context.eventName === 'issue_comment') {
    return payload.issue?.number ? [payload.issue.number] : [];
  }
  if (context.eventName === 'pull_request_target') {
    return linkedIssueNumbers(payload.pull_request?.body || '');
  }
  return [];
}

async function resolveTargetPulls(github, owner, repo, context) {
  const payload = context.payload || {};
  if (context.eventName === 'pull_request_target' && payload.pull_request) {
    return [payload.pull_request];
  }
  if (context.eventName === 'workflow_run') {
    const refs = payload.workflow_run?.pull_requests || [];
    const pulls = [];
    for (const ref of refs.slice(0, 5)) {
      const { data } = await github.rest.pulls.get({
        owner,
        repo,
        pull_number: ref.number,
      });
      pulls.push(data);
    }
    return pulls;
  }
  return null;
}

async function runCore({ github, context, core, nowMs }) {
  const { owner, repo } = context.repo;
  const fullSweep = context.eventName === 'schedule' || context.eventName === 'workflow_dispatch';

  // Label existence is stable repository configuration. Verify/create it only
  // on periodic/manual reconciliation, not on every PR/comment/workflow event.
  if (fullSweep) await ensureLabels(github, owner, repo, core);

  let pulls = await resolveTargetPulls(github, owner, repo, context);
  if (pulls === null) {
    const response = await github.rest.pulls.list({ owner, repo, state: 'open', per_page: 100 });
    pulls = response.data;
  }

  let openIssues;
  if (fullSweep) {
    const response = await github.rest.issues.listForRepo({
      owner,
      repo,
      state: 'open',
      per_page: 100,
    });
    openIssues = response.data.filter(issue => !issue.pull_request);
  } else {
    const direct = new Set(targetIssueNumbersFromContext(context));
    for (const pr of pulls) {
      for (const issueNumber of linkedIssueNumbers(pr.body)) direct.add(issueNumber);
    }

    // A workflow_run on main or any run with no owning PR/issue is not worker
    // state and must not trigger a repository-wide scan.
    if (direct.size === 0) {
      core.info(`Worker Watchdog no-op for ${context.eventName}: no owned issue target.`);
      return { processed: 0, targeted: true };
    }

    openIssues = [];
    for (const issueNumber of [...direct].slice(0, 10)) {
      const { data } = await github.rest.issues.get({ owner, repo, issue_number: issueNumber });
      if (!data.pull_request && data.state === 'open') openIssues.push(data);
    }
  }

  const prByIssue = new Map();
  for (const pr of pulls) {
    for (const issueNumber of linkedIssueNumbers(pr.body)) {
      if (!prByIssue.has(issueNumber)) prByIssue.set(issueNumber, pr);
    }
  }

  let processed = 0;
  for (const issue of openIssues) {
    const labelNames = issue.labels.map(label => typeof label === 'string' ? label : label.name).filter(Boolean);
    const linkedPr = prByIssue.get(issue.number) || null;

    // Read only the newest comment page. Worker state is append-only and the
    // newest state marker wins; old history does not need to be paginated.
    const comments = await latestIssueComments(github, owner, repo, issue);
    const nonWatchdogComments = comments.filter(comment => !isWatchdogComment(comment.body));
    let workerState = null;
    for (let i = nonWatchdogComments.length - 1; i >= 0; i -= 1) {
      workerState = parseWorkerState(nonWatchdogComments[i].body || '');
      if (workerState) break;
    }
    if (!workerState) workerState = parseWorkerState(linkedPr?.body || '');
    if (!workerState) workerState = parseWorkerState(issue.body || '');

    const managed = Boolean(
      (issue.body || '').includes(MANAGED_MARKER)
      || workerState
      || linkedPr
      || labelNames.some(label => STATUS_LABELS.includes(label))
    );
    if (!managed) continue;

    let branch = workerState?.branch || linkedPr?.head?.ref || '';
    if (!branch) {
      const searchable = [...nonWatchdogComments].reverse().map(comment => comment.body || '');
      branch = searchable.map(branchFromText).find(Boolean) || '';
    }

    const hasBranch = linkedPr ? true : await branchExists(github, owner, repo, branch);
    const stateHeartbeat = asMillis(workerState?.heartbeat);
    const heartbeatFresh = stateHeartbeat && minutesSince(nowMs, stateHeartbeat) < STALE_MINUTES;
    const commitActivity = heartbeatFresh
      ? 0
      : await latestCommitMillis(github, owner, repo, linkedPr?.head?.sha || branch);
    const latestActivityMs = executionActivityMillis({ commitActivity, stateHeartbeat });

    const ci = await workflowState(github, owner, repo, linkedPr);
    let mergeable = linkedPr?.mergeable ?? null;
    if (linkedPr && mergeable === null) {
      const { data } = await github.rest.pulls.get({ owner, repo, pull_number: linkedPr.number });
      mergeable = data.mergeable;
    }

    const outcome = classify({
      nowMs,
      managed,
      workerState,
      hasOpenPr: Boolean(linkedPr),
      hasBranch,
      latestActivityMs,
      ciRunning: ci.running,
      ciConclusion: ci.conclusion,
      mergeable,
    });

    processed += 1;
    core.info(`#${issue.number}: ${outcome.status} (${outcome.reason})`);

    if (outcome.status === 'human-gate') {
      await replaceStatusLabels(github, owner, repo, issue, outcome.labels);
      continue;
    }

    const desiredLabel = statusLabel(outcome.status);
    await replaceStatusLabels(github, owner, repo, issue, desiredLabel ? [desiredLabel] : []);

    if (outcome.status !== 'handoff-ready') continue;

    const head = linkedPr?.head?.sha || workerState?.head || '';
    const marker = `<!-- foodex-watchdog:handoff issue=${issue.number} head=${head || 'none'} reason=${outcome.reason} -->`;
    if (comments.some(comment => (comment.body || '').includes(marker))) continue;

    await github.rest.issues.createComment({
      owner,
      repo,
      issue_number: issue.number,
      body: handoffComment({
        issueNumber: issue.number,
        branch,
        prNumber: linkedPr?.number,
        head,
        reason: outcome.reason,
        ciConclusion: ci.conclusion,
        nextAction: workerState?.nextAction,
      }),
    });
  }

  return { processed, targeted: !fullSweep };
}

async function run({ github, context, core, nowMs = Date.now() }) {
  try {
    return await runCore({ github, context, core, nowMs });
  } catch (error) {
    if (!isRateLimitError(error)) throw error;

    const reset = error.response?.headers?.['x-ratelimit-reset'];
    const resetText = reset
      ? new Date(Number(reset) * 1000).toISOString()
      : 'unknown';
    core.warning(`Worker Watchdog deferred: GitHub API rate limit exhausted; reset=${resetText}. Rate limiting is infrastructure pressure, not branch CI failure; repository state was left unchanged from the point of deferral.`);
    return { deferred: true, reason: 'github-api-rate-limit', reset: resetText };
  }
}

module.exports = {
  HUMAN_BLOCKERS,
  MANAGED_MARKER,
  RED_CI_CONCLUSIONS,
  STALE_MINUTES,
  STATE_MARKER,
  branchFromText,
  classify,
  executionActivityMillis,
  handoffComment,
  isRateLimitError,
  linkedIssueNumbers,
  targetIssueNumbersFromContext,
  minutesSince,
  parseWorkerState,
  run,
  statusLabel,
  summarizeCheckRuns,
  summarizeCommitStatuses,
  summarizeWorkflowRuns,
};
