# Chapter 12 — Proxy, redirects and headers

## What you'll learn

- The order in which Next.js handles an incoming request, from `next.config.ts` rules to your pages
- Response headers for every page with `headers()` in `next.config.ts`, and a set of security headers worth having
- Permanent and temporary redirects with `redirects()`, and why Next.js uses `307` and `308`
- Rewrites with `rewrites()`: serving one URL from another without the browser noticing
- `proxy.ts` (formerly `middleware.ts`): matchers, `NextResponse.next()`, `rewrite()` and `redirect()`, and passing
  headers on to your app
- A request id that ties the browser, Proxy and your error logs together
- What belongs in Proxy, what belongs in `next.config.ts`, and what belongs in neither

## Where we are

Gather now has pages, Server Actions, error handling and a small HTTP API. Every request so far goes straight to the
route that matches its URL. This chapter adds the layer *in front of* the routes: rules that run before any page
renders. You'll add security headers to every response, keep an old URL scheme working, give the calendar files a
friendlier address, and add a `proxy.ts` that tags every request with an id, so that an error in the logs can be
matched to the request that caused it.

## The request pipeline

When a request arrives, Next.js runs these steps in order:

1. `headers` from `next.config.ts`
2. `redirects` from `next.config.ts`
3. Proxy (`proxy.ts`), which can rewrite, redirect, set headers or answer by itself
4. `beforeFiles` rewrites from `next.config.ts`
5. Filesystem routes: `public/`, `_next/static/` and the routes in `app/`
6. `afterFiles` rewrites (a plain array of rewrites means `afterFiles`)
7. Dynamic routes such as `/events/[slug]`
8. `fallback` rewrites

Two things follow from this list. First, a redirect in `next.config.ts` happens *before* Proxy, so Proxy never sees
that request. Second, a rewrite in the default `afterFiles` position can't shadow a real file or static route, only
fill in where nothing matches.

The `next.config.ts` rules are static: path patterns plus optional conditions on headers, cookies and query strings,
fixed at build time. Proxy is code, so it can make any decision. Start with the static rules; reach for Proxy when a
rule can't be expressed as a pattern.

## Security headers with `headers()`

Browsers have a handful of protections that sites must opt into with response headers. Add them to every response
in `next.config.ts`:

```ts
// next.config.ts
import type { NextConfig } from 'next'

const securityHeaders = [
  // Don't let browsers guess content types (a classic XSS vector).
  { key: 'X-Content-Type-Options', value: 'nosniff' },
  // Send only the origin, not the full URL, to other sites.
  { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
  // Gather never needs to be shown inside another site's frame.
  { key: 'X-Frame-Options', value: 'DENY' },
  // Features Gather doesn't use can't be used by injected scripts either.
  { key: 'Permissions-Policy', value: 'camera=(), microphone=(), geolocation=()' },
]

const nextConfig: NextConfig = {
  cacheComponents: true,
  partialPrefetching: true,

  experimental: {
    // Enables forbidden() and unauthorized(). Still experimental in 16.4.
    authInterrupts: true,
  },

  async headers() {
    return [{ source: '/:path*', headers: securityHeaders }]
  },
}

export default nextConfig
```

`headers()` returns a list of rules. Each has a `source` pattern and the headers to add when it matches. `/:path*`
means "any path": `:path` is a named segment, and `*` allows zero or more of them. The same pattern syntax is used by
`redirects()`, `rewrites()` and the Proxy matcher:

| Pattern | Matches |
| --- | --- |
| `/events/:slug` | `/events/x`, not `/events` or `/events/x/edit` |
| `/events/:path*` | `/events`, `/events/x`, `/events/x/edit` |
| `/events/:path+` | `/events/x`, `/events/x/edit`, not `/events` |
| `/events/:slug(\\d+)` | `/events/42`, not `/events/x` (a regular expression in brackets) |

Build, start, and look at the headers of the home page:

```bash
npm run build && npm start
curl -s -I http://localhost:3000/
```

```text
HTTP/1.1 200 OK
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
X-Frame-Options: DENY
Permissions-Policy: camera=(), microphone=(), geolocation=()
...
Cache-Control: s-maxage=3600, stale-while-revalidate=82800
```

`Cache-Control` was already there: Next.js derives it from the page's cache life (chapter 7). You can't override it
for pages or for hashed files in `_next/static`; those always get `public, max-age=31536000, immutable`.

The rules apply to static files too, which is what you want for `nosniff`:

```text
HTTP/1.1 200 OK
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Cache-Control: public, max-age=31536000, immutable
```

### When two rules set the same header

If several rules match and set the same header, the last one wins. More surprising: a rule in `next.config.ts` also
wins over a header that a Route Handler sets itself. Chapter 11's `/api/events` handler sends
`Access-Control-Allow-Origin: *`. Add a rule for `/api/:path*` that sets the same header to
`https://example.com`, and the handler's value disappears:

```text
HTTP/1.1 200 OK
Access-Control-Allow-Origin: https://example.com
```

So use `next.config.ts` for headers that are truly global, and let handlers own the headers that are specific to
them. Exercise 3 uses this for CORS.

### What about Content Security Policy?

A `Content-Security-Policy` header is the strongest protection against injected scripts, but a strict one needs a
fresh random *nonce* per request, and Next.js must put that nonce on its own `<script>` tags. That makes every page
dynamic, so it doesn't fit Gather's mostly prerendered pages. The Next.js docs have a full guide (search for "Content
Security Policy"); chapter 20 comes back to it when we look at production hardening.

## Redirects with `redirects()`

Before Gather was called Gather, events were "meetups", and old links like `/meetups/react-reading-group` are still
out there in emails and chat logs. Keep them working:

```ts
// next.config.ts, inside nextConfig
async redirects() {
  // Gather used to call events "meetups"; keep old links working.
  return [{ source: '/meetups/:slug', destination: '/events/:slug', permanent: true }]
},
```

Named segments in `source` can be used in `destination`. Try it, with a query string:

```bash
curl -s -I "http://localhost:3000/meetups/react-reading-group?ref=newsletter"
```

```text
HTTP/1.1 308 Permanent Redirect
location: /events/react-reading-group?ref=newsletter
Refresh: 0;url=/events/react-reading-group
```

The query string is passed through. `permanent: true` gives a `308`, `permanent: false` a `307`. The classic codes,
`301` and `302`, allowed browsers to turn a `POST` into a `GET` when following the redirect, and many did. `307`
and `308` require the browser to repeat the same method:

```bash
curl -s -i -X POST http://localhost:3000/meetups/x | grep -i -E "HTTP|location"
```

```text
HTTP/1.1 308 Permanent Redirect
location: /events/x
```

Use permanent redirects only for URLs that will never come back: browsers and search engines cache them, and a
`308` sent by mistake can stick in users' browsers for a long time. While you're not sure, use `permanent: false`.

Rules can also depend on the request. This one sends visitors from an old campaign to the events list only when the
query string has `utm_campaign=launch`:

```ts
{
  source: '/',
  has: [{ type: 'query', key: 'utm_campaign', value: 'launch' }],
  destination: '/events',
  permanent: false,
}
```

`has` and `missing` accept `header`, `cookie`, `query` and `host` conditions. All of them must hold for the rule to
apply.

### Which redirect when?

You've now seen every kind of redirect in Next.js:

| API | Use it for | Status |
| --- | --- | --- |
| `redirect()` | After a mutation or a check, in Server Components, Server Actions and Route Handlers | `307`, or a client navigation in a Server Action |
| `permanentRedirect()` | The same, when the old URL is gone for good | `308` |
| `router.push()` | Client-side navigation from an event handler | none (no HTTP redirect) |
| `redirects()` in `next.config.ts` | URL patterns known at build time | `307` or `308` |
| `NextResponse.redirect()` in Proxy | Decisions that need code: cookies, lookups, many rules | any |

The redirects guide in the Next.js docs suggests Proxy for very large redirect lists (thousands of entries), because
some hosting platforms limit how many `redirects()` rules a project can have.

## Rewrites with `rewrites()`

A redirect tells the browser to go somewhere else; the address bar changes. A **rewrite** serves a different route
for the same URL; the browser never knows. Chapter 11's calendar file lives at `/api/events/<slug>/calendar`, which is
an honest address for an API but an odd one to show users. Give it a nicer URL:

```ts
// next.config.ts, inside nextConfig
async rewrites() {
  // A friendlier public URL for the calendar files: /calendar/<slug>.ics
  return [{ source: '/calendar/:slug.ics', destination: '/api/events/:slug/calendar' }]
},
```

And use it in the event page's "Add to calendar" link:

```tsx
// app/events/[slug]/page.tsx
<a href={`/calendar/${event.slug}.ics`} download>
  Add to calendar
</a>
```

```bash
curl -s -i http://localhost:3000/calendar/react-reading-group.ics | grep -i -E "HTTP|content-type|SUMMARY"
```

```text
HTTP/1.1 200 OK
content-type: text/calendar; charset=utf-8
SUMMARY:React Reading Group
```

`/calendar/:slug.ics` shows that a named segment can be followed by literal text inside one path segment. The handler
didn't change; it still reads `slug` from `ctx.params`, which comes from the destination route.

A rewrite's `destination` can also be a full URL on another server. That turns Next.js into a reverse proxy for that
path, which is handy when you move a site to Next.js page by page: rewrite everything you haven't migrated to the old
app with a `fallback` rewrite, and remove rules as pages move over.

## Proxy

Everything so far was a pattern. Proxy is a function that runs before the matched route, for every request it
matches. Put it in `proxy.ts` at the project root (next to `app`, or inside `src/` if you use one). Create Gather's:

```ts
// proxy.ts
import { NextResponse, type NextRequest } from 'next/server'

// Runs before every matched request, before any page or route renders.
// Keep it fast: no database calls here.
export function proxy(request: NextRequest) {
  // Give every request an id that the app and the logs can share.
  const requestId = request.headers.get('x-request-id') ?? crypto.randomUUID()
  const requestHeaders = new Headers(request.headers)
  requestHeaders.set('x-request-id', requestId)

  const response = NextResponse.next({ request: { headers: requestHeaders } })
  response.headers.set('x-request-id', requestId)
  return response
}

export const config = {
  // Skip static files and image optimization: they never need auth or ids.
  matcher: ['/((?!_next/static|_next/image|favicon.ico|.*\\.(?:svg|png|jpg|ico)$).*)'],
}
```

Going through it:

- The function is named `proxy` (a default export works too). It receives a `NextRequest`: the Web `Request` from
  chapter 11 plus `nextUrl` (a parsed URL) and `cookies`.
- It must return what should happen next. `NextResponse.next()` means "carry on to the route". The other options are
  `NextResponse.rewrite(url)`, `NextResponse.redirect(url)`, or any `Response` of your own, which answers the request
  without the route ever running.
- `NextResponse.next({ request: { headers } })` changes the headers that **your app** sees. Here it adds
  `x-request-id`, so pages, Server Actions, Route Handlers and `instrumentation.ts` can all read it.
- `response.headers.set(...)` changes the headers that **the browser** sees. Here it echoes the id back, so it shows
  up in the browser's network tab and in any bug report that includes it.
- If the request already has an `x-request-id` (a load balancer in front of Gather may set one), Proxy keeps it. That
  way one id follows the request through every layer.

The `matcher` decides which requests run Proxy at all. Without one, Proxy runs on *every* request, including each
JavaScript chunk and image. This pattern is the usual "everything except static assets" regular expression: a
negative lookahead `(?!...)` that skips `_next/static`, `_next/image`, the favicon and image files. Matchers must be
constant strings or arrays, because Next.js reads them at build time; a value computed in code is ignored. Like the
config rules, matcher entries can also be objects with `source`, `has` and `missing`.

Build, and the route list ends with a new line:

```text
ƒ Proxy (Middleware)
```

Then check what a browser receives:

```bash
curl -s -I http://localhost:3000/nope | grep -i -E "HTTP|x-request-id"
curl -s -I -H "x-request-id: abc-123" http://localhost:3000/ | grep -i x-request-id
```

```text
HTTP/1.1 404 Not Found
x-request-id: 53b92401-7d88-4f47-b6df-8a3c4026cc94
x-request-id: abc-123
```

Every request gets a fresh id (yours will differ), even a 404, and an id sent by the client is kept. A static chunk from `_next/static` has no `x-request-id`: the matcher skipped it. And
`/meetups/react-reading-group` has none either. Its `308` comes from `redirects()`, which runs *before* Proxy (step 2
of the pipeline), so Proxy never saw that request.

### Request ids in the error log

Chapter 10's `onRequestError` logs every server error as one JSON line. Add the id:

```ts
// instrumentation.ts
console.error(
  JSON.stringify({
    level: 'error',
    message: err.message,
    digest: err.digest,
    path: request.path,
    method: request.method,
    requestId: request.headers['x-request-id'],
    routePath: context.routePath,
    routeType: context.routeType,
  }),
)
```

`request.headers` here are the headers your app sees, so they include the one Proxy added. To see it working,
temporarily add `throw new Error('boom')` after `await connection()` in `app/api/health/route.ts`, rebuild, and
request it:

```bash
curl -s -o /dev/null -D - http://localhost:3000/api/health | grep -i -E "HTTP|x-request-id"
```

```text
HTTP/1.1 500 Internal Server Error
x-request-id: cc75653f-daea-49ae-ae89-d66b29f3e74f
```

And in the server's output:

```text
{"level":"error","message":"boom","path":"/api/health","method":"GET","requestId":"cc75653f-daea-49ae-ae89-d66b29f3e74f","routePath":"/api/health","routeType":"route"}
```

The same id in the response and in the log: a user (or a test, or a monitoring tool) that hits an error can hand you
the id, and you can find the exact log line. Remove the `throw` again before moving on.

A Server Component can read the id too, with `(await headers()).get('x-request-id')`. Remember that `headers()`
makes that part of the page request-time (chapter 7), so only read it where you really need it.

### What Proxy is for, and what it isn't

Proxy runs before every matched request, so it has to be fast and it shouldn't do too much. Good uses:

- Redirects and rewrites that need a decision in code: by cookie, by header, by country (when your host supplies
  it), or from a large table of old URLs.
- Setting or forwarding headers, like the request id.
- **Optimistic auth checks.** "No session cookie at all? Redirect to the login page before rendering anything."
  Chapter 15 adds exactly this to Gather's `proxy.ts`.
- Answering simple requests directly, such as CORS preflights for many routes at once.

Things to avoid:

- **Slow work.** Database queries and calls to other services in Proxy delay every matched request, including
  prefetches. Exercise 1 shows a lookup that's worth its cost only in specific cases.
- **Relying on it for security.** Proxy is a first filter, not the lock on the door. Server Actions are `POST`
  requests to the page that uses them, so a matcher change or a moved form can silently take an action out of
  Proxy's coverage. Every Server Action and Route Handler must check permissions itself (chapter 8's `requireUser`
  does), and chapter 15 repeats this rule.
- **Sharing state with your app.** The docs say Proxy may run separately from the rest of your code, on some hosts
  even on a different machine near the user, so don't rely on module-level variables or shared modules for state.
  Pass information to the app with headers, cookies, rewrites or the URL.

Proxy always runs on the Node.js runtime in Next.js 16, so Node APIs and packages work. It also gets a second
argument, `event`, whose `event.waitUntil(promise)` keeps work such as sending analytics going after the response
has been returned.

## What goes where

| You want to... | Use |
| --- | --- |
| Add the same headers to many responses | `headers()` in `next.config.ts` |
| Add headers to one route's responses | The Route Handler or page itself |
| Move a URL pattern that's known in advance | `redirects()` |
| Serve a URL from another route or another server | `rewrites()` |
| Decide per request in code (cookies, lookups, ids) | Proxy |
| Protect data | The Server Action, Route Handler or data function, always; Proxy only as an extra |

## How this differs from older Next.js versions

- **`middleware.ts` is now `proxy.ts`**, and the function is `proxy` instead of `middleware`. The old name still
  works in 16 but is deprecated. The codemod `npx @next/codemod@canary middleware-to-proxy .` renames both.
  Config flags were renamed the same way, for example `skipMiddlewareUrlNormalize` is now `skipProxyUrlNormalize`.
- **Runtime.** Middleware used to run on the Edge runtime by default; Node.js became an option in 15.2 and stable in
  15.5. Proxy always uses Node.js and the `runtime` option isn't allowed in `proxy.ts`. If you need the Edge
  runtime, the upgrade guide says to keep using `middleware.ts` for now.
- The build output still labels it `ƒ Proxy (Middleware)`, and many articles, libraries and error messages still say
  middleware. They mean the same feature.

## Key takeaways

- Requests pass through config headers, config redirects, Proxy, rewrites and then the routes, in that order.
- `headers()`, `redirects()` and `rewrites()` in `next.config.ts` are fast, static rules with `source` patterns and
  optional `has`/`missing` conditions. Prefer them when a pattern is enough.
- Config headers win over headers set by a Route Handler. Keep global headers global and route-specific ones in the
  route.
- `permanent: true` gives `308`, `false` gives `307`. Both keep the request method and pass the query string on.
- A rewrite changes which route answers, not the URL the browser shows.
- `proxy.ts` runs code before matched requests. `NextResponse.next({ request: { headers } })` passes headers to your
  app; `response.headers` sets them for the browser.
- Always set a `matcher`, keep Proxy fast, and never make it the only security check.

## Exercises

1. **A real 404.** Chapter 10 explained that `/events/nope` returns status `200` with `noindex`, because the event
   is looked up inside a streamed part of the page. Make it return a real `404` using Proxy. What does it cost?

<details>
<summary>Solution</summary>

Look the slug up in Proxy and rewrite to a path that doesn't exist, so Next.js renders the root `not-found.tsx` with
a `404` status:

```ts
// proxy.ts (merge this into your existing proxy function)
import { NextResponse, type NextRequest } from 'next/server'
import { db } from '@/lib/db'

export function proxy(request: NextRequest) {
  const match = request.nextUrl.pathname.match(/^\/events\/([^/]+)$/)
  if (match && match[1] !== 'new') {
    const found = db().prepare('SELECT 1 FROM events WHERE slug = ?').get(match[1])
    if (!found) {
      return NextResponse.rewrite(new URL('/not-found-page', request.url))
    }
  }
  return NextResponse.next()
}
```

```text
/events/nope 404
/events/react-reading-group 200
/events/new 200
```

`/events/new` is a static route, not a slug, so it's skipped. The cost is a database query before *every* request
to an event page, including the prefetches that `<Link>` makes, and it duplicates a check the page already does.
With SQLite on the same machine that's microseconds; with a remote database it adds a network round trip to every
event page view. Do it only if something really needs the status code, such as a monitoring setup that counts 404s.
Gather's final app doesn't.

</details>

2. **A rate limit in Proxy.** Chapter 8's exercise limited comments in the action. Write a Proxy rule that allows at
   most 5 `POST` requests per minute per IP address and answers `429 Too Many Requests` above that. Then explain why
   this is fine for a single `next start` process but not a real solution.

<details>
<summary>Solution</summary>

```ts
// proxy.ts
import { NextResponse, type NextRequest } from 'next/server'

const WINDOW_MS = 60_000
const LIMIT = 5
const hits = new Map<string, number[]>()

export function proxy(request: NextRequest) {
  if (request.method === 'POST') {
    const ip = request.headers.get('x-forwarded-for')?.split(',')[0].trim() ?? 'unknown'
    const now = Date.now()
    const recent = (hits.get(ip) ?? []).filter((t) => now - t < WINDOW_MS)
    if (recent.length >= LIMIT) {
      return new NextResponse('Too many requests', {
        status: 429,
        headers: { 'Retry-After': String(Math.ceil((recent[0] + WINDOW_MS - now) / 1000)) },
      })
    }
    recent.push(now)
    hits.set(ip, recent)
  }
  return NextResponse.next()
}
```

Seven `POST`s from one address, then one from another:

```text
HTTP/1.1 405 Method Not Allowed
HTTP/1.1 405 Method Not Allowed
HTTP/1.1 405 Method Not Allowed
HTTP/1.1 405 Method Not Allowed
HTTP/1.1 405 Method Not Allowed
HTTP/1.1 429 Too Many Requests  retry-after: 60
HTTP/1.1 429 Too Many Requests  retry-after: 60
other ip 405
```

(The `405`s are `/api/events` refusing `POST`, as in chapter 11. The limit counts requests, not successes.)

It works here because `next start` is one long-lived process, so the `Map` survives between requests. But the
counts are lost on restart, every server instance counts separately, and the docs warn that Proxy may run apart from
your app, so module-level state isn't guaranteed. `x-forwarded-for` can also be set by the client unless a trusted
proxy in front of you overwrites it. Real rate limits live in a shared store (such as Redis) or, better, in the load
balancer or CDN in front of the app, before requests reach Node.js at all. Per-user limits that depend on your data,
like chapter 8's comment limit, stay in the action.

</details>

3. **CORS for the whole API.** Instead of setting `Access-Control-Allow-Origin` in each handler, allow
   `https://partner.example` to read every response under `/api`. What happens to `/api/events`, which sets its own
   header? Should `/api/revalidate` be included?

<details>
<summary>Solution</summary>

```ts
// next.config.ts, in headers()
return [
  { source: '/:path*', headers: securityHeaders },
  {
    source: '/api/:path*',
    headers: [
      { key: 'Access-Control-Allow-Origin', value: 'https://partner.example' },
      { key: 'Vary', value: 'Origin' },
    ],
  },
]
```

The config rule wins over the handler, so `/api/events` now sends `https://partner.example` instead of `*`. Remove
the header from the handler so the code doesn't claim something the response doesn't do. `Vary: Origin` tells caches
that the answer may depend on the requesting site.

`/api/revalidate` doesn't need CORS at all: it's called by servers and scripts, which ignore CORS, and a browser
on another site has no business calling it. Exclude it with a narrower source, such as `/api/events/:path*`. CORS
should be opened only as far as a browser client actually needs.

</details>

4. **Order of operations.** You add a Proxy rule that redirects every request without a `lang` cookie to
   `/welcome`, and a `redirects()` rule from `/meetups/:slug` to `/events/:slug`. A first-time visitor opens
   `/meetups/react-reading-group`. Which redirects happen, in what order?

<details>
<summary>Solution</summary>

Two. Config redirects run before Proxy, so the first response is the `308` to `/events/react-reading-group`, and
Proxy never sees `/meetups/...`. The browser then requests `/events/react-reading-group`. That request goes through
Proxy, which finds no `lang` cookie and redirects to `/welcome`. If `/welcome` is also matched by Proxy and the page
doesn't set the cookie, you've built a redirect loop; matchers or a `pathname` check must exclude the target.

</details>

---
← Previous: [Route Handlers](11-route-handlers.md) | Next: [Advanced routing](13-advanced-routing.md) →
