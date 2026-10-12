// Why values must never be pasted into SQL. Run: node injection.js
import { pool } from './db.js';

const email = "nobody@example.com' OR '1'='1"; // what an attacker types into a login form

// WRONG: the input becomes part of the SQL text.
const unsafe = await pool.query(`SELECT id, email FROM customers WHERE email = '${email}'`);
console.log('pasted into the SQL:', unsafe.rowCount, 'rows');

// RIGHT: the SQL text and the value travel separately; the value can't change the query.
const safe = await pool.query('SELECT id, email FROM customers WHERE email = $1', [email]);
console.log('sent as a parameter:', safe.rowCount, 'rows');

// A list of values is one parameter: an array, compared with = ANY.
const some = await pool.query('SELECT id, sku FROM products WHERE id = ANY ($1) ORDER BY id', [[3, 7, 11]]);
console.log(some.rows);

await pool.end();
