import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { createApp } from '../src/app.js';
import { createMemoryStore } from '../src/memory-store.js';

let server;
let base;

before(async () => {
  const app = createApp({ store: createMemoryStore(), baseUrl: 'http://snip.test' });
  server = app.listen(0);
  await new Promise((resolve) => server.once('listening', resolve));
  base = `http://127.0.0.1:${server.address().port}`;
});

after(() => server.close());

test('GET /healthz answers ok', async () => {
  const res = await fetch(`${base}/healthz`);
  assert.equal(res.status, 200);
  assert.deepEqual(await res.json(), { status: 'ok' });
});

test('creates a link and redirects to it', async () => {
  const created = await fetch(`${base}/api/links`, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ url: 'https://example.com/a/long/path' }),
  });
  assert.equal(created.status, 201);
  const { code, shortUrl } = await created.json();
  assert.match(code, /^[\w-]{7}$/);
  assert.equal(shortUrl, `http://snip.test/${code}`);

  const redirect = await fetch(`${base}/${code}`, { redirect: 'manual' });
  assert.equal(redirect.status, 302);
  assert.equal(redirect.headers.get('location'), 'https://example.com/a/long/path');

  const stats = await (await fetch(`${base}/api/links/${code}`)).json();
  assert.equal(stats.clicks, 1);
});

test('rejects URLs that are not http(s)', async () => {
  const res = await fetch(`${base}/api/links`, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ url: 'javascript:alert(1)' }),
  });
  assert.equal(res.status, 400);
});

test('unknown codes are 404', async () => {
  const res = await fetch(`${base}/nope123`, { redirect: 'manual' });
  assert.equal(res.status, 404);
});
