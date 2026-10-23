# Chapter 9 — Navigation and prefetching

## What you'll learn

- What happens during a client-side navigation, and what Next.js keeps between pages
- Reading the URL in Client Components: `usePathname`, `useSearchParams` and `useParams`
- Highlighting the active link, and why the hook needs a `<Suspense>` boundary
- Search forms that navigate, with `next/form`
- Navigating from code with `useRouter`, and when to use `redirect()` instead
- Prefetching in Next.js 16.4: the App Shell, per-link prefetching with `prefetch={true}`, and what each one costs
- Deferring work until the user actually navigates, with `navigation()`
- Checking that navigations are instant: the Navigation Inspector, instant validation, and `instant = false`
- Feedback on slow networks with `useLinkStatus`, and controlling scroll

## Where we are

Gather's pages render fast on a first visit: chapter 7 gave them a static shell, and chapter 8 kept the personal
parts in small streamed boxes. Most page views, though, aren't first visits. They're clicks from one page to another.
This chapter is about making those clicks feel instant, and about the URL as a piece of state: the header will
highlight the current section, and the events page gets a real search form.

## What a client-side navigation does

Chapter 3 introduced `<Link>`. When the app has hydrated, clicking a link doesn't load a new document. Instead:

1. Next.js asks the server for the new route's **RSC payload**, the serialized Server Component output from chapter
   4. You can see the request in DevTools' Network panel: a `GET` to the new URL with a `_rsc` query parameter, for
   example `/events/nextjs-meetup-berlin?_rsc=ZzZiSWL1XNlH12c_`.
2. React renders the new page into the existing document. **Layouts shared by both routes are not rendered again**:
   their DOM and Client Component state stay as they are.
3. The browser's history gets a new entry, so Back and Forward work, and the page scrolls to the top.

Usually most of step 1 has already happened before the click, because the link was **prefetched**. That's the
second half of this chapter.

### The previous pages are kept, not destroyed

With Cache Components, Next.js doesn't unmount the page you leave. It hides it with React's `<Activity>` component
(the DOM stays in the document with `display: none`) and keeps up to three recent routes this way. Going Back to one
of them restores it exactly as you left it: a half-typed comment, the scroll position inside a list, an open
`<details>` element, a playing video. Beyond three routes, the oldest is dropped and renders fresh next time.

Two consequences to keep in mind:

- **Effects in a hidden page are cleaned up**, and run again when it becomes visible. A component that subscribes to
  something in `useEffect` stops listening while hidden.
- **Hidden pages are still in the DOM.** Code that does `document.querySelector('h1')` can find the `<h1>` of a hidden
  page; end-to-end tests need visibility-aware selectors (chapter 18).

When a component *should* start fresh on every visit (a form that must be empty each time, say), give it a `key` that
changes. Next.js also exposes `useRouter().bfcacheId`, which changes on push and replace navigations but not on Back
and Forward, for exactly that purpose.

## Reading the URL in Client Components

Server Components get the URL as props: `params` and `searchParams` on pages (chapter 3). Client Components, and
Server Components that aren't pages, use hooks from `next/navigation`:

| Hook | Returns | Example on `/events/svelte-zagreb?ref=mail` |
|---|---|---|
| `usePathname()` | The path, without the query string | `'/events/svelte-zagreb'` |
| `useSearchParams()` | A read-only `URLSearchParams` | `.get('ref')` is `'mail'` |
| `useParams()` | The dynamic segments of the current route | `{ slug: 'svelte-zagreb' }` |

These hooks only work in Client Components, and they update on every navigation, which is exactly what a layout can't
do (chapter 3 explained why layouts never see the URL).

### An active link in the header

Gather's header has two links, Events and Dashboard. Highlighting the current one is the classic use for
`usePathname()`. A first attempt:

```tsx
// components/nav-link.tsx (first attempt)
'use client'

import Link from 'next/link'
import { usePathname } from 'next/navigation'
import styles from './nav-link.module.css'

export function NavLink({ href, children }: { href: string; children: React.ReactNode }) {
  const pathname = usePathname()
  const active = pathname === href || pathname.startsWith(`${href}/`)
  return (
    <Link
      href={href}
      className={active ? `${styles.link} ${styles.active}` : styles.link}
      aria-current={active ? 'page' : undefined}
    >
      {children}
    </Link>
  )
}
```

`npm run build` refuses it:

```text
Error: Route "/events/[slug]/edit": Next.js encountered URL data `usePathname()` in a Client Component outside of `<Suspense>`.

This blocks prerendering because the value is only available at runtime.

Ways to fix this:
  - [stream] Wrap the component in `<Suspense fallback={...}>` so the hook value streams in after prerendering
  - [block] Set `export const instant = false` to allow a blocking route

Learn more: https://nextjs.org/docs/messages/blocking-prerender-client-hook
```

Think about what the hook means during prerendering. The header is in the root layout, so it's part of the static
shell of every route. For `/events` the pathname is known at build time. But the App Shell of `/events/[slug]/edit`
serves *every* slug, so the real pathname only exists once a request arrives. To Next.js, `usePathname()` there is
URL data, just like the `params` prop, and the same rule applies: it must be inside `<Suspense>`.

The fix keeps the static shell useful. The fallback is the same link without the highlight, so the shell contains
working links and the highlight appears when the real pathname is known:

```tsx
// components/nav-link.tsx
'use client'

import Link from 'next/link'
import { usePathname } from 'next/navigation'
import { Suspense } from 'react'
import styles from './nav-link.module.css'

type Props = { href: string; children: React.ReactNode }

// The pathname is URL data. On a dynamic route like /events/[slug] it isn't
// known while the static shell is prerendered, so the highlighted version
// sits behind <Suspense>; the shell contains a plain, unhighlighted link.
export function NavLink(props: Props) {
  return (
    <Suspense
      fallback={
        <Link href={props.href} className={styles.link}>
          {props.children}
        </Link>
      }
    >
      <ActiveLink {...props} />
    </Suspense>
  )
}

function ActiveLink({ href, children }: Props) {
  const pathname = usePathname()
  const active = pathname === href || pathname.startsWith(`${href}/`)
  return (
    <Link
      href={href}
      className={active ? `${styles.link} ${styles.active}` : styles.link}
      aria-current={active ? 'page' : undefined}
    >
      {children}
    </Link>
  )
}
```

The styles are already in `nav-link.module.css` from chapter 5 (`.active` underlines the link). `aria-current="page"`
tells screen readers which link is the current page; it's the accessible half of the highlight.

On a client navigation the router already knows the new URL, so the hook resolves at once and there's no flicker.
The fallback only shows during the first load of a dynamic route, for the moment before the page hydrates.

`useSearchParams()` follows the same rule, on every route: the query string is never known at build time.

## Search with `next/form`

The events page has filtered by city since chapter 3, with a row of links. A search box is better, and it's a classic
HTML pattern: a `GET` form whose fields become the query string. `next/form` makes that pattern fit a client-side app.

```tsx
// app/events/page.tsx
import Form from 'next/form'
import { Suspense } from 'react'
import { getCities, searchEvents } from '@/lib/data'
import { EventGrid, EventGridSkeleton } from '@/components/event-card'
import { SearchButton } from '@/components/search-button'
import styles from './events.module.css'

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
  const params = await searchParams
  const q = first(params.q)
  const city = first(params.city)
  const [cities, events] = await Promise.all([getCities(), searchEvents(q, city)])

  return (
    <>
      {/* <Form action=""> navigates to /events?q=...&city=... on the client,
          without a full page load. It still works without JavaScript. */}
      <Form action="" className={styles.filters} key={`${q}|${city}`}>
        <input name="q" defaultValue={q} placeholder="Search events" aria-label="Search events" />
        <select name="city" defaultValue={city} aria-label="City">
          <option value="">All cities</option>
          {cities.map((c) => (
            <option key={c}>{c}</option>
          ))}
        </select>
        <SearchButton />
      </Form>
      <p className="muted">
        {events.length === 1 ? '1 event' : `${events.length} events`}
        {q && ` matching “${q}”`}
        {city && ` in ${city}`}
      </p>
      <EventGrid events={events} />
    </>
  )
}
```

`searchEvents(q, city)` has existed since chapter 6; until now the page always passed an empty query.

When `action` is a **string**, `<Form>` behaves like an HTML form with `method="get"`: on submit, the fields are
encoded into the URL (`/events?q=react&city=`), and `action=""` means "this same route". On top of that, Next.js:

- performs a **client-side navigation** instead of a page load, so the header and any client state survive;
- **prefetches** the target route when the form becomes visible, like a `<Link>`;
- still works **without JavaScript**, as a plain `GET` form.

(When `action` is a *function*, `<Form>` is just React's `<form>` running a Server Action, as in chapter 8.)

The `key` resets the form whenever the query changes by some other route than the form itself, for example the Back
button or a click on "Events" in the header. Without it, the uncontrolled inputs would keep showing the old search
text. It's a plain React key, which remounts the form; it doesn't change what `<Form>` submits.

The button reuses chapter 8's `useFormStatus` idea:

```tsx
// components/search-button.tsx
'use client'

import { useFormStatus } from 'react-dom'

// useFormStatus reads the state of the <form> this button is inside, so it
// must be rendered as a child of that form.
export function SearchButton() {
  const { pending } = useFormStatus()
  return (
    <button className="button" disabled={pending}>
      {pending ? 'Searching…' : 'Search'}
    </button>
  )
}
```

`<Form>` runs its navigation as a form action, so `pending` is `true` until the new results have rendered. With the
responses artificially slowed down by 1.5 seconds, the button read "Searching…" while the URL was still `/events`,
and "Search" again once the results for `/events?q=lisbon` were on screen.

And the filter row's styles:

```css
/* app/events/events.module.css */
.filters {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-bottom: 0.5rem;
}

.filters input,
.filters select {
  padding: 0.55rem 0.7rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg);
  color: var(--fg);
  font: inherit;
}

.filters input {
  flex: 1;
  min-width: 12rem;
}
```

Because the search lives in the URL, results can be bookmarked and shared, Back and Forward move through earlier
searches, and the server renders the right results on a first visit. Keeping state in the URL is almost always the
right choice for filters, sorting and pagination.

## Navigating from code

Most navigation should be a `<Link>` or a `<Form>`: they're accessible, prefetch, and work before JavaScript loads.
When navigation has to happen from code in a Client Component, use the router:

```tsx
'use client'

import { useRouter } from 'next/navigation'

export function CancelButton() {
  const router = useRouter()
  return (
    <button type="button" className="button secondary" onClick={() => router.back()}>
      Cancel
    </button>
  )
}
```

| Method | What it does |
|---|---|
| `router.push(href)` | Navigate, adding a history entry |
| `router.replace(href)` | Navigate, replacing the current history entry |
| `router.back()` / `router.forward()` | Move through history |
| `router.refresh()` | Re-render the current route on the server, keeping client state |
| `router.prefetch(href)` | Prefetch a route manually |

`push` and `replace` accept `{ scroll: false }` to keep the scroll position. Import `useRouter` from
`next/navigation`; the one in `next/router` belongs to the older Pages Router and doesn't work here.

On the server, use `redirect()` and `permanentRedirect()` from `next/navigation` instead. They work in Server
Components and Server Actions (chapter 8 used `redirect()` after saving an event), and redirects that don't depend on
the page at all belong in `next.config.ts` or in Proxy (chapter 12).

## Prefetching

A navigation can only be instant if the browser already has what the next page needs. Prefetching downloads it in the
background, before the click. It only runs in production builds (`next build && next start`), so everything in this
section is about a production server.

### Three stages

Gather has `partialPrefetching: true` in `next.config.ts` since chapter 1. With it, Next.js loads a route in up to
three **navigation stages**:

| Stage | Triggered by | Contains | Cost |
|---|---|---|---|
| **App Shell** | Any visible `<Link>` (the default) | The route's static and cached output that doesn't depend on the URL, plus content based on cookies or headers | One request per *route*, shared by all its links |
| **Per-link prefetch** | A visible `<Link prefetch={true}>` | The App Shell plus static or cached content that depends on this link's `params` or `searchParams` | One server request per *link* |
| **Navigation** | The click | Everything else: uncached data, and work deferred with `navigation()` | One request per click |

The App Shell is the same idea as chapter 7's: one generic shell per route. `/events/nextjs-meetup-berlin` and
`/events/svelte-zagreb` share one App Shell, so a page full of event links costs about as much to prefetch as one
link. Content that depends on the *URL* can't be in a shared shell. Content that depends on the *session* (cookies)
can, because it's the same for every link the current user sees.

### Seeing it in the network panel

Open `/events` on the production server with DevTools' Network panel open, filtered to `_rsc`. Every prefetch request
carries a `Next-Router-Prefetch` header. Here are the requests captured while `/events` loaded, with Gather's event
cards using default links:

```text
GET /?_rsc=…                                prefetch=1 segment=/_tree
GET /dashboard?_rsc=…                       prefetch=1 segment=/_tree
GET /events/svelte-zagreb?_rsc=…            prefetch=1 segment=/_tree
GET /events/nextjs-meetup-berlin?_rsc=…     prefetch=1 segment=/_tree
GET /events/react-reading-group?_rsc=…      prefetch=1 segment=/_tree
GET /events/node-hack-night-lisbon?_rsc=…   prefetch=1 segment=/_tree
GET /events/svelte-zagreb?_rsc=…            prefetch=1 segment=/events/$d$slug/__PAGE__
GET /?_rsc=…                                prefetch=1 segment=/__PAGE__
GET /dashboard?_rsc=…                       prefetch=1 segment=/dashboard/__PAGE__
```

(`segment` is the `Next-Router-Segment-Prefetch` header.) Each visible link fetches the small route tree
(`/_tree`), but the page segment of `/events/[slug]` (`$d$slug` stands for the dynamic `[slug]`) is fetched **once**,
from the first card, and shared by all of them. That's the App Shell at work.

### What the user sees: App Shell only

What does that shell give the user? The cleanest way to find out is to freeze a navigation at the moment of the click,
before anything streams from the server. The Navigation Inspector in the dev tools does this interactively (see
below); the `instant()` test helper from `@next/playwright` (chapter 18) does it in a test. We used the latter to click
an event card on `/events` and record what was on screen while the navigation was frozen:

```text
h1: (none)
skeleton blocks: 3
"Loading comments…": not shown
```

That's `EventSkeleton`, the fallback around `EventDetails`. Everything about *this* event depends on `params`, and the
App Shell can't contain it. The page appears instantly, but as a skeleton.

### Per-link prefetching with `prefetch={true}`

Event details are cached with `getEvent(slug)` (chapter 7), so they could be ready before the click if the prefetch
knew which slug to use. That's what `prefetch={true}` adds: a prefetch for this link's own URL that resolves static
and cached content depending on `params` and `searchParams`.

```tsx
// components/event-card.tsx (the link)
{/* prefetch={true}: also prefetch the URL-specific (cached) event
    details, so opening an event is instant. */}
<Link href={`/events/${event.slug}`} prefetch={true}>
  {event.title}
</Link>
```

The same frozen navigation now shows:

```text
h1: "Next.js Meetup Berlin"
"2 going · 58 spots left" and the RSVP button
"Loading comments…": shown
```

The cached details and even the RSVP panel (which reads the session cookie) were ready at the click. Only the comments
show their fallback; you'll see why in a moment. The cost is visible in the network panel too: every card now makes
its own request, marked `Next-Router-Prefetch: 2`:

```text
GET /events/svelte-zagreb?_rsc=…            prefetch=2
GET /events/nextjs-meetup-berlin?_rsc=…     prefetch=2
GET /events/react-reading-group?_rsc=…      prefetch=2
… one for each of the 9 cards
```

So `prefetch={true}` is a trade: more of the page is ready at the click, in exchange for a server request per visible
link. It pays off when the destination depends on the URL, its content is cached (a prefetch never includes uncached
data), and users often follow these links. Gather's event cards meet all three. For a long list where users rarely
click, it doesn't, and exercise 1 shows the middle ground: prefetching on hover.

| `prefetch` value | Behaviour with Partial Prefetching |
|---|---|
| not set, `'auto'` or `null` | The App Shell (the default) |
| `true` | The App Shell plus a per-link prefetch of URL-dependent cached content |
| `false` | Nothing until the click |

Older Next.js versions behaved differently: `prefetch={true}` fetched the *whole* page including uncached data, and
there was no shared shell. If you upgrade an older app, the "Adopting Partial Prefetching" guide in the docs walks
through the differences. `partialPrefetching` is opt-in in 16.x; the docs say it will be on everywhere in the next
major version, and the option removed.

### Deferring work with `navigation()`

The comments weren't in the per-link prefetch, because chapter 9's version of `Comments` starts by awaiting
`navigation()`:

```tsx
// app/events/[slug]/page.tsx
import { navigation } from 'next/cache'

async function Comments({ event }: { event: EventDetail }) {
  // Comments sit below the fold. navigation() keeps them out of prefetches:
  // they render when the user actually opens the event.
  await navigation()
  const comments = await getComments(event.id)
  // ...unchanged
}
```

`await navigation()` says "nothing below this line belongs in a prefetch". The App Shell and per-link prefetches
render the nearest `<Suspense>` fallback instead ("Loading comments…"), and the comments render when the user
actually navigates. Nine visible cards no longer mean nine comment queries for pages most visitors won't open.

It's different from `connection()` (chapter 6) in an important way: `connection()` makes the code below it
request-time, so it can't be cached. `navigation()` only *delays* it; `getComments` keeps its `'use cache'` and its
lifetime. And like the comment says, it applies only to prefetches and client navigations: a prerendered page still
includes the content, and a direct visit renders it as usual.

There's a sibling, `prefetch()` from `next/cache`: code below `await prefetch()` is left out of the App Shell but
included in per-link prefetches. Both must be called inside a `<Suspense>` boundary, outside any `'use cache'`
function, and neither works in a Client Component.

## Checking that navigations are instant

### Instant validation in development

With Cache Components, `next dev` checks every page as you open it and reports anything that would make a navigation
into it block: a missing `<Suspense>` boundary, uncached data on the way. The report appears in the dev overlay with
fix suggestions (cache it, or stream it behind Suspense), the same advice as the build error above. The check runs
both for a full page load and for client navigations from the layouts above the page, because a boundary in the root
layout covers a page load but not a navigation between two sibling pages.

### Opting a route out: `instant = false`

Sometimes a route can't reasonably be instant: a report page that must compute everything fresh, or a route you'll fix
later. Chapter 3 mentioned the escape hatch:

```tsx
// page.tsx or layout.tsx
export const instant = false
```

This tells Next.js that navigations into this segment may block on the server, and silences the validation for it.
It's a declaration of intent, not an optimization: the route still renders the same way. Navigations *between*
pages below an opted-out layout are still checked. Gather doesn't need it anywhere.

### The Navigation Inspector

In development, open the dev tools (the Next.js icon in the corner) and select **Navigation Inspector**. Turn on
**Pause on navigations**, then reload a page or click a link: the page freezes at the state the user sees first, the
static shell for a page load or the prefetched UI for a client navigation, labelled "Page load" or "Client nav".
**Resume** lets the rest stream in. Remember that automatic prefetching only runs in production, so a client
navigation in development shows what the route's structure *allows*; the production network panel shows what was
actually fetched.

## Slow networks: `useLinkStatus`

On a slow connection the prefetch might not have finished when the user clicks, and the click seems to do nothing.
`useLinkStatus()` from `next/link` reports whether the `<Link>` it's rendered inside is navigating:

```tsx
'use client'

import { useLinkStatus } from 'next/link'

// Must be rendered inside a <Link>. Always present, so it never shifts the
// layout; only its opacity changes.
export function LinkHint() {
  const { pending } = useLinkStatus()
  return <span aria-hidden className={pending ? 'link-hint pending' : 'link-hint'} />
}
```

If the route was already prefetched, the pending state is skipped entirely, so the hint only appears when it's
useful. Treat it as a patch for a slow transition you've noticed; the real fixes are prefetching and good Suspense
boundaries.

## Scrolling

After a push navigation Next.js scrolls to the top of the new page; Back and Forward restore the previous position. To
keep the position (for tabs, or a modal over the current page, as in chapter 13), use `scroll={false}`:

```tsx
<Link href="/events?city=Lisbon" scroll={false}>
  Lisbon
</Link>
```

Links to an `id` (`/events#upcoming`) scroll to that element. If a sticky header covers the target, add
`scroll-padding-top` to the `html` element in CSS, equal to the header's height.

## How this differs from older Next.js versions

- **The client router cache** of Next.js 13 and 14 kept visited pages for 30 seconds or 5 minutes, configured with
  `staleTimes`. With Cache Components, how long the browser may reuse data is the `stale` time of its `cacheLife`
  (chapter 7), and invalidations from `updateTag` and `revalidateTag` refresh prefetched data automatically.
- **Prefetching** used to be all or nothing: a static page was prefetched whole, a dynamic one only up to its
  `loading.tsx`, and `prefetch={true}` prefetched everything, uncached data included. Partial Prefetching replaces
  that with the shared App Shell and per-link prefetches.
- **`navigation()` and `prefetch()`** are new in 16.4 (they had `unstable_` names in canary releases).
- **Unmounting on navigation**: before Cache Components, leaving a page destroyed its state. Now the last three routes
  are kept hidden.
- **`next/form`** arrived in Next.js 15. Before it, a search form needed `useRouter` and an `onSubmit` handler.

## Key takeaways

- Client navigations fetch the new route's RSC payload and keep shared layouts mounted. With Cache Components, the
  last three pages are kept hidden, with their state.
- `usePathname`, `useSearchParams` and `useParams` read the URL in Client Components. URL data must be inside
  `<Suspense>` when the shell is prerendered; give the fallback a sensible static version.
- Put filters and searches in the URL. `next/form` with a string `action` turns a form into a prefetched client-side
  navigation that still works without JavaScript.
- Prefer `<Link>` and `<Form>`; use `useRouter` for navigation that has to happen in code, and `redirect()` on the
  server.
- The default `<Link>` prefetches the route's App Shell, shared by all links to that route. `prefetch={true}` adds a
  per-link prefetch of cached, URL-dependent content, at the cost of one server request per visible link.
- `await navigation()` keeps cacheable but expensive content out of prefetches, without losing its cache lifetime.
- Development validation and the Navigation Inspector show what a navigation will look like;
  `export const instant = false` opts a route out of the check.

## Exercises

1. **Prefetch on intent.** Nine `prefetch={true}` cards mean nine server requests every time `/events` is opened.
   Change the cards so that each card's per-link prefetch only starts when the pointer moves over it, while the
   shared App Shell is still prefetched as before. Check the result in the Network panel.

<details>
<summary>Solution</summary>

A small Client Component switches the link from `'auto'` to `true` on hover:

```tsx
// components/hover-prefetch-link.tsx
'use client'

import Link from 'next/link'
import { useState } from 'react'

// The App Shell is prefetched as usual ('auto'); the per-link prefetch of
// this event's details starts only when the pointer shows intent.
export function HoverPrefetchLink({ href, children }: { href: string; children: React.ReactNode }) {
  const [intent, setIntent] = useState(false)
  return (
    <Link href={href} prefetch={intent ? true : 'auto'} onMouseEnter={() => setIntent(true)}>
      {children}
    </Link>
  )
}
```

Use it in `EventCard` instead of `<Link ... prefetch={true}>`. (`EventCard` itself stays a Server Component; only the
link is a client island.) After loading `/events`, there are no `Next-Router-Prefetch: 2` requests; hovering "React
Reading Group" triggers exactly one, for `/events/react-reading-group`. Don't use `prefetch={false}` before the hover:
that would turn off the App Shell prefetch too. On touch screens there's no hover, so those users get the App Shell
only, which is still an instant (skeleton) navigation.

</details>

2. **A pending hint in the header.** Use `LinkHint` from this chapter inside `NavLink`, and add CSS so that a small
   dot appears next to the link text after 100 ms of pending navigation. How can you test it, given that prefetched
   navigations skip the pending state?

<details>
<summary>Solution</summary>

Render `<LinkHint />` inside both `<Link>` elements in `nav-link.tsx` (the fallback and `ActiveLink`), after
`{children}`. In `globals.css`:

```css
.link-hint {
  display: inline-block;
  width: 0.4rem;
  height: 0.4rem;
  margin-left: 0.3rem;
  border-radius: 50%;
  background: currentColor;
  opacity: 0;
  vertical-align: middle;
}

.link-hint.pending {
  animation: link-hint-in 0s 100ms forwards;
}

@keyframes link-hint-in {
  to {
    opacity: 1;
  }
}
```

The dot always takes up its space, so nothing shifts; the zero-length animation with a 100 ms delay hides it for
fast navigations. To see it, add `prefetch={false}` to the links temporarily, or use DevTools' network throttling
("Slow 4G") and click before the prefetch completes.

</details>

3. **Search as you type.** Add a second search box to `/events` that updates `?q=` automatically, 300 ms after the user
   stops typing, without adding a history entry for every keystroke. Which hooks do you need, and why must the
   component be inside `<Suspense>`?

<details>
<summary>Solution</summary>

```tsx
// components/live-search.tsx
'use client'

import { usePathname, useRouter, useSearchParams } from 'next/navigation'
import { useEffect, useRef, useTransition } from 'react'

// Updates ?q= as the user types, 300 ms after the last keystroke.
export function LiveSearch() {
  const router = useRouter()
  const pathname = usePathname()
  const searchParams = useSearchParams()
  const [isPending, startTransition] = useTransition()
  const timer = useRef<ReturnType<typeof setTimeout>>(undefined)

  useEffect(() => () => clearTimeout(timer.current), [])

  function onChange(value: string) {
    clearTimeout(timer.current)
    timer.current = setTimeout(() => {
      const params = new URLSearchParams(searchParams)
      if (value) params.set('q', value)
      else params.delete('q')
      startTransition(() => router.replace(`${pathname}?${params}`, { scroll: false }))
    }, 300)
  }

  return (
    <input
      type="search"
      defaultValue={searchParams.get('q') ?? ''}
      onChange={(e) => onChange(e.target.value)}
      placeholder="Type to search"
      aria-label="Live search"
      aria-busy={isPending}
    />
  )
}
```

In the page, render it as `<Suspense fallback={null}><LiveSearch /></Suspense>` under the heading.
`useSearchParams()` is URL data, which is never known at build time, so without the boundary the build fails with
the same error as the first `NavLink`. `router.replace` keeps one history entry however much the user types, and
`{ scroll: false }` stops the page from jumping to the top. The transition keeps the old results visible while the
new ones load, and `isPending` is available for a spinner. The input keeps focus, because the component is in the
page's static part and isn't remounted. (In practice, choose *one* search UI: the form is simpler and works without
JavaScript.)

</details>

4. **Preserved state.** Open Web Performance Clinic, type a sentence into the comment box without posting it, click
   "Events" in the header, and press Back. Then try a longer detour: from Web Performance Clinic (with a draft), go
   via the Events page to Next.js Meetup Berlin, then via the Events page to React Reading Group, and finally via the
   Events page back to Web Performance Clinic. Explain both results.

<details>
<summary>Solution</summary>

After the short detour the draft is still there: the event page was hidden with `<Activity>`, not unmounted, so the
textarea's DOM (and its value) survived. After the long detour the box is empty. Next.js keeps a limited number of
routes (the docs say three), and two more event pages plus the list were enough to push the first event page out, so
it rendered fresh. If drafts must survive longer, store them yourself, for example in `sessionStorage` keyed by event.

</details>

5. **The cost of `navigation()`.** Remove `await navigation()` from `Comments`, rebuild, and watch the Network panel
   on `/events` again. What changes, and is that change good or bad?

<details>
<summary>Solution</summary>

The requests don't change in number: there's still one per-link prefetch per card. What changes is their content:
each per-link prefetch now includes the event's comments (they're cached with `getComments`, so they qualify), and
the server runs `getComments` for every visible card on a cold cache. In return, opening an event shows the comments
immediately instead of "Loading comments…". For Gather, where comments are below the fold and most cards are never
opened, deferring is the better trade. For content at the top of the destination page, it would be the wrong one.

</details>

---
← Previous: [Server Actions and forms](08-server-actions-and-forms.md) | Next: [Error handling](10-error-handling.md) →
