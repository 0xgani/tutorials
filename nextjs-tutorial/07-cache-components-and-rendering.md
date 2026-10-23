# Chapter 7 — Cache Components and rendering

## What you'll learn

- The rendering model of Next.js 16: a prerendered static shell with cached and dynamic parts (Partial Prerendering)
- The three kinds of content in a page: static, cached and runtime, and how Next.js decides which is which
- `'use cache'` on functions and components, and how cache keys work
- `cacheLife`: the `stale`, `revalidate` and `expire` times, the built-in profiles, and choosing lifetimes
- `cacheTag`: labelling cached data so it can be invalidated (used in chapter 8)
- Structuring pages to maximize the static shell: pushing runtime data down behind `<Suspense>`
- `generateStaticParams` and incremental static regeneration with Cache Components
- Reading the build output and the response headers to see what was cached
- The errors Cache Components shows you, and how to fix each one

## Where we are

At the end of chapter 6, every query in Gather runs at request time. That's always correct, but wasteful: every
visitor to the home page triggers the same query, for a list that changes a few times a day. The alternative you saw,
letting a query run at build time, freezes data until the next deploy. What Gather needs is in between: **compute
once, reuse for everyone, refresh when it's old or when it changes**. That's what Cache Components is for.

## The model: static shell, cached data, runtime data

With `cacheComponents: true` (the default in new 16.4 projects), Next.js prerenders every route at build time, as far
as it can. During prerendering, every part of the component tree falls into one of three categories:

| Kind | Examples | What Next.js does |
|---|---|---|
| **Static** | JSX, imports, pure computations, synchronous reads of local data | Rendered at build time into the static shell |
| **Cached** | Functions and components marked `'use cache'` | Run at build time, stored with a lifetime, included in the static shell; refreshed in the background when stale |
| **Runtime** | `cookies()`, `headers()`, `searchParams`, unknown `params`, `connection()`, uncached `fetch` and other I/O | Not prerendered; must be inside `<Suspense>`, whose fallback goes in the shell; rendered for each request and streamed |

The result for each route is a **static shell**: HTML (plus the RSC payload for client navigation) containing
everything static and cached, and fallbacks wherever runtime data is needed. When a request comes in, the server sends
the shell immediately and then streams the runtime parts into it. This is **Partial Prerendering** (PPR), and it is
simply how pages render when Cache Components is on.

```text
┌────────────────── /events/nextjs-meetup-berlin ──────────────────┐
│ header, nav, footer                                    static    │
│ ┌──────────────────────────────────────────────────────────────┐ │
│ │ event title, date, venue, description        cached ('days') │ │
│ │ ┌──────────────────────────────┐                             │ │
│ │ │ your RSVP status   runtime   │ ← fallback in the shell,    │ │
│ │ │ (reads the session cookie)   │   streamed per request      │ │
│ │ └──────────────────────────────┘                             │ │
│ │ comments                                   cached ('minutes')│ │
│ └──────────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────────┘
```

The rule that makes this work: **Next.js won't guess**. If a component reads runtime data or does uncached I/O
outside a Suspense boundary, the build fails and the dev server shows an error. You've seen two such errors already
(`new Date()` in chapter 1, `searchParams` in chapter 3). They are not obstacles; they are the framework asking you
to decide, for each piece of data, whether it is static, cached or per-request.

## `'use cache'`

The `'use cache'` directive marks a function (or a component, or a whole file) whose result can be cached. Put it
as the first statement of an async function:

```ts
import { cacheLife, cacheTag } from 'next/cache'

export async function getUpcomingEvents(limit = 6): Promise<EventSummary[]> {
  'use cache'
  cacheLife('hours')
  cacheTag('events')
  const rows = db()
    .prepare(
      `SELECT ${SUMMARY_COLUMNS} FROM events e
       WHERE e.starts_at >= ? ORDER BY e.starts_at LIMIT ?`,
    )
    .all(new Date().toISOString(), limit) as EventRow[]
  return rows.map(toSummary)
}
```

The first call runs the function and stores its return value; later calls with the same arguments return the stored
value until it expires. The function still uses `new Date()`, but now that's fine: inside a cached function, "now"
means "when this cache entry was computed", and you have said how long that may be reused.

### Cache keys

A cache entry is identified by:

1. the **build ID** (every deploy starts with an empty cache),
2. the **function's identity** (a hash of its location in your code),
3. its **arguments**, serialized (`getUpcomingEvents(6)` and `getUpcomingEvents(4)` are different entries),
4. any **variables it captures** from an enclosing scope (closures are treated as extra arguments).

So `getEvent('nextjs-meetup-berlin')` and `getEvent('typescript-101-nairobi')` are cached separately, and the cache can
hold many entries for one function. Arguments and return values must be serializable, like props to Client
Components: primitives, plain objects, arrays, `Date`, `Map`, `Set`, and (as return values) JSX. Class instances,
functions and `URL` objects aren't.

### Where to put it

`'use cache'` works at three levels:

```tsx
// 1. A data function (Gather's choice: one place, reused by every page)
export async function getCities() {
  'use cache'
  // ...
}

// 2. A component: caches its rendered output
async function PopularEvents() {
  'use cache'
  cacheLife('hours')
  const events = await getPopular()
  return <EventGrid events={events} />
}

// 3. A file: put 'use cache' at the top, and every export must be an async function, each cached
```

Caching **data functions** is the most reusable: every page and component that calls `getEvent(slug)` shares one
entry. Caching a **component** saves the render work too, which is useful for expensive markup (a Markdown renderer,
a big table). Gather caches at the data level.

Cached components can still render dynamic children they receive as props, as long as they don't inspect them:

```tsx
async function CachedFrame({ children }: { children: React.ReactNode }) {
  'use cache'
  const nav = await getNavigation() // cached
  return (
    <div>
      <Sidebar items={nav} />
      {children} {/* passed through untouched; may be dynamic */}
    </div>
  )
}
```

### What can't go inside

Cached functions run in isolation: they can't read anything specific to one request. Calling `cookies()`, `headers()`
or reading `searchParams` inside a `'use cache'` scope (or in any function it calls) is an error:

```text
Error: Route "/dashboard": `cookies()` can't be read inside `"use cache"`. Read it outside the cached function and
pass what you need as an argument.
```

The fix is in the message: read the runtime value outside, and pass what the cached function needs as an argument,
where it becomes part of the cache key:

```tsx
async function Greeting() {
  const userId = (await cookies()).get('user')?.value // runtime: outside the cache
  return <Recommendations userId={userId ?? 'anonymous'} />
}

async function Recommendations({ userId }: { userId: string }) {
  'use cache' // one entry per userId
  cacheLife('hours')
  const events = await getRecommendedEvents(userId)
  return <EventGrid events={events} />
}
```

This is exactly how Gather handles everything personal: its data functions never read the session themselves;
callers resolve the current user first and pass the ID in (chapter 15). For the rare case where a cached function
must read cookies itself, there is `'use cache: private'` (chapter 16).

## `cacheLife`: how long is cached data good for?

`cacheLife` sets the lifetime of the current cache entry. A lifetime has three numbers (in seconds):

| Property | Meaning |
|---|---|
| `stale` | How long the **browser** (the client router) may reuse the data without asking the server. |
| `revalidate` | After this time, the next request still gets the cached data immediately, and the server **regenerates it in the background** for the requests after it (stale-while-revalidate). |
| `expire` | After this time **without any request**, the entry is too old to serve; the next request waits for fresh data. |

```text
time since the entry was created ──────────────────────────────────────────▶
0                 revalidate                                 expire
│   fresh: served │  stale: served, and refreshed in the     │  expired: next request
│   from cache    │  background on the next request         │  waits for a fresh render
```

You rarely write the numbers yourself. Next.js has named **profiles**:

| Profile | Use case | `stale` | `revalidate` | `expire` |
|---|---|---|---|---|
| `default` (no `cacheLife` call) | Standard content | 5 minutes | 15 minutes | never |
| `seconds` | Real-time data | 30 seconds | 1 second | 1 minute |
| `minutes` | Frequently updated content | 5 minutes | 1 minute | 1 hour |
| `hours` | Updated several times a day | 5 minutes | 1 hour | 1 day |
| `days` | Updated daily | 5 minutes | 1 day | 1 week |
| `weeks` | Updated weekly | 5 minutes | 1 week | 30 days |
| `max` | Rarely changes | 5 minutes | 30 days | 1 year |

You can also pass an object, `cacheLife({ stale: 60, revalidate: 300, expire: 3600 })`, or define your own named
profiles (and redefine the built-in ones) under `cacheLife` in `next.config.ts`. Always call `cacheLife` explicitly:
a cached function without it uses `default`, and nested caches can change that silently.

### Lifetimes and prerendering

Very short lifetimes can't be prerendered usefully, so Next.js treats them as runtime data:

- `revalidate: 0` or `expire` under 5 minutes: excluded from the static shell, rendered at request time (it then
  needs a Suspense boundary). Of the presets, only `seconds` falls in this group.
- `stale` under 30 seconds: also excluded, because a prefetched copy would expire before the user could click.
- `stale` between 30 seconds and 5 minutes: prerendered, but not part of the App Shell that is shared across URLs
  (chapter 9).

### Choosing lifetimes for Gather

Lifetimes are product decisions. Ask: if this data changes, how long can visitors see the old version, and does a
user who *made* the change need to see it at once? (The second question is answered by tags, in chapter 8.)

| Function | Profile | Reasoning |
|---|---|---|
| `getUpcomingEvents` | `hours` | New events appear a few times a day; organizers' own changes invalidate it via a tag anyway |
| `searchEvents` | `minutes` | Many different keys (every search), each short-lived |
| `getCities` | `days` | Changes only when an event in a new city is added |
| `getEvent` | `days` | Event details rarely change, and edits invalidate the entry by tag |
| `getComments` | `minutes` | Conversations move faster; a new comment also invalidates by tag |

Update `lib/data.ts`: import the two functions and add three lines to each data function.

```ts
// lib/data.ts (the changed parts)
import 'server-only'
import { cacheLife, cacheTag } from 'next/cache'
import { db } from './db'

// The read side of Gather's data access layer: every page reads data through
// these functions, never with SQL of its own. Every function is cached with
// 'use cache' and tagged, so mutations can invalidate exactly what they
// changed (chapter 8).

export async function getUpcomingEvents(limit = 6): Promise<EventSummary[]> {
  'use cache'
  cacheLife('hours')
  cacheTag('events')
  // ...query unchanged
}

export async function searchEvents(query: string, city: string): Promise<EventSummary[]> {
  'use cache'
  cacheLife('minutes')
  cacheTag('events')
  // ...
}

export async function getCities(): Promise<string[]> {
  'use cache'
  cacheLife('days')
  cacheTag('events')
  // ...
}

export async function getEvent(slug: string): Promise<EventDetail | null> {
  'use cache'
  cacheLife('days')
  cacheTag('events', `event:${slug}`)
  // ...
}

export async function getComments(eventId: number): Promise<Comment[]> {
  'use cache'
  cacheLife('minutes')
  cacheTag(`comments:${eventId}`)
  // ...
}
```

## `cacheTag`: naming what you cached

`cacheTag` attaches one or more string tags to the current cache entry. Later, when data changes, you invalidate
every entry with a given tag in one call (`updateTag('events')`, chapter 8). Tags are how a cache stays correct
without short lifetimes.

Gather's tag scheme:

| Tag | On entries that contain | Invalidated when |
|---|---|---|
| `events` | Any list of events, the city list, every event's details | Any event is created, edited or deleted |
| `event:<slug>` | One event's details | That event is edited or deleted |
| `comments:<eventId>` | One event's comments | Someone comments on that event |
| `rsvps:<eventId>` | One event's attendee data (chapter 8) | Someone RSVPs to or cancels for that event |
| `dashboard:<userId>` | One user's dashboard (chapter 15) | That user's RSVPs or events change |

Design tags from the point of view of *writes*: for each mutation, which cached reads become wrong? Broad tags
(`events`) are simple and safe; narrow tags (`event:<slug>`) avoid throwing away unrelated entries. Tag values must be
strings of at most 256 characters, and you can attach up to 128 tags per entry. Tags are stored in plain text, so
don't put secrets in them.

## Restructuring the pages

With the data cached, the pages can put more into the static shell.

### The home page: fully static, refreshed hourly

`UpcomingEvents` no longer needs `connection()` or a Suspense boundary. The cached query runs during the build, its
result goes into the static HTML, and Next.js refreshes it in the background once an hour:

```tsx
// app/page.tsx
import Image from 'next/image'
import Link from 'next/link'
import { getUpcomingEvents } from '@/lib/data'
import { EventGrid } from '@/components/event-card'
import styles from './page.module.css'
import gathering from '@/public/gathering.png'

// The home page has no request-time data at all: the hero is static and the
// event list is cached with 'use cache', so the whole page is prerendered.
export default function HomePage() {
  return (
    <main>
      <section className={styles.hero}>
        {/* ...hero unchanged... */}
      </section>

      <h2>Coming up</h2>
      <UpcomingEvents />
    </main>
  )
}

async function UpcomingEvents() {
  const events = await getUpcomingEvents(6)
  return <EventGrid events={events} />
}
```

### The events list: a static heading, a streamed search

The list depends on the query string, which is runtime data. Instead of awaiting `searchParams` at the top of the page
(which makes the whole page wait), pass the promise down to the component that needs it, inside a boundary:

```tsx
// app/events/page.tsx
import Link from 'next/link'
import { Suspense } from 'react'
import { getCities, searchEvents } from '@/lib/data'
import { EventGrid, EventGridSkeleton } from '@/components/event-card'

// searchParams is runtime data: it only exists once a request arrives. The
// heading is part of the static shell; everything that reads the query
// string streams in behind <Suspense>.
export default function EventsPage({ searchParams }: PageProps<'/events'>) {
  return (
    <main>
      <h1>All events</h1>
      <Suspense fallback={<EventGridSkeleton count={6} />}>
        <SearchableEvents searchParams={searchParams} />
      </Suspense>
    </main>
  )
}

function first(value: string | string[] | undefined): string {
  return (Array.isArray(value) ? value[0] : value)?.trim() ?? ''
}

async function SearchableEvents({ searchParams }: Pick<PageProps<'/events'>, 'searchParams'>) {
  const city = first((await searchParams).city)
  const [cities, events] = await Promise.all([getCities(), searchEvents('', city)])

  return (
    <>
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
    </>
  )
}
```

Delete `app/events/loading.tsx`: the page now has a precise boundary of its own. Note that the *component* that reads
`searchParams` isn't cached, but the functions it calls are: `searchEvents('', 'Lisbon')` is computed once and
reused by every visitor who filters by Lisbon in the next minute. The `first` helper takes the first value when a
parameter is repeated (chapter 3's exercise).

### The event page: a shell that works for every slug

```tsx
// app/events/[slug]/page.tsx
import Link from 'next/link'
import { notFound } from 'next/navigation'
import { Suspense } from 'react'
import { getComments, getEvent, getUpcomingEvents } from '@/lib/data'
import { formatEventDate, spotsLeft } from '@/lib/format'
import { ShareButton } from '@/components/share-button'
import styles from './event.module.css'

// Prerender the next few events at build time. Any other slug is served the
// App Shell on its first visit and upgraded to a static page in the
// background (ISR with Cache Components).
export async function generateStaticParams() {
  const events = await getUpcomingEvents(4)
  return events.map((event) => ({ slug: event.slug }))
}

export default function EventPage({ params }: PageProps<'/events/[slug]'>) {
  return (
    <main>
      <Link href="/events">← All events</Link>
      {/* The page itself never awaits params: the read happens inside the
          boundary, so an unknown slug still gets an instant App Shell. */}
      <Suspense fallback={<EventSkeleton />}>
        <EventDetails params={params} />
      </Suspense>
    </main>
  )
}

async function EventDetails({ params }: Pick<PageProps<'/events/[slug]'>, 'params'>) {
  const { slug } = await params
  const event = await getEvent(slug)
  if (!event) notFound()

  return (
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
  )
}

// Comments: unchanged from chapter 6

function EventSkeleton() {
  return (
    <div aria-busy="true" aria-label="Loading event" className={styles.event}>
      <div className="skeleton" style={{ height: '1rem', width: '12rem' }} />
      <div className="skeleton" style={{ height: '2.5rem', width: '70%', margin: '1rem 0' }} />
      <div className="skeleton" style={{ height: '8rem' }} />
    </div>
  )
}
```

Two new ideas here.

**`generateStaticParams`** tells Next.js which values of `[slug]` to prerender at build time. It runs during the
build (it can query the database, and here uses the cached `getUpcomingEvents`) and returns an array of params
objects. For each slug it returns, `params` is *known* during prerendering, so `EventDetails` can run at build time
and the whole event page, cached data included, becomes static HTML.

**The page never awaits `params` itself.** For slugs that `generateStaticParams` didn't return (an event created
after the build, an old event), `params` is runtime data. Because the page only reads it inside the boundary,
Next.js can still build one generic shell for the route, the **App Shell**: back link, skeleton, header and footer.
A request for an unknown slug gets that shell instantly, and the details stream in. Next.js then **upgrades** the
URL: it saves the fully rendered page for that slug, so the next visitor gets a static page. That is incremental
static regeneration (ISR) in the Cache Components model, with no configuration.

## Reading the build output

```bash
npm run build
```

```text
Route (app)                         Revalidate  Expire
┌ ○ /                                       1h      1d
├ ○ /_not-found
├ ○ /dashboard
├ ◐ /events
└   /events/[slug]
  ├ ◐ /events/[slug]
  ├ ○ /events/nextjs-meetup-berlin          1m      1h
  ├ ○ /events/react-reading-group           1m      1h
  └ ◐ [+2 more paths]


○  (Static)             prerendered as static content
◐  (Partial Prerender)  prerendered as static HTML with dynamic server-streamed content
```

- `/` is now `○` with **Revalidate 1h, Expire 1d**: fully static, and the page's lifetime is the shortest lifetime of
  the cached data it contains (`hours`).
- `/events` is `◐`: static heading and skeleton, streamed results.
- `/events/[slug]` is listed twice: the `◐` line is the App Shell used for unknown slugs; below it are the
  prerendered slugs. A known event page is `○` with **1m / 1h**, because the comments (`minutes`) are the
  shortest-lived data on it. (`[+2 more paths]` abbreviates the other two of the four slugs.)

Compare this with chapter 6, where `/` was `◐` and every visitor hit the database.

### Watching it in production

Start the server and look at the headers:

```bash
npm start
curl -sI http://localhost:3000/ | grep -i -E "cache|x-nextjs"
```

```text
x-nextjs-cache: HIT
x-nextjs-prerender: 1
x-nextjs-stale-time: 300
Cache-Control: s-maxage=3600, stale-while-revalidate=82800
```

`x-nextjs-cache: HIT` means the response came from the saved prerender. `Cache-Control` translates the lifetime for
CDNs: `s-maxage=3600` (fresh for one hour, the `revalidate` time) and `stale-while-revalidate=82800` (may be served
stale while refreshing for up to one day in total, the `expire` time). `x-nextjs-stale-time: 300` is the `stale`
time, for the client router.

Now a page with runtime data:

```bash
curl -sI "http://localhost:3000/events?city=Lisbon" | grep -i -E "cache|x-nextjs"
```

```text
x-nextjs-stale-time: 300
x-nextjs-prerender: 1
x-nextjs-postponed: 1
Cache-Control: private, no-cache, no-store, max-age=0, must-revalidate
```

`x-nextjs-postponed: 1` means "a prerendered shell, with parts postponed to request time". Since the response
contains per-request content, it must not be cached by a CDN, hence `private, no-store`.

### Seeing the cache keep old data

With the server running, add an event directly in the database, bypassing the app:

```bash
node -e "
const { DatabaseSync } = require('node:sqlite')
const db = new DatabaseSync('data/gather.db')
db.prepare(\`INSERT INTO events (slug, title, description, city, venue, starts_at, capacity, organizer_id)
            VALUES ('svelte-zagreb', 'Svelte Zagreb', 'Hello Zagreb', 'Zagreb', 'Impact Hub', ?, 30, 1)\`)
  .run(new Date(Date.now() + 86400000).toISOString())"
```

Reload the home page: Svelte Zagreb isn't there, even though it's the next event. The home page will show it after
its hourly revalidation, or immediately once the code that creates events invalidates the `events` tag, which is
the job of the Server Action you'll write in chapter 8. This is the trade-off of caching, made explicit: every
cached read has a lifetime and a way to be invalidated, and you choose both.

Now open the new event's page twice:

```bash
curl -s -D - -o /dev/null http://localhost:3000/events/svelte-zagreb | grep -i -E "x-nextjs|cache-control"
curl -s -D - -o /dev/null http://localhost:3000/events/svelte-zagreb | grep -i -E "x-nextjs|cache-control"
```

```text
x-nextjs-stale-time: 300
x-nextjs-prerender: 1
x-nextjs-postponed: 1
Cache-Control: private, no-cache, no-store, max-age=0, must-revalidate

x-nextjs-cache: HIT
x-nextjs-prerender: 1
x-nextjs-stale-time: 300
Cache-Control: s-maxage=60, stale-while-revalidate=3540
```

The first visit got the App Shell with the details streamed in (postponed). The second got a fully static page:
Next.js upgraded the URL in the background, exactly like the four slugs prerendered at build time.

### Where it's all stored

When you self-host with `next start`:

- **Prerendered pages** are files in `.next/server/app/`: for each route an `.html` file (the shell or full page), an
  `.rsc` file (the RSC payload for client navigation), a `.segments` folder (per-segment payloads for prefetching)
  and a `.meta` file (headers, status, postponed state). The App Shell for unknown slugs is `[slug].html`. Regenerated
  pages are written back there.
- **`'use cache'` entries** created at runtime live in memory, in an LRU cache of 50 MB per server process by default
  (`cacheMaxMemorySize`). They are lost when the process restarts, and not shared between several server processes.
  Chapter 16 covers `'use cache: remote'` and custom cache handlers for multi-instance deployments.
- **A new build starts empty**: the build ID is part of every cache key.

## The rules, collected

When you write a component, ask what it waits for:

| It awaits / reads… | It needs… |
|---|---|
| Nothing, or only static imports and pure code | Nothing; it's static |
| A `'use cache'` function or component (lifetime ≥ 5 min) | Nothing; it's prerendered and cached |
| `cookies()`, `headers()`, `searchParams`, `params` not covered by `generateStaticParams` | A `<Suspense>` boundary around it (or `loading.tsx`) |
| An uncached `fetch` or other network I/O | `'use cache'`, or a `<Suspense>` boundary |
| `Date.now()`, `new Date()`, `Math.random()`, `crypto.randomUUID()` | `'use cache'` (one value shared), or `await connection()` inside `<Suspense>` (a value per request) |
| A synchronous database query you want fresh per request | `await connection()` inside `<Suspense>`, or `'use cache'` with a lifetime |

And to get the most out of the static shell:

- **Push runtime reads down.** Don't `await params` or `searchParams` at the top of a page or layout. Pass the promise
  to the component that needs the value, inside a boundary. (A layout can use `params.then(...)` inside `<Suspense>`
  to avoid becoming async at all.)
- **Cache data, not requests.** Put `'use cache'` on functions that take plain values (a slug, a user ID), and read
  runtime values outside them.
- **Keep fallbacks meaningful.** The shell is what users see first, and what the client prefetches (chapter 9).

### `connection()` is still useful

`connection()` remains the tool for things that must be different on every request and aren't data you'd cache: a
per-request ID, the current time on a status page, a random "featured event" that should differ per visitor. Put it in
a small component behind a boundary:

```tsx
async function ServerTime() {
  await connection()
  return <p>Rendered at {new Date().toISOString()}</p>
}
```

### Debugging prerender errors

The error messages name the route and the exact line, and list fixes. When the stack trace isn't enough:

- Open the route in `next dev`: the dev overlay shows the same issue with a component stack.
- Run `next build --debug-prerender` for unminified stack traces from the prerender.
- Set `NEXT_PRIVATE_DEBUG_CACHE=1` when running `next start` to log cache hits, misses and revalidations.

If a build hangs and then fails with "Filling a cache during prerender timed out", a `'use cache'` function is waiting
for something that can't resolve at build time, typically a promise of runtime data (like `params` or `cookies()`)
passed into it as an argument. Await the runtime value outside and pass the plain value instead.

## How this differs from older Next.js versions

If you've used Next.js 13 to 15 (or work on a project without `cacheComponents`), you know a different model: routes
were static or dynamic as a whole, decided by route segment options like `export const dynamic = 'force-dynamic'` and
`export const revalidate = 60`, `fetch` options like `{ next: { revalidate: 60 } }`, and `unstable_cache` for other
data. Reading `cookies()` anywhere made the entire route dynamic. With Cache Components:

- The unit of caching is a function or component, not a route.
- A route is never "all dynamic" because of one cookie read; the rest still goes into the static shell.
- `dynamic`, `revalidate` and `fetchCache` route segment options aren't used (and `dynamicParams` isn't available);
  `'use cache'`, `cacheLife`, Suspense and `connection()` replace them.

Chapter 16 summarizes the older model so you can maintain and migrate such projects.

## Key takeaways

- With Cache Components, Next.js prerenders a static shell for every route. Each part of a page is static, cached
  (`'use cache'`) or runtime (behind `<Suspense>`). Runtime data outside Suspense is a build error, by design.
- `'use cache'` caches a function's or component's result, keyed by build, function and serialized arguments
  (including captured variables). Cached code can't read cookies, headers or search params; pass values in.
- `cacheLife` sets `stale` (browser), `revalidate` (background refresh) and `expire` (hard limit). Use the profiles
  (`minutes`, `hours`, `days`, …) and always call it explicitly. Lifetimes under 5 minutes become runtime data.
- `cacheTag` labels entries so mutations can invalidate them precisely. Design tags around what each write changes.
- Push runtime reads (`params`, `searchParams`, cookies) into the components that need them, inside boundaries, to
  maximize the static shell.
- `generateStaticParams` prerenders known slugs; other slugs get the App Shell first and are upgraded to static pages
  in the background (ISR).
- `next build`'s route table (○ / ◐, Revalidate, Expire) and the `x-nextjs-*` and `Cache-Control` headers tell you
  what was prerendered and cached.

## Exercises

1. **Predict the build output.** Without building, predict the symbol (○ or ◐) and the Revalidate/Expire columns for a
   new page `app/cities/page.tsx` that renders `await getCities()` directly (no Suspense). Then build and check.

<details>
<summary>Solution</summary>

`○` with Revalidate `1d` and Expire `1w`: the page has no runtime data, and its only cached data uses the `days`
profile (revalidate 1 day, expire 1 week), so the page as a whole inherits that lifetime. Unlike the uncached version
in chapter 6, this one refreshes itself daily and can be invalidated with the `events` tag.

</details>

2. **A per-visitor greeting.** Add a component to the home page that says "Good morning", "Good afternoon" or "Good
   evening" based on the server's current time, without making the rest of the home page dynamic. What does the build
   output say about `/` afterwards?

<details>
<summary>Solution</summary>

```tsx
import { Suspense } from 'react'
import { connection } from 'next/server'

async function Greeting() {
  await connection()
  const hour = new Date().getUTCHours()
  const text = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening'
  return <p className="muted">{text} (UTC)!</p>
}

// in HomePage:
<Suspense fallback={<p className="muted">&nbsp;</p>}>
  <Greeting />
</Suspense>
```

`/` becomes `◐`: the hero and the cached event list are still prerendered, and only the greeting is rendered per
request. (Using the server's clock means UTC here; a greeting in the visitor's time zone would be a Client Component,
chapter 4.) The fallback reserves a line of space so nothing shifts when the greeting arrives.

</details>

3. **Find the cache keys.** How many cache entries does `searchEvents` create after these four requests, and which ones
   share an entry? `/events`, `/events?city=Lisbon`, `/events?city=Lisbon&city=Berlin`, `/events?city=%20Lisbon%20`.

<details>
<summary>Solution</summary>

Two entries. `/events` calls `searchEvents('', '')`. The other three all call `searchEvents('', 'Lisbon')`: the
repeated parameter is reduced to its first value by `first()`, and the spaces are trimmed. The entry key consists of
the build ID, the function's ID and the serialized arguments, so normalizing inputs *before* calling a cached
function directly increases the hit rate. (`getCities()` has one entry shared by all four.)

</details>

4. **Break it on purpose.** Move `const { slug } = await params` from `EventDetails` to the top of `EventPage` (making
   the page async), and pass `slug` down instead. Build. What changes in the route table, and what happens to a visit
   to an event created after the build?

<details>
<summary>Solution</summary>

The build succeeds, because `generateStaticParams` makes `params` known for the four prerendered slugs. But the App
Shell can no longer be built from the page: reading `params` at the top level is runtime data outside any boundary
for unknown slugs. The dev server reports the blocking-route error when you open an unknown slug, and in production
an unknown slug can no longer get a meaningful instant shell (only the layouts above the page). Moving the `await`
back inside the Suspense boundary restores the App Shell. This is the "push runtime reads down" rule.

</details>

5. **A custom profile.** Gather's organizers say comments should feel "almost live": visitors may see a comment list at
   most 2 minutes old, and the browser may reuse it for 30 seconds. Define a `comments` profile in `next.config.ts`,
   use it in `getComments`, and explain how this changes the event pages in the build output.

<details>
<summary>Solution</summary>

```ts
// next.config.ts
const nextConfig: NextConfig = {
  cacheComponents: true,
  partialPrefetching: true,
  cacheLife: {
    comments: { stale: 30, revalidate: 120, expire: 3600 },
  },
  // ...
}
```

```ts
// lib/data.ts
cacheLife('comments')
```

After `next typegen` (or a dev/build run), `cacheLife('comments')` is type-checked. The prerendered event pages now
show Revalidate `2m`, Expire `1h`. Because `stale` (30 seconds) is under 5 minutes, the comments are still
prerendered into each event page but are not part of the App Shell shared across URLs. Don't go lower than 30 seconds
for `stale`, or the comments drop out of prerendering entirely and need a Suspense boundary of their own (they
already have one, so the page would still work, just with comments always streamed).

</details>

---
← Previous: [Fetching data and streaming](06-fetching-data-and-streaming.md) | Next: [Server Actions and forms](08-server-actions-and-forms.md) →
