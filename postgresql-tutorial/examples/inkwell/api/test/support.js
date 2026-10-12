// Test helpers: start the API on a free port, and run SQL in a transaction that is always rolled back.
import { createServer } from 'node:http';
import { handleRequest } from '../src/app.js';
import { pool } from '../src/db.js';

export async function startServer() {
  const server = createServer(handleRequest);
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve)); // port 0: any free port
  const baseUrl = `http://127.0.0.1:${server.address().port}`;

  return {
    async request(method, path, { customer, body } = {}) {
      const headers = {};
      if (customer !== undefined) headers['x-customer-id'] = String(customer);
      if (body !== undefined) headers['content-type'] = 'application/json';
      const response = await fetch(baseUrl + path, {
        method,
        headers,
        body: body === undefined ? undefined : JSON.stringify(body),
      });
      return { status: response.status, body: await response.json() };
    },
    async close() {
      server.closeAllConnections();
      await new Promise((resolve) => server.close(resolve));
    },
  };
}

// Runs fn(client) inside BEGIN ... ROLLBACK: whatever the test changes, the database is unchanged afterwards.
// This is how to test database functions and policies without cleaning up (chapter 19).
export async function rolledBack(fn) {
  const client = await pool.connect();
  try {
    await client.query('BEGIN');
    return await fn(client);
  } finally {
    await client.query('ROLLBACK');
    client.release();
  }
}

// Switch the transaction to the customer portal role, as asCustomer() in src/db.js does.
export async function signIn(client, customerId) {
  await client.query('SET LOCAL ROLE inkwell_portal');
  await client.query("SELECT set_config('app.customer_id', $1, true)", [String(customerId)]);
}
