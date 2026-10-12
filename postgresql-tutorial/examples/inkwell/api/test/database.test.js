// Tests of the database itself: functions, row-level security, privileges, triggers. Each test runs in a
// transaction that is rolled back (rolledBack in support.js), so the tests don't affect each other.
import { after, test } from 'node:test';
import assert from 'node:assert/strict';
import { once } from 'node:events';
import pg from 'pg';
import { asCustomer, pool } from '../src/db.js';
import { config } from '../src/config.js';
import { rolledBack, signIn } from './support.js';

after(() => pool.end());

test('place_order needs a signed-in customer', async () => {
  await rolledBack(async (client) => {
    await client.query('SET LOCAL ROLE inkwell_portal');
    await assert.rejects(
      client.query(`SELECT place_order('[{"product_id": 1, "quantity": 1}]')`),
      { code: 'IW002', message: 'no customer is signed in' });
  });
});

test('place_order merges repeated products into one line', async () => {
  await rolledBack(async (client) => {
    await signIn(client, 2);
    const { rows: [{ id }] } = await client.query(
      `SELECT place_order('[{"product_id": 5, "quantity": 2}, {"product_id": 5, "quantity": 3}]') AS id`);
    const { rows } = await client.query(
      'SELECT product_id, quantity, unit_price FROM order_items WHERE order_id = $1', [id]);
    assert.deepEqual(rows, [{ product_id: 5, quantity: 5, unit_price: '1.20' }]);
  });
});

test('the portal role sees exactly the signed-in customer\'s rows', async () => {
  await rolledBack(async (client) => {
    // What staff (inkwell_app) sees for customer 3 ...
    const { rows: expected } = await client.query('SELECT id FROM orders WHERE customer_id = 3 ORDER BY id');
    // ... is what customer 3 sees with no WHERE clause at all.
    await signIn(client, 3);
    const { rows: customers } = await client.query('SELECT id FROM customers');
    const { rows: orders } = await client.query('SELECT id FROM orders ORDER BY id');
    const { rows: [{ count }] } = await client.query(
      'SELECT count(*) FROM order_items WHERE order_id <> ALL($1::bigint[])', [expected.map((o) => o.id)]);
    assert.deepEqual(customers, [{ id: 3 }]);
    assert.deepEqual(orders, expected);
    assert.ok(expected.length >= 2);
    assert.equal(count, 0, 'no order lines of other customers');
  });
});

test("the portal role can't change orders directly", async () => {
  await rolledBack(async (client) => {
    await signIn(client, 3);
    await assert.rejects(client.query("UPDATE orders SET status = 'cancelled' WHERE id = 9"),
      { code: '42501', message: 'permission denied for table orders' });
  });
});

test('a customer can only post reviews in their own name', async () => {
  await rolledBack(async (client) => {
    await signIn(client, 4);
    await assert.rejects(
      client.query('INSERT INTO reviews (product_id, customer_id, rating) VALUES (3, 1, 1)'),
      { code: '42501', message: 'new row violates row-level security policy for table "reviews"' });
  });
});

test('order changes are audited, and the audit log is read-only for the application', async () => {
  await rolledBack(async (client) => {
    await signIn(client, 6);
    const { rows: [{ id }] } = await client.query(
      `SELECT place_order('[{"product_id": 3, "quantity": 1}]') AS id`);
    await client.query('RESET ROLE'); // back to inkwell_app, which may read the audit log
    const { rows } = await client.query(
      `SELECT operation, changed_by, customer_id, new_row ->> 'status' AS status
       FROM audit_log WHERE table_name = 'orders' AND row_id = $1`, [id]);
    assert.deepEqual(rows, [{ operation: 'INSERT', changed_by: 'inkwell_app', customer_id: 6, status: 'pending' }]);

    await assert.rejects(client.query('DELETE FROM audit_log'), { code: '42501' });
  });
});

test('search_products ranks matches in the name above matches in the description', async () => {
  const { rows } = await pool.query("SELECT name, rank FROM search_products('notebook')");
  assert.ok(rows.length >= 3);
  assert.ok(rows.every((row) => /notebook/i.test(row.name)));
  assert.ok(rows.every((row, i) => i === 0 || rows[i - 1].rank >= row.rank), 'sorted by rank');
});

test('a committed order notifies listeners on the order_placed channel', async () => {
  const listener = new pg.Client({ connectionString: config.databaseUrl });
  await listener.connect();
  try {
    await listener.query('LISTEN order_placed');
    const notification = once(listener, 'notification');
    const orderId = await asCustomer(8, async (client) => {
      const { rows: [{ id }] } = await client.query(
        `SELECT place_order('[{"product_id": 12, "quantity": 1}]') AS id`);
      return id;
    });
    const [message] = await notification;
    assert.deepEqual(JSON.parse(message.payload), { order_id: orderId, customer_id: 8 });
  } finally {
    await listener.end();
  }
});
