// End-to-end tests: real HTTP requests against the API, backed by the freshly reset test database.
import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { pool } from '../src/db.js';
import { startServer } from './support.js';

let api;
before(async () => { api = await startServer(); });
after(async () => {
  await api.close();
  await pool.end();
});

async function stockOf(productId) {
  const { body } = await api.request('GET', `/products/${productId}`);
  return body.stock;
}

test('GET /health reports the database as reachable', async () => {
  const { status, body } = await api.request('GET', '/health');
  assert.equal(status, 200);
  assert.deepEqual(body, { status: 'ok' });
});

describe('catalog', () => {
  test('full-text search ranks name matches first', async () => {
    const { status, body } = await api.request('GET', '/products?q=ink');
    assert.equal(status, 200);
    assert.deepEqual(body.items.slice(0, 2).map((p) => p.name), ['Midnight Blue Ink 50ml', 'Sepia Ink 50ml']);
    assert.match(body.items[0].snippet, /<b>ink<\/b>/);
  });

  test('browsing pages through active products with a keyset', async () => {
    const first = await api.request('GET', '/products?limit=5');
    assert.deepEqual(first.body.items.map((p) => p.id), [1, 2, 3, 4, 5]);
    const second = await api.request('GET', `/products?limit=5&after=${first.body.next_after}`);
    assert.deepEqual(second.body.items.map((p) => p.id), [6, 7, 8, 9, 10]);
  });

  test('a product shows its category and rating; prices stay exact strings', async () => {
    const { status, body } = await api.request('GET', '/products/1');
    assert.equal(status, 200);
    assert.equal(body.category, 'Fountain pens');
    assert.equal(body.price, '48.00');
    assert.equal(body.rating, '4.50');
    assert.equal(body.review_count, 2);
  });

  test('inactive, unknown and malformed product ids are 404', async () => {
    for (const id of ['14', '999', 'abc', '99999999999999999999']) {
      const { status } = await api.request('GET', `/products/${id}`);
      assert.equal(status, 404, `GET /products/${id}`);
    }
  });
});

describe('orders', () => {
  test('need a signed-in customer', async () => {
    const { status, body } = await api.request('GET', '/orders');
    assert.equal(status, 401);
    assert.equal(body.error.code, 'not_signed_in');
  });

  test('placing an order charges current prices and takes the items from stock', async () => {
    const before = await stockOf(13);
    const { status, body } = await api.request('POST', '/orders', {
      customer: 5,
      body: { items: [{ product_id: 13, quantity: 2 }, { product_id: 7, quantity: 1 }] },
    });
    assert.equal(status, 201);
    assert.equal(body.status, 'pending');
    assert.equal(body.total, '24.00');
    assert.deepEqual(body.items.map((i) => [i.product_id, i.quantity, i.unit_price]),
      [[7, 1, '14.00'], [13, 2, '5.00']]);
    assert.equal(await stockOf(13), before - 2);
  });

  test('an unavailable item rejects the whole order', async () => {
    const before = await stockOf(1);
    const { status, body } = await api.request('POST', '/orders', {
      customer: 5,
      body: { items: [{ product_id: 1, quantity: 1 }, { product_id: 9, quantity: 1 }] }, // 9 is sold out
    });
    assert.equal(status, 409);
    assert.equal(body.error.code, 'out_of_stock');
    assert.equal(body.error.message, 'product 9 is not available in quantity 1');
    assert.equal(await stockOf(1), before, 'product 1 must not have been taken from stock');
  });

  test('invalid baskets are rejected before reaching the database', async () => {
    for (const items of [[], [{ product_id: 1, quantity: 0 }], [{ product_id: '1', quantity: 1 }], 'x']) {
      const { status } = await api.request('POST', '/orders', { customer: 5, body: { items } });
      assert.equal(status, 400, JSON.stringify(items));
    }
  });

  test("customers see only their own orders", async () => {
    const list = await api.request('GET', '/orders', { customer: 1 });
    assert.deepEqual(list.body.items.map((o) => o.id), [11, 4, 1]);
    const other = await api.request('GET', '/orders/12', { customer: 1 }); // order 12 is customer 5's
    assert.equal(other.status, 404);
  });

  test('pagination returns every order exactly once', async () => {
    const seen = [];
    let cursor = null;
    do {
      const query = cursor ? `?limit=1&cursor=${cursor}` : '?limit=1';
      const { body } = await api.request('GET', `/orders${query}`, { customer: 1 });
      seen.push(...body.items.map((o) => o.id));
      cursor = body.next_cursor;
    } while (cursor);
    assert.deepEqual(seen, [11, 4, 1]);
  });

  test('cancelling puts the items back in stock, once', async () => {
    const placed = await api.request('POST', '/orders', {
      customer: 3, body: { items: [{ product_id: 4, quantity: 3 }] },
    });
    const afterOrder = await stockOf(4);
    const cancelled = await api.request('POST', `/orders/${placed.body.id}/cancel`, { customer: 3 });
    assert.equal(cancelled.status, 200);
    assert.equal(cancelled.body.status, 'cancelled');
    assert.equal(await stockOf(4), afterOrder + 3);

    const again = await api.request('POST', `/orders/${placed.body.id}/cancel`, { customer: 3 });
    assert.equal(again.status, 409);
    assert.equal(again.body.error.code, 'not_cancellable');
  });

  test("a customer can't cancel someone else's order", async () => {
    const { status } = await api.request('POST', '/orders/12/cancel', { customer: 1 });
    assert.equal(status, 404);
  });
});

describe('reviews', () => {
  test('one review per customer and product', async () => {
    const first = await api.request('POST', '/products/10/reviews', {
      customer: 7, body: { rating: 4, body: 'Does the job.' },
    });
    assert.equal(first.status, 201);
    const second = await api.request('POST', '/products/10/reviews', { customer: 7, body: { rating: 5 } });
    assert.equal(second.status, 409);
    assert.equal(second.body.error.message, 'you have already reviewed this product');
  });

  test('ratings outside 1-5 are rejected', async () => {
    const { status } = await api.request('POST', '/products/10/reviews', { customer: 8, body: { rating: 6 } });
    assert.equal(status, 400);
  });
});
