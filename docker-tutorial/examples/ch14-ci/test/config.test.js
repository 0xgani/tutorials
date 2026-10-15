import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { loadConfig, describeConfig } from '../src/config.js';

test('defaults without any environment', () => {
  const config = loadConfig({});
  assert.equal(config.port, 3000);
  assert.equal(config.baseUrl, 'http://localhost:3000');
  assert.equal(config.databaseUrl, undefined);
});

test('the password file is read, encoded into the URL and redacted in the description', () => {
  const file = join(mkdtempSync(join(tmpdir(), 'snip-')), 'db_password');
  writeFileSync(file, 'p@ss/word\n');
  const config = loadConfig({
    DATABASE_URL: 'postgres://snip@db:5432/snip',
    DATABASE_PASSWORD_FILE: file,
  });
  assert.equal(decodeURIComponent(new URL(config.databaseUrl).password), 'p@ss/word');
  assert.equal(describeConfig(config).databaseUrl, 'postgres://snip:***@db:5432/snip');
});

test('all problems are reported at once', () => {
  assert.throws(
    () => loadConfig({ PORT: 'abc', DATABASE_URL: 'nope', DATABASE_PASSWORD_FILE: '/does/not/exist' }),
    (err) => /PORT/.test(err.message) && /DATABASE_URL/.test(err.message) && /ENOENT/.test(err.message),
  );
});
