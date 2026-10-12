// Keyset pagination over all orders, newest first. Run: node paginate.js
import { pool } from './db.js';

// The cursor holds the sort key of the last row on the page: (created_at, id).
// created_at goes into it as PostgreSQL's own text, because a JavaScript Date
// keeps only milliseconds and timestamptz has microseconds.
async function ordersPage(cursor, pageSize = 5) {
  const params = [pageSize];
  let where = '';
  if (cursor) {
    const { createdAt, id } = JSON.parse(Buffer.from(cursor, 'base64url').toString());
    where = 'WHERE (created_at, id) < ($2::timestamptz, $3::bigint)';
    params.push(createdAt, id);
  }
  const { rows } = await pool.query(
    `SELECT id, customer_id, created_at, created_at::text AS created_at_key
     FROM orders
     ${where}
     ORDER BY created_at DESC, id DESC
     LIMIT $1`,
    params,
  );
  const last = rows.at(-1);
  const next = rows.length === pageSize
    ? Buffer.from(JSON.stringify({ createdAt: last.created_at_key, id: last.id })).toString('base64url')
    : null;
  return { orders: rows.map(({ id, customer_id }) => ({ id, customer_id })), next };
}

// Show the first three pages (each one is a separate request in a real application).
let cursor = null;
for (let page = 1; page <= 3; page++) {
  const { orders, next } = await ordersPage(cursor);
  console.log(`page ${page}:`, orders.map((o) => o.id).join(', '));
  if (!next) break;
  cursor = next;
}
console.log('next cursor:', cursor);

await pool.end();
