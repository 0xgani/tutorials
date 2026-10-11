# Chapter 14 — Files, streams and the command line

## What you'll learn

- Reading and writing files, from one-line helpers to file handles, and how file functions report errors
- Writing files safely: atomic replacement, locking, and temporary files
- Paths and directories, and the stat cache
- Streams and wrappers: `php://stdin`, `php://memory`, `php://temp`, `data:`, `compress.zlib://`, filters and contexts
- JSON done properly: flags, `JSON_THROW_ON_ERROR`, the array-versus-object problem, and validating decoded data
- CSV: the `escape` parameter (and why PHP 8.4 deprecates leaving it out), byte-order marks and formula injection
- Writing command-line programs: `$argv`, `getopt()`, standard streams, exit codes, running other programs
- Bookshelf's `bin/bookshelf` command-line tool

## Files the simple way

Two functions cover most small files. `file_get_contents()` reads a whole file into a string, and
`file_put_contents()` writes a string to a file, creating it or replacing its contents:

```php
<?php

declare(strict_types=1);

$path = sys_get_temp_dir() . '/notes.txt';

$bytes = file_put_contents($path, "Dune\nEmma\n");
file_put_contents($path, "Kindred\n", FILE_APPEND | LOCK_EX);

echo "wrote $bytes bytes, then appended\n";
echo file_get_contents($path);

// file() returns the lines as an array.
print_r(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
```

```text
wrote 10 bytes, then appended
Dune
Emma
Kindred
Array
(
    [0] => Dune
    [1] => Emma
    [2] => Kindred
)
```

- `FILE_APPEND` adds to the end instead of replacing. `LOCK_EX` takes an exclusive lock while writing, so two requests
  appending to the same log at the same moment do not interleave their bytes.
- `file()` keeps each line's `"\n"` unless you pass `FILE_IGNORE_NEW_LINES`.
- All three read or build the **whole file in memory**. That is fine for configuration files and templates. For files
  that can grow without limit (logs, imports, exports), use a file handle and read line by line, as below and in
  chapter 13.

### How file functions report errors

File functions come from PHP's early days: on failure they return `false` **and** emit a warning. They do not throw:

```php
<?php

declare(strict_types=1);

$contents = file_get_contents('/no/such/file.txt');
var_dump($contents);
echo "...and the script carries on\n";
```

```text
Warning: file_get_contents(/no/such/file.txt): Failed to open stream: No such file or directory in demo.php on line 5
bool(false)
...and the script carries on
```

If you ignore the `false`, it flows into the rest of your code as an empty string or worse. So **always check the
return value** and turn failures into exceptions at the point where they happen. The idiom is to silence the warning
with `@` (you are handling the failure yourself) and use `error_get_last()` for the reason:

```php
<?php

declare(strict_types=1);

function readFileOrFail(string $path): string
{
    $contents = @file_get_contents($path);
    if ($contents === false) {
        $reason = error_get_last()['message'] ?? 'unknown error';
        throw new RuntimeException("Cannot read $path: $reason");
    }

    return $contents;
}

try {
    readFileOrFail('/no/such/file.txt');
} catch (RuntimeException $e) {
    echo $e->getMessage(), "\n";
}
```

```text
Cannot read /no/such/file.txt: file_get_contents(/no/such/file.txt): Failed to open stream: No such file or directory
```

The other approach, from chapter 8, is an error handler that converts every warning into an `ErrorException`. Frameworks
install one, and then `file_get_contents()` on a missing file throws by itself. In a plain script, wrap the calls as
above. Either way, the rule is the same: a failed file operation must never pass silently.

Do not "check first" with `file_exists()` and then read. Between the check and the read another process can delete or
replace the file (a **race condition**, often called TOCTOU: time of check to time of use), and the read still has to
handle failure anyway. Just try the operation and handle the result.

## File handles

For anything larger than a small file, open a **handle** with `fopen()`, read or write in pieces, and close it. A handle
is a `resource` value, one of PHP's oldest types; there is no class for it.

```php
<?php

declare(strict_types=1);

$path = sys_get_temp_dir() . '/titles.txt';

$out = fopen($path, 'w');
foreach (['Dune', 'Emma', 'Kindred'] as $title) {
    fwrite($out, $title . "\n");
}
fclose($out);

$in = fopen($path, 'r');
try {
    while (($line = fgets($in)) !== false) {
        echo strlen($line), ' bytes: ', rtrim($line, "\r\n"), "\n";
    }
} finally {
    fclose($in);
}
```

```text
5 bytes: Dune
5 bytes: Emma
8 bytes: Kindred
```

`fgets()` returns one line **including** its line ending, or `false` at the end of the file. `rtrim($line, "\r\n")`
removes both Unix (`\n`) and Windows (`\r\n`) endings. The `try`/`finally` closes the handle even if processing throws;
PHP also closes handles automatically when the request ends, but long-running programs (workers, chapter 22) can run
out of file descriptors if they leak them.

The **mode** string says what you intend to do:

| Mode | Reads | Writes | If the file exists | If it doesn't | Position |
|---|---|---|---|---|---|
| `r` | yes | | open it | fail | start |
| `r+` | yes | yes | open it | fail | start |
| `w` | | yes | **truncate to 0 bytes** | create | start |
| `w+` | yes | yes | **truncate** | create | start |
| `a` | | yes | open it | create | always appends at the end |
| `a+` | yes | yes | open it | create | reads anywhere, writes at the end |
| `x` | | yes | **fail** | create | start |
| `x+` | yes | yes | **fail** | create | start |
| `c` | | yes | open it, don't truncate | create | start |
| `c+` | yes | yes | open it, don't truncate | create | start |

Two of these are worth remembering. `x` creates a file only if it does not exist yet, atomically, which is the safe way
to claim a file name (two processes cannot both succeed). `c` opens for writing **without** truncating, which you need
when you want to lock a file first and truncate it only once you hold the lock. You may see a `b` suffix (`rb`, `wb`):
it means "binary, no line-ending translation". It matters only on Windows and is harmless elsewhere, so portable code
often adds it.

The other handle functions:

| Function | Does |
|---|---|
| `fread($h, $length)` | Reads up to `$length` bytes (use for binary data) |
| `fwrite($h, $data)` | Writes; returns the number of bytes written, or `false` |
| `fgets($h)` | Reads one line |
| `fgetc($h)` | Reads one character (byte) |
| `feof($h)` | `true` once a read has hit the end |
| `ftell($h)`, `fseek($h, $offset, SEEK_SET)`, `rewind($h)` | Get or move the position |
| `ftruncate($h, $size)` | Cuts the file to `$size` bytes |
| `fflush($h)` | Pushes buffered writes to the operating system |
| `stream_get_contents($h)` | Reads everything that is left |

A classic mistake is `while (!feof($h)) { $line = fgets($h); ... }`. `feof()` becomes `true` only *after* a read fails,
so the last iteration gets `false` from `fgets()` and processes it as a line. Loop on the read itself, as above.

## Writing files safely

### Atomic replacement

Suppose a program rewrites `catalog.json` with `file_put_contents()`. For a short moment the file is **empty or
half-written**: `w` mode truncates first, then writes. A request that reads the file during that moment sees broken
JSON. If the program crashes or the disk fills up halfway, the file stays broken.

The fix is to write the new contents to a temporary file **in the same directory**, then `rename()` it over the old
one. On Linux and macOS, renaming within one file system is atomic: readers see either the complete old file or the
complete new file, never anything in between.

```php
<?php

declare(strict_types=1);

function writeAtomically(string $path, string $contents): void
{
    $temporary = tempnam(dirname($path), '.tmp-');
    if ($temporary === false) {
        throw new RuntimeException('Cannot create a temporary file next to ' . $path);
    }

    try {
        if (file_put_contents($temporary, $contents) !== strlen($contents)) {
            throw new RuntimeException("Could not write all of $temporary");
        }
        chmod($temporary, 0644);
        if (!rename($temporary, $path)) {
            throw new RuntimeException("Cannot move $temporary to $path");
        }
    } catch (Throwable $e) {
        @unlink($temporary);
        throw $e;
    }
}

$path = sys_get_temp_dir() . '/catalog.json';
writeAtomically($path, '{"books": 10}');
echo file_get_contents($path), "\n";
```

```text
{"books": 10}
```

- `tempnam($dir, $prefix)` creates an empty file with a unique name and returns its path. It creates the file with
  permissions `0600` (only the owner can read it), hence the `chmod()`.
- The temporary file must be in the **same directory** (strictly, the same file system). A rename across file systems,
  for example from `/tmp` to a mounted volume, becomes a copy and is no longer atomic.
- The `catch` removes the temporary file if anything fails, so failed writes don't leave litter.

Use this for any file that other processes read while you write it: generated configuration, caches, exported data.

### Locking

When several processes **update** the same file (read, change, write back), atomic replacement alone is not enough:
two processes can read the same old version, and the second write silently discards the first one's change. A lock
makes them take turns:

```php
<?php

declare(strict_types=1);

/** Adds one to a counter stored in a file, safely across processes. */
function increment(string $path): int
{
    $h = fopen($path, 'c+');   // create if missing, don't truncate yet
    if ($h === false) {
        throw new RuntimeException("Cannot open $path");
    }

    try {
        flock($h, LOCK_EX);    // waits until no other process holds the lock
        $count = (int) stream_get_contents($h) + 1;
        ftruncate($h, 0);
        rewind($h);
        fwrite($h, (string) $count);
        fflush($h);
        return $count;
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
}

$path = sys_get_temp_dir() . '/visits.txt';
@unlink($path);
increment($path);
increment($path);
echo increment($path), "\n";
```

```text
3
```

`flock()` locks are **advisory**: they only work if every program touching the file also calls `flock()`. `LOCK_SH`
is a shared lock for readers (many can hold it at once), `LOCK_EX` an exclusive one for writers, and adding
`LOCK_NB` (`LOCK_EX | LOCK_NB`) makes `flock()` return `false` immediately instead of waiting. Locks may not work on
network file systems such as NFS. In practice, once data is updated concurrently, it belongs in a database
(chapter 17), which solves this properly with transactions. File locks remain useful for small jobs, for example making
sure only one copy of a cron job runs at a time.

### Temporary files

| Function | Gives you | Cleaned up |
|---|---|---|
| `sys_get_temp_dir()` | The system's temporary directory, usually `/tmp` | |
| `tempnam($dir, $prefix)` | A unique, empty file's path | By you, with `unlink()` |
| `tmpfile()` | An open handle to a file with no name | Automatically when closed or at the end of the script |
| `fopen('php://temp', 'w+')` | A handle that stays in memory and spills to disk past 2 MiB | Automatically |

## Paths and directories

### Building paths

Always build paths from a known base, not from the current working directory. The current directory is wherever the
user happened to be when they ran the program (or, under a web server, whatever the server chose), so relative paths
such as `'data/books.csv'` break as soon as someone runs the program from elsewhere.

`__DIR__` is the directory of the file that contains it, so it is the reliable anchor:

```php
<?php

declare(strict_types=1);

$path = '/srv/bookshelf/data/books.tar.gz';

echo dirname($path), "\n";                       // the directory part
echo dirname('/srv/bookshelf/src/Cli/Input.php', 2), "\n";   // two levels up
echo basename($path), "\n";                      // the file name
echo basename('/srv/bookshelf/data/books.csv', '.csv'), "\n";
print_r(pathinfo($path));

// From a file in src/Cli/, the project root is two levels up:
$root = dirname(__DIR__, 2);
```

```text
/srv/bookshelf/data
/srv/bookshelf/src
books.tar.gz
books
Array
(
    [dirname] => /srv/bookshelf/data
    [basename] => books.tar.gz
    [extension] => gz
    [filename] => books.tar
)
```

Forward slashes work on every operating system PHP runs on, including Windows, so you rarely need the
`DIRECTORY_SEPARATOR` constant except when displaying paths to Windows users.

`realpath($path)` resolves `.`, `..` and symbolic links into an absolute path, and returns `false` if the path does not
exist. It is the tool for keeping user-supplied file names **inside** a directory, which prevents *path traversal*
attacks such as asking for `../../etc/passwd`:

```php
<?php

declare(strict_types=1);

function coverPath(string $baseDir, string $fileName): string
{
    $base = realpath($baseDir);
    $full = realpath($baseDir . '/' . $fileName);
    if ($base === false || $full === false || !str_starts_with($full, $base . '/')) {
        throw new InvalidArgumentException("No such cover: $fileName");
    }

    return $full;
}
```

Chapter 18 returns to this, and to the even safer option of never using user input in paths at all.

### Working with directories

```php
<?php

declare(strict_types=1);

$dir = sys_get_temp_dir() . '/bookshelf-demo/exports/2026';

// Create a directory and any missing parents. The second is_dir() handles a race:
// another process may create it between our check and our mkdir().
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    throw new RuntimeException("Cannot create $dir");
}

foreach (['a.csv', 'b.csv', 'notes.txt'] as $name) {
    touch("$dir/$name");
}

print_r(array_map(basename(...), glob("$dir/*.csv")));
print_r(array_values(array_diff(scandir($dir), ['.', '..'])));

foreach (new FilesystemIterator($dir) as $file) {   // SplFileInfo objects, no "." or ".."
    printf("%-10s %d bytes\n", $file->getFilename(), $file->getSize());
}
```

```text
Array
(
    [0] => a.csv
    [1] => b.csv
)
Array
(
    [0] => a.csv
    [1] => b.csv
    [2] => notes.txt
)
a.csv      0 bytes
b.csv      0 bytes
notes.txt  0 bytes
```

(`FilesystemIterator` returns files in the order the file system stores them, which is not necessarily alphabetical;
`glob()` and `scandir()` sort by name.)

The rest of the toolbox:

| Function | Does |
|---|---|
| `file_exists()`, `is_file()`, `is_dir()`, `is_readable()`, `is_writable()` | Tests |
| `filesize()`, `filemtime()`, `fileperms()`, `stat()` | Metadata |
| `copy($from, $to)`, `rename($from, $to)`, `unlink($file)` | Copy, move or rename, delete a file |
| `rmdir($dir)` | Delete an **empty** directory |
| `chmod($path, 0644)`, `chown()`, `chgrp()` | Permissions and ownership (note the leading `0`: the mode is octal) |
| `RecursiveDirectoryIterator` | Walk a tree (chapter 13) |

To delete a directory tree, walk it with `RecursiveIteratorIterator::CHILD_FIRST` so that files are deleted before
their directories, or, more simply, use a library such as `symfony/filesystem`.

### The stat cache

PHP caches the results of `stat()`-based functions (`filesize()`, `filemtime()`, `is_file()` and friends) for each path
during a request. If a file changes while your script runs, for example because you appended to it with a handle,
`filesize()` can return the old value. Call `clearstatcache()` (or `clearstatcache(true, $path)` for one file) before
checking again. Long-running processes that poll files are the usual victims.

## Streams and wrappers

A file handle is one kind of **stream**. A stream is anything you can read or write in sequence with the same `f*`
functions: a file, a network connection, a block of memory, a compressed file, the process's standard input. The
prefix before `://` picks the **wrapper** that implements it:

| Wrapper | Example | What it is |
|---|---|---|
| `file://` (the default) | `/srv/data/books.csv` | The local file system |
| `php://stdin`, `php://stdout`, `php://stderr` | | The process's standard streams |
| `php://input` | | The raw body of an HTTP request (chapter 16) |
| `php://output` | | The same output buffer that `echo` writes to |
| `php://memory` | | A buffer in memory |
| `php://temp` | `php://temp/maxmemory:1048576` | In memory, moving to a temporary file past a limit (default 2 MiB) |
| `php://filter` | `php://filter/read=string.toupper/resource=...` | Applies filters to another stream |
| `data:` | `data://text/plain;base64,SGVsbG8=` | Inline data (RFC 2397) |
| `compress.zlib://` | `compress.zlib:///srv/books.csv.gz` | Transparent gzip compression |
| `http://`, `https://`, `ftp://` | | Network resources (when `allow_url_fopen` is on) |
| `phar://`, `zip://` | `zip://archive.zip#books.csv` | Files inside archives |

Because every function that takes a file name accepts a stream URL, code written for files works unchanged on other
sources. `stream_get_wrappers()` lists the ones your PHP has.

```php
<?php

declare(strict_types=1);

// In-memory streams: perfect for building output or for tests.
$buffer = fopen('php://memory', 'w+');
fwrite($buffer, "Dune\nEmma\n");
rewind($buffer);
echo stream_get_contents($buffer);

// Inline data, and a filter applied while reading.
echo file_get_contents('data://text/plain;base64,SGVsbG8='), "\n";
echo file_get_contents('php://filter/read=string.toupper/resource=data://text/plain,hello'), "\n";

// Gzip compression without any extra code.
$gz = sys_get_temp_dir() . '/titles.txt.gz';
file_put_contents('compress.zlib://' . $gz, str_repeat("Dune\nEmma\n", 1000));
printf("%d bytes on disk, %d bytes of text\n", filesize($gz), strlen(file_get_contents('compress.zlib://' . $gz)));
```

```text
Dune
Emma
Hello
HELLO
74 bytes on disk, 10000 bytes of text
```

Ten thousand bytes of very repetitive text compress to a few dozen.

> **Just a taste — you don't need to learn or fully understand this now.** Filters can also be attached to an open
> handle, and `stream_copy_to_stream()` pipes one stream into another in chunks without loading it into memory.

```php
<?php

$in = fopen('compress.zlib://' . sys_get_temp_dir() . '/titles.txt.gz', 'r');
stream_filter_append($in, 'string.toupper');
stream_copy_to_stream($in, STDOUT, 10);   // the first 10 bytes: "DUNE\nEMMA\n"
```

### Reading URLs, and stream contexts

With `allow_url_fopen` enabled (the default), `file_get_contents()` can fetch a URL. A **stream context** passes
options to the wrapper, such as the method, headers and a timeout:

> **Just a taste — you don't need to learn or fully understand this now.** This shows that HTTP works through the same
> functions. Real applications use an HTTP client library such as Guzzle or `symfony/http-client` (chapter 23), which
> handles retries, errors and concurrency properly.

```php
<?php

declare(strict_types=1);

$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Accept: application/json\r\nUser-Agent: bookshelf/1.0\r\n",
        'timeout' => 5,             // seconds; the default is 60
        'ignore_errors' => true,    // return the body of 4xx/5xx responses instead of false
    ],
]);

$body = file_get_contents('https://openlibrary.org/isbn/9780441013593.json', false, $context);
$headers = http_get_last_response_headers();   // PHP 8.4+; e.g. ['HTTP/1.1 200 OK', 'Content-Type: ...', ...]
```

Older code reads the magic `$http_response_header` variable instead, which PHP 8.5 deprecates in favor of
`http_get_last_response_headers()`. Always set a `timeout`: without one, a slow server can hold your request for a
minute.

### `SplFileObject`

`SplFileObject` wraps a handle in an object that you can iterate with `foreach` (one line per iteration) and that closes
itself when destroyed:

```php
<?php

declare(strict_types=1);

$path = sys_get_temp_dir() . '/titles.txt';
file_put_contents($path, "Dune\nEmma\n\nKindred\n");

$file = new SplFileObject($path);
$file->setFlags(SplFileObject::DROP_NEW_LINE | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);
foreach ($file as $number => $line) {
    echo $number + 1, ": $line\n";
}
```

```text
1: Dune
2: Emma
3: Kindred
```

With `SKIP_EMPTY`, the key counts only the lines that are returned, so it is **not** the line number in the file once
lines are skipped (Kindred is on line 4). When you need real line numbers, for error messages, count them yourself as
`CsvBookReader` does. Add the `READ_CSV` flag and
each line arrives as an array of CSV fields (call `setCsvControl(escape: '')` first, for the reasons below).

## JSON

JSON is how PHP talks to browsers, APIs, queues and other programs, so it is worth knowing the details.

### Encoding

```php
<?php

declare(strict_types=1);

$book = ['title' => 'Café Society', 'url' => 'https://example.org/b/1', 'price' => 10.0, 'tags' => []];

echo json_encode($book), "\n";
echo json_encode($book, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), "\n";
echo json_encode(['Dune', 'Emma']), ' ', json_encode([1 => 'Dune', 2 => 'Emma']), "\n";
echo json_encode(['tags' => [], 'meta' => new stdClass()]), "\n";
echo json_encode(['id' => 7, 'title' => 'Dune'], JSON_PRETTY_PRINT), "\n";
```

```text
{"title":"Caf\u00e9 Society","url":"https:\/\/example.org\/b\/1","price":10,"tags":[]}
{"title":"Café Society","url":"https://example.org/b/1","price":10.0,"tags":[]}
["Dune","Emma"] {"1":"Dune","2":"Emma"}
{"tags":[],"meta":{}}
{
    "id": 7,
    "title": "Dune"
}
```

What to notice:

- An array becomes a JSON **array** only if it is a list (keys `0, 1, 2, ...` in order, chapter 5). Any other keys turn
  it into a JSON **object**. So `array_filter()` output, which keeps the original keys, can turn a list into an object
  and break a JavaScript client that expects an array. Pass lists through `array_values()` before encoding.
- An empty array is always `[]`. When a client expects an empty **object**, send `new stdClass()` (or use the
  `JSON_FORCE_OBJECT` flag, which affects every array).
- By default `/` and non-ASCII characters are escaped. Both forms are valid JSON; `JSON_UNESCAPED_SLASHES` and
  `JSON_UNESCAPED_UNICODE` just produce shorter, more readable output.
- `10.0` becomes `10` unless you pass `JSON_PRESERVE_ZERO_FRACTION`.
- Objects are encoded from their **public** properties, or from `jsonSerialize()` if they implement
  `JsonSerializable` (chapter 10). Bookshelf's `Money` encodes as its cents and `Isbn` as its digits.

### Errors: always use `JSON_THROW_ON_ERROR`

By default, `json_encode()` and `json_decode()` report errors by returning `false` or `null` and setting a global
error you have to fetch with `json_last_error_msg()`. For `json_decode()`, `null` is also the correct result of
decoding the text `null`, so you can't even tell a failure from a success by the return value. Pass
`JSON_THROW_ON_ERROR` every time, and failures become a `JsonException`:

```php
<?php

declare(strict_types=1);

var_dump(json_decode('{title: "Dune"}'));     // invalid JSON: keys must be quoted
echo json_last_error_msg(), "\n";

try {
    json_decode('{title: "Dune"}', flags: JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    json_encode("caf\xE9", JSON_THROW_ON_ERROR);   // Latin-1 bytes, not UTF-8
} catch (JsonException $e) {
    echo $e->getMessage(), "\n";
}

var_dump(json_validate('{"title": "Dune"}'), json_validate('{title: "Dune"}'));   // PHP 8.3+
```

```text
NULL
Syntax error
JsonException: Syntax error
Malformed UTF-8 characters, possibly incorrectly encoded
bool(true)
bool(false)
```

JSON must be UTF-8, so `json_encode()` refuses invalid byte sequences. That usually means some text came from a source
with another encoding (an old database, a Windows file); convert it with `mb_convert_encoding()` at that boundary
(chapter 4). `JSON_INVALID_UTF8_SUBSTITUTE` replaces bad bytes with `U+FFFD` instead of failing, for logs and other
places where losing a character is better than losing the message. `json_validate()` (PHP 8.3+) checks syntax without
building the value, which is cheaper when you only need a yes or no.

### Decoding: objects or arrays

```php
<?php

declare(strict_types=1);

$json = '{"title": "Dune", "year": 1965, "authors": [{"name": "Frank Herbert"}]}';

$asObject = json_decode($json, flags: JSON_THROW_ON_ERROR);
echo $asObject->title, ' by ', $asObject->authors[0]->name, "\n";   // stdClass objects

$asArray = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
echo $asArray['title'], ' by ', $asArray['authors'][0]['name'], "\n";   // nested arrays

var_dump(json_decode('12345678901234567890'));
var_dump(json_decode('12345678901234567890', flags: JSON_BIGINT_AS_STRING));
```

```text
Dune by Frank Herbert
Dune by Frank Herbert
float(1.2345678901234567E+19)
string(20) "12345678901234567890"
```

- The second argument, `true`, decodes objects into **associative arrays**. Most code uses arrays, because the array
  functions work on them and they make it easy to check whether a key exists.
- The third argument is the maximum nesting **depth** (default 512). Deeper input fails with "Maximum stack depth
  exceeded", which protects against maliciously nested documents.
- Integers too large for PHP's `int` become floats and lose precision. IDs from systems that use 64-bit unsigned or
  arbitrary-precision numbers need `JSON_BIGINT_AS_STRING`. (JavaScript has the same problem past 2^53, which is why
  many APIs send big IDs as strings.)

### Decoded JSON is untrusted input

`json_decode()` tells you the text was valid JSON, not that it has the shape you expect. A client can send `"year":
"soon"`, leave out `title`, or send an array where you expect an object. Validate at the boundary and convert into
your own types immediately, just as the CSV importer did in chapter 13:

```php
<?php

declare(strict_types=1);

/**
 * @return array{title: string, year: ?int}
 */
function bookFromJson(string $json): array
{
    $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);

    if (!is_array($data) || !is_string($data['title'] ?? null) || trim($data['title']) === '') {
        throw new InvalidArgumentException('"title" must be a non-empty string');
    }
    $year = $data['year'] ?? null;
    if ($year !== null && !is_int($year)) {
        throw new InvalidArgumentException('"year" must be an integer or null');
    }

    return ['title' => trim($data['title']), 'year' => $year];
}

print_r(bookFromJson('{"title": " Dune ", "year": 1965}'));

foreach (['{"title": "Emma", "year": "1815"}', '["Dune"]', '{"title": '] as $bad) {
    try {
        bookFromJson($bad);
    } catch (InvalidArgumentException | JsonException $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
```

```text
Array
(
    [title] => Dune
    [year] => 1965
)
InvalidArgumentException: "year" must be an integer or null
InvalidArgumentException: "title" must be a non-empty string
JsonException: Syntax error
```

For larger APIs, writing these checks by hand gets tedious. Libraries such as `symfony/serializer`, `cuyz/valinor` or
a framework's validator map JSON onto typed objects and report every problem at once; chapter 19 builds a small version
for Bookshelf's API.

### JSON Lines

For logs and large exports, one JSON array holding everything has to be built (and parsed) in one piece. **JSON
Lines** (`.jsonl`) puts one JSON value per line instead, so files can be appended to and read line by line with a
generator. Encode each record without `JSON_PRETTY_PRINT` (which would add line breaks) and write it followed by
`"\n"`. Exercise 4 builds a reader.

## CSV

`fgetcsv()` reads one CSV record from a handle, `fputcsv()` writes one, and `str_getcsv()` parses a string. All three
take a separator (default `,`), an enclosure (default `"`) and an **escape** character.

### The `escape` parameter

Standard CSV (RFC 4180, and what Excel, LibreOffice and every database export use) has exactly one escaping rule: inside
a quoted field, a quote is written twice (`""`). PHP's CSV functions add a second, non-standard mechanism: by default,
a backslash also "escapes" a quote. Most of the time you don't notice, but data containing `\"` comes out corrupted
when the other side follows the standard:

```php
<?php

declare(strict_types=1);

$row = ['He said \"no\"', 'Dune'];

foreach (['\\', ''] as $escape) {
    $h = fopen('php://memory', 'w+');
    fputcsv($h, $row, escape: $escape);
    rewind($h);
    $line = rtrim(stream_get_contents($h));
    $readBack = str_getcsv($line, escape: '');   // how a standard CSV parser sees it
    echo "escape '$escape': $line\n";
    echo '  read back: ', implode(' | ', $readBack), "\n";
}
```

```text
escape '\': "He said \"no\"",Dune
  read back: He said \no\"" | Dune
escape '': "He said \""no\""",Dune
  read back: He said \"no\" | Dune
```

With the default backslash escape, PHP wrote `\"` without doubling the quote, and a standard parser reads garbage. With
`escape: ''` the mechanism is turned off and the output is standard CSV.

Because the default is a trap, **PHP 8.4 deprecates calling `fgetcsv()`, `fputcsv()` and `str_getcsv()` without
passing `$escape` explicitly**, as a first step towards changing the default:

```text
Deprecated: fputcsv(): the $escape parameter must be provided as its default value will change in demo.php on line 5
```

The rule is simple: **always pass `escape: ''`**, as every example in this tutorial does. The named argument lets you
skip the separator and enclosure.

### Byte-order marks and separators

When Excel saves "CSV UTF-8", it starts the file with a **byte-order mark** (BOM), the three bytes `EF BB BF`. Your
reader must remove it, or the first column name becomes `"\xEF\xBB\xBFtitle"` and a header check fails. Bookshelf's
`CsvBookReader` does this (chapter 13). Conversely, when you **write** a CSV file meant to be opened in Excel, write a
BOM first, or Excel will guess a legacy encoding and show `CafÃ©` instead of `Café`:

```php
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['title', 'author'], escape: '');
```

Excel in many European locales also expects `;` as the separator, because `,` is the decimal separator there. Be
liberal in what you read: if a user uploads semicolon-separated files, detect the separator from the header line.

### CSV formula injection

Spreadsheet programs treat a cell that begins with `=`, `+`, `-` or `@` as a **formula**. If your application exports
user-supplied data to CSV, a member who names themselves `=HYPERLINK("https://evil.example","Click")` gets a live link,
and older formula features have been used to run commands on the victim's computer. The common defense (recommended by
OWASP) is to prefix such cells with a single quote, which spreadsheets display as text:

```php
<?php

declare(strict_types=1);

function spreadsheetSafe(string $cell): string
{
    return preg_match('/^[=+\-@\t\r]/', $cell) === 1 ? "'" . $cell : $cell;
}

echo spreadsheetSafe('=HYPERLINK("https://evil.example")'), "\n";
echo spreadsheetSafe('Dune'), "\n";
```

```text
'=HYPERLINK("https://evil.example")
Dune
```

Apply it to text cells in exports intended for spreadsheets, not to files another program will import, where the
quote would be wrong data.

## Writing command-line programs

PHP runs not only behind a web server but also as a command-line program, through the **CLI SAPI** (chapter 16 explains
SAPIs). Many real tasks are command-line programs: imports, cron jobs, queue workers, migrations, deploy scripts. The
CLI differs from the web in a few important ways:

| | CLI | Web |
|---|---|---|
| `PHP_SAPI` | `'cli'` | `'fpm-fcgi'`, `'apache2handler'`, ... |
| `max_execution_time` | 0 (no limit) | 30 seconds by default |
| Input | `$argv`, standard input, environment variables | The HTTP request |
| Output | Standard output and standard error | The HTTP response |
| Error messages | Printed in the terminal | Hidden from visitors in production, written to a log |
| `STDIN`, `STDOUT`, `STDERR` constants | Defined | Not defined |

The `php` command has options worth knowing:

```bash
php script.php arg1 arg2      # run a script with arguments
php -r 'echo PHP_VERSION, "\n";'   # run code given on the command line
php -l script.php             # check syntax only ("lint")
php -a                        # interactive shell
php -m                        # list loaded extensions
php --ini                     # which php.ini files are loaded
php -i | grep memory_limit    # full configuration (phpinfo() as text)
php -d memory_limit=512M script.php   # override a setting for one run
```

### Arguments: `$argv` and `getopt()`

The command line arrives in `$argv`, an array whose first element is the script name, and `$argc`, its length. The
shell has already split the line into words and removed quotes:

```php
<?php

declare(strict_types=1);

// php args.php import "my books.csv" --strict
var_dump($argc, $argv);
```

```text
int(4)
array(4) {
  [0]=>
  string(8) "args.php"
  [1]=>
  string(6) "import"
  [2]=>
  string(12) "my books.csv"
  [3]=>
  string(8) "--strict"
}
```

`getopt()` parses options in the traditional Unix style. The first argument lists short options, the second long
ones; a trailing `:` means "requires a value", `::` means "optional value". The third argument receives the index in
`$argv` where the non-option arguments start:

```php
<?php

declare(strict_types=1);

// -v (flag), -n VALUE; --verbose, --name=VALUE, --shout
$options = getopt('vn:', ['verbose', 'name:', 'shout'], $restIndex);
$rest = array_slice($argv, $restIndex);

$name = $options['name'] ?? $options['n'] ?? 'reader';
$greeting = "Hello, $name!";
if (isset($options['shout'])) {
    $greeting = strtoupper($greeting);
}
echo $greeting, PHP_EOL;

if (isset($options['v']) || isset($options['verbose'])) {
    var_dump($options, $rest);
}
```

```bash
php greet.php --name=Ada --shout
php greet.php -n Ada -v extra words
```

```text
HELLO, ADA!
Hello, Ada!
array(2) {
  ["n"]=>
  string(3) "Ada"
  ["v"]=>
  bool(false)
}
array(2) {
  [0]=>
  string(5) "extra"
  [1]=>
  string(5) "words"
}
```

`getopt()` has quirks you need to know:

- A flag that is present has the value **`false`**, so test with `isset()` or `array_key_exists()`, never with
  `if ($options['v'])`.
- A flag given twice (`-v -v`) becomes an array of values.
- An option missing its required value (`--name` at the end of the line) is silently left out, not reported.
- Parsing stops at the first non-option argument, so in `php greet.php extra --shout` the `--shout` is ignored.
- It always reads the global `$argv`, which makes code that uses it harder to test.

For tools with subcommands (`bookshelf import ...`), most projects use a library such as `symfony/console`, which also
generates help screens and validates input. Bookshelf's own small parser, at the end of this chapter, shows that the
core idea is not complicated.

### Standard input, output and error

Every process starts with three open streams, available as the constants `STDIN`, `STDOUT` and `STDERR`:

- **Standard output** is for the program's **result**: the data someone might redirect to a file (`> out.json`) or
  pipe into another program (`| jq`).
- **Standard error** is for **messages to the human**: progress, warnings, errors. It still appears in the terminal
  when standard output is redirected, and it never corrupts the data stream.
- **Standard input** is where piped data arrives (`cat books.csv | php import.php -`).

`echo` writes to standard output. Keep that separation, and your tool combines with the rest of the command line:

```php
<?php

declare(strict_types=1);

// Upper-cases standard input, line by line: a filter in the Unix tradition.
$count = 0;
while (($line = fgets(STDIN)) !== false) {
    echo strtoupper($line);
    $count++;
}
fwrite(STDERR, "$count lines processed\n");
```

```bash
printf 'dune\nemma\n' | php upper.php > out.txt
cat out.txt
```

```text
2 lines processed
DUNE
EMMA
```

The count appeared in the terminal even though standard output went to `out.txt`. `stream_get_contents(STDIN)` reads
all of standard input at once, and `fgets(STDIN)` doubles as a way to ask the user a question interactively.

To check whether a stream is an interactive terminal (rather than a file or a pipe), use `stream_isatty(STDOUT)`. Use
it to decide whether to print colors and progress bars: escape codes such as `"\e[31m"` (red) look like garbage in a
log file. Also respect the `NO_COLOR` environment variable, a widely followed convention for turning colors off.

### Exit codes

A process ends with an **exit code**: `0` means success, anything from 1 to 255 means failure. Shells, cron, CI systems
and process supervisors all decide what to do next based on it (`&&` in a shell runs the next command only after a
`0`). A command-line program that prints "Error" but exits with 0 tells its caller that everything went fine.

```php
<?php

declare(strict_types=1);

if (!is_readable($argv[1] ?? '')) {
    fwrite(STDERR, "Usage: php check.php <file>\n");
    exit(2);
}
echo "ok\n";
exit(0);
```

```bash
php check.php /no/such/file; echo "exit code: $?"
```

```text
Usage: php check.php <file>
exit code: 2
```

- `exit(int)` ends the script with that code. `exit('message')` prints the message and exits with code 0, so for
  errors, write the message to standard error and pass a number. Since PHP 8.4, `exit()` is an ordinary function, so
  it follows the usual type rules for its argument.
- A script that ends normally exits with 0. An uncaught exception or a fatal error exits with 255.
- Common conventions: 1 for a general failure, 2 for wrong usage (bad arguments).
- `exit()` runs shutdown functions (`register_shutdown_function()`) and object destructors, but it does **not** run
  pending `finally` blocks. Code that must always run belongs in a shutdown function or must be finished before
  `exit()`. This is one reason to call `exit()` in exactly one place: the last line of the entry script.

### Running other programs

PHP can run other programs, which is useful in deploy scripts and for tools that have no PHP library. It is also one
of the most dangerous things PHP can do, because passing user input to a **shell** allows **command injection**:

```php
// NEVER: the file name "x; rm -rf ~" runs a second command.
shell_exec("wc -l $file");
```

| Function | Runs through a shell | Returns |
|---|---|---|
| `exec($command, $output, $exitCode)` | yes | The last line; all lines in `$output` |
| `shell_exec($command)` (and backticks) | yes | All output as a string, or `null` |
| `system($command)`, `passthru($command)` | yes | Prints the output directly |
| `proc_open([...], ...)` with an array | **no** | Full control of the process's streams |

If you must use a shell, escape every argument with `escapeshellarg()`, which wraps it in single quotes and escapes any
quotes inside. Better, pass the command to `proc_open()` as an **array**: PHP then starts the program directly, without
a shell, so nothing in the arguments can be interpreted as shell syntax:

```php
<?php

declare(strict_types=1);

echo escapeshellarg('my books; rm -rf ~.csv'), "\n";

$process = proc_open(
    ['sort', '-r'],                                   // program and arguments, no shell
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
);
fwrite($pipes[0], "Dune\nEmma\nKindred\n");
fclose($pipes[0]);                                    // end of input: sort can start
$sorted = stream_get_contents($pipes[1]);
$errors = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

echo $sorted, "exit code: $exitCode\n";
```

```text
'my books; rm -rf ~.csv'
Kindred
Emma
Dune
exit code: 0
```

The descriptor array connects the child's standard input (0), output (1) and error (2) to pipes the parent can write to
and read from. For anything beyond this, `symfony/process` wraps `proc_open()` with timeouts, streaming output and
clear errors.

### Long-running scripts and signals

Command-line scripts have no time limit, so they can run for hours. Two things then matter. Memory: release large
arrays when you're done with them (`unset()`, chapter 21 explains why it helps) and process data in streams and
generators. And **signals**: when a deploy or `Ctrl+C` asks the process to stop, it should finish the current item
instead of dying halfway through.

> **Just a taste — you don't need to learn or fully understand this now.** The `pcntl` extension (Linux and macOS, CLI
> only) lets a worker catch `SIGTERM` and `SIGINT` and stop cleanly. Chapter 22 covers workers and how process
> managers stop them.

```php
<?php

declare(strict_types=1);

pcntl_async_signals(true);
$stopping = false;
$stop = function () use (&$stopping): void { $stopping = true; };
pcntl_signal(SIGTERM, $stop);
pcntl_signal(SIGINT, $stop);

while (!$stopping) {
    // ... process one job ...
    sleep(1);
}
fwrite(STDERR, "Stopped cleanly\n");
```

### Making a script executable

On Linux and macOS, a script whose first line is a **shebang** can be run like any other program:

```php
#!/usr/bin/env php
<?php

echo "Hello from a PHP program\n";
```

```bash
chmod +x hello
./hello
```

`/usr/bin/env php` finds `php` on the `PATH`. PHP skips the shebang line itself, so it never appears in the output. By
convention, project scripts live in `bin/` without a `.php` extension. Composer can also install a package's scripts
into `vendor/bin/` (the `bin` key in `composer.json`), which is how `vendor/bin/phpunit` arrives in chapter 20.

## Bookshelf: the `bin/bookshelf` command-line tool

Bookshelf gets a command-line tool with subcommands, like `git` or `composer`. For now it has two: `import`, which
reads a CSV file with chapter 13's `CsvBookReader` and reports what it would import (chapter 17 adds saving to the
database), and `isbn`, which checks ISBNs. The code lives in `src/Cli/`:

```text
examples/bookshelf/
  bin/bookshelf                     entry script: builds the application and exits with its code
  src/Cli/
    Application.php                 finds the command, handles --help, turns exceptions into exit codes
    Command.php                     interface every command implements
    Input.php                       parses arguments and options
    Output.php                      writes results to STDOUT and messages to STDERR
    ExitCode.php                    enum: Success = 0, Failure = 1, Usage = 2
    UsageError.php                  exception for a wrong command line
    Command/ImportBooksCommand.php
    Command/IsbnCommand.php
```

The entry script stays tiny. It wires the objects together, runs the application, and exits with the code it returns:

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

use Bookshelf\Cli\Application;
use Bookshelf\Cli\Command\ImportBooksCommand;
use Bookshelf\Cli\Command\IsbnCommand;
use Bookshelf\Cli\Output;
use Bookshelf\Import\CsvBookReader;

if (PHP_SAPI !== 'cli') {
    exit('This program runs from the command line only.');
}

require dirname(__DIR__) . '/vendor/autoload.php';

$app = new Application(
    'bookshelf',
    new ImportBooksCommand(new CsvBookReader()),
    new IsbnCommand(),
);

exit($app->run($argv, new Output()));
```

Every command implements one interface, and returns an `ExitCode` instead of calling `exit()` itself:

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Cli;

interface Command
{
    /** The word typed after the program name, e.g. "import". */
    public function name(): string;

    /** One line for the command list. */
    public function description(): string;

    /** The full help text shown by `bookshelf <command> --help`. */
    public function help(): string;

    public function run(Input $input, Output $output): ExitCode;
}
```

Commands never touch `$argv`, `STDOUT` or `exit()` directly; they get an `Input` and an `Output`. That keeps them
testable: a test can build an `Input` from an array and give the `Output` two `php://memory` streams, then check what
was written (chapter 20 does exactly that).

`Input` parses the words after the command name. It supports `--name`, `--name=value`, short flags (`-q`, or `-qv` for
two), `-` as an ordinary argument (by convention, "standard input"), and `--` to mark the end of the options:

```php
/**
 * @param list<string> $tokens the command line after the command name
 */
public static function parse(array $tokens): self
{
    $arguments = [];
    $options = [];
    $optionsEnded = false;

    foreach ($tokens as $token) {
        if ($optionsEnded || $token === '-' || !str_starts_with($token, '-')) {
            $arguments[] = $token;
        } elseif ($token === '--') {
            $optionsEnded = true;
        } elseif (str_starts_with($token, '--')) {
            $parts = explode('=', substr($token, 2), 2);
            $options[$parts[0]] = $parts[1] ?? true;
        } else {
            foreach (str_split(substr($token, 1)) as $flag) {
                $options[$flag] = true;
            }
        }
    }

    return new self($arguments, $options);
}
```

`Input` is a `final readonly` class holding the two arrays (`list<string>` arguments and `array<string, string|true>`
options), and its accessors turn mistakes into `UsageError`s with helpful messages:

```php
public function requireArgument(int $position, string $name): string
{
    return $this->argument($position) ?? throw new UsageError("Missing argument <$name>");
}

/**
 * The value of `--name=value`, or $default when the option is absent.
 *
 * @param list<string> $allowed when not empty, the only accepted values
 */
public function option(string $name, string $default, array $allowed = []): string
{
    $value = $this->options[$name] ?? $default;
    if ($value === true) {
        throw new UsageError("Option --$name needs a value: --$name=...");
    }
    if ($allowed !== [] && !in_array($value, $allowed, true)) {
        throw new UsageError("Option --$name must be one of: " . implode(', ', $allowed));
    }

    return $value;
}
```

`argument(int $position): ?string` and `flag(string $name, ?string $short = null): bool` complete the class.

`Output` sends results to `STDOUT` and everything else to `STDERR`, uses colors only when standard error is a terminal
and `NO_COLOR` is not set, and has helpers for JSON and aligned tables (with `mb_str_pad()`, PHP 8.3+, so accented
titles line up). Its core:

```php
final class Output
{
    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    private bool $colors;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct($stdout = null, $stderr = null, ?bool $colors = null)
    {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
        $this->colors = $colors ?? (stream_isatty($this->stderr) && getenv('NO_COLOR') === false);
    }

    /** A line of the command's result, on STDOUT. */
    public function line(string $text = ''): void
    {
        fwrite($this->stdout, $text . PHP_EOL);
    }

    /** A diagnostic message, on STDERR. */
    public function info(string $text): void
    {
        fwrite($this->stderr, $text . PHP_EOL);
    }

    public function error(string $text): void
    {
        $this->info($this->paint($text, '31'));
    }

    // warning() and success() work like error(), in yellow and green; json() and table() format results.

    private function paint(string $text, string $color): string
    {
        return $this->colors ? "\e[{$color}m$text\e[0m" : $text;
    }
}
```

Properties cannot be typed `resource` (it is not a type PHP can declare), so the docblock documents it and the
constructor takes untyped parameters. `Application` picks the command, and is the **one place** that turns problems
into exit codes: a `UsageError` (wrong command line) gives 2, any other exception gives 1 with its message, and
setting the environment variable `BOOKSHELF_DEBUG` also prints the stack trace:

```php
public function run(array $argv, Output $output): int
{
    $name = $argv[1] ?? 'help';
    if (in_array($name, ['help', '--help', '-h'], true)) {
        $this->listCommands($output);
        return ExitCode::Success->value;
    }

    $command = $this->commands[$name] ?? null;
    if ($command === null) {
        $output->error("Unknown command '$name'. Run '{$this->program} help' for a list.");
        return ExitCode::Usage->value;
    }

    try {
        $input = Input::parse(array_slice($argv, 2));
        if ($input->flag('help', 'h')) {
            $output->line($command->help());
            return ExitCode::Success->value;
        }

        return $command->run($input, $output)->value;
    } catch (UsageError $e) {
        $output->error($e->getMessage());
        $output->info("Run '{$this->program} $name --help' for usage.");
        return ExitCode::Usage->value;
    } catch (Throwable $e) {
        $output->error('Error: ' . $e->getMessage());
        if (getenv('BOOKSHELF_DEBUG') !== false) {
            $output->info((string) $e);
        }
        return ExitCode::Failure->value;
    }
}
```

Finally, the `import` command. It maps `-` to `php://stdin`, which works because `CsvBookReader` opens its path with
`fopen()`, and any stream URL will do:

```php
public function run(Input $input, Output $output): ExitCode
{
    $file = $input->requireArgument(0, 'file');
    $format = $input->option('format', 'table', ['table', 'json']);
    $path = $file === '-' ? 'php://stdin' : $file;

    $books = [];
    $invalid = 0;
    foreach ($this->reader->read($path) as $line => $result) {
        if ($result instanceof Book) {
            $books[] = $result;
        } else {
            $output->warning("line $line skipped: {$result->reason}");
            $invalid++;
        }
    }

    if ($format === 'json') {
        $output->json(array_map($this->toArray(...), $books));
    } else {
        $output->table(
            ['Title', 'Author', 'ISBN', 'Genre', 'Year', 'Copies'],
            array_map(fn (Book $book): array => array_map(strval(...), array_values($this->toArray($book))), $books),
        );
    }

    $output->success(sprintf('%d books ready to import, %d skipped', count($books), $invalid));

    return $invalid > 0 && $input->flag('strict') ? ExitCode::Failure : ExitCode::Success;
}
```

`toArray()` is a private method that turns a `Book` into `['title' => ..., 'isbn' => ..., 'genre' => 'sf', ...]`. The
full files are in [`examples/bookshelf/src/Cli/`](examples/bookshelf/src/Cli/). Using the tool, from
`examples/bookshelf` after `composer dump-autoload`:

```bash
php bin/bookshelf
```

```text
Usage: bookshelf <command> [arguments] [options]

Commands:
  import     Read books from a CSV file and report what would be imported
  isbn       Check ISBN-13 numbers given as arguments or on standard input

Run 'bookshelf <command> --help' for details.
```

```bash
head -3 data/books.csv > /tmp/some.csv
echo "Ulysses,James Joyce,978-0-679-72276-1,modernist,1922,1" >> /tmp/some.csv
php bin/bookshelf import /tmp/some.csv --strict; echo "exit code: $?"
```

```text
line 4 skipped: unknown genre 'modernist'
Title    Author             ISBN           Genre  Year  Copies
-------  -----------------  -------------  -----  ----  ------
Dune     Frank Herbert      9780441013593  sf     1965  2
Kindred  Octavia E. Butler  9780807083697  sf     1979  1
2 books ready to import, 1 skipped
exit code: 1
```

Because results go to standard output and messages to standard error, the JSON output can be piped or saved while the
warnings still reach the terminal:

```bash
cat /tmp/some.csv | php bin/bookshelf import - --format=json > books.json
php bin/bookshelf isbn 978-0-441-01359-3 9780441013594; echo "exit code: $?"
php bin/bookshelf import; echo "exit code: $?"
```

```text
line 4 skipped: unknown genre 'modernist'
2 books ready to import, 1 skipped
9780441013593
Invalid ISBN-13: '9780441013594'
exit code: 1
Missing argument <file>
Run 'bookshelf import --help' for usage.
exit code: 2
```

## Key takeaways

- `file_get_contents()`/`file_put_contents()` are fine for small files; use handles and line-by-line reading for
  anything that can grow. Always check for `false`: file functions warn and return `false` rather than throwing.
- Loop on the read (`while (($line = fgets($h)) !== false)`), not on `feof()`, and close handles in `finally`.
- Replace files atomically: write a temporary file in the same directory, then `rename()` it. Use `flock()` only when
  every writer cooperates, and prefer a database for data that is updated concurrently.
- Build paths from `__DIR__`, not the current directory. Use `realpath()` plus a prefix check to keep user-supplied
  names inside a directory. Remember the stat cache in long-running processes.
- Everything is a stream: `php://memory`, `php://temp`, `php://stdin`, `data:`, `compress.zlib://` and HTTP all work
  with the same functions. Always set timeouts on network streams.
- JSON: always pass `JSON_THROW_ON_ERROR`; know that only lists become JSON arrays; decode to arrays and validate the
  shape before trusting it; watch big integers and invalid UTF-8.
- CSV: always pass `escape: ''` (PHP 8.4 deprecates leaving it out); strip a BOM when reading, write one for Excel, and
  neutralize formulas in exported user data.
- Command-line programs: results on standard output, messages on standard error, a meaningful exit code, and `exit()`
  in one place. Never pass user input to a shell; use `proc_open()` with an array.

## Exercises

1. **Safe save.** Write `saveJson(string $path, array $data): void` that encodes `$data` as pretty-printed JSON
   (throwing on errors) and writes it atomically, so a reader never sees a half-written file. Test that saving twice
   leaves the second version in place and no temporary files behind.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

function saveJson(string $path, array $data): void
{
    $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    $temporary = tempnam(dirname($path), '.tmp-');
    if ($temporary === false) {
        throw new RuntimeException("Cannot create a temporary file next to $path");
    }
    try {
        if (file_put_contents($temporary, $json) !== strlen($json) || !chmod($temporary, 0644)
            || !rename($temporary, $path)) {
            throw new RuntimeException("Could not save $path");
        }
    } catch (Throwable $e) {
        @unlink($temporary);
        throw $e;
    }
}

$dir = sys_get_temp_dir() . '/save-demo';
@mkdir($dir);
saveJson("$dir/catalog.json", ['books' => 1]);
saveJson("$dir/catalog.json", ['books' => 2]);
echo file_get_contents("$dir/catalog.json");
echo count(glob("$dir/.tmp-*")), " temporary files left\n";
```

```text
{
    "books": 2
}
0 temporary files left
```

Encoding happens **before** anything touches the disk, so a value that cannot be encoded (say, invalid UTF-8) throws
without creating any file. The `||` chain stops at the first failure.

</details>

2. **`tail` in constant memory.** Write `lastLines(string $path, int $count): array` that returns the last `$count`
   lines of a file without the line endings. It must work on a file larger than the memory limit, so it may hold at most
   `$count` lines at a time.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

/** @return list<string> */
function lastLines(string $path, int $count): array
{
    $h = @fopen($path, 'r');
    if ($h === false) {
        throw new RuntimeException("Cannot open $path");
    }

    $buffer = new SplQueue();
    try {
        while (($line = fgets($h)) !== false) {
            $buffer->enqueue(rtrim($line, "\r\n"));
            if (count($buffer) > $count) {
                $buffer->dequeue();
            }
        }
    } finally {
        fclose($h);
    }

    return iterator_to_array($buffer, false);
}

$path = sys_get_temp_dir() . '/log.txt';
$h = fopen($path, 'w');
for ($i = 1; $i <= 100_000; $i++) {
    fwrite($h, "event $i\n");
}
fclose($h);

print_r(lastLines($path, 3));
```

```text
Array
(
    [0] => event 99998
    [1] => event 99999
    [2] => event 100000
)
```

The queue never holds more than `$count + 1` lines, so memory does not depend on the file size. (A faster version for
huge files seeks to the end and reads backwards in blocks, which is what the Unix `tail` command does.)

</details>

3. **Spreadsheet export.** Write `exportForSpreadsheet(iterable $rows, $handle): void`, where `$rows` yields arrays of
   strings (the first one being the header). It writes a UTF-8 BOM, then standard CSV with formula cells neutralized.
   Test it on a row containing `=1+1`, a title with a comma and a quote, and an accented name.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

/**
 * @param iterable<list<string>> $rows
 * @param resource $handle
 */
function exportForSpreadsheet(iterable $rows, $handle): void
{
    fwrite($handle, "\xEF\xBB\xBF");
    foreach ($rows as $row) {
        $safe = array_map(
            fn (string $cell): string => preg_match('/^[=+\-@\t\r]/', $cell) === 1 ? "'" . $cell : $cell,
            $row,
        );
        fputcsv($handle, $safe, escape: '');
    }
}

$out = fopen('php://memory', 'w+');
exportForSpreadsheet([
    ['name', 'favorite book'],
    ['=1+1', 'Dune'],
    ['Zoë', 'The "Hobbit", illustrated'],
], $out);
rewind($out);
$csv = stream_get_contents($out);

echo bin2hex(substr($csv, 0, 3)), "\n";
echo substr($csv, 3);
```

```text
efbbbf
name,"favorite book"
'=1+1,Dune
Zoë,"The ""Hobbit"", illustrated"
```

`fputcsv()` quotes fields that contain spaces, commas or quotes and doubles the inner quotes, which is standard CSV.
Accepting a `$handle` rather than a path lets the same function write to a file, to `php://output` for a download
(chapter 16), or to memory in a test.

</details>

4. **A JSON Lines reader.** Write a generator `readJsonLines(string $path): Generator` that yields each line's decoded
   value keyed by line number, skips blank lines, and throws a `RuntimeException` naming the line number when a line is
   not valid JSON. Test it with three good lines, a blank one, and a broken one.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

/** @return Generator<int, mixed> */
function readJsonLines(string $path): Generator
{
    $h = @fopen($path, 'r');
    if ($h === false) {
        throw new RuntimeException("Cannot open $path");
    }

    try {
        $number = 0;
        while (($line = fgets($h)) !== false) {
            $number++;
            if (trim($line) === '') {
                continue;
            }
            try {
                yield $number => json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RuntimeException("Line $number: {$e->getMessage()}", previous: $e);
            }
        }
    } finally {
        fclose($h);
    }
}

$path = sys_get_temp_dir() . '/events.jsonl';
file_put_contents($path, implode("\n", [
    '{"event": "lent", "book": "Dune"}',
    '{"event": "returned", "book": "Dune"}',
    '',
    '{"event": "lent", "book": "Emma"}',
    '{"event": "lent", "book": ',
]) . "\n");

try {
    foreach (readJsonLines($path) as $number => $event) {
        echo "$number: {$event['event']} {$event['book']}\n";
    }
} catch (RuntimeException $e) {
    echo $e->getMessage(), "\n";
}
```

```text
1: lent Dune
2: returned Dune
4: lent Emma
Line 5: Syntax error
```

The `try` covers the whole `yield` statement, but it only ever catches errors from the decode: an exception thrown in
the *consumer's* loop body is raised in the consumer, not inside the generator, so it cannot be mislabeled as a JSON
error.

</details>

5. **A `stats` command.** Add a command to `bin/bookshelf`, `bookshelf stats <file>`, that reads a book CSV with
   `CsvBookReader` and prints, per genre, the number of titles and copies, as a table sorted by copies (most first).
   Books without a genre count as "(none)"; invalid lines are ignored. Register it in `bin/bookshelf`.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Cli\Command;

use Bookshelf\Cli\Command;
use Bookshelf\Cli\ExitCode;
use Bookshelf\Cli\Input;
use Bookshelf\Cli\Output;
use Bookshelf\Domain\Book;
use Bookshelf\Import\CsvBookReader;

final class StatsCommand implements Command
{
    public function __construct(
        private readonly CsvBookReader $reader,
    ) {
    }

    public function name(): string
    {
        return 'stats';
    }

    public function description(): string
    {
        return 'Count titles and copies per genre in a CSV file';
    }

    public function help(): string
    {
        return 'Usage: bookshelf stats <file>';
    }

    public function run(Input $input, Output $output): ExitCode
    {
        $file = $input->requireArgument(0, 'file');

        $stats = [];
        foreach ($this->reader->read($file === '-' ? 'php://stdin' : $file) as $result) {
            if (!$result instanceof Book) {
                continue;
            }
            $genre = $result->genre?->label() ?? '(none)';
            $stats[$genre] ??= ['titles' => 0, 'copies' => 0];
            $stats[$genre]['titles']++;
            $stats[$genre]['copies'] += $result->copies;
        }

        uasort($stats, fn (array $a, array $b): int => $b['copies'] <=> $a['copies']);

        $rows = [];
        foreach ($stats as $genre => $counts) {
            $rows[] = [(string) $genre, (string) $counts['titles'], (string) $counts['copies']];
        }
        $output->table(['Genre', 'Titles', 'Copies'], $rows);

        return ExitCode::Success;
    }
}
```

In `bin/bookshelf`, add `new StatsCommand(new CsvBookReader())` to the `Application` constructor (and the `use`
line). Then, on the sample file:

```bash
php bin/bookshelf stats data/books.csv
```

```text
Genre            Titles  Copies
---------------  ------  ------
Science fiction  3       6
Classic          3       3
Fantasy          2       3
Crime            1       1
Non-fiction      1       1
```

`uasort()` keeps the genre keys while sorting (chapter 5), and because PHP's sort is stable, genres with equal counts
stay in the order they were first seen. Notice how little the command has to do: argument parsing,
help, error handling and exit codes all come from `Application`, which is the point of the structure.

</details>

---
← Previous: [Iterators, generators and fibers](13-iterators-generators-and-fibers.md) | Next: [Dates, times and internationalization](15-dates-times-and-internationalization.md) →
