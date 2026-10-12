-- Inkwell: the core schema built in chapter 4.
-- Run it in an empty database (sql/reset.sql does that for you):
--   psql -X -v ON_ERROR_STOP=1 -d inkwell -f sql/schema.sql

CREATE TYPE order_status AS ENUM ('pending', 'paid', 'shipped', 'delivered', 'cancelled');

CREATE TABLE customers (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    email       text NOT NULL UNIQUE
                CONSTRAINT customers_email_lowercase CHECK (email = lower(email)),
    full_name   text NOT NULL CHECK (full_name <> ''),
    country     text NOT NULL CHECK (country ~ '^[A-Z]{2}$'),
    created_at  timestamptz NOT NULL DEFAULT now()
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
    created_at   timestamptz NOT NULL DEFAULT now()
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

-- Foreign-key columns that are not already the first column of an index.
CREATE INDEX categories_parent_id_idx ON categories (parent_id);
CREATE INDEX products_category_id_idx ON products (category_id);
CREATE INDEX orders_customer_id_idx ON orders (customer_id);
CREATE INDEX order_items_product_id_idx ON order_items (product_id);
CREATE INDEX reviews_customer_id_idx ON reviews (customer_id);

COMMENT ON TABLE order_items IS 'One line of an order. unit_price is the price at the time of the order.';
COMMENT ON COLUMN products.attributes IS 'Product-specific attributes such as nib size or paper weight (chapter 12).';
