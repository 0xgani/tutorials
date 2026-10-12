// LISTEN/NOTIFY (chapter 19) from Node.js. Run: node listen.js
import pg from 'pg';
import { pool } from './db.js';

// A listener needs its own long-lived connection, never one borrowed from the pool:
// the pool would hand that connection to other code, and LISTEN would end with it.
const listener = new pg.Client({ connectionString: process.env.DATABASE_URL, application_name: 'inkwell-listener' });
listener.on('notification', ({ channel, payload }) => {
  console.log(`notification on ${channel}:`, JSON.parse(payload));
});
listener.on('error', (err) => {
  // A real service reconnects here, runs LISTEN again, and then checks the durable state
  // (the orders or jobs table) for anything it missed while it was disconnected.
  console.error('listener lost its connection:', err.message);
});
await listener.connect();
await listener.query('LISTEN order_paid');

// Elsewhere (another process, or a trigger as in chapter 19), an event is sent.
// pg_notify() takes the channel and payload as ordinary values, so parameters work.
await pool.query("SELECT pg_notify('order_paid', $1)", [JSON.stringify({ order_id: 11, customer_id: 1 })]);

// Notifications sent inside a transaction are delivered when it commits, and only then.
const client = await pool.connect();
await client.query('BEGIN');
await client.query("SELECT pg_notify('order_paid', $1)", [JSON.stringify({ order_id: 10, customer_id: 8 })]);
await client.query('ROLLBACK'); // never delivered
client.release();

await new Promise((resolve) => setTimeout(resolve, 200)); // give the notification time to arrive
await listener.end();
await pool.end();
