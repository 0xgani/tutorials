-- Customers can save products for later.
CREATE TABLE wishlist_items (
    customer_id  bigint NOT NULL REFERENCES customers (id),
    product_id   bigint NOT NULL REFERENCES products (id),
    added_at     timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (customer_id, product_id)
);

CREATE INDEX wishlist_items_product_id_idx ON wishlist_items (product_id);
