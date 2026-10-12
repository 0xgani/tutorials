// Reacting to specific database errors by their SQLSTATE code. Run: node errors.js
import { pool } from './db.js';

// Turns the errors the application expects into answers for the user; anything else is a bug
// or an outage, so it is rethrown (and logged, and answered with a generic 500 by a web app).
function toResponse(err) {
  switch (err.code) {
    case '23505': // unique_violation
      return { status: 409, message: 'already exists', constraint: err.constraint };
    case '23503': // foreign_key_violation
      return { status: 422, message: 'refers to a row that does not exist', constraint: err.constraint };
    case 'IW001': // raised by place_order() (chapter 19)
      return { status: 409, message: err.message, hint: err.hint };
    default:
      throw err;
  }
}

async function attempt(label, sql, params) {
  try {
    const { rows } = await pool.query(sql, params);
    console.log(label, { status: 200, result: rows[0] });
  } catch (err) {
    console.log(label, toResponse(err));
  }
}

const basket = (items) => JSON.stringify(items); // jsonb parameters are sent as JSON text

await attempt('duplicate e-mail:', 'INSERT INTO customers (email, full_name, country) VALUES ($1, $2, $3) RETURNING id',
  ['ana@example.com', 'Ana Again', 'BR']);
await attempt('unknown customer:', 'SELECT place_order($1, $2)', [999, basket([{ product_id: 7, quantity: 1 }])]);
await attempt('too many pens:   ', 'SELECT place_order($1, $2)', [5, basket([{ product_id: 2, quantity: 5 }])]);
await attempt('a notebook:      ', 'SELECT place_order($1, $2)', [5, basket([{ product_id: 7, quantity: 1 }])]);

try {
  await pool.query('SELECT 1 / 0');
} catch (err) {
  console.log('unexpected:', { code: err.code, message: err.message, severity: err.severity });
}

await pool.end();
