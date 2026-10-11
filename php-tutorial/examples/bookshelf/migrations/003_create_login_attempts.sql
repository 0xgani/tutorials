-- Failed logins, for throttling password guessing (chapter 18).
-- subject is 'account:<e-mail>' or 'address:<IP address>'; attempted_at is a Unix timestamp.

CREATE TABLE login_attempts (
    id           INTEGER PRIMARY KEY,
    subject      TEXT    NOT NULL,
    attempted_at INTEGER NOT NULL
) STRICT;

CREATE INDEX login_attempts_by_subject ON login_attempts (subject, attempted_at);
