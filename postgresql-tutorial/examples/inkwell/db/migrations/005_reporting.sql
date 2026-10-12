-- 005: the reporting schema (chapters 20 and 21). The report role reads these views and nothing else in it.
-- The views belong to the owner, who bypasses row-level security, so reports see every customer's orders.

CREATE SCHEMA reporting;

-- Revenue per month, live. date_trunc with an explicit time zone (16+) makes the months independent of the
-- session's TimeZone setting.
CREATE VIEW reporting.monthly_revenue AS
SELECT date_trunc('month', o.created_at, 'UTC')::date AS month,
       count(DISTINCT o.id)                           AS orders,
       count(DISTINCT o.customer_id)                  AS customers,
       sum(oi.quantity * oi.unit_price)               AS revenue
FROM orders AS o
JOIN order_items AS oi ON oi.order_id = o.id
WHERE o.status <> 'cancelled'
GROUP BY 1;

-- Units and revenue per product and month. Materialized: on a real shop this scans every order line, and the
-- report needs yesterday's numbers, not this second's (chapter 20).
CREATE MATERIALIZED VIEW reporting.product_sales AS
SELECT date_trunc('month', o.created_at, 'UTC')::date AS month,
       p.id                                           AS product_id,
       p.name                                         AS product_name,
       sum(oi.quantity)                               AS units,
       sum(oi.quantity * oi.unit_price)               AS revenue
FROM orders AS o
JOIN order_items AS oi ON oi.order_id = o.id
JOIN products AS p ON p.id = oi.product_id
WHERE o.status <> 'cancelled'
GROUP BY 1, 2, 3;

-- A unique index allows REFRESH MATERIALIZED VIEW CONCURRENTLY, which doesn't block readers.
CREATE UNIQUE INDEX product_sales_key ON reporting.product_sales (month, product_id);

GRANT USAGE ON SCHEMA reporting TO inkwell_report;
GRANT SELECT ON reporting.monthly_revenue, reporting.product_sales TO inkwell_report;
-- MAINTAIN (17+) allows REFRESH MATERIALIZED VIEW without owning the view.
GRANT MAINTAIN ON reporting.product_sales TO inkwell_report;
