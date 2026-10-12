// Full-text search through search_products() (chapters 13 and 19).
// Run: node search.js blue ink
import { pool } from './db.js';

const terms = process.argv.slice(2).join(' ') || 'blue ink';
const { rows } = await pool.query(
  'SELECT id, name, round(rank::numeric, 3) AS rank, snippet FROM search_products($1, $2)',
  [terms, 5],
);
console.log(`${rows.length} result(s) for "${terms}"`);
for (const row of rows) {
  console.log(`${row.id}\t${row.rank}\t${row.name}\t${row.snippet}`);
}

await pool.end();
