-- Inkwell: bulk data for the performance chapters (15, 16 and 18).
--
-- Run it on a freshly reset database:
--   psql -X -v ON_ERROR_STOP=1 -d postgres -f sql/reset.sql
--   psql -X -v ON_ERROR_STOP=1 -d inkwell  -f sql/bulk.sql
--
-- It keeps the reference data and adds three years of history (2023-2025):
--   100,000 customers, 1,000,000 orders, about 2.5 million order items and about
--   46,000 reviews. Loading takes a minute or so and needs roughly 400 MB of disk.
-- The data is pseudo-random but repeatable: setseed() makes random() produce the same
-- sequence every time, so your counts match the ones printed in the chapters.

SELECT setseed(0.42) \gset

-- Customers 9 .. 100008. Some names repeat, as real names do.
INSERT INTO customers (email, full_name, country, created_at)
SELECT 'customer' || g || '@example.net',
       (ARRAY['Alex', 'Sam', 'Maria', 'Jon', 'Lena', 'Omar', 'Yuki', 'Priya', 'Lucas', 'Zoe',
              'Mateo', 'Ines', 'Noah', 'Aisha', 'Erik', 'Mei', 'Tom', 'Sara', 'Ivan', 'Nia'])[1 + floor(random() * 20)::int]
       || ' ' ||
       (ARRAY['Smith', 'Garcia', 'Müller', 'Rossi', 'Tanaka', 'Silva', 'Kowalski', 'Nguyen', 'Jensen', 'Brown',
              'Dubois', 'Khan', 'Olsen', 'Sato', 'Novak', 'Lopez', 'Weber', 'Costa', 'Berg', 'Moreau'])[1 + floor(random() * 20)::int],
       (ARRAY['US', 'US', 'US', 'US', 'GB', 'GB', 'DE', 'DE', 'FR', 'FR',
              'BR', 'JP', 'SE', 'NL', 'ES', 'IT', 'CA', 'AU', 'IN', 'MX'])[1 + floor(random() * 20)::int],
       timestamptz '2022-06-01' + random() * interval '1300 days'
FROM generate_series(1, 100000) AS g;

-- Orders 13 .. 1000012, in time order, about 95 seconds apart. Low customer ids order far
-- more often than high ones (random() squared is skewed towards 0). They are all history:
-- 97% delivered, 3% cancelled.
INSERT INTO orders (customer_id, status, created_at, shipped_at)
SELECT customer_id, status, created_at,
       CASE WHEN status = 'delivered'
            THEN created_at + (1 + random() * 71) * interval '1 hour' END
FROM (
    SELECT 9 + floor(100000 * random() ^ 2)::int AS customer_id,
           (CASE WHEN random() < 0.03 THEN 'cancelled' ELSE 'delivered' END)::order_status AS status,
           timestamptz '2023-01-01' + (g - 1) * interval '94.6 seconds' + random() * interval '60 seconds' AS created_at
    FROM generate_series(1, 1000000) AS g
) AS o;

-- One to four distinct products per order (never product 13, the brass clip, or 14, the
-- inactive case). Cheap products sell more often than expensive ones.
INSERT INTO order_items (order_id, product_id, quantity, unit_price)
SELECT o.id, x.product_id, 1 + floor(random() ^ 3 * 5)::int,
       (SELECT p.price FROM products AS p WHERE p.id = x.product_id)
FROM orders AS o
CROSS JOIN LATERAL (
    SELECT o.id AS order_id,
           floor(12 * random() ^ 2)::int AS first,
           1 + floor(random() * 4)::int AS n
) AS r
CROSS JOIN LATERAL (
    SELECT ((r.first + j * 5) % 12) + 1 AS product_id
    FROM generate_series(0, r.n - 1) AS j
) AS x
WHERE o.id > 12
ORDER BY o.id, x.product_id;

-- A review for one in fifty delivered orders, at most one per customer and product.
INSERT INTO reviews (product_id, customer_id, rating, body, created_at)
SELECT DISTINCT ON (oi.product_id, o.customer_id)
       oi.product_id, o.customer_id,
       (ARRAY[5, 5, 5, 4, 4, 4, 3, 3, 2, 1])[1 + (o.id + oi.product_id) % 10],
       (ARRAY['Great value.', 'Exactly as described.', 'Arrived quickly.', NULL,
              'Would buy again.', 'Not what I expected.', 'Good, but pricey.'])[1 + (o.id * 3 + oi.product_id) % 7],
       o.shipped_at + interval '3 days'
FROM orders AS o
JOIN order_items AS oi ON oi.order_id = o.id
WHERE o.status = 'delivered' AND o.id > 12 AND o.id % 50 = 0
ORDER BY oi.product_id, o.customer_id, o.id;

-- Collect statistics for the planner and set the visibility map (chapters 16 and 18).
VACUUM (ANALYZE);
