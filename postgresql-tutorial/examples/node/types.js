// How PostgreSQL types arrive in JavaScript. Run: node types.js
import pg from 'pg';
import { pool } from './db.js';

const sql = `
  SELECT 42::integer                          AS int4,
         9007199254740993::bigint             AS int8,
         12.50::numeric(10, 2)                AS price,
         0.1::double precision                AS float8,
         true                                 AS bool,
         timestamptz '2026-04-09 20:05:00+00' AS ts,
         date '2026-04-09'                    AS day,
         '{"nib": "fine"}'::jsonb             AS doc,
         ARRAY['ink', 'pen']                  AS tags,
         '\\xdeadbeef'::bytea                 AS bytes,
         interval '1 day 02:00'               AS iv,
         NULL                                 AS nothing`;

console.log((await pool.query(sql)).rows[0]);

// Parse bigint as BigInt and keep dates as 'YYYY-MM-DD' strings.
// setTypeParser changes the parsing for every query in this process from now on.
pg.types.setTypeParser(pg.types.builtins.INT8, (value) => BigInt(value));
pg.types.setTypeParser(pg.types.builtins.DATE, (value) => value);

const { rows } = await pool.query(sql);
console.log({ int8: rows[0].int8, day: rows[0].day });

await pool.end();
