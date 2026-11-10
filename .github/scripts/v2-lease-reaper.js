'use strict';
// Isolated V2 worker lease reconciler. No main/release/production mutation.
const TIMEOUT_MS = 30 * 60 * 1000;
const STATE_MARKER = '<!-- foodex-worker-state:v1 -->';
const CLAIM_MARKER = '<!-- foodex-v2-claim:v1 -->';
const HANDOFF_MARKER = '<!-- foodex-v2-reaper:';
const ACTIVE_LABELS = ['v2:leased','worker:active','worker:waiting-ci'];
const V2_PREFIX = /^V2(?:\s|\||:|\[|—|-)/;
function ms(value) { const n = Date.parse(value || ''); return Number.isFinite(n) ? n : 0; }
function parseState(body) {
  const str = String(body || '');
  const cut = Math.max(str.lastIndexOf(STATE_MARKER), str.lastIndexOf(CLAIM_MARKER));
  if (cut < 0) return null;
  const fields = {};
  for (const line of str.slice(cut).split(/\r?\n/)) {
    const m = line.match(/^\s*([A-Z_]+)\s*:\s*(.*?)\s*$/);
    if (m) fields[m[1]] = m[2];
  }
  if (!fields.OWNER || !fields.BRANCH) return null;
  return {
    owner: fields.OWNER, branch: fields.BRANCH.replace(/^'`|'`$/g, ''),
    head: fields.HEAD || '', pr: fields.PR || '',
    state: (fields.STATE || 'CLAIMED').toUpperCase(),
    heartbeat: fields.HEARTBEAT || '', claimedAt: fields.CLAIMED_AT || '',
  };
}
function chooseLease(comments) {
  let latest = null;
  for (const c of comments || []) {
    if (String(c.body || '').includes(HANDOFF_MARKER)) continue;
    const state = parseState(c.body);
    if (state) latest = { state, commentId: c.id, createdAt: c.created_at };
  }
  return latest;
}
function activityMillis(now, lease, commit) {
  const created = ms(lease.createdAt), claimed = ms(lease.state.claimedAt);
  const beat = ms(lease.state.heartbeat);
  const trustedClaim = claimed && claimed <= now + 60000 && Math.abs(claimed - created) <= 600000 ? claimed : created;
  const trustedBeat = beat && beat <= now + 60000 && Math.abs(beat - created) <= 600000 ? beat : 0;
  const trustedCommit = commit && commit <= now + 60000 ? commit : 0;
  return Math.max(trustedClaim, trustedBeat, trustedCommit);
}
function decision(now, activity, ciRunning, state) {
  if (state === 'HANDOFF') return { status: 'handoff', reason: 'explicit' };
  if (ciRunning) return { status: 'protected', reason: 'exact-head-ci' };
  if (!activity) return { status: 'unknown', reason: 'no-evidence' };
  if (activity > now) return { status: 'protected', reason: 'clock-skew' };
  const idleMinutes = Math.floor((now - activity) / 60000);
  return now - activity >= TIMEOUT_MS
    ? { status: 'handoff', reason: 'idle-30m', idleMinutes }
    : { status: 'protected', reason: 'fresh', idleMinutes };
}
function labels(issue) {
  return (issue.labels || []).map(x => typeof x === 'string' ? x : x.name);
}
function v2(issue) {
  return issue.state === 'open' && !issue.pull_request && V2_PREFIX.test(issue.title || '');
}
async function comments(github, owner, repo, issue) {
  if (!issue.comments) return [];
  const per_page = 100, page = Math.ceil(issue.comments / per_page);
  const r = await github.rest.issues.listComments({owner,repo,issue_number:issue.number,per_page,page});
  return r.data;
}
async function branch(github, owner, repo, name) {
  try {
    const r = await github.rest.repos.getBranch({owner,repo,branch:name});
    const head = r.data.commit.sha;
    const c = await github.rest.repos.getCommit({owner,repo,ref:head});
    return {head,activity:Math.max(ms(c.data.commit?.committer?.date),ms(c.data.commit?.author?.date))};
  } catch (e) {
    if (e.status === 404) return {head:'',activity:0};
    throw e;
  }
}
async function ciRunning(github,owner,repo,name,sha) {
  if (!sha) return false;
  const [checks,runs] = await Promise.all([
    github.rest.checks.listForRef({owner,repo,ref:sha,per_page:100}),
    github.rest.actions.listWorkflowRunsForRepo({owner,repo,branch:name,per_page:100}),
  ]);
  return (checks.data.check_runs || []).some(c =>
    c.name !== 'V2 Lease Reaper' && c.name !== 'Detect stale workers and external gates' && c.status !== 'completed'
  ) || (runs.data.workflow_runs || []).some(r =>
    r.head_sha === sha && r.name !== 'V2 Worker Lease Reaper' && r.name !== 'Worker Watchdog' && r.status !== 'completed'
  );
}
async function candidateIssues(github,owner,repo) {
  const map = new Map();
  for (const label of ACTIVE_LABELS) {
    const rows = await github.paginate(github.rest.issues.listForRepo,{owner,repo,state:'open',labels:label,per_page:100});
    for (const issue of rows) if (v2(issue)) map.set(issue.number,issue);
  }
  return [...map.values()];
}
async function freeLabels(github,owner,repo,issue,hasBranch) {
  for (const label of ACTIVE_LABELS) {
    if (!labels(issue).includes(label)) continue;
    try {
      await github.rest.issues.removeLabel({owner,repo,issue_number:issue.number,name:label});
    } catch (e) { if(e.status!==404) throw e; }
  }
  const target = hasBranch ? 'worker:handoff-ready' : 'worker:ready';
  if (!labels(issue).includes(target)) {
    await github.rest.issues.addLabels({owner,repo,issue_number:issue.number,labels:[target]});
  }
}
async function reconcile({github,context,core,nowMs=Date.now(),dryRun=false}) {
  const {owner,repo} = context.repo;
  const issues = await candidateIssues(github,owner,repo);
  let freed=0,skipped=0;
  for(const candidate of issues) {
    try {
      const claim = chooseLease(await comments(github,owner,repo,candidate));
      if(!claim) { skipped++;core.info('#'+candidate.number+': no structured lease; needs review');continue; }
      const b1 = await branch(github,owner,repo,claim.state.branch);
      const running1 = await ciRunning(github,owner,repo,claim.state.branch,b1.head);
      const initial = decision(nowMs,activityMillis(nowMs,claim,b1.activity),running1,claim.state.state);
      if(initial.status!=='handoff') {skipped++;continue;}
      // Recheck claim, current branch SHA and exact-head CI just before change.
      const live = (await github.rest.issues.get({owner,repo,issue_number:candidate.number})).data;
      if(!v2(live)||!labels(live).some(x=>ACTIVE_LABELS.includes(x))) {skipped++;continue;}
      const lastComments=await comments(github,owner,repo,live), next=chooseLease(lastComments);
      if(!next||next.commentId!==claim.commentId) {skipped++;continue;}
      const b2=await branch(github,owner,repo,claim.state.branch);
      if(b2.head!==b1.head) {skipped++;continue;}
      const running2=await ciRunning(github,owner,repo,claim.state.branch,b2.head);
      const outcome=decision(nowMs,activityMillis(nowMs,next,b2.activity),running2,next.state.state);
      if(outcome.status!=='handoff') {skipped++;continue;}
      const marker=HANDOFF_MARKER+'issue='+live.number+' lease='+claim.commentId+' head='+(b2.head||'none')+' -->';
      core.info('#'+live.number+': stale worker '+claim.state.owner+', idle='+outcome.idleMinutes+'m');
      if(dryRun)continue;
      if(!lastComments.some(c=>String(c.body||'').includes(marker))) {
        await github.rest.issues.createComment({
          owner,repo,issue_number:live.number,
          body:marker+'\n## V2 Worker Lease — AUTO-RELEASED\n\nOwner '+claim.state.owner+
            ' has no verified execution activity for '+outcome.idleMinutes+' minutes (30-minute threshold).\n'+
            'Existing branch: '+claim.state.branch+'; PR: '+(claim.state.pr||'none')+
            '; head: '+(b2.head||'missing')+'.\n'+
            'The issue is now available for takeover. Reuse the SAME Issue/branch/PR; never duplicate work.\n'+
            'PR target is Foodex_V2 ONLY. No merge into main or production deploy.',
        });
      }
      await freeLabels(github,owner,repo,live,Boolean(b2.head||claim.state.pr));
      freed++;
    } catch (e) {
      core.warning('#'+candidate.number+': fail-closed; cannot validate lease: '+e.message);
      skipped++;
    }
  }
  return {checked:issues.length,freed,skipped,dryRun};
}
module.exports={TIMEOUT_MS,STATE_MARKER,parseState,chooseLease,activityMillis,decision,candidateIssues,reconcile};
