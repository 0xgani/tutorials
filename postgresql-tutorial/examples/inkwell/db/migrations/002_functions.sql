-- 002: business logic in the database (chapters 13, 19 and 21).
--
-- Custom error codes (SQLSTATE class IW, chapter 19); the API maps them to HTTP responses:
--   IW001  a product isn't available in the requested quantity  -> 409
--   IW002  no customer is signed in                              -> 401
--   IW003  the order doesn't exist (or isn't the customer's)     -> 404
--   IW004  the order can no longer be cancelled                  -> 409

-- The signed-in customer, set per transaction by the API with set_config('app.customer_id', ..., true).
CREATE FUNCTION current_customer_id() RETURNS bigint
    LANGUAGE sql
    STABLE
    RETURN nullif(current_setting('app.customer_id', true), '')::bigint;

-- Ranked full-text search over active products, with highlighted snippets (chapters 13 and 19).
CREATE FUNCTION search_products(p_query text, p_limit integer DEFAULT 10)
RETURNS TABLE (id bigint, name text, price numeric, rank real, snippet text)
LANGUAGE sql
STABLE
BEGIN ATOMIC
    SELECT top.id, top.name, top.price, top.rank,
           ts_headline('english', top.description, top.q, 'StartSel=<b>, StopSel=</b>')
    FROM (
        SELECT p.id, p.name, p.price, p.description, q, ts_rank(p.search, q) AS rank
        FROM products AS p, websearch_to_tsquery('english', p_query) AS q
        WHERE p.search @@ q AND p.active
        ORDER BY rank DESC, p.name
        LIMIT p_limit
    ) AS top
    ORDER BY top.rank DESC, top.name;
END;

-- Places an order for the signed-in customer. SECURITY DEFINER: customers can't insert orders or change stock
-- themselves, only through this function, which checks everything. The fixed search_path, with pg_temp last,
-- stops callers from substituting their own objects (chapters 19 and 21).
CREATE FUNCTION place_order(p_items jsonb)
RETURNS bigint
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public, pg_temp
AS $$
DECLARE
    v_customer_id  bigint := current_customer_id();
    v_order_id     bigint;
    v_item         record;
    v_price        numeric(10, 2);
BEGIN
    IF v_customer_id IS NULL THEN
        RAISE EXCEPTION 'no customer is signed in' USING ERRCODE = 'IW002';
    END IF;
    IF jsonb_typeof(p_items) IS DISTINCT FROM 'array' OR jsonb_array_length(p_items) = 0 THEN
        RAISE EXCEPTION 'an order needs at least one item' USING ERRCODE = 'invalid_parameter_value';
    END IF;

    INSERT INTO orders (customer_id)
    VALUES (v_customer_id)
    RETURNING id INTO v_order_id;

    -- One line per product (the same product twice is merged), locked in id order so that
    -- concurrent orders can't deadlock (chapter 17).
    FOR v_item IN
        SELECT (x ->> 'product_id')::bigint AS product_id,
               sum((x ->> 'quantity')::integer)::integer AS quantity
        FROM jsonb_array_elements(p_items) AS x
        GROUP BY 1
        ORDER BY 1
    LOOP
        IF v_item.product_id IS NULL OR v_item.quantity IS NULL OR v_item.quantity <= 0 THEN
            RAISE EXCEPTION 'every item needs a product_id and a positive quantity'
                USING ERRCODE = 'invalid_parameter_value';
        END IF;

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

-- Cancels one of the signed-in customer's orders and puts the items back in stock (chapter 19, exercise 3).
-- Someone else's order is reported as "not found", which doesn't reveal that it exists.
CREATE FUNCTION cancel_order(p_order_id bigint)
RETURNS void
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public, pg_temp
AS $$
DECLARE
    v_status order_status;
BEGIN
    IF current_customer_id() IS NULL THEN
        RAISE EXCEPTION 'no customer is signed in' USING ERRCODE = 'IW002';
    END IF;

    -- FOR UPDATE: two cancellations of the same order run one after the other (chapter 17).
    SELECT status INTO v_status
    FROM orders
    WHERE id = p_order_id AND customer_id = current_customer_id()
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'order % not found', p_order_id USING ERRCODE = 'IW003';
    END IF;
    IF v_status NOT IN ('pending', 'paid') THEN
        RAISE EXCEPTION 'order % is % and can no longer be cancelled', p_order_id, v_status
            USING ERRCODE = 'IW004';
    END IF;

    UPDATE orders SET status = 'cancelled' WHERE id = p_order_id;

    UPDATE products AS p
    SET stock = p.stock + oi.quantity
    FROM order_items AS oi
    WHERE oi.order_id = p_order_id AND oi.product_id = p.id;
END;
$$;
