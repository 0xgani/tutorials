// The HTTP API: plain node:http, a small router, and SQL. Every query is parameterized (chapter 22).
import { pool, asCustomer } from './db.js';
import { HttpError, toHttpError } from './errors.js';

const MAX_BODY_BYTES = 64 * 1024;

// ---------------------------------------------------------------------------------------------------------
// Request helpers

function sendJson(response, status, body) {
  const json = JSON.stringify(body);
  response.writeHead(status, {
    'content-type': 'application/json; charset=utf-8',
    'content-length': Buffer.byteLength(json),
  });
  response.end(json);
}

async function readJson(request) {
  if (!request.headers['content-type']?.startsWith('application/json')) {
    throw new HttpError(415, 'unsupported_media_type', 'send a JSON body with content-type: application/json');
  }
  let size = 0;
  const chunks = [];
  for await (const chunk of request) {
    size += chunk.length;
    if (size > MAX_BODY_BYTES) throw new HttpError(413, 'too_large', 'request body is too large');
    chunks.push(chunk);
  }
  try {
    return JSON.parse(Buffer.concat(chunks).toString('utf8'));
  } catch {
    throw new HttpError(400, 'invalid_json', 'request body is not valid JSON');
  }
}

// A positive integer that fits in both a JavaScript number and a PostgreSQL bigint, or null.
function parseId(text) {
  if (!/^[1-9]\d{0,15}$/.test(text ?? '')) return null;
  const id = Number(text);
  return Number.isSafeInteger(id) ? id : null;
}

function parseLimit(text, fallback = 20, max = 100) {
  if (text === null) return fallback;
  const limit = parseId(text);
  if (limit === null || limit > max) throw new HttpError(400, 'invalid_request', `limit must be 1-${max}`);
  return limit;
}

// Stand-in for real authentication. A real shop verifies a session cookie or a token here and takes the
// customer's id from it; trusting a header like this would let anyone act as any customer.
function customerIdOf(request) {
  const id = parseId(request.headers['x-customer-id']);
  if (id === null) throw new HttpError(401, 'not_signed_in', 'sign in first (send X-Customer-Id)');
  return id;
}

// Keyset pagination cursors (chapter 22): an opaque base64url string holding the last row's sort key.
// The timestamp travels as text, because a JavaScript Date would drop its microseconds.
function encodeCursor(row) {
  return Buffer.from(JSON.stringify([row.created_at_key, row.id])).toString('base64url');
}

function decodeCursor(text) {
  if (text === null) return [null, null];
  try {
    const [createdAt, id] = JSON.parse(Buffer.from(text, 'base64url').toString('utf8'));
    if (typeof createdAt === 'string' && Number.isSafeInteger(id)) return [createdAt, id];
  } catch {
    // fall through
  }
  throw new HttpError(400, 'invalid_request', 'invalid cursor');
}

// ---------------------------------------------------------------------------------------------------------
// Handlers. Each receives { request, params, query } and returns [status, body].

async function health() {
  await pool.query('SELECT 1');
  return [200, { status: 'ok' }];
}

// GET /products?q=fountain+pen  (full-text search)  or  GET /products?after=7&limit=5  (browse)
async function listProducts({ query }) {
  const limit = parseLimit(query.get('limit'));
  const q = query.get('q')?.trim();
  if (q) {
    const { rows } = await pool.query(
      'SELECT id, name, price, snippet FROM search_products($1, $2)', [q, limit]);
    return [200, { items: rows }];
  }
  const after = query.has('after') ? parseId(query.get('after')) : 0;
  if (after === null) throw new HttpError(400, 'invalid_request', 'invalid after');
  const { rows } = await pool.query(
    `SELECT id, sku, name, price, stock > 0 AS in_stock
     FROM products
     WHERE active AND id > $1
     ORDER BY id
     LIMIT $2`, [after, limit]);
  return [200, { items: rows, next_after: rows.length === limit ? rows.at(-1).id : null }];
}

// GET /products/:id
async function getProduct({ params }) {
  const { rows } = await pool.query(
    `SELECT p.id, p.sku, p.name, p.description, p.price, p.stock, p.attributes, p.tags,
            c.name AS category,
            round(avg(r.rating), 2) AS rating,
            count(r.id) AS review_count
     FROM products AS p
     JOIN categories AS c ON c.id = p.category_id
     LEFT JOIN reviews AS r ON r.product_id = p.id
     WHERE p.id = $1 AND p.active
     GROUP BY p.id, c.name`, [params.id]);
  if (rows.length === 0) throw new HttpError(404, 'not_found', 'product not found');
  return [200, rows[0]];
}

// POST /products/:id/reviews  {"rating": 5, "body": "..."}
async function createReview({ request, params }) {
  const customerId = customerIdOf(request);
  const { rating, body = null } = await readJson(request);
  if (!Number.isInteger(rating) || rating < 1 || rating > 5) {
    throw new HttpError(400, 'invalid_request', 'rating must be an integer from 1 to 5');
  }
  if (body !== null && (typeof body !== 'string' || body.length > 2000)) {
    throw new HttpError(400, 'invalid_request', 'body must be a string of at most 2000 characters');
  }
  // customer_id comes from the database's own notion of who is signed in; the RLS policy checks it anyway.
  const review = await asCustomer(customerId, async (client) => {
    const { rows } = await client.query(
      `INSERT INTO reviews (product_id, customer_id, rating, body)
       VALUES ($1, current_customer_id(), $2, $3)
       RETURNING id, product_id, rating, body, created_at`, [params.id, rating, body]);
    return rows[0];
  });
  return [201, review];
}

// GET /me
async function getMe({ request }) {
  const customer = await asCustomer(customerIdOf(request), async (client) => {
    const { rows } = await client.query('SELECT id, email, full_name, country, created_at FROM customers');
    return rows[0]; // row-level security returns only the signed-in customer's row
  });
  if (!customer) throw new HttpError(404, 'not_found', 'customer not found');
  return [200, customer];
}

// GET /orders?limit=10&cursor=...  the signed-in customer's orders, newest first
async function listOrders({ request, query }) {
  const customerId = customerIdOf(request);
  const limit = parseLimit(query.get('limit'), 10, 50);
  const [createdAt, id] = decodeCursor(query.get('cursor'));
  const rows = await asCustomer(customerId, async (client) => {
    // The total comes from a LATERAL subquery, computed only for the rows on this page. A join with
    // GROUP BY would total every order of the customer first, then sort and cut (chapter 24 compares the plans).
    const result = await client.query(
      `SELECT o.id, o.status, o.created_at, o.created_at::text AS created_at_key, t.total
       FROM orders AS o
       CROSS JOIN LATERAL (SELECT sum(oi.quantity * oi.unit_price) AS total
                           FROM order_items AS oi
                           WHERE oi.order_id = o.id) AS t
       WHERE $1::timestamptz IS NULL OR (o.created_at, o.id) < ($1::timestamptz, $2::bigint)
       ORDER BY o.created_at DESC, o.id DESC
       LIMIT $3`, [createdAt, id, limit]);
    return result.rows;
  });
  const nextCursor = rows.length === limit ? encodeCursor(rows.at(-1)) : null;
  const items = rows.map(({ created_at_key, ...order }) => order);
  return [200, { items, next_cursor: nextCursor }];
}

// Reads one order with its items, in a transaction that already runs as the customer.
async function fetchOrder(client, orderId) {
  const { rows: [order] } = await client.query(
    'SELECT id, status, created_at, shipped_at FROM orders WHERE id = $1', [orderId]);
  if (!order) return null; // missing, or another customer's: row-level security hides it
  const { rows: items } = await client.query(
    `SELECT oi.product_id, p.name, oi.quantity, oi.unit_price, oi.quantity * oi.unit_price AS amount
     FROM order_items AS oi
     JOIN products AS p ON p.id = oi.product_id
     WHERE oi.order_id = $1
     ORDER BY oi.product_id`, [orderId]);
  const { rows: [{ total }] } = await client.query(
    'SELECT coalesce(sum(quantity * unit_price), 0) AS total FROM order_items WHERE order_id = $1', [orderId]);
  return { ...order, total, items };
}

// GET /orders/:id
async function getOrder({ request, params }) {
  const order = await asCustomer(customerIdOf(request), (client) => fetchOrder(client, params.id));
  if (!order) throw new HttpError(404, 'not_found', 'order not found');
  return [200, order];
}

// POST /orders  {"items": [{"product_id": 7, "quantity": 2}, ...]}
async function createOrder({ request }) {
  const customerId = customerIdOf(request);
  const { items } = await readJson(request);
  const valid = Array.isArray(items) && items.length > 0 && items.length <= 50
    && items.every((item) => Number.isSafeInteger(item?.product_id) && item.product_id > 0
                             && Number.isInteger(item.quantity) && item.quantity > 0 && item.quantity <= 100);
  if (!valid) {
    throw new HttpError(400, 'invalid_request',
      'items must be 1-50 objects with a positive product_id and a quantity of 1-100');
  }
  const order = await asCustomer(customerId, async (client) => {
    // A JSON string for the jsonb parameter: a JS array would be sent as a PostgreSQL array (chapter 22).
    const { rows: [{ id }] } = await client.query('SELECT place_order($1::jsonb) AS id', [JSON.stringify(items)]);
    return fetchOrder(client, id);
  });
  return [201, order];
}

// POST /orders/:id/cancel
async function cancelOrder({ request, params }) {
  const order = await asCustomer(customerIdOf(request), async (client) => {
    await client.query('SELECT cancel_order($1)', [params.id]);
    return fetchOrder(client, params.id);
  });
  return [200, order];
}

// ---------------------------------------------------------------------------------------------------------
// Routing

const routes = [
  ['GET', /^\/health$/, health],
  ['GET', /^\/products$/, listProducts],
  ['GET', /^\/products\/(?<id>[^/]+)$/, getProduct],
  ['POST', /^\/products\/(?<id>[^/]+)\/reviews$/, createReview],
  ['GET', /^\/me$/, getMe],
  ['GET', /^\/orders$/, listOrders],
  ['POST', /^\/orders$/, createOrder],
  ['GET', /^\/orders\/(?<id>[^/]+)$/, getOrder],
  ['POST', /^\/orders\/(?<id>[^/]+)\/cancel$/, cancelOrder],
];

export async function handleRequest(request, response) {
  try {
    const url = new URL(request.url, 'http://localhost');
    let pathMatched = false;
    for (const [method, pattern, handler] of routes) {
      const match = pattern.exec(url.pathname);
      if (!match) continue;
      pathMatched = true;
      if (method !== request.method) continue;
      const params = {};
      if (match.groups?.id !== undefined) {
        params.id = parseId(match.groups.id);
        if (params.id === null) throw new HttpError(404, 'not_found', 'not found');
      }
      const [status, body] = await handler({ request, params, query: url.searchParams });
      return sendJson(response, status, body);
    }
    if (pathMatched) throw new HttpError(405, 'method_not_allowed', 'method not allowed');
    throw new HttpError(404, 'not_found', 'not found');
  } catch (err) {
    const httpError = toHttpError(err);
    if (!httpError) {
      // Log everything about unexpected errors, but never send internals to the client.
      console.error(`${request.method} ${request.url} failed:`, err);
      return sendJson(response, 500, { error: { code: 'internal_error', message: 'internal error' } });
    }
    return sendJson(response, httpError.status, { error: { code: httpError.code, message: httpError.message } });
  }
}

