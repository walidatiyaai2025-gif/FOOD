#!/usr/bin/env node
'use strict';

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const REGISTRY_PATH = 'docs/release/RELEASE_REGISTRY.json';

function fail(message) {
  throw new Error(message);
}

function parseVersion(version) {
  const match = /^(\d+)\.(\d+)\.(\d+)$/.exec(version);
  if (!match) fail('Invalid semantic version: ' + version);
  return match.slice(1).map(Number);
}

function compareVersions(left, right) {
  const a = parseVersion(left);
  const b = parseVersion(right);
  for (let i = 0; i < 3; i += 1) {
    if (a[i] !== b[i]) return a[i] - b[i];
  }
  return 0;
}

function readJson(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8'));
}

function sha256(file) {
  return crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
}

function currentVersion(root) {
  return fs.readFileSync(path.join(root, 'VERSION'), 'utf8').trim();
}

function validateRegistry(root, registry, expectedVersion = null) {
  if (registry.schema_version !== 1) fail('Release registry schema_version must be 1.');
  if (registry.policy !== 'main-authoritative-append-only') {
    fail('Release registry policy must be main-authoritative-append-only.');
  }
  if (!Array.isArray(registry.releases) || registry.releases.length === 0) {
    fail('Release registry must contain at least one release.');
  }

  const repoVersion = expectedVersion || currentVersion(root);
  if (registry.current_version !== repoVersion) {
    fail('VERSION (' + repoVersion + ') must match release registry current_version (' + registry.current_version + ').');
  }

  const seen = new Set();
  for (let i = 0; i < registry.releases.length; i += 1) {
    const entry = registry.releases[i];
    parseVersion(entry.version);
    if (seen.has(entry.version)) fail('Duplicate registered release version: ' + entry.version);
    seen.add(entry.version);

    if (i > 0 && compareVersions(registry.releases[i - 1].version, entry.version) >= 0) {
      fail('Release registry versions must be strictly increasing.');
    }
    if (entry.state !== 'published') fail('Release ' + entry.version + ' must be registered as published.');
    if (!entry.release_notes || !fs.existsSync(path.join(root, entry.release_notes))) {
      fail('Release ' + entry.version + ' is missing its registered release-notes file.');
    }
  }

  const current = registry.releases[registry.releases.length - 1];
  if (current.version !== repoVersion) {
    fail('The last release-registry entry must equal VERSION.');
  }

  for (const key of [
    'dashboard_update_manifest',
    'dashboard_update_package',
    'dashboard_update_checksum',
    'dashboard_update_file_list',
    'dashboard_update_sha256',
  ]) {
    if (!current[key]) fail('Current release is missing ' + key + '.');
  }

  const manifestPath = path.join(root, current.dashboard_update_manifest);
  const packagePath = path.join(root, current.dashboard_update_package);
  const checksumPath = path.join(root, current.dashboard_update_checksum);
  const fileListPath = path.join(root, current.dashboard_update_file_list);
  for (const file of [manifestPath, packagePath, checksumPath, fileListPath]) {
    if (!fs.existsSync(file)) fail('Registered release artifact is missing: ' + path.relative(root, file));
  }

  const manifest = readJson(manifestPath);
  if (manifest.target_version !== repoVersion) {
    fail('Dashboard update target_version (' + manifest.target_version + ') must equal VERSION (' + repoVersion + ').');
  }
  if (manifest.package !== path.basename(packagePath)) {
    fail('Dashboard update manifest package name does not match the registered package.');
  }

  const digest = sha256(packagePath);
  if (manifest.sha256 !== digest) fail('Dashboard update manifest SHA-256 does not match package bytes.');
  if (current.dashboard_update_sha256 !== digest) fail('Release registry SHA-256 does not match package bytes.');

  const checksum = fs.readFileSync(checksumPath, 'utf8').trim().split(/\s+/)[0];
  if (checksum !== digest) fail('Dashboard checksum file does not match package bytes.');

  return { version: repoVersion, digest };
}

function assertAppendOnly(base, head) {
  if (!base) return;
  if (!Array.isArray(base.releases) || !Array.isArray(head.releases)) {
    fail('Base/head release registry is malformed.');
  }
  if (head.releases.length < base.releases.length) {
    fail('Published release registry entries cannot be removed.');
  }

  for (let i = 0; i < base.releases.length; i += 1) {
    if (JSON.stringify(base.releases[i]) !== JSON.stringify(head.releases[i])) {
      fail('Published release registry entry ' + base.releases[i].version + ' is immutable.');
    }
  }

  if (head.current_version === base.current_version) {
    if (head.releases.length !== base.releases.length) {
      fail('Cannot append a release without advancing current_version.');
    }
    return;
  }

  if (head.releases.length !== base.releases.length + 1) {
    fail('A release PR must append exactly one new version.');
  }
  if (compareVersions(head.current_version, base.current_version) <= 0) {
    fail('New release version must be greater than the registered main version.');
  }
  if (head.releases[head.releases.length - 1].version !== head.current_version) {
    fail('New registry entry must match current_version.');
  }
}

function validationVersionForCandidate(repoVersion, registry, baseRegistry) {
  if (!baseRegistry || registry.current_version === repoVersion) {
    return repoVersion;
  }

  if (JSON.stringify(registry) !== JSON.stringify(baseRegistry)) {
    fail(
      'A PR with an unpublished VERSION may not mutate the published release registry. ' +
      'Register generated release artifacts in the dedicated release flow.'
    );
  }

  if (compareVersions(repoVersion, baseRegistry.current_version) <= 0) {
    fail(
      'Candidate VERSION (' + repoVersion + ') must be greater than the published registry version (' +
      baseRegistry.current_version + ').'
    );
  }

  return baseRegistry.current_version;
}

function registryFromGit(root, ref) {
  try {
    const raw = execFileSync(
      'git',
      ['show', ref + ':' + REGISTRY_PATH],
      { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] },
    );
    return JSON.parse(raw);
  } catch (error) {
    return null;
  }
}

function runValidate(root) {
  const registry = readJson(path.join(root, REGISTRY_PATH));
  const repoVersion = currentVersion(root);
  const baseRef = process.env.GITHUB_BASE_REF ? 'origin/' + process.env.GITHUB_BASE_REF : null;
  const baseRegistry = baseRef ? registryFromGit(root, baseRef) : null;
  const validationVersion = validationVersionForCandidate(
    repoVersion,
    registry,
    baseRegistry,
  );
  const result = validateRegistry(root, registry, validationVersion);

  if (baseRef) assertAppendOnly(baseRegistry, registry);

  if (validationVersion !== repoVersion) {
    console.log(
      'Candidate VERSION ' + repoVersion +
      ' is ahead of published registry ' + validationVersion +
      '; published artifacts remain immutable until the release flow registers the candidate.'
    );
  }
  console.log('Release registry valid: ' + result.version + ' ' + result.digest);
}

function assertUnpublished(root, ref) {
  const version = currentVersion(root);
  const base = registryFromGit(root, ref);
  if (!base) fail('Cannot read release registry from ' + ref + '.');
  if (base.releases.some((entry) => entry.version === version)) {
    fail(
      'FOODEX ' + version + ' is already registered on main and is immutable. ' +
      'Bump VERSION and append a new release-registry entry before generating another release package.'
    );
  }
  console.log('Release version ' + version + ' is not registered on ' + ref + '; generation is allowed.');
}

if (require.main === module) {
  const root = process.cwd();
  const command = process.argv[2] || 'validate';
  try {
    if (command === 'validate') runValidate(root);
    else if (command === 'assert-unpublished') assertUnpublished(root, process.argv[3] || 'origin/main');
    else fail('Unknown command: ' + command);
  } catch (error) {
    console.error('::error::' + error.message);
    process.exit(1);
  }
}

module.exports = {
  assertAppendOnly,
  compareVersions,
  parseVersion,
  validateRegistry,
  validationVersionForCandidate,
};
