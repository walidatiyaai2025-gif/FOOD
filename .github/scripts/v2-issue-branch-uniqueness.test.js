'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { branchIssue, detectIssueBranches, allBranchNames, run } = require('./v2-issue-branch-uniqueness');

const HEAD = 'ci/3377-v2-issue-branch-uniqueness';

function fakeGithub(pages) {
  return {
    rest: { repos: { listBranches() {} } },
    paginate: {
      iterator: async function* () {
        for (const page of pages) yield { data: page };
      },
    },
  };
}

function context(body = 'Refs #3377; Customer, Driver, Van unaffected') {
  return {
    repo: { owner: 'example', repo: 'food' },
    payload: { pull_request: {
      base: { ref: 'Foodex_V2' },
      head: { ref: HEAD, repo: { full_name: 'example/food' } },
      body,
    } },
  };
}

test('one remote branch for the exact issue is authorized', () => {
  assert.deepEqual(detectIssueBranches(HEAD, [
    'main', 'Foodex_V2', HEAD, 'feat/3378-other-issue',
    'release/3377-production', 'feature/3377-unscoped',
  ]), { issue: 3377, head: HEAD, branchCount: 1 });
});

test('two distinct implementation branches for the same issue fail closed', () => {
  assert.throws(() => detectIssueBranches(HEAD, [
    HEAD, 'fix/3377-competing-branch', 'test/33770-unrelated',
  ]), /multiple issue-scoped branches/);
});

test('reject a missing current remote branch', () => {
  assert.throws(() => detectIssueBranches(HEAD, [
    'fix/3377-other-branch',
  ]), /absent from repository branches/);
});

test('reject invalid/missing issue-scope and malformed branch list', () => {
  assert.equal(branchIssue('feat/3377-valid-scope'), 3377);
  for (const name of ['main', 'Foodex_V2', 'feature/3377-branch', 'test/0-nope', 'feat/3377-', 'feat/3377-UPPER']) {
    assert.equal(branchIssue(name), null);
  }
  assert.throws(() => detectIssueBranches('main', [HEAD]), /issue-scoped/);
  assert.throws(() => detectIssueBranches(HEAD, null), /Unable to verify/);
  assert.throws(() => detectIssueBranches(HEAD, [HEAD, null]), /Invalid remote/);
});

test('all pages are scanned and second-page conflicts cannot be missed', async () => {
  const branches = await allBranchNames(fakeGithub([
    Array.from({ length: 100 }, (_, index) => ({ name: 'feat/' + (4000 + index) + '-scope' })),
    [{ name: HEAD }, { name: 'test/3377-conflict' }],
  ]), 'example', 'food');
  assert.equal(branches.length, 102);
  assert.throws(() => detectIssueBranches(HEAD, branches), /multiple issue-scoped branches/);
});

test('API payload corruption or failure cannot turn a duplicate into a pass', async () => {
  await assert.rejects(() => allBranchNames(fakeGithub([[{ name: HEAD }], null]), 'example', 'food'), /Incomplete/);
  await assert.rejects(() => allBranchNames(fakeGithub([[{ name: HEAD }, {}]]), 'example', 'food'), /Invalid GitHub/);
  const broken = fakeGithub([]);
  broken.paginate.iterator = async function* () { throw new Error('GitHub unavailable'); };
  await assert.rejects(() => allBranchNames(broken, 'example', 'food'), /GitHub unavailable/);
});

test('real gate checks own issue reference, base, origin, and matching refs', async () => {
  const messages = [];
  const args = { github: fakeGithub([[{ name: HEAD }], [{ name: 'fix/999-unrelated' }]]),
    context: context(), core: { info: message => messages.push(message) } };
  assert.equal((await run(args)).issue, 3377);
  assert.equal(messages.length, 1);
  await assert.rejects(() => run({ ...args, context: context('Refs #33770') }), /own issue/);
  const wrongBase = context();
  wrongBase.payload.pull_request.base.ref = 'main';
  await assert.rejects(() => run({ ...args, context: wrongBase }), /Foodex_V2/);
  const wrongOrigin = context();
  wrongOrigin.payload.pull_request.head.repo.full_name = 'fork/food';
  await assert.rejects(() => run({ ...args, context: wrongOrigin }), /this repository/);
});

test('duplicate active worker branch fails in the full gate', async () => {
  await assert.rejects(() => run({
    github: fakeGithub([[{ name: HEAD }, { name: 'fix/3377-duplicate' }]]),
    context: context(), core: { info() {} },
  }), /multiple issue-scoped branches/);
});
