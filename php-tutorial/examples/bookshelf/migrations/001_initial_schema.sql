-- Bookshelf's tables. SQLite dialect; STRICT tables (SQLite 3.37+) reject values of the wrong type.

CREATE TABLE books (
    id        INTEGER PRIMARY KEY,
    title     TEXT    NOT NULL CHECK (title <> ''),
    author    TEXT    NOT NULL CHECK (author <> ''),
    isbn      TEXT    NOT NULL UNIQUE CHECK (length(isbn) = 13),
    genre     TEXT,
    year      INTEGER,
    copies    INTEGER NOT NULL DEFAULT 1 CHECK (copies >= 1),
    available INTEGER NOT NULL CHECK (available BETWEEN 0 AND copies)
) STRICT;

CREATE TABLE members (
    id            INTEGER PRIMARY KEY,
    name          TEXT NOT NULL CHECK (name <> ''),
    email         TEXT NOT NULL UNIQUE,
    type          TEXT NOT NULL DEFAULT 'standard' CHECK (type IN ('standard', 'premium', 'staff')),
    password_hash TEXT
) STRICT;

CREATE TABLE loans (
    id                 INTEGER PRIMARY KEY,
    book_id            INTEGER NOT NULL REFERENCES books (id),
    member_id          INTEGER NOT NULL REFERENCES members (id),
    lent_on            TEXT    NOT NULL,             -- calendar dates as 'YYYY-MM-DD'
    due_on             TEXT    NOT NULL,
    loan_days          INTEGER NOT NULL,
    fine_per_day_cents INTEGER NOT NULL,
    returned_on        TEXT,
    renewals           INTEGER NOT NULL DEFAULT 0
) STRICT;

CREATE INDEX loans_by_book ON loans (book_id);
CREATE INDEX open_loans_by_member ON loans (member_id) WHERE returned_on IS NULL;
