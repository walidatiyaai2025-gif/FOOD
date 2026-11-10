'use strict';

/**
 * V2-specific 30-minute idle lease sweeper.
 * Never changes main, deletes branches, closes PRs or overwrites source.
 * GitHub Actions schedule runs ONLY from the repository's default branch;
 * keep this V2 branch isolated until explicit owner approval.
 */
const LIMIT_MS = 30 * 60 * 1000;
const STATE = '<!-- foodex-worker-state:v1 -->';
const RELEASE_MARK = '<!-- foodex-v2-idle-release:';
const CLAIM_RE = /(?:^|\n)\s*(?:OWNER|BRANCH|HEARTBEAT|STATE)\s*:/m;
const ACTIVE = new Set(['worker:active', 'worker:waiting-ci']);
const name = label => typeof label === 'string' ? label : label.name;

function time(value) {
  const v = Date.parse(value || '');
  return Number.isFinite(v) ? v : 0;
}
function isV2(issue) {
  return /^V2(?:\s|[:|\[-])/i.test(issue.title || '') && issue.state === 'open' && !issue.pull_request;
}
function parseClaim(comments) {
  // Track the original claim, not the latest heartbeat/status comment.
  // Repeated WAITING_CI comments cannot extend a 30-minute inactivity TTL.
  let claim = null;
  const inactive = new Set(['HANDOFF', 'HANDOFF_READY', 'RELEASED', 'UNCLAIMED', 'INTEGRATED_PENDING_ACCEPTANCE']);
  for (const c of comments || []) {
    const body = c.body || '';
    if (body.includes(RELEASE_MARK) || body.includes('<!-- foodex-watchdog:handoff')) {
      claim = null;
      continue;
    }
    const marker = body.lastIndexOf(STATE);
    if (marker < 0) continue;
    const kv = Object.fromEntries(body.slice(marker + STATE.length).split(/\r?\n/)
      .map(line => line.match(/^\s*([A-Z_]+)\s*:\s*(.*?)\s*$/))
      .filter(Boolean).map(m => [m[1], m[2]]));
    const state = (kv.STATE || 'ACTIVE').toUpperCase();
    if (inactive.has(state)) { claim = null; continue; }
    if (!kv.OWNER || !time(c.created_at)) continue;
    // A worker may claim before creating its issue branch; keep the empty
    // branch identity until a subsequent checkpoint supplies the real ref.
    const branch = kv.BRANCH || '';
    if (!claim || claim.owner !== kv.OWNER || claim.branch !== branch) {
      claim = { owner: kv.OWNER, branch, claimId: c.id,
        createdMs: time(c.created_at) };
    }
    claim = { ...claim, commentId: c.id, state, pr: Number(kv.PR) || 0,
      blocker: (kv.BLOCKER || '').toLowerCase(), heartbeatMs: time(kv.HEARTBEAT) };
  }
  return claim;
}
function activityDecision({ nowMs, claim, latestCommitMs, latestPrCommitMs, ciRunning }) {
  if (!claim || !claim.createdMs) return { release: false, why: 'no-verifiable-claim' };
  // A comment or self-declared heartbeat without associated code/CI work
  // never extends a lease. The *commit date* must fall after this claim.
  const workMs = Math.max(
    latestCommitMs > claim.createdMs ? latestCommitMs : 0,
    latestPrCommitMs > claim.createdMs ? latestPrCommitMs : 0,
    claim.createdMs
  );
  if (ciRunning) return { release: false, why: 'ci-in-progress' };
  if (nowMs - workMs < LIMIT_MS) return { release: false, why: 'within-30-minute-window' };
  return { release: true, why: 'idle-over-30m', workMs };
}
function claimIdentity(c) {
  return String(c.claimId || c.commentId) + ':' + String(c.createdMs);
}

async function listPages(fetch, config, maxPages = 15) {
  const found = [];
  for (let page = 1; page <= maxPages; page++) {
    const response = await fetch({ ...config, per_page: 100, page });
    // GitHub Search returns { data: { items: [...] } }, while REST list
    // endpoints return { data: [...] }. A malformed response must fail closed.
    const pageItems = Array.isArray(response?.data) ? response.data : response?.data?.items;
    if (!Array.isArray(pageItems)) throw Error('Invalid GitHub pagination payload; refuse incomplete sweep');
    found.push(...pageItems);
    if (pageItems.length < 100) break;
    if (page === maxPages) throw Error('V2 scan pagination limit reached; refuse incomplete sweep');
  }
  return found;
}
async function branchState(github, owner, repo, branch) {
  if (!branch) return { sha: '', commitMs: 0 };
  try {
    const ref = await github.rest.repos.getBranch({ owner, repo, branch });
    const sha = ref.data.commit.sha;
    const commit = await github.rest.repos.getCommit({ owner, repo, ref: sha });
    return { sha, commitMs: time(commit.data.commit?.committer?.date)
      || time(commit.data.commit?.author?.date) };
  } catch (err) {
    if (err.status === 404) return { sha: '', commitMs: 0 };
    throw err;
  }
}
async function ciInProgress(github, owner, repo, head, branch) {
  if (!head) return false;
  const [checks, workflows] = await Promise.all([
    github.rest.checks.listForRef({ owner, repo, ref: head, per_page: 100 }),
    github.rest.actions.listWorkflowRunsForRepo({ owner, repo, branch, per_page: 100 }),
  ]);
  const checkActive = (checks.data.check_runs || []).some(check =>
    check.status !== 'completed' && check.name !== 'Release V2 claims with no real work for 30 minutes');
  const workflowActive = (workflows.data.workflow_runs || []).some(run =>
    run.head_sha === head && run.status !== 'completed'
    && !['FOODEX V2 Idle Lease Watchdog', 'Worker Watchdog'].includes(run.name));
  return checkActive || workflowActive;
}
function humanGate(labels, claim) {
  return labels.some(l => l === 'gate:human' || l.startsWith('gate:'))
    || ['human', 'approval', 'credentials', 'device', 'production', 'deploy'].includes(claim?.blocker);
}

async function run({ github, context, core, nowMs = Date.now() }) {
  const { owner, repo } = context.repo;
  // Search is scoped by title to avoid walking unrelated repo backlog.
  const issues = await listPages(args => github.rest.search.issuesAndPullRequests(args), {
    q: 'repo:' + owner + '/' + repo + ' is:issue is:open in:title V2 label:worker:active,worker:waiting-ci',
    sort: 'created',
    order: 'asc',
  });
  const prs = await listPages(args => github.rest.pulls.list(args), { owner, repo, state: 'open' });
  let scanned = 0, released = 0, active = 0;
  for (const issue of issues.filter(isV2)) {
    const labels = (issue.labels || []).map(name);
    // Explicit worker label is REQUIRED when claiming. Do not scan 423+ unclaimed backlog items every five minutes.
    const comments = await listPages(args => github.rest.issues.listComments(args), {
      owner, repo, issue_number: issue.number,
    }, 10);
    const claim = parseClaim(comments);
    if (!claim) continue;
    scanned += 1;
    const ownPr = prs.find(pr =>
      pr.base?.ref === 'Foodex_V2' &&
      (
        (claim.pr && pr.number === claim.pr) ||
        (claim.branch && pr.head?.ref === claim.branch)
      )
    );
    // Use the *live issue branch*, not a potentially stale PR-search head.
    const firstBranch = await branchState(github, owner, repo, claim.branch);
    const head = firstBranch.sha || ownPr?.head?.sha || '';
    const ciRunning = await ciInProgress(github, owner, repo, head, claim.branch);
    if (humanGate(labels, claim)) { active += 1; continue; }

    const decision = activityDecision({
      nowMs, claim, latestCommitMs: firstBranch.commitMs,
      latestPrCommitMs: firstBranch.commitMs, ciRunning,
    });
    if (!decision.release) { active += 1; continue; }
    const marker = RELEASE_MARK + 'issue=' + issue.number + ' claim=' + claimIdentity(claim) + ' -->';
    if (comments.some(c => (c.body || '').includes(marker))) continue;
    // Re-read latest claim immediately before release to prevent racing
    // against a replacement worker claim or a fresh lease.
    const current = await listPages(args => github.rest.issues.listComments(args), {
      owner, repo, issue_number: issue.number,
    }, 10);
    if (claimIdentity(parseClaim(current) || {}) !== claimIdentity(claim)) {
      core.info('#' + issue.number + ': skip; claim changed during scan');
      continue;
    }
    // A newer push or newly queued exact-head CI cancels the handoff.
    // Labels are re-read because a new worker may have claimed since search.
    const live = await github.rest.issues.get({ owner, repo, issue_number: issue.number });
    const liveLabels = (live.data.labels || []).map(name);
    if (!isV2(live.data) || !liveLabels.some(l => ACTIVE.has(l))
        || humanGate(liveLabels, claim)) continue;
    const secondBranch = await branchState(github, owner, repo, claim.branch);
    if (secondBranch.sha !== firstBranch.sha) continue;
    const secondCi = await ciInProgress(github, owner, repo,
      secondBranch.sha || head, claim.branch);
    const secondDecision = activityDecision({
      nowMs, claim, latestCommitMs: secondBranch.commitMs,
      latestPrCommitMs: secondBranch.commitMs, ciRunning: secondCi,
    });
    if (!secondDecision.release) continue;
    // Labels are best-effort queue signals. Release never deletes original branch/PR.
    for (const currentLabel of [...ACTIVE]) {
      if (!liveLabels.includes(currentLabel)) continue;
      try {
        await github.rest.issues.removeLabel({ owner, repo, issue_number: issue.number, name: currentLabel });
      } catch (err) { if (err.status !== 404) throw err; }
    }
    await github.rest.issues.addLabels({
      owner, repo, issue_number: issue.number,
      labels: ['worker:handoff-ready'],
    });
    await github.rest.issues.createComment({
      owner, repo, issue_number: issue.number,
      body: marker + '\n## V2 — Automatic lease release (30m inactivity)\n\n'
        + 'The previous claim from **' + (claim.owner || 'unknown worker') + '** has expired because no verifiable code activity was seen for at least 30 minutes.\n'
        + '- Existing branch: `' + (claim.branch || 'not yet created') + '`\n'
        + '- Existing PR: ' + (ownPr ? '#' + ownPr.number : 'none') + '\n'
        + '- No code/branch/PR was deleted or closed.\n'
        + '- **Lease is now free for immediate takeover.** A new worker must inspect existing branch/PR and claim with a new ownership comment before continuing.\n'
        + '- No V2 change may target `main` or production.\n',
    });
    released += 1;
  }
  core.info('V2 idle-lease sweep: ' + JSON.stringify({ scanned, released, active, matchingIssues: issues.length }));
  return { scanned, released, active };
}
module.exports = { LIMIT_MS, isV2, parseClaim, activityDecision, claimIdentity, listPages, run };
