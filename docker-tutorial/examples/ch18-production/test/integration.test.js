import { test } from 'node:test';
import assert from 'node:assert/strict';

// Runs against a running stack, for example:
//   docker compose up -d --wait
//   docker compose run --rm --no-deps -e SNIP_URL=http://api:3000 api node --test test/integration.test.js
// Skipped when SNIP_URL isn't set, so a plain `node --test` runs only the unit tests.
const base = process.env.SNIP_URL;

test('a short link redirects and its clicks are counted', { skip: !base && 'SNIP_URL not set' }, async () => {
  const created = await fetch(`${base}/api/links`, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ url: 'https://example.com/integration' }),
  });
  assert.equal(created.status, 201);
  const { code } = await created.json();

  const redirect = await fetch(`${base}/${code}`, { redirect: 'manual' });
  assert.equal(redirect.status, 302);
  assert.equal(redirect.headers.get('location'), 'https://example.com/integration');

  // The worker counts clicks asynchronously; poll for up to 10 seconds.
  for (let i = 0; i < 20; i++) {
    const link = await (await fetch(`${base}/api/links/${code}`)).json();
    if (link.clicks === 1) return;
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
  assert.fail('click was not counted within 10 seconds');
});
