# Chapter 17 — Databases with PDO

## What you'll learn

- What PDO is, and how to connect to SQLite, PostgreSQL and MySQL
- Running queries safely with prepared statements, and why string-built SQL is a security hole
- Fetching results in the shape you need, and what PHP types come back
- Inserting, updating, `RETURNING`, upserts and `lastInsertId()`
- Handling database errors by their SQLSTATE codes
- Transactions, and the concurrency bugs they do and don't prevent
- Schema migrations, the repository pattern, and the N+1 query problem
- SQLite and PostgreSQL in practice
- Bookshelf's database: migrations, repositories, a lending service, and CLI commands that use them

This chapter assumes you can read basic SQL (`SELECT`, `INSERT`, `JOIN`, `CREATE TABLE`). The focus is on using a
database **from PHP** correctly.

## PDO: one API for many databases

**PDO** (PHP Data Objects) is PHP's database access layer. You write the same PHP code for every database; a
**driver** translates it for a specific one. The common drivers are `pdo_sqlite`, `pdo_pgsql` and `pdo_mysql` (which
also serves MariaDB). PDO does **not** translate SQL itself: each database's own dialect still applies.

```php
<?php

print_r(PDO::getAvailableDrivers());
```

```text
Array
(
    [0] => mysql
    [1] => pgsql
    [2] => sqlite
)
```

(Your list shows the drivers installed with your PHP; on Debian and Ubuntu, they come in packages like
`php8.5-sqlite3`, `php8.5-pgsql` and `php8.5-mysql`.) There are also older database-specific extensions (`mysqli`,
`pgsql`, `sqlite3`) with their own functions. They expose a few database-specific features, but new code uses PDO,
and so do the libraries and frameworks built on top of it (Doctrine DBAL, Laravel's query builder).

### Connecting

A connection is described by a **DSN** (Data Source Name): the driver name, a colon, and driver-specific settings.

| Database | DSN |
|---|---|
| SQLite, a file | `sqlite:/srv/bookshelf/var/bookshelf.sqlite` |
| SQLite, in memory (gone when the connection closes) | `sqlite::memory:` |
| PostgreSQL | `pgsql:host=db.internal;port=5432;dbname=bookshelf` |
| MySQL / MariaDB | `mysql:host=db.internal;port=3306;dbname=bookshelf;charset=utf8mb4` |

```php
<?php

declare(strict_types=1);

$pdo = new PDO(
    'pgsql:host=localhost;port=5432;dbname=bookshelf',
    getenv('DB_USER') ?: 'bookshelf',
    getenv('DB_PASSWORD') ?: '',
    [
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,   // rows as ['column' => value] arrays
        PDO::ATTR_TIMEOUT => 5,                             // seconds to wait when connecting
    ],
);
```

- **Errors throw `PDOException`.** That has been the default since PHP 8.0 (`PDO::ATTR_ERRMODE` is
  `PDO::ERRMODE_EXCEPTION`). Very old code sets "silent" mode and checks return values; don't.
- Set the **default fetch mode** to `PDO::FETCH_ASSOC`. The built-in default, `FETCH_BOTH`, returns every column twice,
  once by name and once by number.
- Credentials come from the **environment** or a configuration file outside the repository, never from source code
  (chapter 18).
- For **MySQL**, always put `charset=utf8mb4` in the DSN (MySQL's `utf8` is a three-byte subset that cannot store
  emoji), and set `PDO::ATTR_EMULATE_PREPARES => false` so that prepared statements are real server-side ones and
  integers come back as integers.
- A failed connection throws a `PDOException` whose message can include the host name and user. Catch it at the top of
  the application and log it; never show it to visitors.

### Driver-specific classes (PHP 8.4+)

Since PHP 8.4, each driver has a subclass of `PDO`: `Pdo\Sqlite`, `Pdo\Pgsql`, `Pdo\Mysql` and others. They carry the
driver-specific methods and constants. `PDO::connect()` returns the right subclass for a DSN, or you can instantiate
one directly:

```php
<?php

declare(strict_types=1);

$db = PDO::connect('sqlite::memory:');
echo get_class($db), "\n";

$db->createFunction('reverse', fn (string $text): string => strrev($text), 1, Pdo\Sqlite::DETERMINISTIC);
echo $db->query("SELECT reverse('Dune')")->fetchColumn(), "\n";

echo get_class(new PDO('sqlite::memory:')), "\n";   // plain new PDO() still returns the base class
```

```text
Pdo\Sqlite
enuD
PDO
```

Both styles work; `PDO::connect()` is the one that gives you the driver methods. PHP 8.5 deprecates the older spellings that put driver specifics on the base
class, such as `PDO::sqliteCreateFunction()` and constants like `PDO::SQLITE_DETERMINISTIC` or
`PDO::MYSQL_ATTR_INIT_COMMAND`. Use `Pdo\Sqlite::createFunction()`, `Pdo\Sqlite::DETERMINISTIC` and
`Pdo\Mysql::ATTR_INIT_COMMAND` instead.

### A sample database for this chapter

The examples below use a small SQLite database in a file next to the scripts. This script creates it:

```php
<?php

declare(strict_types=1);

// setup.php: creates library.sqlite for the examples in this chapter.
$path = __DIR__ . '/library.sqlite';
@unlink($path);

$pdo = new PDO("sqlite:$path");
$pdo->exec(<<<'SQL'
    CREATE TABLE books (
        id        INTEGER PRIMARY KEY,
        title     TEXT NOT NULL,
        author    TEXT NOT NULL,
        genre     TEXT,
        year      INTEGER,
        available INTEGER NOT NULL DEFAULT 1
    );
    INSERT INTO books (title, author, genre, year, available) VALUES
        ('Dune', 'Frank Herbert', 'sf', 1965, 2),
        ('Kindred', 'Octavia E. Butler', 'sf', 1979, 1),
        ('Emma', 'Jane Austen', 'classic', 1815, 0),
        ('Persuasion', 'Jane Austen', 'classic', 1817, 1),
        ('The Hobbit', 'J. R. R. Tolkien', 'fantasy', 1937, 2),
        ('Silent Spring', 'Rachel Carson', NULL, 1962, 1);
    SQL);

echo $pdo->query('SELECT count(*) FROM books')->fetchColumn(), " books\n";
```

```text
6 books
```

`exec()` runs SQL that returns no rows, and returns the number of rows changed. With SQLite (and PostgreSQL) it accepts
several statements separated by semicolons.

## Queries and prepared statements

### SQL injection

The most important rule in this chapter: **never put a value into SQL text by concatenation or interpolation.** Here is
why:

```php
<?php

declare(strict_types=1);

$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE members (id INTEGER PRIMARY KEY, email TEXT, password_hash TEXT)");
$pdo->exec("INSERT INTO members (email, password_hash) VALUES ('ada@example.org', '\$2y\$12\$secret...'), ('alan@example.org', '\$2y\$12\$other...')");

$email = "nobody' OR '1'='1";     // typed into a login or search form

// WRONG: the input becomes part of the SQL text.
$sql = "SELECT id, email FROM members WHERE email = '$email'";
echo $sql, "\n";
print_r($pdo->query($sql)->fetchAll());

// RIGHT: the SQL text is fixed; the input travels separately, as data.
$statement = $pdo->prepare('SELECT id, email FROM members WHERE email = ?');
$statement->execute([$email]);
print_r($statement->fetchAll());
```

```text
SELECT id, email FROM members WHERE email = 'nobody' OR '1'='1'
Array
(
    [0] => Array
        (
            [id] => 1
            [email] => ada@example.org
        )

    [1] => Array
        (
            [id] => 2
            [email] => alan@example.org
        )

)
Array
(
)
```

The quote in the input ended the string literal, and the rest became SQL: `OR '1'='1'` is always true, so the query
returned every member. With other inputs the same hole lets an attacker read any table (`' UNION SELECT ...`), change
data, or log in as anyone. **SQL injection** has been among the most damaging web vulnerabilities for decades.

A **prepared statement** fixes it at the root. `prepare()` sends the SQL with **placeholders** to the database, which
parses it; `execute()` sends the values separately. A value can never change the structure of the query, whatever
characters it contains. Escaping functions such as `PDO::quote()` exist, but placeholders are simpler and impossible to
forget in one spot, so use them for every value, even ones you think are safe.

### Placeholders

```php
<?php

declare(strict_types=1);

$pdo = new PDO('sqlite:' . __DIR__ . '/library.sqlite', options: [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

// Positional placeholders: values in order.
$statement = $pdo->prepare('SELECT title FROM books WHERE author = ? AND year > ?');
$statement->execute(['Jane Austen', 1816]);
print_r($statement->fetchAll(PDO::FETCH_COLUMN));

// Named placeholders: clearer when there are several.
$statement = $pdo->prepare('SELECT title FROM books WHERE genre = :genre AND available > :min ORDER BY title');
$statement->execute(['genre' => 'sf', 'min' => 0]);
print_r($statement->fetchAll(PDO::FETCH_COLUMN));

// A prepared statement can be executed many times with different values.
foreach (['fantasy', 'classic'] as $genre) {
    $statement->execute(['genre' => $genre, 'min' => 0]);
    echo $genre, ': ', implode(', ', $statement->fetchAll(PDO::FETCH_COLUMN)), "\n";
}
```

```text
Array
(
    [0] => Persuasion
)
Array
(
    [0] => Dune
    [1] => Kindred
)
fantasy: The Hobbit
classic: Persuasion
```

The rules of placeholders:

- They stand for **values** only. Table names, column names, `ASC`/`DESC` and SQL keywords cannot be parameters. When
  those depend on input (a "sort by" option), choose from an **allow list** in code: `$column = ['title' => 'title',
  'year' => 'year'][$input] ?? 'title';` (exercise 2).
- Don't mix positional and named placeholders in one statement. Reusing the same named placeholder twice works with
  SQLite and PostgreSQL but not with MySQL's native prepared statements; distinct names are portable.
- `execute($array)` passes every value as a string (or `null`), and the database converts it. When the type matters,
  bind explicitly: `bindValue('limit', $limit, PDO::PARAM_INT)`. Other types are `PARAM_BOOL`, `PARAM_NULL` and
  `PARAM_LOB` for binary data. (`bindParam()` binds a *variable by reference*, read at `execute()` time; it is rarely
  needed.)
- A list of values for `IN (...)` needs one placeholder per value, generated in code (exercise 1).
- `LIKE` patterns are values too, but `%` and `_` inside the user's text are wildcards. Escape them if the user should
  search for them literally (Bookshelf's repository shows how).

`query()` runs SQL without parameters and returns a statement with the results. Use it only for SQL that contains no
input at all.

## Fetching results

A statement returns rows one at a time with `fetch()`, or all at once with `fetchAll()`. The **fetch mode** decides
their shape:

```php
<?php

declare(strict_types=1);

$pdo = new PDO('sqlite:' . __DIR__ . '/library.sqlite');
$sql = 'SELECT id, title, genre FROM books WHERE id <= 4 ORDER BY id';

var_dump($pdo->query($sql)->fetch(PDO::FETCH_ASSOC));                  // one row, or false when none is left
print_r($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));               // the first column of each row
print_r($pdo->query('SELECT id, title FROM books WHERE id <= 3')->fetchAll(PDO::FETCH_KEY_PAIR));   // col 1 => col 2
print_r($pdo->query('SELECT genre, title FROM books WHERE genre IS NOT NULL ORDER BY genre, title')
    ->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_COLUMN));                   // grouped by the first column
echo $pdo->query('SELECT max(year) FROM books')->fetchColumn(), "\n";   // a single value
```

```text
array(3) {
  ["id"]=>
  int(1)
  ["title"]=>
  string(4) "Dune"
  ["genre"]=>
  string(2) "sf"
}
Array
(
    [0] => 1
    [1] => 2
    [2] => 3
    [3] => 4
)
Array
(
    [1] => Dune
    [2] => Kindred
    [3] => Emma
)
Array
(
    [classic] => Array
        (
            [0] => Emma
            [1] => Persuasion
        )

    [fantasy] => Array
        (
            [0] => The Hobbit
        )

    [sf] => Array
        (
            [0] => Dune
            [1] => Kindred
        )

)
1979
```

| Mode | Each row becomes |
|---|---|
| `FETCH_ASSOC` | `['id' => 1, 'title' => 'Dune']` |
| `FETCH_NUM` | `[1, 'Dune']` |
| `FETCH_OBJ` | a `stdClass` with properties |
| `FETCH_COLUMN` | the value of one column (the first, or `fetchAll(PDO::FETCH_COLUMN, 2)`) |
| `FETCH_KEY_PAIR` | `fetchAll()` returns `[first column => second column, ...]` |
| `FETCH_GROUP` | `fetchAll()` groups rows by the first column |
| `FETCH_UNIQUE` | `fetchAll()` keys rows by the first column |
| `FETCH_CLASS` | an object of a given class, with properties set (bypassing the constructor by default) |

`FETCH_CLASS` looks convenient, but it writes properties directly, which does not work with `readonly` properties or
constructors that validate. Converting rows to objects yourself (**hydration**), as Bookshelf's repositories do below,
keeps the domain classes in control.

`fetch()` returns `false` when there are no more rows, so "not found" is `$row === false`. A statement can also be
iterated with `foreach`, one row at a time, which is how to process a large result without holding it all in memory:

```php
$statement = $pdo->query('SELECT id, title FROM books');
foreach ($statement as $row) {
    // one row at a time
}
```

(With MySQL, PDO by default buffers the whole result in PHP memory anyway; set `Pdo\Mysql::ATTR_USE_BUFFERED_QUERY`
to `false` to stream large results.)

### What types come back

| Column type | SQLite | PostgreSQL | MySQL (no emulation) |
|---|---|---|---|
| Integer | `int` | `int` | `int` |
| Floating point | `float` | `float` | `float` |
| Exact decimal (`numeric`, `decimal`) | (stored as float or int) | **`string`** | **`string`** |
| Boolean | `int` (0/1) | `bool` | `int` (0/1) |
| Text, dates, times | `string` | `string` | `string` |
| JSON | `string` | `string` | `string` |
| `NULL` | `null` | `null` | `null` |

Exact decimals arrive as strings on purpose: converting `'9.99'` to a float would lose exactness (chapter 2). Dates are
strings for you to turn into `DateTimeImmutable` objects, and JSON columns are strings for `json_decode()`. Mapping
these at one place, the repository, keeps the rest of the code clean.

## Changing data

```php
<?php

declare(strict_types=1);

$pdo = new PDO('sqlite:' . __DIR__ . '/library.sqlite', options: [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$insert = $pdo->prepare('INSERT INTO books (title, author, genre, year) VALUES (:title, :author, :genre, :year)');
$insert->execute(['title' => 'Beloved', 'author' => 'Toni Morrison', 'genre' => 'classic', 'year' => 1987]);
var_dump($pdo->lastInsertId());

// RETURNING (SQLite 3.35+, PostgreSQL, MariaDB 10.5+) gives back columns of the new row directly.
$insert = $pdo->prepare('INSERT INTO books (title, author, year) VALUES (?, ?, ?) RETURNING id, available');
$insert->execute(['Middlemarch', 'George Eliot', 1871]);
var_dump($insert->fetch());

$update = $pdo->prepare('UPDATE books SET available = available + 1 WHERE author = ?');
$update->execute(['Jane Austen']);
echo $update->rowCount(), " rows updated\n";

$delete = $pdo->prepare('DELETE FROM books WHERE year < ?');
$delete->execute([1800]);
echo $delete->rowCount(), " rows deleted\n";
```

```text
string(1) "7"
array(2) {
  ["id"]=>
  int(8)
  ["available"]=>
  int(1)
}
2 rows updated
0 rows deleted
```

- `lastInsertId()` returns the new row's generated key as a **string**; cast it with `(int)`. With PostgreSQL it uses
  the session's last sequence value. `RETURNING` is more direct, and also returns defaults the database filled in.
- `rowCount()` reports how many rows an `UPDATE` or `DELETE` changed. Use it to detect "nothing matched" (the record
  was deleted meanwhile, or a condition in the `WHERE` failed). Don't use it for `SELECT`: most drivers don't support
  that.
- An **upsert** inserts a row or updates it if it exists: `INSERT ... ON CONFLICT (isbn) DO UPDATE SET title =
  excluded.title` in SQLite and PostgreSQL, `INSERT ... ON DUPLICATE KEY UPDATE` in MySQL.

### Errors and SQLSTATE codes

Every database error becomes a `PDOException`. Its `getCode()` returns the five-character **SQLSTATE** code defined by
the SQL standard, as a **string**, and `errorInfo` holds the driver's own code and message:

```php
<?php

declare(strict_types=1);

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE members (id INTEGER PRIMARY KEY, email TEXT NOT NULL UNIQUE)');
$insert = $pdo->prepare('INSERT INTO members (email) VALUES (?)');
$insert->execute(['ada@example.org']);

try {
    $insert->execute(['ada@example.org']);
} catch (PDOException $e) {
    var_dump($e->getCode(), $e->errorInfo);
}
```

```text
string(5) "23000"
array(3) {
  [0]=>
  string(5) "23000"
  [1]=>
  int(19)
  [2]=>
  string(39) "UNIQUE constraint failed: members.email"
}
```

The class (first two characters) tells you the category: `23` is an integrity constraint violation, `42` a syntax or
access problem, `08` a connection problem, `40` a transaction rollback (deadlock or serialization failure). The exact
code differs by database: a duplicate key is `23000` in SQLite and MySQL but the more specific `23505` in PostgreSQL.

The common use is turning a known violation into a domain error, while letting everything else propagate:

```php
try {
    $repository->save($member);
} catch (PDOException $e) {
    if (str_starts_with((string) $e->getCode(), '23')) {
        throw new DuplicateEmail("A member with this e-mail address already exists", previous: $e);
    }
    throw $e;
}
```

Checking for the duplicate first (`SELECT` before `INSERT`) is still useful for friendly messages, but only the
database's `UNIQUE` constraint is reliable: two requests can both check, find nothing, and both insert.

## Transactions

A **transaction** groups statements so that they take effect **all together or not at all**. Lending a book is two
changes: the book's available count goes down, and a loan row is inserted. If the second fails after the first
succeeded, the library has lost a copy.

```php
<?php

declare(strict_types=1);

$pdo = new PDO('sqlite:' . __DIR__ . '/library.sqlite');
$pdo->exec('CREATE TABLE IF NOT EXISTS loans (id INTEGER PRIMARY KEY, book_id INTEGER NOT NULL, member TEXT NOT NULL)');

function lend(PDO $pdo, int $bookId, ?string $member): void
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE books SET available = available - 1 WHERE id = ?')->execute([$bookId]);
        $pdo->prepare('INSERT INTO loans (book_id, member) VALUES (?, ?)')->execute([$bookId, $member]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();   // undo everything since beginTransaction()
        throw $e;
    }
}

$available = fn (): int => (int) $pdo->query('SELECT available FROM books WHERE id = 1')->fetchColumn();

echo 'before: ', $available(), "\n";
lend($pdo, 1, 'Ada');
echo 'after a loan: ', $available(), "\n";

try {
    lend($pdo, 1, null);    // the INSERT fails: member is NOT NULL
} catch (PDOException $e) {
    echo 'failed: ', $e->getMessage(), "\n";
}
echo 'after a failed loan: ', $available(), "\n";
```

```text
before: 2
after a loan: 1
failed: SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed: loans.member
after a failed loan: 1
```

The failed loan's `UPDATE` had already run, and the rollback undid it. The pattern (begin, try, commit, catch and roll
back, rethrow) is always the same, so it belongs in one helper; Bookshelf's `Database::transactional()` below takes a
closure and runs it in a transaction.

Without an explicit transaction, every statement is its own transaction (**autocommit**). Transactions also make bulk
writes much faster: inserting 10,000 rows in one transaction is one disk sync instead of 10,000. With SQLite and
PostgreSQL, even `CREATE TABLE` and `ALTER TABLE` can be rolled back; MySQL commits implicitly before every schema
change, so a failed migration there can leave a half-changed schema.

### What transactions don't prevent: concurrent updates

A transaction makes *your* changes atomic. It does not by itself stop two requests from making decisions on the same
stale data. Suppose two librarians lend the last copy of *Kindred* at the same moment:

```text
Request A                                   Request B
SELECT available FROM books → 1
                                            SELECT available FROM books → 1
"1 > 0, OK to lend"                         "1 > 0, OK to lend"
UPDATE books SET available = 0
INSERT INTO loans ...                       UPDATE books SET available = 0
COMMIT                                      INSERT INTO loans ...
                                            COMMIT        → two loans, one copy
```

Each request ran in a transaction, and the result is still wrong. Defenses, from simplest to most general:

1. **Let the database enforce the invariant.** A `CHECK (available >= 0)` constraint makes the second decrement fail
   (if it is written as `available - 1` rather than as a value computed in PHP).
2. **Make the check and the change one statement**:
   `UPDATE books SET available = available - 1 WHERE id = ? AND available > 0`, then `rowCount()` tells you whether
   you got the copy (exercise 3). The database applies concurrent updates to one row one after the other.
3. **Lock what you read.** In PostgreSQL and MySQL, `SELECT ... FOR UPDATE` inside a transaction locks the selected
   rows until commit, so request B's `SELECT` waits until A is done and then sees `0`.
4. **Use a stricter isolation level** (`SERIALIZABLE` in PostgreSQL), where the database detects the conflict and
   aborts one transaction with SQLSTATE `40001`; the application retries it.

SQLite has no row locks: only one connection can write at a time. By default, PDO starts SQLite transactions as
`BEGIN DEFERRED`, which takes the write lock only at the first write; if another connection wrote in between, that
write fails at once with "database is locked". Starting write transactions with `BEGIN IMMEDIATE` takes the lock up
front, so a second writer waits (up to the busy timeout) instead. PHP 8.5 adds an attribute for exactly that:

```php
$pdo = new Pdo\Sqlite('sqlite:' . $path, options: [
    PDO::ATTR_TIMEOUT => 5,                                                  // wait up to 5 s for the lock
    Pdo\Sqlite::ATTR_TRANSACTION_MODE => Pdo\Sqlite::TRANSACTION_MODE_IMMEDIATE,   // PHP 8.5+
]);
```

Bookshelf uses both the immediate mode and a `CHECK` constraint.

## Schema migrations

The database schema changes as the application grows: a new table, a new column, an index. Every copy of the database
(each developer's, the test database, production) must get the same changes, in the same order, exactly once. That is
what **migrations** are for:

- Each change is a file with an increasing version number: `001_initial_schema.sql`, `002_create_suggestions.sql`, ...
- A table in the database (`schema_migrations`) records which versions have been applied.
- A command applies the files not yet recorded, each in a transaction, and records them.
- An applied migration is **never edited**. To change something, write a new migration. (Editing an old one changes
  nothing on databases that already ran it, so copies drift apart.)

Bookshelf's `Migrator` (below) is about 70 lines. Larger projects use Doctrine Migrations, Phinx or their framework's
migrations, which add rollbacks ("down" migrations), generation from entity changes, and per-database dialects.

Changing a schema under a running application needs care: adding a nullable column is instant, but adding a
`NOT NULL` column, renaming a column or building an index on a large table can lock it or break the old code that is
still running during a deploy. The safe approach is "expand, then contract": add the new structure, deploy code that
uses it, and remove the old structure in a later migration (chapter 22).

## The repository pattern

Domain classes such as `Book` and `Loan` should not know about SQL. A **repository** is the object that does: it saves
and loads domain objects, and to the rest of the application it looks like a collection. Bookshelf declares the
interface in the domain and implements it with PDO:

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Domain;

/**
 * Where books are stored. The domain depends on this interface; src/Persistence implements it.
 */
interface BookRepository
{
    /** @throws EntityNotFound */
    public function get(int $id): Book;

    public function findByIsbn(Isbn $isbn): ?Book;

    /**
     * Books whose title or author contains $text (case-insensitively), optionally of one genre, by title.
     *
     * @return list<Book>
     */
    public function search(string $text = '', ?Genre $genre = null, int $limit = 50, int $offset = 0): array;

    public function count(string $text = '', ?Genre $genre = null): int;

    /** Inserts a new book (and assigns its id) or updates an existing one. */
    public function save(Book $book): void;
}
```

The interface is the contract. Code that needs books (a controller, a command, the lending service) depends on
`BookRepository`, so tests can pass an in-memory implementation, and switching from SQLite to PostgreSQL changes only
the implementation. The conventions used here: `get()` throws when the record is missing (the caller expected it to
exist), `find...()` returns `null` (absence is a normal answer), and `save()` handles both insert and update.

The PDO implementation's core is the query and the **hydration** of rows into objects:

```php
public function get(int $id): Book
{
    $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM books WHERE id = ?');
    $statement->execute([$id]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return $row === false ? throw EntityNotFound::book($id) : $this->toBook($row);
}

/** @param array<string, mixed> $row */
private function toBook(array $row): Book
{
    return new Book(
        $row['id'],
        $row['title'],
        $row['author'],
        new Isbn($row['isbn']),
        $row['genre'] === null ? null : Genre::from($row['genre']),
        $row['year'],
        $row['copies'],
        $row['available'],
    );
}
```

Hydration goes through the real constructor, so a corrupt row (an unknown genre, an invalid ISBN) fails loudly instead
of producing an invalid object. `Genre::from()` rather than `tryFrom()` is deliberate here: the database is trusted to
contain valid data, and if it doesn't, that's a bug to hear about.

The search builds its `WHERE` clause from parts, with every piece of user text in a parameter, and escapes `LIKE`'s
wildcards so that searching for `100%` finds a literal percent sign:

```php
private function filter(string $text, ?Genre $genre): array
{
    $conditions = [];
    $parameters = [];
    if ($text !== '') {
        // Escape LIKE's wildcards so that a search for "100%" means a literal percent sign.
        $pattern = '%' . addcslashes($text, '%_\\') . '%';
        $conditions[] = "(title LIKE :title ESCAPE '\\' OR author LIKE :author ESCAPE '\\')";
        $parameters['title'] = $pattern;
        $parameters['author'] = $pattern;
    }
    if ($genre !== null) {
        $conditions[] = 'genre = :genre';
        $parameters['genre'] = $genre->value;
    }

    return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $parameters];
}
```

SQLite's `LIKE` ignores case only for ASCII letters; PostgreSQL's `LIKE` is case-sensitive and `ILIKE` is not. For real
search features (stemming, ranking, accents), use the database's full-text search (SQLite FTS5, PostgreSQL `tsvector`)
or a search engine.

### The N+1 query problem

Repositories make one mistake easy. Listing overdue loans with each member's name:

```php
foreach ($loans->overdue($today) as $loan) {                 // 1 query
    $member = $members->get($loan->memberId);                 // + 1 query per loan
    echo "{$member->name}: due {$loan->dueOn->format('j M')}\n";
}
```

With 200 overdue loans, that is 201 queries. Each one is fast, but together, with network round trips to a database
server, they add up to a slow page, and the cost grows with the data. This is the **N+1 query problem**, the most
common database performance bug in applications that use repositories or ORMs. The fixes:

- **Load the related records in one query**: collect the member ids, then `SELECT ... WHERE id IN (...)` (exercise 1)
  and index the results by id.
- **Join** in a query written for this screen: `SELECT loans.*, members.name FROM loans JOIN members ON ...`.
  Read-only reports and lists are a fine place for queries that return plain arrays instead of domain objects
  (exercise 4).

Watch the number of queries per request in development; a profiler or a query log makes N+1 problems obvious.

### Indexes, briefly

A query that filters or sorts by a column without an **index** reads the whole table. That is invisible with 10 rows
and painful with 10 million. Bookshelf's migration indexes the columns its queries filter by (`loans.book_id`, and open
loans by member). `EXPLAIN QUERY PLAN` (SQLite) or `EXPLAIN ANALYZE` (PostgreSQL) shows how the database runs a query:

```php
<?php

declare(strict_types=1);

$pdo = new PDO('sqlite:' . __DIR__ . '/library.sqlite');
$explain = fn (string $sql): string => $pdo->query("EXPLAIN QUERY PLAN $sql")->fetch(PDO::FETCH_ASSOC)['detail'];

echo $explain("SELECT * FROM books WHERE author = 'Jane Austen'"), "\n";
$pdo->exec('CREATE INDEX IF NOT EXISTS books_by_author ON books (author)');
echo $explain("SELECT * FROM books WHERE author = 'Jane Austen'"), "\n";
```

```text
SCAN books
SEARCH books USING INDEX books_by_author (author=?)
```

`SCAN` reads every row; `SEARCH ... USING INDEX` jumps to the matching ones. Indexes are not free: each one slows down
writes and uses space, so add them for the queries you actually run. Note that `LIKE '%text%'` cannot use a normal
index, because the match can start anywhere.

## SQLite and PostgreSQL in practice

**SQLite** is a database in a single file, run inside your PHP process, with no server to install. It is excellent for
development, tests, command-line tools, and small-to-medium sites with one server. Know its specifics:

- **Foreign keys are not enforced** unless each connection runs `PRAGMA foreign_keys = ON`.
- Columns are loosely typed unless the table is declared **`STRICT`** (SQLite 3.37+), which makes it reject a string in
  an `INTEGER` column.
- **WAL mode** (`PRAGMA journal_mode = WAL`, a setting stored in the file) lets readers work while one connection
  writes. Without it, a write blocks all readers.
- One writer at a time: set a busy timeout and use immediate transactions, as above. Keep write transactions short.
- The file and its `-wal` and `-shm` companions must be on a local disk (not a network share), writable by the PHP
  process, and **outside the document root**.

**PostgreSQL** is the usual choice for production applications: a server with real concurrency, row-level locking,
rich types and strong correctness guarantees. The same PDO code works; the SQL differs in places:

| | SQLite | PostgreSQL |
|---|---|---|
| Auto-increment key | `id INTEGER PRIMARY KEY` | `id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY` |
| Booleans | `INTEGER` 0/1 | `boolean` (`true`/`false`, returned as PHP `bool`) |
| Instants | `TEXT` in UTC by convention | `timestamptz` |
| JSON | `TEXT` with JSON functions | `jsonb`, indexable |
| Case-insensitive match | `LIKE` (ASCII only) | `ILIKE` |
| Upsert, `RETURNING` | Both supported | Both supported |
| Duplicate key SQLSTATE | `23000` | `23505` |

Against a PostgreSQL 16 server, an insert with `RETURNING` and a duplicate:

```php
<?php

declare(strict_types=1);

$pdo = new PDO('pgsql:host=localhost;dbname=bookshelf', 'postgres', '', [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP TABLE IF EXISTS books');
$pdo->exec("CREATE TABLE books (
    id       bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    title    text NOT NULL,
    isbn     char(13) NOT NULL UNIQUE,
    price    numeric(8,2),
    in_stock boolean NOT NULL DEFAULT true
)");

$insert = $pdo->prepare('INSERT INTO books (title, isbn, price) VALUES (?, ?, ?) RETURNING id, price, in_stock');
$insert->execute(['Dune', '9780441013593', '9.99']);
var_dump($insert->fetch());

try {
    $insert->execute(['Dune again', '9780441013593', null]);
} catch (PDOException $e) {
    echo $e->getCode(), "\n";
}
```

```text
array(3) {
  ["id"]=>
  int(1)
  ["price"]=>
  string(4) "9.99"
  ["in_stock"]=>
  bool(true)
}
23505
```

The `numeric` price came back as an exact string and the `boolean` as a PHP `bool`. Bookshelf stays on SQLite in this
tutorial, so that it runs with nothing to install; moving it to PostgreSQL means translating the migrations and
changing the DSN, and the repositories' SQL already works on both.

## Bookshelf: the database

Bookshelf now stores its catalog, members and loans in SQLite. The new and changed files:

```text
examples/bookshelf/
  migrations/
    001_initial_schema.sql        books, members, loans, indexes
    002_create_suggestions.sql    replaces chapter 16's var/suggestions.csv
  src/Domain/
    BookRepository.php, MemberRepository.php, LoanRepository.php     interfaces
  src/Persistence/
    Database.php                  connection settings and transactional()
    Migrator.php
    PdoBookRepository.php, PdoMemberRepository.php, PdoLoanRepository.php
  src/Lending/
    LendingService.php            lend, return, renew: each in one transaction
  src/Cli/Command/
    MigrateCommand.php            bookshelf db:migrate
    AddMemberCommand.php          bookshelf member:add
    ImportBooksCommand.php        now saves to the database (--dry-run keeps the old behavior)
  public/index.php                reads books from the database, saves suggestions there
```

### The schema

```sql
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
```

The `CHECK` constraints repeat rules the PHP classes already enforce. That is intentional: the database is the last
line of defense against bugs, other scripts, and manual fixes in a database console. Loan dates are **calendar dates**
(chapter 15), stored as `YYYY-MM-DD` text, which also sorts and compares correctly as text. Money is stored in integer
cents. The last index is a **partial index**: it covers only open loans, which is all the "how many books does this
member have" query looks at.

### Connection and transactions

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Persistence;

use PDO;
use Pdo\Sqlite;
use RuntimeException;
use Throwable;

/**
 * The application's database connection, and its transaction boundary.
 */
final readonly class Database
{
    public function __construct(
        public PDO $pdo,
    ) {
    }

    /** Opens (and creates, if needed) an SQLite database file, or ':memory:' for a throwaway one. */
    public static function sqlite(string $path): self
    {
        if ($path !== ':memory:') {
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException("Cannot create the database directory $directory");
            }
        }

        $pdo = new Sqlite('sqlite:' . $path, options: [
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,                                           // wait up to 5 s for a lock
            Sqlite::ATTR_TRANSACTION_MODE => Sqlite::TRANSACTION_MODE_IMMEDIATE,   // PHP 8.5+
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        if ($path !== ':memory:') {
            $pdo->exec('PRAGMA journal_mode = WAL');
        }

        return new self($pdo);
    }

    /** The database configured by the BOOKSHELF_DATABASE environment variable, or var/bookshelf.sqlite. */
    public static function fromEnvironment(string $projectRoot): self
    {
        $path = getenv('BOOKSHELF_DATABASE');

        return self::sqlite($path !== false && $path !== '' ? $path : $projectRoot . '/var/bookshelf.sqlite');
    }

    /**
     * Runs $work in a transaction: commits if it returns, rolls back if it throws.
     * Called inside another transaction, it simply joins it.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transactional(callable $work): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $work();
        }

        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
```

`transactional()` joins an existing transaction instead of starting a nested one (PDO cannot nest transactions;
`beginTransaction()` inside one throws). So a service method that runs in a transaction can call another that also
asks for one, and everything commits together. The `@template T` docblock tells static analysers (chapter 20) that
`transactional()` returns whatever the closure returns.

### Migrations

The `Migrator` applies the `.sql` files in `migrations/` that are not yet recorded, in version order, each in its own
transaction together with its `schema_migrations` row:

```php
public function migrate(): array
{
    $pdo = $this->database->pdo;
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        version    TEXT PRIMARY KEY,
        applied_at TEXT NOT NULL
    )');
    $done = array_flip($pdo->query('SELECT version FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN));

    $applied = [];
    foreach ($this->pending($done) as $version => $file) {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException("Cannot read $file");
        }
        $this->database->transactional(function () use ($pdo, $sql, $version): void {
            $pdo->exec($sql);
            $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)')
                ->execute([$version, gmdate('Y-m-d H:i:s')]);
        });
        $applied[] = basename($file);
    }

    return $applied;
}
```

If a migration fails halfway, its transaction rolls back both the schema change and the record, so it can be fixed
and run again.

### Loans and dates

`PdoLoanRepository` turns the date columns back into `DateTimeImmutable` objects at midnight in the **library's time
zone**, which it receives in its constructor, and writes them back as `Y-m-d`:

```php
private function date(string $value): DateTimeImmutable
{
    return DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->timezone);
}
```

Its `overdue()` query can compare dates as text, because `'2026-11-01' < '2026-11-22'` sorts the same way as the dates:

```php
public function overdue(DateTimeImmutable $today): array
{
    $statement = $this->pdo->prepare(
        'SELECT ' . self::COLUMNS . ' FROM loans WHERE returned_on IS NULL AND due_on < ? ORDER BY due_on, id',
    );
    $statement->execute([$today->setTimezone($this->timezone)->format('Y-m-d')]);

    return array_map($this->toLoan(...), $statement->fetchAll(PDO::FETCH_ASSOC));
}
```

### The lending service

The domain objects enforce the rules about one book or one loan (chapter 9: `lendCopy()` refuses when no copy is
left). Some rules need several objects, such as "a member may not exceed their loan limit", and every use case
changes several rows that must be saved together. That is the job of an **application service**:

```php
public function lend(int $bookId, int $memberId): Loan
{
    return $this->database->transactional(function () use ($bookId, $memberId): Loan {
        $book = $this->books->get($bookId);
        $member = $this->members->get($memberId);

        $openLoans = $this->loans->openLoansOf($memberId);
        if (count($openLoans) >= $member->type->maxLoans()) {
            throw LoanRuleViolation::loanLimitReached($member);
        }
        foreach ($openLoans as $open) {
            if ($open->bookId === $bookId) {
                throw LoanRuleViolation::alreadyBorrowed($member, $book);
            }
        }

        $book->lendCopy();
        $loan = Loan::start($book, $member, $this->clock->now());
        $this->books->save($book);
        $this->loans->save($loan);

        return $loan;
    });
}
```

Because the transaction is immediate, a second `lend()` for the same book on another connection waits until this one
commits, then reads the updated `available` count: the race described earlier cannot happen. `returnLoan()` and
`renew()` follow the same shape. The service depends only on interfaces and a `Clock` (chapter 15), so chapter 20 can
test it with an in-memory SQLite database and a frozen clock.

### Using it

The command-line tool gets `db:migrate` and `member:add`, and `import` now saves the books, 500 per transaction, and
skips ISBNs that are already in the catalog. The `bin/bookshelf` script opens the database and passes it to the
commands that need it:

```php
$root = dirname(__DIR__);
$database = Database::fromEnvironment($root);

$app = new Application(
    'bookshelf',
    new MigrateCommand(new Migrator($database, "$root/migrations")),
    new ImportBooksCommand(new CsvBookReader(), new PdoBookRepository($database->pdo), $database),
    new AddMemberCommand(new PdoMemberRepository($database->pdo)),
    new IsbnCommand(),
);
```

From `examples/bookshelf`:

```bash
php bin/bookshelf db:migrate
php bin/bookshelf db:migrate
php bin/bookshelf import data/books.csv
php bin/bookshelf member:add "Ada Lovelace" ada@example.org --type=premium
```

```text
applied 001_initial_schema.sql
applied 002_create_suggestions.sql
2 migrations applied
The database is up to date
10 books imported, 0 already in the catalog, 0 invalid lines skipped
1
Added Ada Lovelace as a Premium member with id 1
```

And a script that lends a book through the service, with a frozen clock so the dates are predictable:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Bookshelf\Domain\BookshelfException;
use Bookshelf\Domain\Isbn;
use Bookshelf\Lending\LendingService;
use Bookshelf\Persistence\Database;
use Bookshelf\Persistence\PdoBookRepository;
use Bookshelf\Persistence\PdoLoanRepository;
use Bookshelf\Persistence\PdoMemberRepository;
use Bookshelf\Support\FrozenClock;

$database = Database::fromEnvironment(__DIR__);
$timezone = new DateTimeZone('Europe/Berlin');
$clock = FrozenClock::at('2026-10-11 10:00', 'Europe/Berlin');

$books = new PdoBookRepository($database->pdo);
$members = new PdoMemberRepository($database->pdo);
$loans = new PdoLoanRepository($database->pdo, $timezone);
$lending = new LendingService($database, $books, $members, $loans, $clock);

$kindred = $books->findByIsbn(new Isbn('978-0-8070-8369-7'));   // one copy
$ada = $members->findByEmail('ada@example.org');

$loan = $lending->lend($kindred->id, $ada->id);
echo "loan {$loan->id}: due {$loan->dueOn->format('D j M')}\n";
echo 'copies left: ', $books->get($kindred->id)->available, "\n";

try {
    $lending->lend($kindred->id, $ada->id);
} catch (BookshelfException $e) {
    echo 'refused: ', $e->getMessage(), "\n";
}

$clock->advance('+45 days');
echo 'overdue loans: ', count($loans->overdue($clock->now())), "\n";
echo 'fine: ', $lending->returnLoan($loan->id), "\n";
echo 'copies left: ', $books->get($kindred->id)->available, "\n";
```

```text
loan 1: due Sun 22 Nov
copies left: 0
refused: Ada Lovelace already has a copy of 'Kindred'
overdue loans: 1
fine: $0.75
copies left: 1
```

Ada is a premium member, so her loan runs 42 days; returned 45 days after lending, it is three days late at 25 cents a
day. The web pages from chapter 16 now read the catalog with `PdoBookRepository::search()` and insert suggestions into
the `suggestions` table; run `db:migrate` and `import` before `composer serve`.

## Key takeaways

- PDO gives one API over many databases; each driver still speaks its own SQL. Connect with a DSN, credentials from the
  environment, `FETCH_ASSOC` as default; on MySQL add `charset=utf8mb4` and turn off emulated prepares.
- PHP 8.4+ has driver subclasses (`Pdo\Sqlite`, `Pdo\Pgsql`, ...) and `PDO::connect()`; 8.5 deprecates driver specifics
  on the base `PDO` class.
- **Every value goes through a placeholder.** Identifiers that depend on input come from an allow list. This is the
  complete defense against SQL injection.
- Pick the fetch mode that gives the shape you need; know that exact decimals come back as strings and that dates and
  JSON are strings to convert.
- `lastInsertId()` is a string; prefer `RETURNING` where available. `rowCount()` tells you whether an update matched.
- Handle constraint violations by SQLSTATE class (`23...`), and rely on `UNIQUE` constraints rather than prior checks.
- Use transactions for every multi-statement change, through one helper. They don't stop races on stale reads: use
  constraints, conditional updates, `FOR UPDATE`, or SQLite's immediate transactions.
- Manage schema changes with numbered, never-edited migrations.
- Repositories keep SQL out of the domain; hydrate through constructors. Watch for N+1 queries and add indexes for the
  queries you run.

## Exercises

1. **Load many by id.** Add `findByIds(array $ids): array` to a book repository that returns the books with the given
   ids, keyed by id, in **one** query, using one placeholder per id. Make it return `[]` without querying for an empty
   list, and remove duplicate ids.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

/**
 * @param list<int> $ids
 * @return array<int, array<string, mixed>>
 */
function findByIds(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique($ids));
    if ($ids === []) {
        return [];                                      // "IN ()" is a syntax error
    }

    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $statement = $pdo->prepare("SELECT id, title, author FROM books WHERE id IN ($placeholders)");
    $statement->execute($ids);

    return $statement->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
}

$pdo = new PDO('sqlite:' . __DIR__ . '/library.sqlite');
print_r(findByIds($pdo, [4, 1, 4, 99]));
var_dump(findByIds($pdo, []));
```

```text
Array
(
    [1] => Array
        (
            [title] => Dune
            [author] => Frank Herbert
        )

    [4] => Array
        (
            [title] => Persuasion
            [author] => Jane Austen
        )

)
array(0) {
}
```

Only the **placeholders** are generated in code (a fixed string of `?`); the ids still travel as parameters. With
`FETCH_UNIQUE` the first column becomes the key, which is exactly what you need to fix an N+1 loop:
`$books = findByIds($pdo, array_column($loans, 'book_id'));` and then `$books[$loan['book_id']]` inside the loop. Very
long lists (thousands of ids) hit placeholder limits; chunk them, or use a join instead.

</details>

2. **Sortable catalog.** Write `listBooks(PDO $pdo, string $sort, string $direction): array` that sorts by `title`,
   `author` or `year` and in `asc` or `desc` order, as chosen by query parameters such as `?sort=year&dir=desc`. Any
   other input falls back to title ascending. Explain why a placeholder cannot be used here.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

/** @return list<string> */
function listBooks(PDO $pdo, string $sort, string $direction): array
{
    $column = ['title' => 'title', 'author' => 'author, title', 'year' => 'year'][$sort] ?? 'title';
    $order = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';

    return $pdo->query("SELECT title FROM books ORDER BY $column $order, id")->fetchAll(PDO::FETCH_COLUMN);
}

$pdo = new PDO('sqlite:' . __DIR__ . '/library.sqlite');
echo implode(', ', listBooks($pdo, 'year', 'desc')), "\n";
echo implode(', ', listBooks($pdo, 'title; DROP TABLE books', 'sideways')), "\n";
```

```text
Beloved, Kindred, Dune, Silent Spring, The Hobbit, Middlemarch, Persuasion, Emma
Beloved, Dune, Emma, Kindred, Middlemarch, Persuasion, Silent Spring, The Hobbit
```

(The outputs include the books added earlier in the chapter.) If the column can be `NULL`, decide where those rows go:
SQLite and MySQL sort `NULL` first in ascending order, PostgreSQL sorts it last, and `ORDER BY year DESC NULLS LAST`
(SQLite 3.30+, PostgreSQL) makes it explicit.

A placeholder is always a **value**: `ORDER BY ?` would sort by a constant string, the same for every row, so it
would not sort at all. The column and direction are therefore written into the SQL, which is safe only because they
come from the allow list in code; the user's text never reaches the query. The trailing `, id` makes the order
deterministic when titles or years are equal.

</details>

3. **Lending without a race.** Without reading `available` first, write `takeCopy(PDO $pdo, int $bookId): bool` that
   decrements the available count only if a copy is left, in a single statement, and returns whether it succeeded.
   Call it three times for a book with two copies.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

function takeCopy(PDO $pdo, int $bookId): bool
{
    $statement = $pdo->prepare('UPDATE books SET available = available - 1 WHERE id = ? AND available > 0');
    $statement->execute([$bookId]);

    return $statement->rowCount() === 1;
}

$pdo = new PDO('sqlite:' . __DIR__ . '/library.sqlite');
$pdo->exec('UPDATE books SET available = 2 WHERE id = 5');   // The Hobbit: two copies

foreach ([1, 2, 3] as $attempt) {
    echo "attempt $attempt: ", takeCopy($pdo, 5) ? 'got a copy' : 'none left', "\n";
}
```

```text
attempt 1: got a copy
attempt 2: got a copy
attempt 3: none left
```

The check (`available > 0`) and the change happen in one statement, which the database executes atomically: two
concurrent calls cannot both see the last copy, because the second one's `WHERE` is evaluated after the first one's
update. `rowCount()` reports whether the row matched. This is the cheapest correct solution whenever the decision can be
expressed in SQL.

</details>

4. **An overdue report without N+1.** Write one SQL query (run through PDO) that lists every open loan due before a
   given date with the member's name and e-mail and the book's title, most overdue first, plus the number of days
   overdue. Test it against a Bookshelf database after running the lending script above without the return.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Bookshelf\Persistence\Database;

$database = Database::fromEnvironment(__DIR__);

$statement = $database->pdo->prepare(<<<'SQL'
    SELECT members.name, members.email, books.title, loans.due_on,
           CAST(julianday(:today) - julianday(loans.due_on) AS INTEGER) AS days_overdue
    FROM loans
    JOIN members ON members.id = loans.member_id
    JOIN books ON books.id = loans.book_id
    WHERE loans.returned_on IS NULL AND loans.due_on < :today
    ORDER BY loans.due_on, loans.id
    SQL);
$statement->execute(['today' => '2026-11-25']);

foreach ($statement as $row) {
    printf("%-14s %-18s %-10s %d days\n", $row['name'], $row['email'], $row['title'], $row['days_overdue']);
}
```

```text
Ada Lovelace   ada@example.org    Kindred    3 days
```

One query returns everything the report shows, however many loans there are. The repository's `overdue()` method is
still the right tool when the code needs `Loan` objects to call methods on; a read-only report like this one is better
served by a query written for it, returning plain rows. `julianday()` is SQLite's way to turn a date into a day number;
in PostgreSQL the same column is `(:today::date - loans.due_on)`.

</details>

5. **Keyset pagination.** `LIMIT 20 OFFSET 10000` makes the database read and discard 10,000 rows, and rows shift
   between pages when books are added. Write `nextPage(PDO $pdo, ?string $afterTitle, ?int $afterId, int $size)` that
   returns the next `$size` books ordered by `(title, id)` after the given position, and print all pages of size 3.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

/** @return list<array{id: int, title: string}> */
function nextPage(PDO $pdo, ?string $afterTitle, ?int $afterId, int $size): array
{
    if ($afterTitle === null) {
        $statement = $pdo->prepare('SELECT id, title FROM books ORDER BY title, id LIMIT :size');
    } else {
        $statement = $pdo->prepare(
            'SELECT id, title FROM books WHERE (title, id) > (:title, :id) ORDER BY title, id LIMIT :size',
        );
        $statement->bindValue('title', $afterTitle);
        $statement->bindValue('id', $afterId, PDO::PARAM_INT);
    }
    $statement->bindValue('size', $size, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

$pdo = new PDO('sqlite:' . __DIR__ . '/library.sqlite');
$title = $id = null;
$page = 1;
while (($rows = nextPage($pdo, $title, $id, 3)) !== []) {
    echo "page $page: ", implode(', ', array_column($rows, 'title')), "\n";
    $last = end($rows);
    [$title, $id] = [$last['title'], $last['id']];
    $page++;
}
```

```text
page 1: Beloved, Dune, Emma
page 2: Kindred, Middlemarch, Persuasion
page 3: Silent Spring, The Hobbit
```

The page is defined by "after this title and id" rather than "skip N rows", so with an index on `(title, id)` the
database jumps straight to the right place, and page 400 is as fast as page 1. The row-value comparison
`(title, id) > (?, ?)` works in SQLite 3.15+, PostgreSQL and MySQL; the `id` breaks ties between equal titles. The
trade-off: you can go to the next page, but not jump to page 37. In a web page, the position travels in the "next" link,
for example `?after=Kindred&id=2`.

</details>

---
← Previous: [PHP on the web](16-php-on-the-web.md) | Next: [Security](18-security.md) →
