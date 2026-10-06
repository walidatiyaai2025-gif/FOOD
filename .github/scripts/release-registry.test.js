'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const {
  assertAppendOnly,
  compareVersions,
  validateRegistry,
  validationVersionForCandidate,
} = require('./release-registry.js');

function fixture(version = '1.0.40') {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'foodex-release-registry-'));
  fs.mkdirSync(path.join(root, 'docs/release'), { recursive: true });
  fs.mkdirSync(path.join(root, 'Release/Updates'), { recursive: true });
  fs.writeFileSync(path.join(root, 'VERSION'), version + '\n');
  fs.writeFileSync(path.join(root, 'docs/release/UPDATE_NOTES_' + version + '.md'), '# notes\n');

  const packagePath = path.join(root, 'Release/Updates/FOODEX-Update.zip');
  fs.writeFileSync(packagePath, 'deterministic-test-package');
  const digest = crypto.createHash('sha256').update(fs.readFileSync(packagePath)).digest('hex');

  fs.writeFileSync(
    path.join(root, 'Release/Updates/FOODEX-Update.json'),
    JSON.stringify({ package: 'FOODEX-Update.zip', target_version: version, sha256: digest }),
  );
  fs.writeFileSync(
    path.join(root, 'Release/Updates/FOODEX-Update.sha256.txt'),
    digest + '  FOODEX-Update.zip\n',
  );
  fs.writeFileSync(path.join(root, 'Release/Updates/FOODEX-Update.files.txt'), 'VERSION\n');

  const entry = {
    version,
    state: 'published',
    registration: 'test',
    release_notes: 'docs/release/UPDATE_NOTES_' + version + '.md',
    dashboard_update_manifest: 'Release/Updates/FOODEX-Update.json',
    dashboard_update_package: 'Release/Updates/FOODEX-Update.zip',
    dashboard_update_checksum: 'Release/Updates/FOODEX-Update.sha256.txt',
    dashboard_update_file_list: 'Release/Updates/FOODEX-Update.files.txt',
    dashboard_update_sha256: digest,
  };
  const registry = {
    schema_version: 1,
    policy: 'main-authoritative-append-only',
    current_version: version,
    releases: [entry],
  };

  return { root, registry, digest };
}

test('semantic versions compare numerically', () => {
  assert.ok(compareVersions('1.0.41', '1.0.40') > 0);
  assert.ok(compareVersions('1.10.0', '1.9.9') > 0);
});

test('registry validates VERSION, manifest and package checksum together', () => {
  const { root, registry, digest } = fixture();
  const result = validateRegistry(root, registry);
  assert.equal(result.version, '1.0.40');
  assert.equal(result.digest, digest);
});

test('registry rejects package bytes that do not match registered SHA', () => {
  const { root, registry } = fixture();
  fs.appendFileSync(path.join(root, 'Release/Updates/FOODEX-Update.zip'), 'tampered');
  assert.throws(() => validateRegistry(root, registry), /SHA-256/);
});

test('published entries are immutable and a release appends exactly one higher version', () => {
  const { registry: base } = fixture();
  const nextEntry = {
    ...base.releases[0],
    version: '1.0.41',
    release_notes: 'docs/release/UPDATE_NOTES_1.0.41.md',
  };
  const head = {
    ...base,
    current_version: '1.0.41',
    releases: [...base.releases, nextEntry],
  };
  assert.doesNotThrow(() => assertAppendOnly(base, head));

  const mutated = JSON.parse(JSON.stringify(head));
  mutated.releases[0].note = 'rewritten history';
  assert.throws(() => assertAppendOnly(base, mutated), /immutable/);
});



test('release branch validation may accept only its current final release-candidate entry', () => {
  const { root, registry } = fixture('1.0.58');
  registry.releases[0].state = 'release-candidate';

  assert.throws(
    () => validateRegistry(root, registry),
    /must be registered as published/,
  );
  assert.doesNotThrow(() => validateRegistry(
    root,
    registry,
    null,
    { allowReleaseCandidate: true },
  ));
});

test('release-candidate exception never permits historical candidate entries', () => {
  const { root, registry } = fixture('1.0.57');
  const first = registry.releases[0];
  first.state = 'release-candidate';

  fs.writeFileSync(path.join(root, 'docs/release/UPDATE_NOTES_1.0.58.md'), '# notes\n');
  const packagePath = path.join(root, 'Release/Updates/FOODEX-Update.zip');
  const digest = crypto.createHash('sha256').update(fs.readFileSync(packagePath)).digest('hex');
  registry.current_version = '1.0.58';
  registry.releases.push({
    ...first,
    version: '1.0.58',
    state: 'published',
    release_notes: 'docs/release/UPDATE_NOTES_1.0.58.md',
    dashboard_update_sha256: digest,
  });
  fs.writeFileSync(path.join(root, 'VERSION'), '1.0.58\n');
  fs.writeFileSync(
    path.join(root, 'Release/Updates/FOODEX-Update.json'),
    JSON.stringify({ package: 'FOODEX-Update.zip', target_version: '1.0.58', sha256: digest }),
  );

  assert.throws(
    () => validateRegistry(root, registry, null, { allowReleaseCandidate: true }),
    /Release 1.0.57 must be registered as published/,
  );
});

test('unpublished PR candidate may advance VERSION while published registry stays immutable', () => {
  const { root, registry } = fixture('1.0.57');
  fs.writeFileSync(path.join(root, 'VERSION'), '1.0.58\n');

  const validationVersion = validationVersionForCandidate(
    '1.0.58',
    registry,
    JSON.parse(JSON.stringify(registry)),
  );

  assert.equal(validationVersion, '1.0.57');
  assert.doesNotThrow(() => validateRegistry(root, registry, validationVersion));
});

test('unpublished PR candidate cannot rewrite published registry history', () => {
  const { registry: base } = fixture('1.0.57');
  const mutated = JSON.parse(JSON.stringify(base));
  mutated.releases[0].note = 'rewritten';

  assert.throws(
    () => validationVersionForCandidate('1.0.58', mutated, base),
    /may not mutate the published release registry/,
  );
});
