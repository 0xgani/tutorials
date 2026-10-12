-- 004: privileges and row-level security (chapter 21).

-- The database: CONNECT for Inkwell's roles only (db/create-database.sql revoked it from PUBLIC).
-- Migrations don't hard-code the database name, so the same files serve inkwell_dev, inkwell_test, ...
DO $$
BEGIN
    EXECUTE format('GRANT CONNECT ON DATABASE %I TO inkwell_readonly', current_database());
END;
$$;

-- Tables. Staff and the application (readonly, readwrite) see and change everything; audit_log is read-only.
GRANT SELECT ON customers, categories, products, orders, order_items, reviews, audit_log TO inkwell_readonly;
GRANT INSERT, UPDATE, DELETE ON customers, categories, products, orders, order_items, reviews TO inkwell_readwrite;

-- The customer portal reads the catalog and reviews, its own customer row and orders (row-level security
-- below), and writes reviews. Orders are placed and cancelled only through the SECURITY DEFINER functions.
GRANT SELECT ON categories, products, reviews, customers, orders, order_items TO inkwell_portal;
GRANT INSERT (product_id, customer_id, rating, body) ON reviews TO inkwell_portal;

-- Functions (EXECUTE was revoked from PUBLIC by default in 001).
GRANT EXECUTE ON FUNCTION current_customer_id() TO inkwell_readonly, inkwell_portal;
GRANT EXECUTE ON FUNCTION search_products(text, integer) TO inkwell_readonly, inkwell_portal;
GRANT EXECUTE ON FUNCTION place_order(jsonb) TO inkwell_portal;
GRANT EXECUTE ON FUNCTION cancel_order(bigint) TO inkwell_portal;

-- Row-level security. The *_staff policies let readonly (and so readwrite and the app) see every row; the
-- *_customer policies limit the portal to the signed-in customer. The owner bypasses RLS, which the
-- SECURITY DEFINER functions and the reporting views rely on.
ALTER TABLE customers ENABLE ROW LEVEL SECURITY;
CREATE POLICY customers_staff ON customers TO inkwell_readonly USING (true);
CREATE POLICY customers_customer ON customers FOR SELECT TO inkwell_portal
    USING (id = current_customer_id());

ALTER TABLE orders ENABLE ROW LEVEL SECURITY;
CREATE POLICY orders_staff ON orders TO inkwell_readonly USING (true);
CREATE POLICY orders_customer ON orders FOR SELECT TO inkwell_portal
    USING (customer_id = current_customer_id());

ALTER TABLE order_items ENABLE ROW LEVEL SECURITY;
CREATE POLICY order_items_staff ON order_items TO inkwell_readonly USING (true);
CREATE POLICY order_items_customer ON order_items FOR SELECT TO inkwell_portal
    USING (EXISTS (SELECT FROM orders AS o
                   WHERE o.id = order_items.order_id
                     AND o.customer_id = current_customer_id()));

ALTER TABLE reviews ENABLE ROW LEVEL SECURITY;
CREATE POLICY reviews_staff ON reviews TO inkwell_readonly USING (true);
CREATE POLICY reviews_public ON reviews FOR SELECT TO inkwell_portal USING (true);
CREATE POLICY reviews_customer_insert ON reviews FOR INSERT TO inkwell_portal
    WITH CHECK (customer_id = current_customer_id());
