import pg from 'pg';
import { connectValkey } from './valkey.js';

// Moves queued clicks from Valkey into PostgreSQL in batches:
// one UPDATE per link per batch instead of one per redirect.
const pool = new pg.Pool({ connectionString: process.env.DATABASE_URL, max: 2 });
pool.on('error', (err) => console.error(`database: ${err.message}`));
const valkey = await connectValkey(process.env.VALKEY_URL);
let running = true;

// Finish the current batch, then exit. The blocking pop returns within 2 s,
// well inside Docker's 10 s stop timeout.
function stop(signal) {
  console.log(`${signal} received, stopping after the current batch`);
  running = false;
}
process.on('SIGTERM', stop);
process.on('SIGINT', stop);

console.log(`worker started (pid ${process.pid})`);
while (running) {
  // Wait up to 2 s for clicks, then take up to 500 at once.
  const reply = await valkey.blmPop(2, 'clicks', 'RIGHT', { COUNT: 500 });
  if (!reply) continue;
  const counts = new Map();
  for (const code of reply[1]) counts.set(code, (counts.get(code) ?? 0) + 1);
  await pool.query(
    `UPDATE links SET clicks = links.clicks + batch.n
       FROM unnest($1::text[], $2::int[]) AS batch(code, n)
      WHERE links.code = batch.code`,
    [[...counts.keys()], [...counts.values()]],
  );
  console.log(`recorded ${reply[1].length} clicks for ${counts.size} links`);
}

await valkey.close();
await pool.end();
console.log('worker stopped');
