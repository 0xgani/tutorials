# Chapter 6 — Fetching data and streaming

## What you'll learn

- How Server Components load data: just `await` it, no API layer in between
- A data access layer for Gather on SQLite with Node's built-in `node:sqlite` module
- Why a database query can silently end up frozen into the build, and how `connection()` prevents it (for now)
- `fetch` in Server Components: uncached by default, memoized within a request
- Streaming: `<Suspense>`, `loading.tsx`, skeletons, and what actually travels over the wire
- Sequential versus parallel data loading, and how to avoid waterfalls
- Deduplicating work within a request with `React.cache`
- Passing a promise from a Server Component to a Client Component and reading it with `use`

## Data in Server Components

In a single-page app, a component that needs data renders a spinner, fetches from an API in an effect, and renders
again. Server Components don't need any of that. They run on the server, they can be `async`, and they await data
directly:

```tsx
export default async function Page() {
  const events = await getUpcomingEvents()
  return <EventGrid events={events} />
}
```

There is no API endpoint, no JSON serialization step written by you, no loading state in the browser, and the
database credentials never leave the server. The HTML that reaches the browser already contains the events.

The question in this chapter is not *how* to load data (it's an `await`) but **where** and **when**: where to keep
data access code so it stays secure and reusable, and when it runs: at build time, at request time, or streamed in
after the rest of the page.

## Gather's database

Gather stores its data in SQLite, through the `node:sqlite` module that ships with Node.js. SQLite is a full SQL
database in a single file, embedded in your process: no server to install, no connection string, and queries take
microseconds because there is no network involved. It is a solid choice for many real applications on a single
server, and everything in this chapter applies the same way to PostgreSQL with a driver or an ORM. (For SQL itself,
see the [PostgreSQL tutorial](../postgresql-tutorial/README.md).)

### The connection and the schema

```ts
// lib/db.ts
import 'server-only'
import { DatabaseSync } from 'node:sqlite'
import { mkdirSync } from 'node:fs'
import path from 'node:path'
import { seed } from './seed'

// One connection per server process. node:sqlite is synchronous, which is
// fine for SQLite: queries take microseconds and never wait on a network.
const globalForDb = globalThis as unknown as { gatherDb?: DatabaseSync }

export function db(): DatabaseSync {
  if (!globalForDb.gatherDb) {
    globalForDb.gatherDb = open(process.env.DATABASE_PATH ?? 'data/gather.db')
  }
  return globalForDb.gatherDb
}

function open(file: string): DatabaseSync {
  if (file !== ':memory:') mkdirSync(path.dirname(file), { recursive: true })
  const database = new DatabaseSync(file)
  // WAL lets the build's parallel workers and the server read concurrently;
  // busy_timeout makes a writer wait instead of failing with SQLITE_BUSY.
  database.exec('PRAGMA journal_mode = WAL')
  database.exec('PRAGMA busy_timeout = 5000')
  database.exec('PRAGMA foreign_keys = ON')
  migrate(database)
  return database
}

function migrate(database: DatabaseSync) {
  database.exec('BEGIN IMMEDIATE')
  try {
    database.exec(`
      CREATE TABLE IF NOT EXISTS users (
        id            INTEGER PRIMARY KEY,
        email         TEXT NOT NULL UNIQUE,
        name          TEXT NOT NULL,
        password_hash TEXT NOT NULL,
        role          TEXT NOT NULL DEFAULT 'member' CHECK (role IN ('member', 'organizer')),
        created_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
      );
      CREATE TABLE IF NOT EXISTS sessions (
        token_hash TEXT PRIMARY KEY,
        user_id    INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
        expires_at TEXT NOT NULL
      );
      CREATE TABLE IF NOT EXISTS events (
        id           INTEGER PRIMARY KEY,
        slug         TEXT NOT NULL UNIQUE,
        title        TEXT NOT NULL,
        description  TEXT NOT NULL,
        city         TEXT NOT NULL,
        venue        TEXT NOT NULL,
        starts_at    TEXT NOT NULL,
        capacity     INTEGER NOT NULL CHECK (capacity > 0),
        organizer_id INTEGER NOT NULL REFERENCES users (id),
        created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
      );
      CREATE INDEX IF NOT EXISTS events_starts_at ON events (starts_at);
      CREATE TABLE IF NOT EXISTS rsvps (
        event_id   INTEGER NOT NULL REFERENCES events (id) ON DELETE CASCADE,
        user_id    INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
        created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
        PRIMARY KEY (event_id, user_id)
      );
      CREATE TABLE IF NOT EXISTS comments (
        id         INTEGER PRIMARY KEY,
        event_id   INTEGER NOT NULL REFERENCES events (id) ON DELETE CASCADE,
        user_id    INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
        body       TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
      );
    `)
    const { count } = database.prepare('SELECT count(*) AS count FROM users').get() as {
      count: number
    }
    if (count === 0 && process.env.SEED !== 'false') seed(database)
    database.exec('COMMIT')
  } catch (error) {
    database.exec('ROLLBACK')
    throw error
  }
}
```

The interesting parts are the Next.js-specific ones:

- **`import 'server-only'`.** The database must never be bundled for the browser. If a Client Component ever imports
  this module, directly or through another module, the build fails (chapter 4).
- **A lazily created singleton on `globalThis`.** `db()` opens the database on first use and reuses the connection.
  Storing it on `globalThis` instead of in a module-level variable matters in development: when you edit a file, the
  dev server re-evaluates changed modules, and a module-level variable would open a new connection on every edit. A
  global survives those reloads. (The same pattern is common with Prisma and other clients.)
- **Lazily, not at import time.** Opening the database inside `db()` rather than at the top level means merely
  importing the module (for example while `next build` collects page information) doesn't touch the file system.
- **WAL mode and a busy timeout.** `next build` prerenders pages in several worker processes at once, and in
  production the server reads while a Server Action writes. Write-ahead logging lets readers and a writer work at the
  same time, and `busy_timeout` makes a second writer wait up to 5 seconds instead of failing immediately.
- **The schema is created on startup**, inside a transaction (`BEGIN IMMEDIATE` takes the write lock, so two build
  workers starting at once don't both try to create tables). A real project would use numbered migration files; for
  a tutorial app, `CREATE TABLE IF NOT EXISTS` keeps everything in one place.

The database file goes in `data/`, which shouldn't be committed. Add it to `.gitignore`:

```text
# gather
/data
```

### Demo data

`seed()` fills an empty database with three users, eight events in Berlin, Lisbon and Nairobi (dated a few days to a
few weeks after the moment of seeding, so they are always upcoming), some RSVPs and one comment. The file is long
and unremarkable; here is its shape:

```ts
// lib/seed.ts (abridged; the full file is in examples/gather/lib/seed.ts)
import type { DatabaseSync } from 'node:sqlite'
import { hashPasswordSync } from './password'

const DEMO_PASSWORD = 'gather-demo-1'

const users = [
  { email: 'ada@example.com', name: 'Ada Okafor', role: 'organizer' },
  { email: 'grace@example.com', name: 'Grace Lindqvist', role: 'organizer' },
  { email: 'linus@example.com', name: 'Linus Mbeki', role: 'member' },
]

const events = [
  {
    slug: 'nextjs-meetup-berlin',
    title: 'Next.js Meetup Berlin',
    city: 'Berlin',
    venue: 'Factory Görlitzer Park',
    inDays: 3,
    hour: 18,
    capacity: 60,
    organizer: 0,
    description: 'Three short talks on Cache Components, Server Actions and self-hosting, ...',
  },
  // ... seven more
]

export function seed(database: DatabaseSync) {
  const passwordHash = hashPasswordSync(DEMO_PASSWORD)
  const insertUser = database.prepare(
    'INSERT INTO users (email, name, password_hash, role) VALUES (?, ?, ?, ?) RETURNING id',
  )
  const userIds = users.map(
    (u) => (insertUser.get(u.email, u.name, passwordHash, u.role) as { id: number }).id,
  )
  // ... insert events with starts_at = now + inDays, RSVPs for the first few, one comment
}
```

Copy `lib/seed.ts` and `lib/password.ts` from `examples/gather/lib/`. `password.ts` hashes passwords with scrypt;
chapter 15 explains it line by line. Delete `lib/sample-events.ts`: from now on, the data comes from the database.

### `node:sqlite` in five minutes

```ts
const database = new DatabaseSync('data/gather.db')

// Statements are prepared once and run many times. `?` placeholders are bound
// in order; values are never concatenated into the SQL text.
const bySlug = database.prepare('SELECT id, title FROM events WHERE slug = ?')

bySlug.get('nextjs-meetup-berlin') // first row, or undefined
database.prepare('SELECT city FROM events').all() // every row, as an array
database.prepare('DELETE FROM rsvps WHERE event_id = ?').run(7) // { changes: 1, lastInsertRowid: 0 }
```

- **Always use placeholders** (`?`) for values. Building SQL with template strings (`` `WHERE slug = '${slug}'` ``)
  is an SQL injection vulnerability; placeholders make the value data, never SQL.
- **The API is synchronous.** `get()` returns rows, not a promise. For SQLite that's fine and fast. It also has a
  consequence for Next.js that you'll see in a moment.
- **Rows have a `null` prototype.** `get()` returns objects created with `Object.create(null)`. They work like normal
  objects in your code, but React refuses to pass them as props to Client Components ("Only plain objects, and a few
  built-ins, can be passed to Client Components from Server Components. Classes or null prototypes are not
  supported."). Gather's data layer copies every row into a plain object.
- **TypeScript.** `node:sqlite` types come from `@types/node`. If your editor can't find the module, make sure
  `@types/node` is version 24 (`npm install --save-dev @types/node@24`, as in chapter 1).

### The data access layer

Pages never contain SQL. They call functions from one module, `lib/data.ts`, which is Gather's **data access layer**
(DAL) for reading. Keeping every query in one place makes it easy to see what the app reads, to add caching and
authorization checks consistently (chapters 7 and 15), and to change the database later.

```ts
// lib/data.ts
import 'server-only'
import { db } from './db'

// The read side of Gather's data access layer: every page reads data through
// these functions, never with SQL of its own.

export type EventSummary = {
  id: number
  slug: string
  title: string
  city: string
  venue: string
  startsAt: string
  capacity: number
  going: number
}

export type EventDetail = EventSummary & {
  description: string
  organizer: { id: number; name: string }
}

export type Comment = {
  id: number
  body: string
  createdAt: string
  author: { id: number; name: string }
}

type EventRow = {
  id: number
  slug: string
  title: string
  city: string
  venue: string
  starts_at: string
  capacity: number
  going: number
}

// node:sqlite returns objects with a null prototype. React can only pass
// plain objects to Client Components, so every row is copied into one.
function toSummary(row: EventRow): EventSummary {
  return {
    id: row.id,
    slug: row.slug,
    title: row.title,
    city: row.city,
    venue: row.venue,
    startsAt: row.starts_at,
    capacity: row.capacity,
    going: row.going,
  }
}

const SUMMARY_COLUMNS = `
  e.id, e.slug, e.title, e.city, e.venue, e.starts_at, e.capacity,
  (SELECT count(*) FROM rsvps r WHERE r.event_id = e.id) AS going`

export async function getUpcomingEvents(limit = 6): Promise<EventSummary[]> {
  const rows = db()
    .prepare(
      `SELECT ${SUMMARY_COLUMNS} FROM events e
       WHERE e.starts_at >= ? ORDER BY e.starts_at LIMIT ?`,
    )
    .all(new Date().toISOString(), limit) as EventRow[]
  return rows.map(toSummary)
}

export async function searchEvents(query: string, city: string): Promise<EventSummary[]> {
  const rows = db()
    .prepare(
      `SELECT ${SUMMARY_COLUMNS} FROM events e
       WHERE e.starts_at >= ?
         AND (? = '' OR e.title LIKE '%' || ? || '%' OR e.description LIKE '%' || ? || '%')
         AND (? = '' OR e.city = ?)
       ORDER BY e.starts_at`,
    )
    .all(new Date().toISOString(), query, query, query, city, city) as EventRow[]
  return rows.map(toSummary)
}

export async function getCities(): Promise<string[]> {
  const rows = db().prepare('SELECT DISTINCT city FROM events ORDER BY city').all() as {
    city: string
  }[]
  return rows.map((row) => row.city)
}

export async function getEvent(slug: string): Promise<EventDetail | null> {
  const row = db()
    .prepare(
      `SELECT ${SUMMARY_COLUMNS}, e.description, u.id AS organizer_id, u.name AS organizer_name
       FROM events e JOIN users u ON u.id = e.organizer_id
       WHERE e.slug = ?`,
    )
    .get(slug) as (EventRow & { description: string; organizer_id: number; organizer_name: string }) | undefined
  if (!row) return null
  return {
    ...toSummary(row),
    description: row.description,
    organizer: { id: row.organizer_id, name: row.organizer_name },
  }
}

export async function getComments(eventId: number): Promise<Comment[]> {
  const rows = db()
    .prepare(
      `SELECT c.id, c.body, c.created_at, u.id AS author_id, u.name AS author_name
       FROM comments c JOIN users u ON u.id = c.user_id
       WHERE c.event_id = ? ORDER BY c.created_at, c.id`,
    )
    .all(eventId) as {
    id: number
    body: string
    created_at: string
    author_id: number
    author_name: string
  }[]
  return rows.map((row) => ({
    id: row.id,
    body: row.body,
    createdAt: row.created_at,
    author: { id: row.author_id, name: row.author_name },
  }))
}
```

Every function is `async` even though the queries are synchronous. That keeps the interface the same as for any
other database (you could swap in PostgreSQL without touching a page), and it is what chapter 7 needs to cache them.
The query in `searchEvents` uses SQLite's `||` string concatenation to build the `LIKE` pattern from a placeholder
value, so the user's search text is still never part of the SQL. Dates are stored as ISO 8601 strings in UTC, which
sort correctly as text.

Update `components/event-card.tsx` to import the type from the new module:

```tsx
import type { EventSummary } from '@/lib/data'
```

and add a skeleton for loading states at the end of the same file (you'll use it below):

```tsx
// components/event-card.tsx (add)
export function EventGridSkeleton({ count = 3 }: { count?: number }) {
  return (
    <div className={styles.grid} aria-busy="true" aria-label="Loading events">
      {Array.from({ length: count }, (_, i) => (
        <div key={i} className={`skeleton ${styles.skeletonCard}`} />
      ))}
    </div>
  )
}
```

## When does a query run?

Use the new data layer on the home page in the most obvious way:

```tsx
// app/page.tsx — first attempt (hero section omitted)
import { getUpcomingEvents } from '@/lib/data'
import { EventGrid } from '@/components/event-card'

export default async function HomePage() {
  const events = await getUpcomingEvents(6)
  return (
    <main>
      <h2>Coming up</h2>
      <EventGrid events={events} />
    </main>
  )
}
```

The page works in development. `npm run build` fails:

```text
Error: Route "/": Next.js encountered the unstable value `new Date()` while prerendering.

This value can change between renders, so it must be either prerendered or computed later.

Ways to fix this:
  - [dynamic] Render at request time by adding a dynamic data access (e.g. `await connection()`) before this call
  - [cache] Prerender and cache the value with `"use cache"`
  - [client] Render the value on the client with `"use client"`
  - [measure] If the value is for telemetry, use a timing API such as `performance.now()`

Learn more: https://nextjs.org/docs/messages/blocking-prerender-current-time
    at o (lib/data.ts:66:10)
    at r (app/page.tsx:9:24)
> 66 |     .all(new Date().toISOString(), limit) as EventRow[]
     |          ^
```

You saw this error in chapter 1. The build tries to **prerender** the home page; `getUpcomingEvents` asks for "events
after now", and "now" at build time is not "now" when a visitor arrives. Next.js stops and asks what you mean.

The error is lucky. Consider a query without a clock, like `getCities()`. A page that renders it directly builds
without complaint and is marked `○` (static). Then add an event in a new city to the database and reload: the new
city never appears. The query ran **once, at build time**, and its result was frozen into the static HTML.

That's the subtle part of a synchronous database: Next.js decides what can be prerendered by watching what your
components wait for. Network requests (`fetch`, an async database driver talking to a server) take time to resolve,
so Next.js sees that the component is waiting on external data and refuses to prerender it unless you cache it or put
it behind a Suspense boundary. A synchronous SQLite query finishes immediately, and to Next.js it looks the same as
computing `2 + 2`: a pure, prerenderable value.

So, for every piece of data, *you* decide when it may be computed:

| Choice | How | Effect |
|---|---|---|
| At request time, every time | `await connection()` before the read, inside `<Suspense>` | Always fresh; the rest of the page is still prerendered |
| Cached, refreshed on a schedule or on demand | `'use cache'` with `cacheLife` and `cacheTag` | Prerendered and fast; you control staleness (chapter 7) |
| At build time only | Nothing (synchronous, no clock) | Frozen until the next deploy; only right for truly static data |

Chapter 7 is about the second row, which is what Gather will use for most of its data. In this chapter, make every
read happen at request time, so that data in the page is always current.

### `connection()`

`connection()` from `next/server` returns a promise that, during prerendering, never resolves: it marks "everything
after this point depends on an actual request". At request time it resolves immediately. Put it in a component
inside a Suspense boundary, and Next.js prerenders everything around the boundary (plus the fallback) and renders the
inside for each request:

```tsx
// app/page.tsx
import Image from 'next/image'
import Link from 'next/link'
import { Suspense } from 'react'
import { connection } from 'next/server'
import { getUpcomingEvents } from '@/lib/data'
import { EventGrid, EventGridSkeleton } from '@/components/event-card'
import styles from './page.module.css'
import gathering from '@/public/gathering.png'

export default function HomePage() {
  return (
    <main>
      <section className={styles.hero}>
        {/* ...hero unchanged from chapter 5... */}
      </section>

      <h2>Coming up</h2>
      <Suspense fallback={<EventGridSkeleton count={6} />}>
        <UpcomingEvents />
      </Suspense>
    </main>
  )
}

async function UpcomingEvents() {
  // Render this part for every request, so new events show up at once.
  // Chapter 7 replaces this with caching.
  await connection()
  const events = await getUpcomingEvents(6)
  return <EventGrid events={events} />
}
```

Notice the shape: the page itself is no longer `async`. It renders the static parts (hero, heading) and a Suspense
boundary around a small async component that does the data work. This "static shell with dynamic holes" pattern is
the core of rendering with Cache Components, and you'll use it everywhere. The build now succeeds, and the home page
is `◐`: the hero and the skeleton are in the prerendered shell, the events are rendered for each request.

The other two pages read `searchParams` and `params`, which already make them request-time (chapter 3's
`loading.tsx` provides their boundary), so their queries run per request without `connection()`:

```tsx
// app/events/page.tsx
import Link from 'next/link'
import { EventGrid } from '@/components/event-card'
import { getCities, searchEvents } from '@/lib/data'

export default async function EventsPage({ searchParams }: PageProps<'/events'>) {
  const { city } = await searchParams
  const selected = typeof city === 'string' ? city : ''
  // Two independent queries: start both, then wait for both.
  const [cities, events] = await Promise.all([getCities(), searchEvents('', selected)])

  return (
    <main>
      <h1>All events</h1>
      <p>
        <Link href="/events">All cities</Link>
        {cities.map((name) => (
          <span key={name}>
            {' · '}
            <Link href={`/events?city=${encodeURIComponent(name)}`}>{name}</Link>
          </span>
        ))}
      </p>
      <EventGrid events={events} />
    </main>
  )
}
```

### `fetch` in Server Components

Many apps load data from HTTP APIs instead of (or as well as) a database. In a Server Component, use the standard
`fetch`:

```tsx
async function RepoStars() {
  const res = await fetch('https://api.github.com/repos/vercel/next.js', {
    headers: { Accept: 'application/vnd.github+json' },
  })
  if (!res.ok) throw new Error(`GitHub responded with ${res.status}`)
  const repo = (await res.json()) as { stargazers_count: number }
  return <p>{repo.stargazers_count.toLocaleString('en-US')} stars on GitHub</p>
}
```

What you need to know about `fetch` in Next.js 16:

- **It is not cached.** Each call makes a real request. (Next.js 14 cached `fetch` by default; Next.js 15 and later
  don't. Old tutorials that rely on that caching are wrong for current versions.)
- **Network requests are detected as dynamic.** Unlike the synchronous SQLite query, a `fetch` takes time, so during
  prerendering Next.js sees a component waiting for it and requires you to either wrap it in `<Suspense>` (fresh at
  request time) or cache it with `'use cache'` (chapter 7). Otherwise the build fails with "Next.js encountered
  uncached or runtime data during prerendering".
- **Identical requests are memoized within one render.** If three components call `fetch` with the same URL and
  options while rendering one request, Next.js makes a single network request and shares the response. So you can
  fetch data where it's needed instead of fetching once at the top and passing it down.
- Handle errors. A thrown error is caught by the nearest error boundary (chapter 10).

## Streaming

With Suspense boundaries, a page can be sent in pieces: first everything that's ready, with fallbacks in place of
what isn't, then each missing piece as soon as it's rendered, **in the same HTTP response**. This is streaming, and
it is why a slow query doesn't make the whole page slow.

The event page is a good candidate. The details are quick to load, but imagine comments come from a slower service.
Put them in their own component and boundary:

```tsx
// app/events/[slug]/page.tsx
import Link from 'next/link'
import { notFound } from 'next/navigation'
import { Suspense } from 'react'
import { getComments, getEvent } from '@/lib/data'
import { formatEventDate, spotsLeft } from '@/lib/format'
import { ShareButton } from '@/components/share-button'
import styles from './event.module.css'

export default async function EventPage({ params }: PageProps<'/events/[slug]'>) {
  const { slug } = await params
  const event = await getEvent(slug)
  if (!event) notFound()

  return (
    <main>
      <Link href="/events">← All events</Link>
      <article className={styles.event}>
        <p className={styles.date}>{formatEventDate(event.startsAt)}</p>
        <h1>{event.title}</h1>
        <p className="muted">
          {event.venue}, {event.city} · organized by {event.organizer.name}
        </p>
        <div className={styles.rsvp}>
          <p>
            <strong>{event.going}</strong> going · {spotsLeft(event.capacity, event.going)}
          </p>
          <ShareButton title={event.title} path={`/events/${event.slug}`} />
        </div>
        <p className={styles.description}>{event.description}</p>

        <section>
          <h2>Discussion</h2>
          <Suspense fallback={<p className="muted">Loading comments…</p>}>
            <Comments eventId={event.id} />
          </Suspense>
        </section>
      </article>
    </main>
  )
}

async function Comments({ eventId }: { eventId: number }) {
  const comments = await getComments(eventId)
  if (comments.length === 0) return <p className="muted">No comments yet.</p>
  return (
    <ul className={styles.comments}>
      {comments.map((comment) => (
        <li key={comment.id}>
          <strong>{comment.author.name}</strong>{' '}
          <span className="muted">{formatEventDate(comment.createdAt)}</span>
          <p>{comment.body}</p>
        </li>
      ))}
    </ul>
  )
}
```

and add the comment styles to `event.module.css`:

```css
/* app/events/[slug]/event.module.css (add) */
.comments {
  padding: 0;
  list-style: none;
}

.comments li {
  padding: 0.75rem 0;
  border-bottom: 1px solid var(--border);
}

.comments p {
  margin: 0.25rem 0 0;
}
```

To *see* streaming, make the comments slow on purpose. Add this line at the top of `Comments` (and remove it again
afterwards):

```tsx
await new Promise((resolve) => setTimeout(resolve, 2000)) // pretend the query is slow
```

Build, start, and watch the response arrive. This small script prints when each chunk of the HTML response arrives
and which interesting strings it contains:

```js
// chunks.mjs — run with: node chunks.mjs
const start = Date.now()
const res = await fetch('http://localhost:3000/events/nextjs-meetup-berlin')
const decoder = new TextDecoder()
for await (const chunk of res.body) {
  const text = decoder.decode(chunk)
  const hints = ['Next.js Meetup Berlin', 'Loading comments', 'Will the talks'].filter((h) => text.includes(h))
  console.log(`${String(Date.now() - start).padStart(5)} ms  ${String(chunk.length).padStart(6)} bytes  ${hints.join(' | ')}`)
}
```

```text
  275 ms    2212 bytes
  358 ms    7073 bytes  Next.js Meetup Berlin | Loading comments
  359 ms      73 bytes
  359 ms    1853 bytes  Next.js Meetup Berlin | Loading comments
  360 ms    5173 bytes
  360 ms    1425 bytes  Next.js Meetup Berlin | Loading comments
 2291 ms     213 bytes  Will the talks
 2291 ms     370 bytes  Will the talks
 2294 ms      14 bytes
```

The first chunk is the prerendered static shell: header, footer and the "Loading…" fallback from `loading.tsx`,
sent immediately. A few milliseconds later the event details arrive, with "Loading comments…" in place of the
discussion. Two seconds later the comments arrive, and the response ends. In the browser, the user reads the event
while the comments load.

How does HTML that arrives later end up in the right place? Look at the raw response:

```bash
curl -s http://localhost:3000/events/nextjs-meetup-berlin | grep -o '<template id="B:[^"]*"></template>.\{0,50\}'
```

```text
<template id="B:0"></template><p>Loading…</p><!--/$--><!--/&--><!--/&--><footer class="m
<template id="B:2"></template><p class="muted">Loading comments…</p><!--/$--></section><
```

Each Suspense boundary's fallback is marked with an empty `<template id="B:…">`. When the content is ready, React
sends it in a `<div hidden id="S:…">` at the end of the stream, followed by a tiny inline script call
(`$RC("B:2","S:2")`) that moves the content into place and removes the fallback. Content arrives in order of
readiness, not page order, and the page doesn't need the main JavaScript bundle to show it. The same data is also
streamed in the RSC payload (`self.__next_f.push(...)` scripts), which React uses when it hydrates.

Remove the artificial delay before continuing.

### `loading.tsx` versus `<Suspense>`

`loading.tsx` is a convenience: Next.js wraps the segment's page (and the segments below it) in a Suspense boundary
with your component as the fallback. It's coarse: everything in the page waits for the slowest part. Explicit
`<Suspense>` boundaries inside the page give you control over what appears when:

| | `loading.tsx` | `<Suspense>` in the page |
|---|---|---|
| Granularity | Whole page (per route segment) | Any subtree |
| Static parts of the page in the shell | No (only layouts and the fallback) | Yes (everything outside the boundary) |
| Also shown during client navigation | Yes | Yes |
| Effort | One file | One boundary per independent part |

Use both: a `loading.tsx` as a safety net for a segment, and Suspense boundaries for the parts you want to stream
separately. In chapter 7 Gather replaces its `loading.tsx` with finer boundaries.

### Designing loading states

- **Reserve the space.** A fallback with roughly the size of the real content (a skeleton) avoids layout shift when
  the content arrives. `EventGridSkeleton` renders gray cards the size of real cards.
- **Put boundaries around independent parts.** Two parts that load independently get separate boundaries, so the
  fast one doesn't wait for the slow one.
- **Don't scatter spinners.** Too many tiny boundaries make a page "pop" in a dozen places. Group things that belong
  together visually under one boundary.
- **Keep meaningful content in the shell.** Headings, navigation and the main text should be outside boundaries
  whenever their data allows it. The shell should be useful on its own.
- **Make fallbacks accessible.** `aria-busy="true"` and a label tell screen readers that content is loading.

### What streaming changes about status codes and headers

Once the first chunk is sent, the status code and headers are final. That's why, as you saw in chapter 3, a
`notFound()` inside a streamed part produces a 200 response with a `noindex` meta tag instead of a 404, and a
`redirect()` inside a streamed part becomes a client-side redirect. Search engines handle this fine. If a correct
status code is essential, the check must run before streaming starts (in Proxy, chapter 12).

## Avoiding waterfalls

A **waterfall** is a sequence of requests where each one waits for the previous one to finish, even though it
doesn't need its result. It's the most common performance problem in data loading.

```tsx
// ✗ sequential: the second query waits for the first for no reason
const cities = await getCities()
const events = await searchEvents('', selected)
```

```tsx
// ✓ parallel: both start immediately; total time = the slower of the two
const [cities, events] = await Promise.all([getCities(), searchEvents('', selected)])
```

With SQLite the difference is negligible because queries take microseconds, but with network calls (each taking, say,
50 ms) three sequential awaits cost 150 ms and three parallel ones 50 ms. Make it a habit.

Sometimes requests are *genuinely* dependent: you need the event before you can load its comments by `event.id`.
That's fine. Structure components so that dependent data is loaded by a child component inside its own Suspense
boundary (as `Comments` does), so the parent can render and stream while the child waits.

Component nesting can also create waterfalls without you noticing: if a parent awaits its data before rendering a
child that awaits its own, the child's request starts only after the parent's finished. Two ways out:

- Render siblings in separate Suspense boundaries, so they load in parallel.
- **Preload**: start the child's request early by calling the function without `await`, and let the child call it
  again later. This only helps if the second call reuses the first one's result, which is what the next section is
  about.

`Promise.all` rejects as soon as one promise rejects. If one failing part shouldn't break the others, use
`Promise.allSettled`, or (better) give each part its own boundary and error boundary.

## Deduplicating within a request: `React.cache`

Often several components need the same data during one render: the page needs the event, and so do the metadata
(chapter 14) and maybe a breadcrumb in a layout. Passing it down through props is awkward. Instead, wrap the
function in `cache` from React, and every call with the same arguments **during the same request** shares one
result:

```ts
import { cache } from 'react'

export const getEventCached = cache(async (slug: string) => {
  // runs at most once per slug per request
  return getEvent(slug)
})
```

`React.cache` is per request: nothing is shared between two visitors or two requests, so it is always safe, even for
personalized data. `fetch` gets the same treatment automatically. Gather won't need `React.cache` explicitly: in
chapter 7 its data functions get `'use cache'`, which also deduplicates calls (and more).

## Streaming data into Client Components with `use`

Sometimes a Client Component needs server data: an interactive list you can sort or filter in the browser, a chart,
an editor. You could await the data in the Server Component and pass it as a prop, but then the parent waits. You
can instead **pass the promise**, without awaiting it, and let the Client Component read it with React's `use`:

```tsx
// components/sortable-comments.tsx
'use client'

import { use, useState } from 'react'
import type { Comment } from '@/lib/data'
import { formatEventDate } from '@/lib/format'

export function SortableComments({ commentsPromise }: { commentsPromise: Promise<Comment[]> }) {
  const comments = use(commentsPromise)
  const [newestFirst, setNewestFirst] = useState(false)
  const sorted = newestFirst ? comments.toReversed() : comments

  return (
    <>
      <button type="button" className="button secondary" onClick={() => setNewestFirst(!newestFirst)}>
        {newestFirst ? 'Oldest first' : 'Newest first'}
      </button>
      <ul>
        {sorted.map((comment) => (
          <li key={comment.id}>
            <strong>{comment.author.name}</strong>{' '}
            <span className="muted">{formatEventDate(comment.createdAt)}</span>
            <p>{comment.body}</p>
          </li>
        ))}
      </ul>
    </>
  )
}
```

```tsx
// in the event page (a Server Component): start the query, don't await it
<Suspense fallback={<p className="muted">Loading comments…</p>}>
  <SortableComments commentsPromise={getComments(event.id)} />
</Suspense>
```

The page renders immediately; React streams the promise's result to the browser when it resolves, and `use`
suspends the Client Component until then, showing the nearest Suspense fallback. The comments are still in the
initial HTML (the Client Component also renders on the server once the promise resolves). The resolved value must be
serializable, like any prop.

Gather keeps its comment list as a Server Component, because nothing about it is interactive. Reach for this pattern
when the interactivity is real.

### Fetching from the client

You can still fetch from Client Components: for data that changes while the user looks at the page (live scores,
notifications polling), for infinite scrolling, or for data that only exists in the browser. Use a Route Handler as
the endpoint (chapter 11) and a library like SWR or TanStack Query, which handle caching, revalidation and request
deduplication on the client. Don't use it as the default way to load a page's data: you'd be rebuilding the waterfall
that Server Components remove.

## Key takeaways

- Server Components load data with `await`, directly from a database or API. Keep all queries in a data access layer
  (`lib/data.ts`) that imports `server-only`.
- `node:sqlite` is a synchronous, embedded SQL database built into Node.js 24. Use placeholders, a single connection on
  `globalThis`, WAL mode and a busy timeout, and copy its null-prototype rows into plain objects.
- With Cache Components, Next.js prerenders whatever it can. A synchronous query with no request data is prerendered
  at build time and frozen. Decide explicitly: request time (`connection()` inside `<Suspense>`), cached
  (`'use cache'`, chapter 7), or build time.
- `fetch` isn't cached by default in Next.js 15+, is memoized within a render, and must be cached or inside Suspense.
- `<Suspense>` boundaries (and `loading.tsx`) let the server stream a page: the shell first, then each part when it's
  ready, all in one response. Design fallbacks that reserve space. After streaming starts, status codes and headers
  can't change.
- Avoid waterfalls: start independent requests together with `Promise.all`, and move dependent data into child
  components with their own boundaries.
- `React.cache` deduplicates a function's calls within one request. A promise passed to a Client Component can be read
  with `use`, streaming data into interactive components.

## Exercises

1. **Read the database directly.** Use Node.js (not Next.js) to list the titles and start dates of all events in
   `data/gather.db`, ordered by date, in a one-off script. Then answer: why can this script run while `npm run dev` is
   running?

<details>
<summary>Solution</summary>

```bash
node -e "
const { DatabaseSync } = require('node:sqlite')
const db = new DatabaseSync('data/gather.db', { readOnly: true })
for (const row of db.prepare('SELECT title, starts_at FROM events ORDER BY starts_at').all()) {
  console.log(row.starts_at.slice(0, 16), row.title)
}"
```

SQLite allows several processes to open the same file. In WAL mode, readers don't block the writer and the writer
doesn't block readers, so a script (or the SQLite command-line shell, `sqlite3 data/gather.db`) can read while the
dev server is running. Only two simultaneous *writers* have to take turns, which is what `busy_timeout` handles.

</details>

2. **Find the frozen page.** Create a page `app/cities/page.tsx` that lists `await getCities()` with no Suspense
   boundary and no `connection()`. Build and start the app, then add an event in a new city with a one-off script.
   Does the page show it? Fix the page so that it does, without changing `lib/data.ts`.

<details>
<summary>Solution</summary>

The page builds as `○` (static): the query is synchronous and doesn't read the clock, so it runs once during
`next build` and its result is part of the static HTML. A new city added afterwards never appears.

```tsx
// app/cities/page.tsx
import { Suspense } from 'react'
import { connection } from 'next/server'
import { getCities } from '@/lib/data'

export default function CitiesPage() {
  return (
    <main>
      <h1>Cities</h1>
      <Suspense fallback={<p>Loading…</p>}>
        <CityList />
      </Suspense>
    </main>
  )
}

async function CityList() {
  await connection()
  const cities = await getCities()
  return <ul>{cities.map((city) => <li key={city}>{city}</li>)}</ul>
}
```

Now the route is `◐`: the heading is prerendered and the list is rendered per request. Chapter 7 shows the better
fix for data like this, which changes rarely: cache it with a lifetime.

</details>

3. **Two boundaries.** On the event page, the comments are streamed. Add a second slow part, an "Other events in this
   city" list that takes 1 second (simulate it with a timeout), as its own async component. Arrange the boundaries so
   that the comments (2 seconds) and the city list (1 second) arrive independently, and verify with `chunks.mjs` that
   the page finishes after about 2 seconds, not 3.

<details>
<summary>Solution</summary>

```tsx
async function MoreInCity({ city, excludeId }: { city: string; excludeId: number }) {
  await new Promise((resolve) => setTimeout(resolve, 1000))
  const events = (await searchEvents('', city)).filter((e) => e.id !== excludeId)
  if (events.length === 0) return null
  return (
    <section>
      <h2>More in {city}</h2>
      <EventGrid events={events} />
    </section>
  )
}
```

```tsx
<Suspense fallback={<p className="muted">Loading comments…</p>}>
  <Comments eventId={event.id} />
</Suspense>
<Suspense fallback={<EventGridSkeleton count={2} />}>
  <MoreInCity city={event.city} excludeId={event.id} />
</Suspense>
```

Sibling boundaries render in parallel: the city list chunk arrives after about 1 second, the comments after about 2,
and the response ends after about 2 seconds. If you nested one boundary inside the other component (or awaited both
in one component), you'd get 3 seconds.

</details>

4. **Fix the waterfall.** This component takes 300 ms. Without changing the three functions, make it take about
   100 ms.

```tsx
async function Stats() {
  const users = await countUsers() // 100 ms
  const events = await countEvents() // 100 ms
  const rsvps = await countRsvps() // 100 ms
  return <p>{users} members · {events} events · {rsvps} RSVPs</p>
}
```

<details>
<summary>Solution</summary>

```tsx
async function Stats() {
  const [users, events, rsvps] = await Promise.all([countUsers(), countEvents(), countRsvps()])
  return <p>{users} members · {events} events · {rsvps} RSVPs</p>
}
```

The three calls are independent, so starting them together makes the total time equal to the slowest one. If one of
them may fail and the others should still show, use `Promise.allSettled` and render a placeholder for the failed one.

</details>

5. **A promise across the boundary.** Replace the event page's `Comments` with the `SortableComments` Client Component
   from this chapter, passing the promise. Then answer: what does the browser receive if JavaScript is disabled? And
   what would go wrong if `getComments` returned the raw `node:sqlite` rows?

<details>
<summary>Solution</summary>

With JavaScript disabled, the comments are still in the HTML: the Client Component is rendered on the server once the
promise resolves, and its HTML is streamed like any other boundary's. Only the "Newest first" button doesn't work,
since there is no JavaScript to handle the click.

If `getComments` returned the raw rows, the resolved value would contain objects with a `null` prototype. React can
only serialize plain objects, arrays and the other supported types across the server/client boundary, so rendering
fails with "Only plain objects, and a few built-ins, can be passed to Client Components from Server Components.
Classes or null prototypes are not supported." That's why the data layer maps every row into a plain object.

</details>

---
← Previous: [Styling, fonts and images](05-styling-fonts-and-images.md) | Next: [Cache Components and rendering](07-cache-components-and-rendering.md) →
