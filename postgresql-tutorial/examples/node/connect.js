// A first query through the pool. Run: node connect.js
import { pool } from './db.js';

const { rows } = await pool.query(
  "SELECT current_user, current_database(), current_setting('server_version') AS version",
);
console.log(rows[0]);

const result = await pool.query(
  'SELECT id, full_name, created_at FROM customers WHERE country = $1 ORDER BY id',
  ['US'],
);
console.log(result.rowCount, 'rows:', result.rows);
console.log('columns:', result.fields.map((field) => field.name));

await pool.end(); // close all connections, so the process can exit
