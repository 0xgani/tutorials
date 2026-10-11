-- Prepares the Inkwell database for the application examples in chapter 22:
--   * the search function from chapters 13 and 19,
--   * place_order() from chapter 19, with its custom error IW001,
--   * the roles and privileges from chapter 21 (a subset), including row-level security on orders.
-- Run it right after sql/reset.sql, as a superuser:
--   psql -X -v ON_ERROR_STOP=1 -d postgres -f sql/reset.sql
--   psql -X -v ON_ERROR_STOP=1 -d inkwell -f examples/setup.sql

-- Re-running the script re-grants existing memberships; skip the notices about that.
SET client_min_messages = warning;

-- Roles belong to the whole cluster, so they may exist already (from chapter 21 or an earlier run).
-- Create the missing ones and (re)set the passwords; everything else below is per database.
DO $$
DECLARE
    r record;
BEGIN
    FOR r IN
        SELECT * FROM (VALUES
            ('inkwell_owner',     'NOLOGIN', NULL),
            ('inkwell_readonly',  'NOLOGIN', NULL),
            ('inkwell_readwrite', 'NOLOGIN', NULL),
            ('inkwell_migrate',   'LOGIN',   'migrate-dev-password'),
            ('inkwell_app',       'LOGIN',   'app-dev-password'),
            ('inkwell_portal',    'NOLOGIN', NULL)
        ) AS t(name, login, password)
    LOOP
        IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = r.name) THEN
            EXECUTE format('CREATE ROLE %I', r.name);
        END IF;
        EXECUTE format('ALTER ROLE %I %s PASSWORD %L', r.name, r.login, r.password);
    END LOOP;
END;
$$;

-- Membership: the application reads and writes, and may switch to the portal role per transaction.
GRANT inkwell_readonly TO inkwell_readwrite;
GRANT inkwell_readwrite TO inkwell_app;
GRANT inkwell_portal TO inkwell_app WITH INHERIT FALSE, SET TRUE;
GRANT inkwell_owner TO inkwell_migrate WITH INHERIT FALSE, SET TRUE;
ALTER ROLE inkwell_migrate SET role = 'inkwell_owner';

-- Ownership.
ALTER DATABASE inkwell OWNER TO inkwell_owner;
ALTER TABLE customers OWNER TO inkwell_owner;
ALTER TABLE categories OWNER TO inkwell_owner;
ALTER TABLE products OWNER TO inkwell_owner;
ALTER TABLE orders OWNER TO inkwell_owner;
ALTER TABLE order_items OWNER TO inkwell_owner;
ALTER TABLE reviews OWNER TO inkwell_owner;
ALTER TYPE order_status OWNER TO inkwell_owner;

-- Database and table privileges.
REVOKE ALL ON DATABASE inkwell FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE inkwell TO inkwell_readonly;
GRANT CONNECT ON DATABASE inkwell TO inkwell_migrate;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO inkwell_readonly;
GRANT INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO inkwell_readwrite;
GRANT SELECT ON categories, products, orders, order_items TO inkwell_portal;

ALTER DEFAULT PRIVILEGES FOR ROLE inkwell_owner GRANT SELECT ON TABLES TO inkwell_readonly;
ALTER DEFAULT PRIVILEGES FOR ROLE inkwell_owner GRANT INSERT, UPDATE, DELETE ON TABLES TO inkwell_readwrite;
ALTER DEFAULT PRIVILEGES FOR ROLE inkwell_owner GRANT USAGE, SELECT ON SEQUENCES TO inkwell_readwrite;
ALTER DEFAULT PRIVILEGES FOR ROLE inkwell_owner REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;

-- Everything from here on is created by the owner, as a migration would be.
SET ROLE inkwell_owner;

-- Search (chapters 13 and 19).
ALTER TABLE products
    ADD COLUMN search tsvector
    GENERATED ALWAYS AS (setweight(to_tsvector('english', name), 'A')
                         || setweight(to_tsvector('english', description), 'B')) STORED;
CREATE INDEX products_search_idx ON products USING gin (search);

CREATE FUNCTION search_products(p_query text, p_limit integer DEFAULT 10)
RETURNS TABLE (id bigint, name text, rank real, snippet text)
LANGUAGE sql
STABLE
BEGIN ATOMIC
    SELECT top.id, top.name, top.rank,
           ts_headline('english', top.description, top.q, 'StartSel=<b>, StopSel=</b>')
    FROM (
        SELECT p.id, p.name, p.description, q, ts_rank(p.search, q) AS rank
        FROM products AS p, websearch_to_tsquery('english', p_query) AS q
        WHERE p.search @@ q AND p.active
        ORDER BY rank DESC, p.name
        LIMIT p_limit
    ) AS top
    ORDER BY top.rank DESC, top.name;
END;

-- Checkout (chapter 19).
CREATE FUNCTION place_order(p_customer_id bigint, p_items jsonb)
RETURNS bigint
LANGUAGE plpgsql
AS $$
DECLARE
    v_order_id  bigint;
    v_item      record;
    v_price     numeric(10, 2);
BEGIN
    IF jsonb_typeof(p_items) IS DISTINCT FROM 'array' OR jsonb_array_length(p_items) = 0 THEN
        RAISE EXCEPTION 'an order needs at least one item'
            USING ERRCODE = 'invalid_parameter_value';
    END IF;

    INSERT INTO orders (customer_id)
    VALUES (p_customer_id)
    RETURNING id INTO v_order_id;

    -- Lock and update the products in id order, so that concurrent orders can't deadlock (chapter 17).
    FOR v_item IN
        SELECT (x ->> 'product_id')::bigint AS product_id,
               (x ->> 'quantity')::integer AS quantity
        FROM jsonb_array_elements(p_items) AS x
        ORDER BY 1
    LOOP
        UPDATE products
        SET stock = stock - v_item.quantity
        WHERE id = v_item.product_id AND active AND stock >= v_item.quantity
        RETURNING price INTO v_price;

        IF NOT FOUND THEN
            RAISE EXCEPTION 'product % is not available in quantity %', v_item.product_id, v_item.quantity
                USING ERRCODE = 'IW001',
                      HINT = 'Reload the basket to see current stock.';
        END IF;

        INSERT INTO order_items (order_id, product_id, quantity, unit_price)
        VALUES (v_order_id, v_item.product_id, v_item.quantity, v_price);
    END LOOP;

    RETURN v_order_id;
END;
$$;

-- Row-level security for the customer portal (chapter 21).
CREATE FUNCTION current_customer_id() RETURNS bigint
    LANGUAGE sql STABLE
    RETURN nullif(current_setting('app.customer_id', true), '')::bigint;

ALTER TABLE orders ENABLE ROW LEVEL SECURITY;
CREATE POLICY orders_staff ON orders TO inkwell_readonly USING (true);
CREATE POLICY orders_customer ON orders FOR SELECT TO inkwell_portal
    USING (customer_id = current_customer_id());

ALTER TABLE order_items ENABLE ROW LEVEL SECURITY;
CREATE POLICY order_items_staff ON order_items TO inkwell_readonly USING (true);
CREATE POLICY order_items_customer ON order_items FOR SELECT TO inkwell_portal
    USING (EXISTS (SELECT FROM orders o
                   WHERE o.id = order_items.order_id
                     AND o.customer_id = current_customer_id()));

RESET ROLE;

GRANT EXECUTE ON FUNCTION search_products(text, integer) TO inkwell_readonly, inkwell_portal;
GRANT EXECUTE ON FUNCTION place_order(bigint, jsonb) TO inkwell_readwrite;
GRANT EXECUTE ON FUNCTION current_customer_id() TO inkwell_portal;

ANALYZE;
