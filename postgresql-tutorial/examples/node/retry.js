// Two concurrent read-modify-write transactions under REPEATABLE READ (chapter 17).
// PostgreSQL makes the second one fail with 40001, and withTransaction() retries it.
// Run: node retry.js
import { pool, withTransaction } from './db.js';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function sellOneHeronPen(name, startAfter) {
  await sleep(startAfter);
  const stock = await withTransaction(async (client) => {
    const { rows } = await client.query('SELECT stock FROM products WHERE id = 2');
    console.log(`${name}: read stock ${rows[0].stock}`);
    await sleep(100); // the application thinks for a while...
    await client.query('UPDATE products SET stock = $1 WHERE id = 2', [rows[0].stock - 1]);
    return rows[0].stock - 1;
  }, {
    isolation: 'REPEATABLE READ',
    onRetry: (err, attempt) => console.log(`${name}: attempt ${attempt} failed with ${err.code} (${err.message}), retrying`),
  });
  console.log(`${name}: committed, stock now ${stock}`);
}

await pool.query('UPDATE products SET stock = 3 WHERE id = 2'); // the seed value, so the demo can be repeated
await Promise.all([sellOneHeronPen('A', 0), sellOneHeronPen('B', 20)]);

const { rows } = await pool.query('SELECT stock FROM products WHERE id = 2');
console.log('final stock:', rows[0].stock);
await pool.end();
