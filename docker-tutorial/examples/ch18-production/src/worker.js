import pg from 'pg';
import { connectValkey } from './valkey.js';
import { loadConfigOrExit } from './config.js';
import { logger as root } from './logger.js';

const logger = root.child({ component: 'worker' });

// Moves queued clicks from Valkey into PostgreSQL in batches:
// one UPDATE per link per batch instead of one per redirect.
const config = loadConfigOrExit();
const pool = new pg.Pool({ connectionString: config.databaseUrl, max: 2 });
pool.on('error', (err) => logger.error({ err }, 'database connection error'));
const valkey = await connectValkey(config.valkeyUrl);
let running = true;

// Finish the current batch, then exit. The blocking pop returns within 2 s,
// well inside Docker's 10 s stop timeout.
function stop(signal) {
  logger.info({ signal }, 'stopping after the current batch');
  running = false;
}
process.on('SIGTERM', stop);
process.on('SIGINT', stop);

logger.info('worker started');
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
  logger.info({ clicks: reply[1].length, links: counts.size }, 'recorded clicks');
}

await valkey.close();
await pool.end();
logger.info('worker stopped');
