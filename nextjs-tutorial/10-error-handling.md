# Chapter 10 — Error handling

## What you'll learn

- The difference between expected errors and unexpected exceptions, and how each is handled
- `error.tsx`: error boundaries for route segments, with `retry()`
- `catchError` from `next/error`: an error boundary for one part of a page
- What users and developers see of a production error: hidden messages and the `digest`
- `global-error.tsx` for failures in the root layout
- `notFound()`, `forbidden()` and `unauthorized()`, their files, and the status codes they produce
- Errors in Server Actions, including the "new deployment" case
- Reporting server errors in one place with `onRequestError` in `instrumentation.ts`
- Why `try`/`catch` can swallow `redirect()` and `notFound()`, and what to do about it

## Where we are

Gather reads from a database, streams personalised parts of pages and runs Server Actions. Any of these can fail. So
far a failure would show Next.js's built-in error screen, or (as in chapter 8's edit page) a hand-written message
where a proper status would be better. This chapter gives every kind of failure a deliberate answer.

## Two kinds of errors

It helps to sort failures into two groups before writing any code:

| | Expected errors | Unexpected exceptions |
|---|---|---|
| **Examples** | Invalid form input, "event is full", a missing event, no permission | A database outage, a bug, a network timeout |
| **How you handle them** | As normal results: return them from actions, render a message, call `notFound()` or `forbidden()` | Let them throw; an error boundary shows a fallback |
| **Who sees details** | The user, in plain language | The developer, in logs; the user sees a generic message |

Chapter 8 already handled the first group: `createEvent` *returns* `{ errors }` for invalid input, and `toggleRsvp`
returns `{ error: 'Sorry, this event is full.' }`. Those aren't failures of the app; they're answers. This chapter is
mostly about the second group, plus the special "not found" and "not allowed" answers.

## `error.tsx`: a boundary for a route segment

An `error.tsx` file next to a `page.tsx` or `layout.tsx` wraps that segment's content in a React **error boundary**.
When anything inside throws while rendering, the boundary shows the `error.tsx` component instead of the broken
part. Gather gets one at the root:

```tsx
// app/error.tsx
'use client' // Error boundaries must be Client Components

import { useEffect } from 'react'
import { unstable_isUnrecognizedActionError } from 'next/navigation'

export default function ErrorPage({ error, retry }: { error: unknown; retry: () => void }) {
  useEffect(() => {
    // In production, Server Component errors arrive without their message;
    // `digest` matches the full error in the server logs.
    console.error(error)
  }, [error])

  // A new version was deployed while this tab was open, and the button the
  // user pressed belongs to the old one. Only a full reload fixes that.
  if (unstable_isUnrecognizedActionError(error)) {
    return (
      <main>
        <h1>Gather has been updated</h1>
        <p className="muted">Reload the page to get the latest version, then try again.</p>
        <button className="button" onClick={() => window.location.reload()}>
          Reload
        </button>
      </main>
    )
  }

  const digest = error instanceof Error && 'digest' in error ? String(error.digest) : undefined

  return (
    <main>
      <h1>Something went wrong</h1>
      <p className="muted">
        Sorry about that. You can try again{digest && <> (error reference {digest})</>}.
      </p>
      <button className="button" onClick={() => retry()}>
        Try again
      </button>
    </main>
  )
}
```

The details, one at a time:

- **It must be a Client Component.** Error boundaries are a client-side React feature: they catch errors during
  rendering in the browser, including errors that arrive from the server in the RSC payload.
- **`error` is typed `unknown`.** Errors from Server Components arrive as `Error` objects; a Client Component can throw
  anything (`throw 'oops'` is legal JavaScript). Narrow with `instanceof Error` before reading properties.
- **`retry()`** re-fetches the segment from the server and renders it again. If the cause was temporary, the page comes
  back. (There's also `reset()`, which re-renders *without* re-fetching. It can't recover from a Server Component
  error, so prefer `retry()`.)
- **The "updated" branch** is the promise from chapter 8: when a deployment changes an action's ID, an open tab sends
  the old one. We'll trigger it later in this chapter.

### Where the boundary sits

In the component hierarchy of a segment, `error.tsx` wraps `loading.tsx`, `not-found.tsx` and `page.tsx`, but **not the
`layout.tsx` of the same folder**:

```text
<Layout>                 ← app/layout.tsx: NOT covered by app/error.tsx
  <ErrorBoundary>        ← app/error.tsx
    <Suspense>           ← app/loading.tsx (if any)
      <Page />           ← and every nested layout and page below
```

So `app/error.tsx` replaces the content between the header and the footer, and the header stays usable. An error in
`app/layout.tsx` itself needs `global-error.tsx` (below). Boundaries nest: an `app/events/error.tsx` would catch
errors in the events pages before they reach `app/error.tsx`. An error bubbles up to the nearest one.

## What a production error looks like

Let's break something on purpose. Make `getComments` in `lib/data.ts` fail whenever a file named `break-comments`
exists in the project folder:

```ts
// lib/data.ts (temporary!)
import { existsSync } from 'node:fs'

export async function getComments(eventId: number): Promise<Comment[]> {
  'use cache'
  cacheLife('minutes')
  cacheTag(`comments:${eventId}`)
  if (existsSync('break-comments')) throw new Error('Comments database is down')
  // ...
}
```

Build and start the production server (`npm run build && npm start`). Then create the file with `touch break-comments`
and open an event whose comments haven't been cached yet. Open Source Saturday is a good choice: it isn't one of the
four events prerendered by `generateStaticParams`.

Without any boundary closer than `app/error.tsx`, the whole page body is replaced by "Something went wrong", even
though only the comments failed. That's too much. Let's look at what each side sees first.

**The browser** gets no message. The console shows:

```text
Error: Minified React error #441; visit https://react.dev/errors/441 for the full message ...
```

That's React's "An error occurred in the Server Components render. The specific message is omitted in production
builds" error. Next.js deliberately strips the message of every Server Component error, because messages can contain
secrets: a connection string, an SQL statement, a user's data. In development you'd see the full message.

**The server** logs the real error, with a `digest`:

```text
⨯ Error: Comments database is down
  digest: '3398801386'
}
```

The digest is a hash that identifies this error. It's the only detail the browser receives, which is why Gather's
error page shows it as an "error reference": a user can report it, and you can find the matching log line.

Prerendered event pages behave differently. Opening Next.js Meetup Berlin still showed its comments, because the
page was prerendered at build time. When its cache entry expired and Next.js tried to regenerate it in the background,
the server logged

```text
Error revalidating the page in the background Error: Comments database is down
```

and kept serving the last good version. Failed revalidations never replace good content with an error.

### A boundary for one section: `catchError`

The comments are secondary. If they fail, the event details and the RSVP button should still work. `error.tsx` is tied
to route segments, but `catchError` from `next/error` creates an error boundary component you can put anywhere:

```tsx
// components/section-error.tsx
'use client'

import { catchError, type ErrorInfo } from 'next/error'

// An error boundary for one part of a page: if the part fails, the rest of
// the page stays usable and only this box shows the problem.
function SectionErrorFallback({ label }: { label: string }, { retry }: ErrorInfo) {
  return (
    <div className="muted" role="alert">
      <p>Couldn’t load {label}.</p>
      <button className="button secondary" onClick={() => retry()}>
        Try again
      </button>
    </div>
  )
}

export const SectionErrorBoundary = catchError(SectionErrorFallback)
```

The fallback function receives two arguments: the props given to the boundary (here a `label`) and an `ErrorInfo`
with `error`, `retry` and `reset`. `catchError` returns a component that accepts those props plus `children`. Compared
with writing a React error boundary class yourself, it knows about Next.js: `retry()` re-fetches from the server, the
error clears when the user navigates elsewhere, and the special errors thrown by `redirect()` and `notFound()` pass
through instead of being caught.

Wrap the discussion with it:

```tsx
// app/events/[slug]/page.tsx (inside EventDetails)
import { SectionErrorBoundary } from '@/components/section-error'

      <section>
        <h2>Discussion</h2>
        <SectionErrorBoundary label="the discussion">
          <Suspense fallback={<p className="muted">Loading comments…</p>}>
            <Comments event={event} />
          </Suspense>
        </SectionErrorBoundary>
      </section>
```

Rebuild and open Open Source Saturday again with `break-comments` present. The page now shows everything except the
discussion, where it says:

```text
Discussion
Couldn’t load the discussion.
Try again
```

Now delete the file (`rm break-comments`) and click **Try again**. `retry()` fetches the section again, and this time
it works:

```text
Discussion
No comments yet. Ask the organizer something!
Add a comment
Post comment
```

That's the right experience for a temporary outage. Remove the `existsSync` lines from `lib/data.ts` when you're done.

The page-level boundary still matters. If `getEvent` fails, nothing on the event page can render, and `app/error.tsx`
takes over. With the same experiment applied to `getEvent`, the page showed:

```text
Something went wrong
Sorry about that. You can try again (error reference 1761549479).
```

### Status codes

The status of both broken pages was `200`. That surprises people, but it follows from streaming: the static shell was
sent before the failing component ran, and the status line is the first thing in a response. React streams the error
into the page, and the boundary renders it in the browser. (If you `curl` such a page, you'll see the shell and the
fallback, not the error UI, because the boundary runs client-side.) An error before anything was sent, such as one in
a Route Handler or a Server Action, does produce a `500`.

For users, the status code doesn't matter; the UI does. For search engines it does matter, which leads to "not found".

## Not found

`notFound()` from `next/navigation` throws a special error that renders the nearest `not-found.tsx`. Gather has used
it since chapter 3. Give the root one the same look as the other error pages:

```tsx
// app/not-found.tsx
import Link from 'next/link'

export default function NotFound() {
  return (
    <main>
      <h1>Page not found</h1>
      <p className="muted">That event may have been deleted, or the link is mistyped.</p>
      <Link href="/events" className="button">
        Browse events
      </Link>
    </main>
  )
}
```

The root `not-found.tsx` also handles every URL that matches no route at all. You can add `not-found.tsx` files deeper
in the tree to show segment-specific messages; `notFound()` uses the nearest one.

Chapter 3 found that `/events/nope` returns `200`, while `/nope` returns `404`, and promised to come back to it. Here
is the rule: if `notFound()` is called **before streaming starts**, the response is a `404`. If it's called **inside
a streamed part** (any `<Suspense>` boundary), the status has already gone out as `200`, and Next.js instead adds
`<meta name="robots" content="noindex">` to the page, so search engines don't index it either way.

With Cache Components, Gather's event page looks up the event inside a Suspense boundary (that's what gives unknown
slugs an instant App Shell), so a deleted or mistyped event is a `200` with `noindex`. For a public site that's
usually fine: users see a helpful page, and search engines drop the URL. If you truly need the `404` status (some
monitoring or compliance setups do), the check must happen before rendering, in Proxy, at the cost of a lookup on
every request. Chapter 12 shows how.

## Forbidden and unauthorized

Two more answers are "you must log in" (`401 Unauthorized`) and "you're logged in but not allowed" (`403 Forbidden`).
Next.js has functions and files for both, modelled on `notFound()`:

| Function | File | Status (before streaming) |
|---|---|---|
| `notFound()` | `not-found.tsx` | 404 |
| `unauthorized()` | `unauthorized.tsx` | 401 |
| `forbidden()` | `forbidden.tsx` | 403 |

`unauthorized()` and `forbidden()` are still experimental in 16.4. Turn them on in `next.config.ts`:

```ts
// next.config.ts
const nextConfig: NextConfig = {
  cacheComponents: true,
  partialPrefetching: true,
  experimental: {
    // Enables forbidden() and unauthorized(). Still experimental in 16.4.
    authInterrupts: true,
  },
}
```

Add the page:

```tsx
// app/forbidden.tsx
import Link from 'next/link'

// Rendered by forbidden() (experimental.authInterrupts) with a 403 status.
export default function Forbidden() {
  return (
    <main>
      <h1>Not allowed</h1>
      <p className="muted">You’re signed in, but this page belongs to someone else.</p>
      <Link href="/dashboard" className="button">
        Go to your dashboard
      </Link>
    </main>
  )
}
```

And replace chapter 8's hand-written message in the edit page:

```tsx
// app/events/[slug]/edit/page.tsx
import { forbidden, notFound } from 'next/navigation'

// ...in EditForm:
  const { slug } = await params
  const user = await requireOrganizer(`/events/${slug}/edit`)
  const event = await getEvent(slug)
  if (!event) notFound()
  if (event.organizer.id !== user.id) forbidden()
```

Test it as Grace, who organizes events but not this one:

```bash
curl -s -o /dev/null -w "%{http_code}\n" -b "demo_user=grace@example.com" \
  http://localhost:3000/events/nextjs-meetup-berlin/edit
```

```text
200
```

In the browser, Grace sees "Not allowed" with the link to her dashboard. The status is `200` for the reason you
already know: the check runs inside the edit page's Suspense boundary, after the shell was sent. Next.js adds the
`noindex` tag here too. Like `notFound()`, `forbidden()` throws, so code after it doesn't run, and TypeScript knows
it (its return type is `never`).

Gather doesn't use `unauthorized()`: when a visitor isn't logged in, `requireUser` *redirects* to the login page,
which is friendlier than an error page. `unauthorized()` suits APIs and apps where the login happens elsewhere.

## Errors in Server Actions

What happens when an action throws instead of returning? Make `toggleRsvp` fail on purpose, right after
`requireUser`:

```ts
// app/actions/rsvp.ts (temporary!)
if (process.env.BREAK_RSVP) throw new Error('RSVP service is down')
```

Build, start with `BREAK_RSVP=1 npm start`, and click RSVP on an event. The action's `POST` returns `500`, and the
error travels back to the browser, where it's thrown at the `await toggleRsvp(...)` inside `startTransition`. An
unhandled error in a transition goes to the nearest error boundary, so the whole page body is replaced by
`app/error.tsx` with an error reference. That's correct for a bug, but heavy-handed for a button. Exercise 1 makes the
button handle it locally.

The same applies to forms: an action passed to `useActionState` or a `<form action>` that throws ends up in the
nearest boundary. This is why chapter 8 returns expected errors as state.

### When the action no longer exists

Chapter 8 explained that action IDs change between deployments. To see what users of an open tab experience, we
opened an event page, then changed the name of the RSVP action, rebuilt and restarted the server, and clicked RSVP in
the old tab. The server answered `409 Conflict` and logged:

```text
Error: Failed to find Server Action "601a7b462e20ab01a5bb9c3cf58029199a7ddc92e1". This request might be from an older or newer deployment.
```

In the browser, the action call threw an `UnrecognizedActionError`. `unstable_isUnrecognizedActionError(error)` from
`next/navigation` detects it, and Gather's `error.tsx` showed:

```text
Gather has been updated
Reload the page to get the latest version, then try again.
Reload
```

A full reload is the only fix, because the tab is running JavaScript from the old build. `retry()` wouldn't help. The
function has the `unstable_` prefix because its name may still change; it's the documented way to detect this case.

## Global errors

`app/error.tsx` can't catch errors in `app/layout.tsx`, because it sits inside it. For that last case there's
`app/global-error.tsx`. When it's active it *replaces* the root layout, so it must render `<html>` and `<body>` itself,
and it doesn't get your global CSS or fonts:

```tsx
// app/global-error.tsx
'use client'

// Replaces the root layout when the layout itself fails, so it renders its
// own <html> and <body> and can't rely on globals.css.
export default function GlobalError({ retry }: { error: unknown; retry: () => void }) {
  return (
    <html lang="en">
      <body style={{ fontFamily: 'system-ui, sans-serif', padding: '2rem' }}>
        <title>Error · Gather</title>
        <h1>Gather is having trouble</h1>
        <p>Please try again in a moment.</p>
        <button onClick={() => retry()}>Try again</button>
      </body>
    </html>
  )
}
```

Being a Client Component, it can't export `metadata`; React's `<title>` element sets the tab title instead. Keep it
simple and self-contained: if the root layout failed, little else can be relied upon. Gather's root layout only
renders static markup and the header, so this page should never appear.

## Errors that boundaries don't catch

Error boundaries catch errors **during rendering** (and in transitions). They don't catch errors in event handlers,
timers, or other code that runs after rendering. In those places, catch the error yourself and put it in state:

```tsx
'use client'

import { useState } from 'react'

export function CopyLinkButton({ url }: { url: string }) {
  const [error, setError] = useState<string>()
  async function copy() {
    try {
      await navigator.clipboard.writeText(url)
    } catch {
      setError('Copying isn’t allowed here. Select the address bar instead.')
    }
  }
  return (
    <>
      <button className="button secondary" onClick={copy}>
        Copy link
      </button>
      {error && <span className="error">{error}</span>}
    </>
  )
}
```

(Chapter 4's `ShareButton` handles the same failure by falling back from `navigator.share` to the clipboard.)

## Don't swallow framework errors with `try`/`catch`

`redirect()`, `notFound()`, `forbidden()` and `unauthorized()` all work by **throwing**. A `try`/`catch` around them
catches them like any other error, and then nothing happens:

```tsx
// A bug: notFound() is caught and logged, and the page renders without data
try {
  const event = await getEvent(slug)
  if (!event) notFound()
  // ...
} catch (error) {
  console.error(error)
}
```

Two fixes:

- **Keep framework calls outside the `try`.** Wrap only the call that can fail (`getEvent`), then decide what to do
  afterwards. This is usually the clearest code.
- **Rethrow them.** Call `unstable_rethrow(error)` from `next/navigation` as the first line of the `catch` block. It
  rethrows Next.js's internal errors and does nothing for your own.

The same applies to Server Actions: `requireUser` redirects by throwing, so an action body wrapped in a big
`try`/`catch` would turn "please log in" into an error message. On the client side, though, you can safely wrap
`await someAction()` in `try`/`catch`: a redirect from the action is handled by the router before your code sees
anything, as exercise 1 confirms.

## Reporting errors: `onRequestError`

Server logs are where the full errors are. Rather than adding logging to every component, Next.js calls one function
for every error thrown on the server while rendering, in an action, in a Route Handler or in Proxy. Define it in
`instrumentation.ts` at the project root (next to `app/`):

```ts
// instrumentation.ts
import type { Instrumentation } from 'next'

// Called for every error thrown while rendering, in a Server Action, in a
// Route Handler or in proxy. Send it to your error tracker here.
export const onRequestError: Instrumentation.onRequestError = async (error, request, context) => {
  const err = error as Error & { digest?: string }
  console.error(
    JSON.stringify({
      level: 'error',
      message: err.message,
      digest: err.digest,
      path: request.path,
      method: request.method,
      routePath: context.routePath,
      routeType: context.routeType,
    }),
  )
}
```

Gather prints one JSON line per error, which log tools can search. With the experiments above, the server printed:

```text
{"level":"error","message":"Comments database is down","digest":"3398801386","path":"/events/open-source-saturday","method":"GET","routePath":"/events/[slug]","routeType":"render"}
{"level":"error","message":"RSVP service is down","digest":"880793116","path":"/events/open-source-saturday","method":"POST","routePath":"/events/[slug]","routeType":"action"}
```

The `digest` is the same number the user saw as the error reference, so a support request can be traced to the exact
log line. `routeType` tells you where it happened (`render`, `action`, `route` or `proxy`). In a real app, this is
where you'd call your error tracker's SDK (Sentry, for example, provides a ready-made function for `onRequestError`).
Chapter 19 adds more to this file.

## How this differs from older Next.js versions

- **`retry` replaced `reset`** as the recommended recovery function in `error.tsx` (stable since 16.3; it was
  `unstable_retry` in 16.2). Older tutorials call `reset()`, which doesn't re-fetch Server Components.
- **`catchError`** is new; before it, component-level boundaries meant writing a class component or using
  `react-error-boundary`, neither of which understands `redirect()` or retries from the server.
- **The `error` prop is typed `unknown`** in current docs, instead of `Error & { digest?: string }`.
- **`forbidden()` and `unauthorized()`** arrived in 15.1, still experimental.
- **Status codes and streaming**: with Cache Components, more of each page is prerendered, so more `notFound()` calls
  happen after streaming has started and produce `200` with `noindex` rather than `404`.

## Key takeaways

- Return expected errors as values; throw only for the unexpected.
- `error.tsx` wraps a route segment (not its own layout) in an error boundary; `retry()` re-fetches and re-renders.
- `catchError` makes a boundary for any part of a page, so a failing section doesn't take the page down.
- Production hides Server Component error messages from the browser. The `digest` links what the user sees to the
  server log.
- Failed background revalidations keep serving the last good content.
- `notFound()`, `forbidden()` and `unauthorized()` render their files; their status is 404/403/401 before streaming
  starts, and 200 with `noindex` after.
- A thrown Server Action error reaches the nearest error boundary; a deployment mismatch is an
  `UnrecognizedActionError`, fixed only by a reload.
- Keep `redirect()` and `notFound()` out of `try` blocks, or use `unstable_rethrow`.
- `onRequestError` in `instrumentation.ts` sees every server error in one place.

## Exercises

1. **A sturdier RSVP button.** With `BREAK_RSVP=1`, a failed RSVP replaces the whole page. Change `RsvpButton` so that
   any thrown error shows a message next to the button instead, with a special message for the "Gather was updated"
   case. Make sure that a redirect from the action (`requireUser` sending a logged-out user away) still works.

<details>
<summary>Solution</summary>

Wrap the call in `try`/`catch` inside the transition:

```tsx
// components/rsvp-button.tsx
import { unstable_isUnrecognizedActionError } from 'next/navigation'

// ...in onClick:
    startTransition(async () => {
      setOptimistic(!optimistic.going)
      try {
        const result = await toggleRsvp(eventId, slug)
        if (result.error) setError(result.error)
        setState({ going: result.going, count: result.count })
      } catch (err) {
        setError(
          unstable_isUnrecognizedActionError(err)
            ? 'Gather was updated. Reload the page and try again.'
            : 'Couldn’t save your RSVP. Please try again.',
        )
      }
    })
```

With `BREAK_RSVP=1`, clicking RSVP now leaves the page in place and shows "Couldn’t save your RSVP. Please try
again." next to the button. The optimistic state is dropped when the transition ends, so the button goes back to
"RSVP". To check redirects: load an event page as Ada, then set `document.cookie = 'demo_user=nobody@example.com;
path=/'` and click RSVP. The action's `requireUser` finds no user and redirects; the browser goes to
`/?login-needed=%2Fevents%2F...` and no error message appears. The client-side `try`/`catch` didn't interfere,
because the router handles the redirect itself.

</details>

2. **Find the bug.** A teammate wrote this Server Component. Visiting a deleted event shows an empty page instead of
   "Page not found". Why, and what are two ways to fix it?

```tsx
async function EventTitle({ slug }: { slug: string }) {
  try {
    const event = await getEvent(slug)
    if (!event) notFound()
    return <h1>{event.title}</h1>
  } catch (error) {
    console.error('Could not load event', error)
    return null
  }
}
```

<details>
<summary>Solution</summary>

`notFound()` throws a special error, and the `catch` block catches it, logs it as "Could not load event" and returns
`null`. Fix one: move the framework call out of the `try`:

```tsx
async function EventTitle({ slug }: { slug: string }) {
  let event
  try {
    event = await getEvent(slug)
  } catch (error) {
    console.error('Could not load event', error)
    return null
  }
  if (!event) notFound()
  return <h1>{event.title}</h1>
}
```

Fix two: keep the structure and add `unstable_rethrow(error)` (from `next/navigation`) as the first line of the
`catch` block. Also ask whether catching is right at all here: if loading the event fails, letting the error reach
an error boundary (with a retry button and a log entry via `onRequestError`) is usually better than silently
rendering nothing.

</details>

3. **Which boundary?** For each failure, say which UI the user sees on an event page: (a) `getComments` throws; (b)
   `getEvent` throws; (c) the `deleteEvent` action throws "Event not found or not yours"; (d) `notFound()` is called in
   `EventDetails`; (e) `app/layout.tsx` throws.

<details>
<summary>Solution</summary>

(a) The `SectionErrorBoundary` around the discussion: "Couldn’t load the discussion." with a retry button; the rest of
the page works. (b) `app/error.tsx`, because `EventDetails` isn't inside any closer boundary: "Something went wrong"
with an error reference; header and footer remain. (c) Also `app/error.tsx`: the action was called from a `<form
action>`, the error is thrown in the browser during the transition, and the nearest boundary above the button is the
root one. (d) `app/not-found.tsx`, rendered in place of the page content, with status 200 and `noindex` because it
happens inside a Suspense boundary. (e) `app/global-error.tsx`, which replaces the whole document, header included.

</details>

4. **A not-found page for events.** Make a missing *event* say "This event doesn't exist (any more)" with a link to
   the events list, while other missing pages keep the generic message.

<details>
<summary>Solution</summary>

Create `app/events/[slug]/not-found.tsx`:

```tsx
import Link from 'next/link'

export default function EventNotFound() {
  return (
    <main>
      <h1>This event doesn’t exist (any more)</h1>
      <p className="muted">It may have been cancelled by its organizer.</p>
      <Link href="/events" className="button">
        See upcoming events
      </Link>
    </main>
  )
}
```

`notFound()` renders the nearest `not-found.tsx` above the component that called it. For `EventDetails`, that's now
the one in `app/events/[slug]/`. URLs that match no route at all still get `app/not-found.tsx`, which only the root
file handles.

</details>

---
← Previous: [Navigation and prefetching](09-navigation-and-prefetching.md) | Next: [Route Handlers](11-route-handlers.md) →
