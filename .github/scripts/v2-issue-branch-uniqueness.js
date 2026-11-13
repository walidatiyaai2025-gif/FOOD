'use strict';

/**
 * Read-only V2 pull-request invariant: exactly one remote issue-scoped branch
 * exists for the PR's issue number. Never modify a conflicting worker branch.
 */
const BRANCH_RE = /^(?:feat|fix|chore|docs|refactor|test|ci|worker)\/([1-9][0-9]*)-[a-z0-9-]+$/;

function branchIssue(name) {
  const match = typeof name === 'string' ? name.match(BRANCH_RE) : null;
  return match ? Number(match[1]) : null;
}

function detectIssueBranches(head, branchNames) {
  const issue = branchIssue(head);
  if (!issue) throw new Error('V2 PR head must be an issue-scoped implementation branch');
  if (!Array.isArray(branchNames)) throw new Error('Unable to verify remote V2 branches');
  const matches = new Set();
  for (const branch of branchNames) {
    if (typeof branch !== 'string') throw new Error('Invalid remote GitHub branch response');
    if (branchIssue(branch) === issue) matches.add(branch);
  }
  if (!matches.has(head)) {
    throw new Error('V2 PR head is absent from repository branches; verify fork/ref provenance');
  }
  const conflicts = [...matches].filter(branch => branch !== head).sort();
  if (conflicts.length) {
    throw new Error('Issue #' + issue + ' has multiple issue-scoped branches: '
      + [head, ...conflicts].join(', ') + '. Coordinate the owner and preserve the original issue branch/PR; do not create duplicates.');
  }
  return { issue, head, branchCount: matches.size };
}

async function allBranchNames(github, owner, repo) {
  if (!github?.paginate?.iterator || !github?.rest?.repos?.listBranches) {
    throw new Error('GitHub branch API is unavailable; refusing unverified merge');
  }
  const names = [];
  for await (const page of github.paginate.iterator(github.rest.repos.listBranches, {
    owner, repo, per_page: 100,
  })) {
    if (!Array.isArray(page?.data)) {
      throw new Error('Incomplete GitHub branch pagination; refusing unverified merge');
    }
    for (const branch of page.data) {
      if (typeof branch?.name !== 'string' || !branch.name) {
        throw new Error('Invalid GitHub branch payload');
      }
      names.push(branch.name);
    }
  }
  return names;
}

async function run({ github, context, core }) {
  const pr = context?.payload?.pull_request;
  const head = pr?.head?.ref;
  if (pr?.base?.ref !== 'Foodex_V2' || typeof head !== 'string') {
    throw new Error('V2 branch uniqueness can run only for a Foodex_V2 pull request');
  }
  if (pr.head.repo?.full_name !== context.repo.owner + '/' + context.repo.repo) {
    throw new Error('V2 branch provenance check requires an issue branch in this repository');
  }
  const issue = branchIssue(head);
  if (!issue) throw new Error('V2 PR head has no valid issue number');
  const issueReference = new RegExp('(?:^|\\D)#' + issue + '(?![0-9])');
  if (!issueReference.test(pr.body || '')) {
    throw new Error('V2 PR description must explicitly reference its own issue #' + issue);
  }
  const names = await allBranchNames(github, context.repo.owner, context.repo.repo);
  const outcome = detectIssueBranches(head, names);
  core.info('V2 branch ownership gate passed: issue #' + outcome.issue
    + ', head ' + outcome.head + ', matching branches ' + outcome.branchCount);
  return outcome;
}

module.exports = { BRANCH_RE, branchIssue, detectIssueBranches, allBranchNames, run };
