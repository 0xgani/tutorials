-- 003: triggers (chapter 19).

-- Keep customers.updated_at current, whoever changes the row.
CREATE FUNCTION set_updated_at() RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    NEW.updated_at := now();
    RETURN NEW;
END;
$$;

CREATE TRIGGER customers_set_updated_at
    BEFORE UPDATE ON customers
    FOR EACH ROW
    WHEN (OLD IS DISTINCT FROM NEW)
    EXECUTE FUNCTION set_updated_at();

-- Audit log. SECURITY DEFINER lets the trigger write to audit_log although the roles that change the audited
-- tables have no privileges on it, so they can't forge or erase entries.
CREATE FUNCTION audit_row_change() RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public, pg_temp
AS $$
BEGIN
    INSERT INTO audit_log (table_name, operation, row_id, old_row, new_row, changed_by, customer_id)
    VALUES (TG_TABLE_NAME,
            TG_OP,
            CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END,
            CASE WHEN TG_OP <> 'INSERT' THEN to_jsonb(OLD) END,
            CASE WHEN TG_OP <> 'DELETE' THEN to_jsonb(NEW) END,
            session_user,
            current_customer_id());
    RETURN NULL;   -- the return value of an AFTER trigger is ignored
END;
$$;

CREATE TRIGGER orders_audit
    AFTER INSERT OR UPDATE OR DELETE ON orders
    FOR EACH ROW
    EXECUTE FUNCTION audit_row_change();

-- Price and availability changes of products get an audit trail too. (Stock changes with every order,
-- so it isn't audited.)
CREATE TRIGGER products_audit
    AFTER UPDATE OF price, active ON products
    FOR EACH ROW
    WHEN (OLD.price IS DISTINCT FROM NEW.price OR OLD.active IS DISTINCT FROM NEW.active)
    EXECUTE FUNCTION audit_row_change();

-- Tell listeners about new orders (LISTEN order_placed). Delivered only if the transaction commits.
CREATE FUNCTION notify_order_placed() RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    PERFORM pg_notify('order_placed',
                      json_build_object('order_id', NEW.id, 'customer_id', NEW.customer_id)::text);
    RETURN NULL;
END;
$$;

CREATE TRIGGER orders_notify_placed
    AFTER INSERT ON orders
    FOR EACH ROW
    EXECUTE FUNCTION notify_order_placed();
