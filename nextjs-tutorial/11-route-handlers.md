# Chapter 11 — Route Handlers

## What you'll learn

- What Route Handlers are and when to use one instead of a page or a Server Action
- The `route.ts` file: HTTP method exports, `Request`/`NextRequest`, `Response`, and `RouteContext`
- Returning JSON, files and other non-HTML responses
- How Route Handlers are prerendered or run per request under Cache Components, and why a health check needs
  `connection()`
- A webhook-style endpoint that invalidates cached data with `revalidateTag(tag, 'max')`
- Shared secrets in `.env.local`, compared in constant time
- Automatic `OPTIONS`, `HEAD` and `405` responses, and CORS headers

## Where we are

Everything Gather serves so far is a page for people. But other programs want to talk to Gather too: a load balancer
asking "are you alive?", a calendar app importing an event, a partner site listing events, an admin script telling
Gather that data changed. None of these want HTML, and none of them can call a Server Action (those are tied to
React and to your own build). They need plain HTTP endpoints. In Next.js, those are **Route Handlers**.

## `route.ts`

A Route Handler is a file named `route.ts` in the `app` folder. Its URL comes from its folder, exactly like a page,
and it exports one function per HTTP method:

```ts
// app/api/hello/route.ts → GET /api/hello
export async function GET() {
  return Response.json({ message: 'Hello from Gather' })
}
```

The function receives a standard Web `Request` and returns a standard Web `Response`, the same classes that browsers
and `fetch` use. If you've used `fetch`, you already know the API. `Response.json(data, init?)` is a shortcut for
a JSON body with the right `Content-Type`.

Rules worth knowing:

- Supported methods are `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD` and `OPTIONS`. Export only the ones you
  need.
- A folder can contain a `page.tsx` **or** a `route.ts`, never both, because both would answer `GET` for the same
  URL. That's why API endpoints conventionally live under `app/api/`, though nothing requires the name.
- Route Handlers don't use layouts and don't take part in client-side navigation. They're the lowest-level
  building block of routing.

### Pages, Server Actions or Route Handlers?

| You want to... | Use |
|---|---|
| Show something to people | A page |
| Let your own UI change data (forms, buttons) | A Server Action (chapter 8) |
| Serve other programs: JSON APIs, webhooks, files, health checks | A Route Handler |

A common mistake from older tutorials is building an `/api/...` route for your own forms and calling it with
`fetch`. With Server Actions that's extra code, extra validation of the same data, and an extra round trip. Use Route
Handlers when the caller is *not* your React UI.

## A health check, and how handlers are prerendered

Load balancers and container platforms (chapter 20) poll an endpoint to see whether an instance is healthy. A first
version:

```ts
// app/api/health/route.ts (first version)
import { db } from '@/lib/db'

export async function GET() {
  try {
    db().prepare('SELECT 1').get()
    return Response.json({ status: 'ok' })
  } catch {
    return Response.json({ status: 'error' }, { status: 503 })
  }
}
```

Build it, and look at the route table:

```text
├ ○ /api/health
```

`○` means static: the handler ran **once, at build time**, and its response was saved. You can see it on disk:

```bash
cat .next/server/app/api/health.body
```

```text
{"status":"ok"}
```

That's the same rule as for pages in chapter 7. With Cache Components, a `GET` handler is prerendered unless it
touches something that only exists at request time, and a synchronous SQLite query doesn't count (chapter 6 met the
same trap). The handler would report "ok" forever, even with the database gone. Prerendering stops when a handler:

- reads the request: `request.url`, `request.headers`, `request.cookies`, the body;
- calls `cookies()`, `headers()` or `connection()`;
- does asynchronous I/O that isn't cached, such as `fetch` or an async database driver;
- uses a non-deterministic value such as `new Date()` or `Math.random()`.

Adding a `checkedAt: new Date().toISOString()` field would make the route dynamic (`ƒ`) as a side effect, but say what
you mean instead. `connection()` from chapter 6 says "this must run for a real request":

```ts
// app/api/health/route.ts
import { connection } from 'next/server'
import { db } from '@/lib/db'

// GET /api/health — for load balancers and container health checks.
export async function GET() {
  // Without this, the build would prerender the response once and serve
  // that frozen "ok" forever. connection() makes it run for every request.
  await connection()
  try {
    db().prepare('SELECT 1').get()
    return Response.json({ status: 'ok' })
  } catch {
    return Response.json({ status: 'error' }, { status: 503 })
  }
}
```

Now the build shows `ƒ /api/health` (dynamic: runs on every request), and the endpoint answers honestly:

```bash
curl -i http://localhost:3000/api/health
```

```text
HTTP/1.1 200 OK
content-type: application/json

{"status":"ok"}
```

The opposite case is useful too: a handler that returns data from `'use cache'` functions and doesn't read the request
is prerendered and revalidated by its `cacheLife`, just like a page. `'use cache'` can't go directly in the handler's
body; put it in a helper function, as Gather's `lib/data.ts` already does.

## A public JSON API

Partner sites want to list Gather's events. Give them `GET /api/events?q=&city=`, backed by the same cached
`searchEvents` the events page uses:

```ts
// app/api/events/route.ts
import type { NextRequest } from 'next/server'
import { searchEvents } from '@/lib/data'

// GET /api/events?q=react&city=Berlin — a small public JSON API.
// Reading the request URL makes this handler run at request time; the data
// itself comes from the same cached function the /events page uses.
export async function GET(request: NextRequest) {
  const q = request.nextUrl.searchParams.get('q')?.trim() ?? ''
  const city = request.nextUrl.searchParams.get('city')?.trim() ?? ''
  const events = await searchEvents(q, city)

  return Response.json(
    {
      events: events.map((e) => ({
        ...e,
        url: new URL(`/events/${e.slug}`, request.nextUrl.origin).href,
      })),
    },
    { headers: { 'Cache-Control': 'public, max-age=60' } },
  )
}
```

- **`NextRequest`** extends `Request` with conveniences. `request.nextUrl` is a parsed `URL` (with `searchParams`
  and `origin`), and `request.cookies` reads cookies. The plain `Request` works too; you'd write
  `new URL(request.url).searchParams`.
- **The response is shaped on purpose.** It adds an absolute `url` to each event, which API consumers need, and
  contains only fields that are already public on the website. Never return database rows as they are: a column
  added later (an organizer's email, say) would leak automatically.
- **`Cache-Control: public, max-age=60`** lets browsers and CDNs reuse the answer for a minute. This is HTTP caching,
  separate from Next.js's `'use cache'`: `searchEvents` is cached on the server, and the header lets *clients* skip the
  request entirely.

```bash
curl -s "http://localhost:3000/api/events?q=react"
```

```json
{
  "events": [
    {
      "id": 2,
      "slug": "react-reading-group",
      "title": "React Reading Group",
      "city": "Berlin",
      "venue": "Café Kreuzberg",
      "startsAt": "2026-10-29T19:00:00.000Z",
      "capacity": 12,
      "going": 2,
      "url": "http://localhost:3000/events/react-reading-group"
    }
  ]
}
```

(The output is pretty-printed here. Your dates depend on when you seeded the database.)

## Dynamic segments and files: a calendar export

Route Handlers can live in dynamic folders like pages. The second argument carries the params, and the global
`RouteContext` helper types them from the route's path:

```ts
// app/api/events/[slug]/calendar/route.ts
import { getEvent } from '@/lib/data'

// GET /api/events/<slug>/calendar — an .ics file for calendar apps.
export async function GET(_request: Request, ctx: RouteContext<'/api/events/[slug]/calendar'>) {
  const { slug } = await ctx.params
  const event = await getEvent(slug)
  if (!event) return new Response('Not found', { status: 404 })

  const stamp = (iso: string) => iso.replace(/[-:]/g, '').replace(/\.\d{3}/, '')
  const start = new Date(event.startsAt)
  const end = new Date(start.getTime() + 2 * 60 * 60 * 1000) // assume two hours
  const escape = (text: string) => text.replace(/[\\;,]/g, (c) => `\\${c}`).replace(/\n/g, '\\n')

  const ics = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//Gather//Next.js tutorial//EN',
    'BEGIN:VEVENT',
    `UID:${event.slug}@gather.example`,
    `DTSTAMP:${stamp(new Date().toISOString())}`,
    `DTSTART:${stamp(start.toISOString())}`,
    `DTEND:${stamp(end.toISOString())}`,
    `SUMMARY:${escape(event.title)}`,
    `LOCATION:${escape(`${event.venue}, ${event.city}`)}`,
    `DESCRIPTION:${escape(event.description)}`,
    'END:VEVENT',
    'END:VCALENDAR',
  ].join('\r\n')

  return new Response(ics, {
    headers: {
      'Content-Type': 'text/calendar; charset=utf-8',
      'Content-Disposition': `attachment; filename="${event.slug}.ics"`,
    },
  })
}
```

`RouteContext<'/api/events/[slug]/calendar'>` is generated by Next.js like `PageProps` (run `next dev`, `next build`
or `next typegen` once if your editor doesn't know it yet). As in pages, `params` is a promise.

The handler builds an iCalendar file: the format every calendar app imports. It's plain text with CRLF line endings,
and commas, semicolons and backslashes in values must be escaped, which `escape` does. `Content-Disposition:
attachment` makes browsers download it with a sensible file name instead of displaying it.

Not finding the event returns a real `404`. Route Handlers don't stream a shell first, so unlike pages (chapter 10)
they control their status code completely.

```bash
curl -i http://localhost:3000/api/events/nextjs-meetup-berlin/calendar
```

```text
HTTP/1.1 200 OK
content-disposition: attachment; filename="nextjs-meetup-berlin.ics"
content-type: text/calendar; charset=utf-8

BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Gather//Next.js tutorial//EN
BEGIN:VEVENT
UID:nextjs-meetup-berlin@gather.example
DTSTAMP:20261023T100656Z
DTSTART:20261026T180000Z
DTEND:20261026T200000Z
SUMMARY:Next.js Meetup Berlin
LOCATION:Factory Görlitzer Park\, Berlin
DESCRIPTION:Three short talks on Cache Components\, Server Actions and self-hosting\, followed by pizza and ...
```

Link to it from the event page, next to the share button:

```tsx
// app/events/[slug]/page.tsx (in EventDetails)
        <ShareButton title={event.title} path={`/events/${event.slug}`} />
        {/* A plain <a>: the target is a file from a Route Handler, not a page. */}
        <a href={`/api/events/${event.slug}/calendar`} download>
          Add to calendar
        </a>
```

A `<Link>` would try to prefetch and client-navigate to it, which makes no sense for a file. For downloads and API
URLs, use a plain `<a>`.

## A revalidation webhook

Chapter 7's caches have one blind spot: changes that don't go through Gather's Server Actions. An admin script that
edits the database directly, a nightly import, a CMS: none of them can call `updateTag`. They can send an HTTP
request, though. `POST /api/revalidate` lets them tell Gather which cache tag is out of date.

### A shared secret

Anyone on the internet can call your endpoints, so this one needs a password that only the trusted callers know.
Secrets go in **environment variables**, never in code. For local development, Next.js loads them from `.env.local`
in the project root:

```bash
# .env.local (never committed)
REVALIDATE_SECRET=paste-a-long-random-string-here
```

Generate a good value with:

```bash
node -e "console.log(require('node:crypto').randomBytes(32).toString('hex'))"
```

`create-next-app`'s `.gitignore` already ignores `.env*` files. To document the variables your app needs without
committing secrets, add an example file and allow it in `.gitignore`:

```bash
# .env.example
# Copy to .env.local and fill in. .env.local is git-ignored.

# Public URL of the site, used for absolute links in metadata, the sitemap
# and Open Graph images.
SITE_URL=http://localhost:3000

# SQLite file. Created and seeded on first use.
DATABASE_PATH=data/gather.db

# Set to "false" to start with an empty database.
SEED=true

# Shared secret for POST /api/revalidate (chapter 11). Generate one with:
#   node -e "console.log(require('node:crypto').randomBytes(32).toString('hex'))"
REVALIDATE_SECRET=
```

```text
# .gitignore (added at the end)
!.env.example
```

`next build` and `next start` print `- Environments: .env.local` when they load the file. Variables without the
`NEXT_PUBLIC_` prefix are only available on the server, which is what you want for secrets (chapter 4). Chapter 17
covers environment variables in full.

### The handler

```ts
// app/api/revalidate/route.ts
import { timingSafeEqual } from 'node:crypto'
import { revalidateTag } from 'next/cache'

// POST /api/revalidate with "Authorization: Bearer <REVALIDATE_SECRET>" and
// a JSON body like {"tag": "events"}. Lets another system (an admin tool, a
// cron job, a CMS webhook) tell Gather that data changed outside the app.
export async function POST(request: Request) {
  const secret = process.env.REVALIDATE_SECRET
  const given = request.headers.get('authorization')?.replace(/^Bearer /, '') ?? ''
  if (!secret || !safeEqual(given, secret)) {
    return Response.json({ error: 'unauthorized' }, { status: 401 })
  }

  const body = (await request.json().catch(() => null)) as { tag?: unknown } | null
  if (typeof body?.tag !== 'string' || body.tag.length === 0) {
    return Response.json({ error: 'body must be {"tag": "<tag>"}' }, { status: 400 })
  }

  // 'max': serve the stale entry while a fresh one renders in the background.
  revalidateTag(body.tag, 'max')
  return Response.json({ revalidated: body.tag })
}

function safeEqual(a: string, b: string) {
  const x = Buffer.from(a)
  const y = Buffer.from(b)
  return x.length === y.length && timingSafeEqual(x, y)
}
```

Step by step:

1. **Check the secret first**, before reading the body. If `REVALIDATE_SECRET` isn't set, *every* request is
   rejected: a missing secret must never mean "no password needed".
2. **Compare in constant time.** `given === secret` stops at the first different character, so in theory an attacker
   could measure response times to guess the secret one character at a time. `timingSafeEqual` takes the same time
   regardless of where the strings differ. (It requires equal lengths, hence the length check.)
3. **Parse and validate the body.** `request.json()` throws on invalid JSON; `.catch(() => null)` turns that into a
   clean `400`. Then check that `tag` really is a non-empty string, because it's untrusted input like everything else.
4. **`revalidateTag(tag, 'max')`** marks every cache entry with that tag as stale. As chapter 8 explained, `updateTag`
   only works in Server Actions; in a Route Handler, `revalidateTag` is the tool. The `'max'` profile means
   *stale-while-revalidate*: the next visitor still gets the old content while a fresh version is rendered in the
   background, and the visitor after that sees the new one. If stale content must never be served, pass
   `{ expire: 0 }` instead, and the next request waits for fresh data.

### Trying it

Start the production server, then simulate an admin script that adds an event behind Gather's back. With the server
running, insert a row directly (any SQLite tool works; this uses Node's built-in driver):

```bash
node -e "
const { DatabaseSync } = require('node:sqlite')
const db = new DatabaseSync('data/gather.db')
const startsAt = new Date(Date.now() + 2 * 3600e3).toISOString()
db.prepare(\`INSERT INTO events (slug, title, description, city, venue, starts_at, capacity, organizer_id)
  VALUES ('flash-talk-night', 'Flash Talk Night', 'Five-minute talks, added by an admin script.',
          'Berlin', 'c-base', ?, 40, 1)\`).run(startsAt)
"
```

The event starts in two hours, so it should be first on the home page, but the home page is cached for hours and
doesn't show it. Now call the webhook, first without the secret, then with a broken body, then properly:

```bash
curl -s -X POST http://localhost:3000/api/revalidate -d '{"tag":"events"}'
curl -s -X POST http://localhost:3000/api/revalidate -H "Authorization: Bearer $REVALIDATE_SECRET" -d 'nope'
curl -s -X POST http://localhost:3000/api/revalidate -H "Authorization: Bearer $REVALIDATE_SECRET" \
  -H "Content-Type: application/json" -d '{"tag":"events"}'
```

(Set `REVALIDATE_SECRET` in your shell to the same value as in `.env.local` first.)

```text
{"error":"unauthorized"}
{"error":"body must be {\"tag\": \"<tag>\"}"}
{"revalidated":"events"}
```

Then load the home page twice:

```bash
curl -s http://localhost:3000/ | grep -o "Flash Talk Night"   # first request: nothing yet
curl -s http://localhost:3000/ | grep -o "Flash Talk Night"   # second request
```

```text
Flash Talk Night
```

The first request after revalidation got the stale page and triggered the background refresh; the second got the new
one. That's `'max'` doing exactly what it promises. Delete the test event afterwards if you like, and call the webhook
again.

## HTTP details Next.js handles for you

Ask the events API for something it doesn't support:

```bash
curl -s -i -X POST http://localhost:3000/api/events | head -1
curl -s -i -X OPTIONS http://localhost:3000/api/events | grep -i -E "HTTP|allow"
```

```text
HTTP/1.1 405 Method Not Allowed
HTTP/1.1 204 No Content
allow: GET, HEAD, OPTIONS
```

Methods you don't export get a `405`. If you don't export `OPTIONS`, Next.js answers it with an `Allow` header listing
the methods you do support. `HEAD` requests are answered by your `GET` handler, without the body.

### CORS

Browsers only let JavaScript on *other* origins read your responses if you allow it with CORS headers. For a public,
read-only API like `/api/events`, allowing everyone is reasonable:

```ts
return Response.json(data, {
  headers: {
    'Cache-Control': 'public, max-age=60',
    'Access-Control-Allow-Origin': '*',
  },
})
```

Requests from servers and `curl` ignore CORS entirely; it only governs browsers. Never send
`Access-Control-Allow-Origin: *` on endpoints that use cookies for authentication. For CORS rules shared by many
routes, set headers in Proxy or `next.config.ts` (chapter 12).

### Other things a handler can do

- **Read cookies and headers** with `request.cookies` / `request.headers`, or with `cookies()` / `headers()` from
  `next/headers`. `cookies()` can also *set* cookies in a handler.
- **Redirect** with `redirect()` from `next/navigation`, or return `Response.redirect(url, 307)`.
- **Read the body** as `request.json()`, `request.text()` or `request.formData()`. There's no body parser to
  configure.
- **Stream** by returning a `Response` whose body is a `ReadableStream`, for server-sent events or large exports.
- **Use `notFound()`?** No: that's for pages. Return a `Response` with `status: 404`, as the calendar handler does.

## Special files are Route Handlers too

`sitemap.ts`, `robots.ts`, `opengraph-image.tsx` and icons are generated by special Route Handlers that Next.js
knows by name. They're prerendered unless they use request data, like any `GET` handler. Chapter 14 builds Gather's.

## How this differs from older Next.js versions

- **Pages Router API routes** (`pages/api/*.ts`, with `req` and `res` objects in the Node.js style) are replaced by
  Route Handlers using Web `Request`/`Response`. There's no `bodyParser` config.
- **Caching of `GET` handlers** flipped twice: Next.js 13 and 14 cached them by default, 15 stopped. With Cache
  Components they follow the same rules as pages: prerendered if they touch no request data or uncached I/O, cached
  with `'use cache'` helpers, dynamic otherwise. Segment options such as `export const dynamic = 'force-dynamic'`
  aren't available with Cache Components; use `connection()`.
- **`revalidateTag(tag)` with one argument** is deprecated. Pass a profile (`'max'`), or `{ expire: 0 }` when stale
  data must never be served.
- **`RouteContext`** is a generated, typed context helper; older code typed `params` by hand.

## Key takeaways

- Route Handlers (`route.ts`) serve non-HTML clients: APIs, webhooks, files, health checks. Your own UI should use
  Server Actions for mutations.
- They use Web `Request` and `Response`; `NextRequest` adds `nextUrl` and `cookies`. `RouteContext<'/path'>` types
  `params`.
- Under Cache Components a `GET` handler is prerendered unless it reads the request, calls `connection()`,
  `cookies()` or `headers()`, does uncached async I/O, or uses time or randomness. Health checks need `connection()`.
- Shape API responses explicitly; never return raw rows.
- Secrets live in environment variables (`.env.local` locally) and are compared with `timingSafeEqual`.
- In handlers, invalidate with `revalidateTag(tag, 'max')` (stale-while-revalidate) or `{ expire: 0 }` (immediate).
- Next.js answers `405`, `OPTIONS` and `HEAD` for you; CORS is up to you.

## Exercises

1. **Allow CORS on the events API.** Make `/api/events` readable by JavaScript on any website. Then explain why the
   same change would be a bad idea for `/api/revalidate`, even though that endpoint has a secret.

<details>
<summary>Solution</summary>

Add `'Access-Control-Allow-Origin': '*'` to the response headers in `app/api/events/route.ts`, as shown in the CORS
section. `GET` requests without custom headers are "simple requests", so no preflight `OPTIONS` handling is needed.

For `/api/revalidate`, allowing every origin would let *any* website's JavaScript call it from your visitors'
browsers. With a secret in the `Authorization` header that's not directly exploitable, but it invites people to put
the secret into front-end code, where anyone can read it. The callers of a webhook are servers, and servers don't
need CORS at all. Least privilege: leave it off.

</details>

2. **Validate the tag.** `POST /api/revalidate` accepts any string, including tags Gather never uses. Restrict it to
   `events` and the prefixed patterns from chapter 7 (`event:<slug>`, `comments:<id>`, `rsvps:<id>`,
   `dashboard:<id>`), and return a `400` for anything else.

<details>
<summary>Solution</summary>

```ts
const TAG_PATTERN = /^(events|event:[a-z0-9-]{1,60}|(comments|rsvps|dashboard):\d{1,10})$/

// ...after parsing the body:
if (typeof body?.tag !== 'string' || !TAG_PATTERN.test(body.tag)) {
  return Response.json({ error: 'unknown tag' }, { status: 400 })
}
```

An unknown tag wouldn't do damage (revalidating a tag nobody uses is a no-op), but a strict check catches typos in
the caller's configuration immediately instead of silently doing nothing.

</details>

3. **Event details as JSON.** Add `GET /api/events/<slug>` that returns one event, with its description and organizer
   name but **without** the organizer's id. Return a `404` with a JSON body for unknown slugs. Why can't this file be
   `app/api/events/[slug]/route.ts` *and* keep the calendar at `app/api/events/[slug]/calendar/route.ts`?

<details>
<summary>Solution</summary>

It can! A `route.ts` in `[slug]` and another in `[slug]/calendar` are two different URLs (`/api/events/x` and
`/api/events/x/calendar`), just as a page and a nested page can coexist. Only a `page.tsx` and a `route.ts` in the
*same* folder conflict. The trick question is there to check that you know the actual rule.

```ts
// app/api/events/[slug]/route.ts
import { getEvent } from '@/lib/data'

export async function GET(_request: Request, ctx: RouteContext<'/api/events/[slug]'>) {
  const { slug } = await ctx.params
  const event = await getEvent(slug)
  if (!event) return Response.json({ error: 'not found' }, { status: 404 })

  const { organizer, ...rest } = event
  return Response.json({ ...rest, organizer: organizer.name })
}
```

Destructuring out `organizer` and putting back only the name keeps the internal id out of the public response.

</details>

4. **A frozen handler.** Add `app/api/stats/route.ts` that returns `{ events: <number of events> }` with a synchronous
   `SELECT count(*)`. Build and run it, add an event, and call it again. What do you see, and what are two different
   correct fixes depending on how fresh the number must be?

<details>
<summary>Solution</summary>

The build shows `○ /api/stats`, and the count never changes: like the first health check, the handler ran once at
build time. Fix one, always exact: `await connection()` at the top, which makes it run per request (`ƒ`). Fix two,
cheap and fresh enough: move the query into a `'use cache'` function with `cacheLife('minutes')` and
`cacheTag('events')`. The route stays prerendered, refreshes in the background, and `updateTag('events')` in the
Server Actions (and the revalidation webhook) refresh it immediately when events change.

</details>

---
← Previous: [Error handling](10-error-handling.md) | Next: [Proxy, redirects and headers](12-proxy-redirects-and-headers.md) →
