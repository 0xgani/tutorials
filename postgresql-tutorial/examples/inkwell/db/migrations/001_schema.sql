-- 001: the tables, built up in chapters 4, 13, 14, 15 and 19.
-- Migrations run as inkwell_owner (see db/roles.sql), so everything created here belongs to it.

-- Functions created from now on are not executable by PUBLIC; each one is granted explicitly (chapter 21).
ALTER DEFAULT PRIVILEGES REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;

CREATE TYPE order_status AS ENUM ('pending', 'paid', 'shipped', 'delivered', 'cancelled');

CREATE TABLE customers (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    email       text NOT NULL UNIQUE
                CONSTRAINT customers_email_lowercase CHECK (email = lower(email)),
    full_name   text NOT NULL CHECK (full_name <> ''),
    country     text NOT NULL CHECK (country ~ '^[A-Z]{2}$'),
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE categories (
    id         integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    parent_id  integer REFERENCES categories (id),
    name       text NOT NULL,
    slug       text NOT NULL UNIQUE CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')
);

CREATE TABLE products (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    sku          text NOT NULL UNIQUE,
    name         text NOT NULL,
    description  text NOT NULL DEFAULT '',
    category_id  integer NOT NULL REFERENCES categories (id),
    price        numeric(10, 2) NOT NULL CHECK (price >= 0),
    stock        integer NOT NULL DEFAULT 0 CHECK (stock >= 0),
    attributes   jsonb NOT NULL DEFAULT '{}',
    tags         text[] NOT NULL DEFAULT '{}',
    active       boolean NOT NULL DEFAULT true,
    created_at   timestamptz NOT NULL DEFAULT now(),
    -- Full-text search document (chapter 13). STORED, because a GIN index needs a stored column.
    search       tsvector GENERATED ALWAYS AS (
                     setweight(to_tsvector('english', name), 'A')
                     || setweight(to_tsvector('english', description), 'B')) STORED
);

CREATE TABLE orders (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    customer_id  bigint NOT NULL REFERENCES customers (id),
    status       order_status NOT NULL DEFAULT 'pending',
    created_at   timestamptz NOT NULL DEFAULT now(),
    shipped_at   timestamptz,
    CONSTRAINT orders_shipped_after_created
        CHECK (shipped_at >= created_at),
    CONSTRAINT orders_shipped_at_matches_status
        CHECK ((status IN ('shipped', 'delivered')) = (shipped_at IS NOT NULL))
);

CREATE TABLE order_items (
    order_id    bigint NOT NULL REFERENCES orders (id) ON DELETE CASCADE,
    product_id  bigint NOT NULL REFERENCES products (id),
    quantity    integer NOT NULL CHECK (quantity > 0),
    unit_price  numeric(10, 2) NOT NULL CHECK (unit_price >= 0),
    PRIMARY KEY (order_id, product_id)
);

CREATE TABLE reviews (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    product_id   bigint NOT NULL REFERENCES products (id) ON DELETE CASCADE,
    customer_id  bigint NOT NULL REFERENCES customers (id),
    rating       smallint NOT NULL CHECK (rating BETWEEN 1 AND 5),
    body         text,
    created_at   timestamptz NOT NULL DEFAULT now(),
    UNIQUE (product_id, customer_id)
);

-- Who changed what (chapter 19). Written only by a trigger; nobody else may write to it.
CREATE TABLE audit_log (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    table_name   text NOT NULL,
    operation    text NOT NULL,
    row_id       bigint,
    old_row      jsonb,
    new_row      jsonb,
    changed_by   text NOT NULL,          -- the login role (session_user)
    customer_id  bigint,                 -- the signed-in customer, if any
    changed_at   timestamptz NOT NULL DEFAULT now()
);

-- Indexes: foreign keys, search, and the access paths of the API (chapters 15 and 22).
CREATE INDEX categories_parent_id_idx ON categories (parent_id);
CREATE INDEX products_category_id_idx ON products (category_id);
CREATE INDEX products_search_idx ON products USING gin (search);
-- A customer's orders, newest first, with keyset pagination on (created_at, id).
CREATE INDEX orders_customer_created_idx ON orders (customer_id, created_at DESC, id DESC);
CREATE INDEX order_items_product_id_idx ON order_items (product_id);
CREATE INDEX reviews_customer_id_idx ON reviews (customer_id);
CREATE INDEX audit_log_row_idx ON audit_log (table_name, row_id);

COMMENT ON TABLE order_items IS 'One line of an order. unit_price is the price at the time of the order.';
COMMENT ON COLUMN products.attributes IS 'Product-specific attributes such as nib size or paper weight (chapter 12).';
COMMENT ON TABLE audit_log IS 'Row changes recorded by audit_row_change(); append-only for everyone but the owner.';
