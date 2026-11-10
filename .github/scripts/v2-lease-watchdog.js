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
  // A worker must explicitly claim with the canonical state marker.
  for (let i = comments.length - 1; i >= 0; i--) {
    const c = comments[i];
    if (!(c.body || '').includes(STATE)) continue;
    const body = c.body.slice(c.body.lastIndexOf(STATE) + STATE.length);
    const kv = Object.fromEntries(body.split(/\r?\n/).map(line => line.match(/^\s*([A-Z_]+)\s*:\s*(.*?)\s*$/)).filter(Boolean).map(m => [m[1], m[2]]));
    if (!kv.STATE && !kv.OWNER && !kv.BRANCH) continue;
    if (['HANDOFF', 'RELEASED', 'UNCLAIMED'].includes((kv.STATE || '').toUpperCase())) return null;
    return { owner: kv.OWNER || c.user?.login || '', branch: kv.BRANCH || '', pr: Number(kv.PR) || 0, createdMs: time(c.created_at), commentId: c.id, state: (kv.STATE || '').toUpperCase(), heartbeatMs: time(kv.HEARTBEAT) };
  }
  return null;
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
  return String(c.commentId) + ':' + String(c.createdMs);
}

async function listPages(fetch, config, maxPages = 15) {
  const found = [];
  for (let page = 1; page <= maxPages; page++) {
    const items = await fetch({ ...config, per_page: 100, page });
    found.push(...items.data);
    if (items.data.length < 100) break;
    if (page === maxPages) throw Error('V2 scan pagination limit reached; refuse incomplete sweep');
  }
  return found;
}
async function commitMs(github, owner, repo, ref) {
  if (!ref) return 0;
  try {
    const r = await github.rest.repos.getCommit({ owner, repo, ref });
    return time(r.data.commit?.committer?.date) || time(r.data.commit?.author?.date);
  } catch (err) {
    if (err.status === 404) return 0;
    throw err;
  }
}
async function ciInProgress(github, owner, repo, head) {
  if (!head) return false;
  const r = await github.rest.checks.listForRef({ owner, repo, ref: head, per_page: 100 });
  return (r.data.check_runs || []).some(check => check.status !== 'completed');
}
async function run({ github, context, core, nowMs = Date.now() }) {
  const { owner, repo } = context.repo;
  // Search is scoped by title to avoid walking unrelated repo backlog.
  const issues = await listPages(args => github.rest.search.issuesAndPullRequests(args), {
    q: 'repo:' + owner + '/' + repo + ' is:issue is:open in:title V2',
    sort: 'created',
    order: 'asc',
  });
  const prs = await listPages(args => github.rest.pulls.list(args), { owner, repo, state: 'open' });
  let scanned = 0, released = 0, active = 0;
  for (const issue of issues.filter(isV2)) {
    const labels = (issue.labels || []).map(name);
    // Include unlabelled claimed Issues so a claim comment cannot be missed.
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
    const head = ownPr?.head?.sha || claim.branch;
    const [commit, ciRunning] = await Promise.all([
      commitMs(github, owner, repo, head),
      ownPr ? ciInProgress(github, owner, repo, ownPr.head.sha) : Promise.resolve(false),
    ]);
    const decision = activityDecision({
      nowMs, claim, latestCommitMs: commit,
      latestPrCommitMs: commit, ciRunning,
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
    // Labels are best-effort queue signals. Release never deletes original branch/PR.
    for (const currentLabel of [...ACTIVE]) {
      if (!labels.includes(currentLabel)) continue;
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
module.exports = { LIMIT_MS, isV2, parseClaim, activityDecision, claimIdentity, run };
