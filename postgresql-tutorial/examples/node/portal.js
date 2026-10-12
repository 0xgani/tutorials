// Per-request context for row-level security (chapter 21). Run: node portal.js
import { pool, asCustomer } from './db.js';

const anasOrders = await asCustomer(1, async (client) => {
  const { rows } = await client.query('SELECT id, status FROM orders ORDER BY id');
  return rows;
});
console.log('customer 1 sees:', anasOrders);

const lines = await asCustomer(1, async (client) => {
  const { rows } = await client.query('SELECT count(*)::integer AS n FROM order_items');
  return rows[0].n;
});
console.log('customer 1 order lines:', lines);

// The same pooled connections, outside asCustomer(): back to the application role.
const { rows } = await pool.query('SELECT current_user, (SELECT count(*)::integer FROM orders) AS orders');
console.log('outside:', rows[0]);

await pool.end();
