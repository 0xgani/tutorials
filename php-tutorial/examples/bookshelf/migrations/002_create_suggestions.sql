-- Suggestions from the web form, which chapter 16 stored in var/suggestions.csv.

CREATE TABLE suggestions (
    id         INTEGER PRIMARY KEY,
    created_at TEXT NOT NULL,                        -- an instant, in UTC: 'YYYY-MM-DD HH:MM:SS'
    title      TEXT NOT NULL,
    author     TEXT NOT NULL,
    isbn       TEXT,
    reason     TEXT NOT NULL DEFAULT ''
) STRICT;
