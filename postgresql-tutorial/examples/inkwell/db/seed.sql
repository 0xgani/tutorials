-- Inkwell's sample data (the same as sql/seed.sql in the tutorial), for development and tests.
-- Load it into a freshly migrated, empty database, as the migration role; scripts/reset-db.js in the API does.
-- The identity columns then number the rows 1, 2, 3, ... in the order of each VALUES list, and the foreign keys
-- below (parent_id, category_id, customer_id, ...) rely on that.

INSERT INTO customers (email, full_name, country, created_at) VALUES
    ('ana@example.com',   'Ana Souza',      'BR', '2026-01-05 09:12:00+00'),  -- 1
    ('ben@example.com',   'Ben Carter',     'US', '2026-01-11 14:30:00+00'),  -- 2
    ('chloe@example.com', 'Chloé Martin',   'FR', '2026-01-20 18:45:00+00'),  -- 3
    ('dev@example.com',   'Dev Patel',      'GB', '2026-02-02 08:05:00+00'),  -- 4
    ('emma@example.com',  'Emma Schulz',    'DE', '2026-02-14 11:00:00+00'),  -- 5
    ('farah@example.com', 'Farah Haddad',   'US', '2026-03-03 16:20:00+00'),  -- 6
    ('gus@example.com',   'Gus Lindqvist',  'SE', '2026-03-18 10:10:00+00'),  -- 7
    ('hana@example.com',  'Hana Kobayashi', 'JP', '2026-04-01 07:55:00+00');  -- 8

INSERT INTO categories (parent_id, name, slug) VALUES
    (NULL, 'Writing',        'writing'),          -- 1
    (1,    'Pens',           'pens'),             -- 2
    (2,    'Fountain pens',  'fountain-pens'),    -- 3
    (2,    'Ballpoint pens', 'ballpoint-pens'),   -- 4
    (1,    'Pencils',        'pencils'),          -- 5
    (1,    'Ink',            'ink'),              -- 6
    (NULL, 'Paper',          'paper'),            -- 7
    (7,    'Notebooks',      'notebooks'),        -- 8
    (7,    'Loose paper',    'loose-paper'),      -- 9
    (NULL, 'Accessories',    'accessories');      -- 10

INSERT INTO products (sku, name, description, category_id, price, stock, attributes, tags, active, created_at) VALUES
    ('FP-001', 'Kestrel Fountain Pen',                                                                -- 1
     'A light resin fountain pen with a fine steel nib. Smooth and reliable for everyday writing.',
     3, 48.00, 12, '{"nib": "F", "color": "black", "material": "resin"}', '{gift,bestseller}', true, '2025-12-01 09:00:00+00'),
    ('FP-002', 'Heron Fountain Pen',                                                                  -- 2
     'A weighty brass fountain pen with a medium gold-plated nib, balanced for long writing sessions.',
     3, 129.00, 3, '{"nib": "M", "color": "green", "material": "brass"}', '{gift}', true, '2025-12-01 09:00:00+00'),
    ('BP-001', 'Everyday Ballpoint',                                                                  -- 3
     'A dependable disposable ballpoint pen with blue ink.',
     4, 2.50, 400, '{"color": "blue", "refillable": false}', '{}', true, '2025-12-01 09:00:00+00'),
    ('BP-002', 'Click Ballpoint 3-pack',                                                              -- 4
     'Three refillable retractable ballpoint pens in black.',
     4, 6.90, 150, '{"color": "black", "refillable": true, "pack": 3}', '{bestseller}', true, '2025-12-01 09:00:00+00'),
    ('PC-001', 'Graphite Pencil HB',                                                                  -- 5
     'A classic HB graphite pencil for writing and sketching.',
     5, 1.20, 800, '{"grade": "HB"}', '{}', true, '2025-12-01 09:00:00+00'),
    ('PC-002', 'Sketching Pencil Set',                                                                -- 6
     'Five graphite pencils from 2H to 6B in a tin, for drawing and shading.',
     5, 18.50, 40, '{"grades": ["2H", "HB", "2B", "4B", "6B"]}', '{gift}', true, '2025-12-01 09:00:00+00'),
    ('NB-001', 'Dot Grid Notebook A5',                                                                -- 7
     'A5 notebook with 192 pages of dotted paper that handles fountain pen ink without bleeding.',
     8, 14.00, 75, '{"size": "A5", "pages": 192, "ruling": "dot"}', '{bestseller}', true, '2025-12-01 09:00:00+00'),
    ('NB-002', 'Lined Notebook A4',                                                                   -- 8
     'A4 notebook with 160 lined pages for notes and journaling.',
     8, 11.00, 60, '{"size": "A4", "pages": 160, "ruling": "lined"}', '{}', true, '2025-12-01 09:00:00+00'),
    ('NB-003', 'Pocket Notebook 3-pack',                                                              -- 9
     'Three plain pocket notebooks with 64 pages each.',
     8, 9.50, 0, '{"size": "A6", "pages": 64, "ruling": "plain", "pack": 3}', '{}', true, '2025-12-01 09:00:00+00'),
    ('LP-001', 'Copy Paper 500 sheets',                                                               -- 10
     'A ream of 80 gsm A4 paper for printers and copiers.',
     9, 7.99, 200, '{"size": "A4", "weight_gsm": 80}', '{}', true, '2025-12-01 09:00:00+00'),
    ('IN-001', 'Midnight Blue Ink 50ml',                                                              -- 11
     'Deep blue fountain pen ink with subtle shading.',
     6, 16.00, 30, '{"volume_ml": 50, "color": "blue"}', '{}', true, '2025-12-01 09:00:00+00'),
    ('IN-002', 'Sepia Ink 50ml',                                                                      -- 12
     'Warm brown fountain pen ink with a vintage look.',
     6, 16.00, 25, '{"volume_ml": 50, "color": "brown"}', '{}', true, '2025-12-01 09:00:00+00'),
    ('AC-001', 'Brass Pen Clip',                                                                      -- 13
     'A solid brass clip that fits most pens and pencils.',
     10, 5.00, 90, '{"material": "brass"}', '{}', true, '2026-02-01 09:00:00+00'),
    ('AC-002', 'Leather Pen Case',                                                                    -- 14
     'A handmade leather case that holds three pens.',
     10, 35.00, 10, '{"material": "leather", "slots": 3}', '{gift}', false, '2025-12-01 09:00:00+00');

INSERT INTO orders (customer_id, status, created_at, shipped_at) VALUES
    (1, 'delivered', '2026-01-06 10:00:00+00', '2026-01-07 09:00:00+00'),  -- 1
    (2, 'delivered', '2026-01-15 12:00:00+00', '2026-01-16 10:30:00+00'),  -- 2
    (3, 'delivered', '2026-02-03 19:30:00+00', '2026-02-05 08:15:00+00'),  -- 3
    (1, 'delivered', '2026-02-10 08:45:00+00', '2026-02-11 14:00:00+00'),  -- 4
    (4, 'cancelled', '2026-02-20 13:15:00+00', NULL),                      -- 5
    (5, 'delivered', '2026-03-02 15:00:00+00', '2026-03-03 11:20:00+00'),  -- 6
    (2, 'shipped',   '2026-03-21 09:30:00+00', '2026-03-22 16:45:00+00'),  -- 7
    (6, 'delivered', '2026-03-28 17:40:00+00', '2026-03-30 09:10:00+00'),  -- 8
    (3, 'shipped',   '2026-04-04 11:11:00+00', '2026-04-06 13:00:00+00'),  -- 9
    (8, 'paid',      '2026-04-08 06:30:00+00', NULL),                      -- 10
    (1, 'paid',      '2026-04-09 20:05:00+00', NULL),                      -- 11
    (5, 'pending',   '2026-04-10 12:00:00+00', NULL);                      -- 12

INSERT INTO order_items (order_id, product_id, quantity, unit_price) VALUES
    (1, 1, 1, 48.00), (1, 11, 2, 16.00),
    (2, 3, 10, 2.50), (2, 7, 1, 14.00),
    (3, 2, 1, 129.00), (3, 12, 1, 16.00),
    (4, 7, 2, 14.00), (4, 5, 12, 1.20),
    (5, 6, 1, 18.50),
    (6, 1, 1, 48.00), (6, 4, 2, 6.90), (6, 8, 1, 11.00),
    (7, 10, 5, 7.99),
    (8, 14, 1, 35.00), (8, 1, 1, 45.00),
    (9, 9, 2, 9.50), (9, 11, 1, 16.00),
    (10, 2, 1, 129.00),
    (11, 6, 1, 18.50), (11, 5, 6, 1.20),
    (12, 7, 3, 14.00);

INSERT INTO reviews (product_id, customer_id, rating, body, created_at) VALUES
    (1,  1, 5, 'Smooth nib, writes every time.',                '2026-01-20 08:00:00+00'),
    (11, 1, 4, 'Lovely color, dries a bit slowly.',             '2026-01-21 19:30:00+00'),
    (7,  2, 5, 'Great paper, no ghosting with fountain pens.',  '2026-01-25 12:10:00+00'),
    (3,  2, 3, NULL,                                            '2026-01-25 12:12:00+00'),
    (2,  3, 5, 'Heavy, balanced and beautiful.',                '2026-02-15 21:00:00+00'),
    (12, 3, 4, 'Warm brown, nice shading.',                     '2026-02-16 09:45:00+00'),
    (1,  5, 4, 'Good starter pen.',                             '2026-03-10 18:00:00+00'),
    (8,  5, 2, 'Paper is thinner than expected.',               '2026-03-12 10:00:00+00'),
    (14, 6, 5, 'Fits three pens perfectly.',                    '2026-04-05 15:20:00+00');

-- The materialized report was created empty by migration 005; fill it, and give the planner statistics.
REFRESH MATERIALIZED VIEW reporting.product_sales;
ANALYZE customers, categories, products, orders, order_items, reviews;
