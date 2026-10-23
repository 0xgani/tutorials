# Chapter 3 — Routing: pages and layouts

## What you'll learn

- How the folders in `app/` become URLs, and which files make a route public
- Pages, layouts, and how layouts nest and persist across navigations
- The root layout and its rules
- Dynamic segments (`[slug]`), and reading `params` and `searchParams`
- Why `params` and `searchParams` are promises, and the first encounter with `<Suspense>` via `loading.tsx`
- Linking between pages with `<Link>`
- `notFound()` and a custom 404 page
- Where to put components and other code: colocation, private folders, and the project layout Gather uses
- Typed routes: `PageProps`, `LayoutProps` and `next typegen`

## File-system routing

In the App Router, **folders define routes** and **special files define what a route shows**. Each folder inside
`app/` is a **route segment**, which maps to one segment of the URL path:

```text
app/                         URL
├── page.tsx                 /
├── events/
│   ├── page.tsx             /events
│   └── [slug]/
│       └── page.tsx         /events/nextjs-meetup-berlin, /events/typescript-101, ...
└── dashboard/
    └── page.tsx             /dashboard
```

A folder alone doesn't create a page. A route becomes publicly reachable only when its folder contains a `page.tsx`
(or a `route.ts`, for API endpoints, chapter 11). This means you can put other files in the same folders, such as
components, styles, tests or helpers, and they never become URLs by accident.

These are the special file names. You will meet each of them in this tutorial:

| File | Purpose | Chapter |
|---|---|---|
| `page.tsx` | The UI of a route; makes the route reachable | 3 |
| `layout.tsx` | UI shared by a segment and everything below it; persists across navigations | 3 |
| `loading.tsx` | A loading state (a Suspense fallback) for a segment | 3, 6 |
| `not-found.tsx` | UI shown when `notFound()` is called or no route matches | 3, 10 |
| `error.tsx` | An error boundary for a segment | 10 |
| `global-error.tsx` | The error boundary for the root layout | 10 |
| `forbidden.tsx`, `unauthorized.tsx` | UI for `forbidden()` and `unauthorized()` (experimental) | 10, 15 |
| `route.ts` | An HTTP endpoint (GET, POST, …) | 11 |
| `template.tsx` | Like a layout, but re-mounted on every navigation | 3 |
| `default.tsx` | Fallback for a parallel route slot | 13 |

Files can end in `.js`, `.jsx`, `.ts` or `.tsx` (except `route`, which has no JSX: `.js` or `.ts`).

## Building Gather's first pages

You'll build Gather's page structure with some temporary sample data. Chapter 6 replaces it with a real database,
so keep the data access behind a few small functions:

```ts
// lib/sample-events.ts
// Temporary data for chapters 3–5. Chapter 6 replaces it with a database.

export type EventSummary = {
  id: number
  slug: string
  title: string
  city: string
  venue: string
  startsAt: string // ISO 8601, UTC
  capacity: number
  going: number
}

export type EventDetail = EventSummary & { description: string }

const events: EventDetail[] = [
  {
    id: 1,
    slug: 'nextjs-meetup-berlin',
    title: 'Next.js Meetup Berlin',
    city: 'Berlin',
    venue: 'Factory Görlitzer Park',
    startsAt: '2026-11-05T18:00:00Z',
    capacity: 60,
    going: 41,
    description: 'Three short talks on Cache Components, Server Actions and self-hosting.',
  },
  {
    id: 2,
    slug: 'react-reading-group',
    title: 'React Reading Group',
    city: 'Berlin',
    venue: 'Café Kreuzberg',
    startsAt: '2026-11-09T19:00:00Z',
    capacity: 12,
    going: 9,
    description: 'We read one post from the React blog and discuss it over coffee.',
  },
  {
    id: 3,
    slug: 'nodejs-hack-night',
    title: 'Node.js Hack Night',
    city: 'Lisbon',
    venue: 'LX Factory, Building C',
    startsAt: '2026-11-12T18:00:00Z',
    capacity: 40,
    going: 17,
    description: 'Bring a project, or join one. Pizza at eight.',
  },
  {
    id: 4,
    slug: 'typescript-101',
    title: 'TypeScript 101',
    city: 'Nairobi',
    venue: 'iHub, Senteu Plaza',
    startsAt: '2026-11-20T17:00:00Z',
    capacity: 35,
    going: 12,
    description: 'A hands-on introduction to TypeScript for JavaScript developers.',
  },
]

export function getSampleEvents(city?: string): EventSummary[] {
  return city ? events.filter((event) => event.city === city) : events
}

export function getSampleEvent(slug: string): EventDetail | undefined {
  return events.find((event) => event.slug === slug)
}

export function getSampleCities(): string[] {
  return [...new Set(events.map((event) => event.city))].sort()
}
```

`lib/` sits at the project root, outside `app/`. It isn't a Next.js convention, just a common place for code that
isn't UI. The `@/` alias from chapter 1 makes it importable as `@/lib/sample-events` from anywhere.

### Pages

A **page** is the `default` export of a `page.tsx` file. Gather's home page shows the next few events. The list
rendering goes into reusable components:

```tsx
// components/event-card.tsx
import Link from 'next/link'
import type { EventSummary } from '@/lib/sample-events'

export function EventCard({ event }: { event: EventSummary }) {
  return (
    <article>
      <h3>
        <Link href={`/events/${event.slug}`}>{event.title}</Link>
      </h3>
      <p>
        {event.venue}, {event.city} · {event.going} going
      </p>
    </article>
  )
}

export function EventGrid({ events }: { events: EventSummary[] }) {
  if (events.length === 0) return <p>No events found.</p>
  return (
    <div>
      {events.map((event) => (
        <EventCard key={event.id} event={event} />
      ))}
    </div>
  )
}
```

```tsx
// app/page.tsx
import Link from 'next/link'
import { EventGrid } from '@/components/event-card'
import { getSampleEvents } from '@/lib/sample-events'

export default function HomePage() {
  const events = getSampleEvents().slice(0, 3)
  return (
    <main>
      <h1>Meet the people who build the web.</h1>
      <p>Gather lists tech meetups, workshops and hack nights.</p>
      <Link href="/events">Browse all events</Link>
      <h2>Coming up</h2>
      <EventGrid events={events} />
    </main>
  )
}
```

Notice that `HomePage` calls `getSampleEvents()` directly while rendering. That's fine: this is a Server Component,
it runs on the server, and it can call any server-side code. There is no API endpoint in between and no `useEffect`.
Chapter 4 explains why this works and chapter 6 does it with a real database.

Add a placeholder dashboard, so the navigation has somewhere to go:

```tsx
// app/dashboard/page.tsx
export default function DashboardPage() {
  return (
    <main>
      <h1>Your dashboard</h1>
      <p>Log in to see your events. (Coming in chapter 15.)</p>
    </main>
  )
}
```

### Layouts

A **layout** is UI that wraps a segment and every route below it. It receives the content below it as `children`.
Gather's root layout adds a header and a footer around every page:

```tsx
// components/site-header.tsx
import Link from 'next/link'

export function SiteHeader() {
  return (
    <header>
      <nav>
        <Link href="/">Gather</Link> · <Link href="/events">Events</Link> ·{' '}
        <Link href="/dashboard">Dashboard</Link>
      </nav>
    </header>
  )
}
```

```tsx
// app/layout.tsx
import type { Metadata } from 'next'
import { Geist } from 'next/font/google'
import { SiteHeader } from '@/components/site-header'
import './globals.css'

const geist = Geist({ subsets: ['latin'], variable: '--font-geist-sans' })

export const metadata: Metadata = {
  title: 'Gather',
  description: 'Tech meetups, workshops and hack nights near you.',
}

export default function RootLayout({ children }: LayoutProps<'/'>) {
  return (
    <html lang="en" className={geist.variable}>
      <body>
        <SiteHeader />
        {children}
        <footer>Gather is the example app of the Next.js tutorial.</footer>
      </body>
    </html>
  )
}
```

(Gather uses one font, Geist, so the second font from the generated layout is gone. Chapter 5 is about fonts. You can
also delete `app/page.module.css` and the SVG files in `public/` if you haven't yet.)

#### The root layout

The layout in `app/layout.tsx` is the **root layout**. Every app must have one, and it has rules of its own:

- It must render `<html>` and `<body>`. Next.js doesn't add them for you.
- Don't put `<title>`, `<meta>` or `<link rel="icon">` in a `<head>` yourself. Use the metadata API (the
  `metadata` export above, chapter 14), which handles de-duplication and streaming. Next.js renders `<head>` for you.
- It applies to every route, including the 404 page.

#### Nesting

Layouts **nest**. A `layout.tsx` in `app/events/` would wrap `/events` and `/events/[slug]`, and would itself be
wrapped by the root layout:

```text
app/layout.tsx           ┌──────────────── RootLayout ────────────────┐
app/events/layout.tsx    │  header                                    │
app/events/[slug]/       │  ┌──────────── EventsLayout ────────────┐  │
  page.tsx               │  │  sidebar with cities                  │  │
                         │  │  ┌────────── EventPage ────────────┐  │  │
                         │  │  │ event details                   │  │  │
                         │  │  └─────────────────────────────────┘  │  │
                         │  └───────────────────────────────────────┘  │
                         │  footer                                    │
                         └────────────────────────────────────────────┘
```

The rendered tree for `/events/typescript-101` is, conceptually:

```tsx
<RootLayout>
  <EventsLayout>
    <EventPage params={...} />
  </EventsLayout>
</RootLayout>
```

#### Layouts persist

When the user navigates from `/events/nextjs-meetup-berlin` to `/events/typescript-101`, only the page changes. The
layouts that both routes share are not re-rendered and not re-mounted: their DOM and their Client Components' state
are kept (an open dropdown stays open, a video keeps playing, a scroll position in a sidebar stays). The server
doesn't even render them again; it sends only the new page.

This has a consequence that surprises people: **a layout can't read the current pathname or the query string**. A
layout that showed `searchParams` would show stale values after navigating, because it isn't rendered again. If
something in a layout must react to the URL (for example, highlighting the active link in the header), it goes in a
Client Component that reads the URL with `usePathname()` or `useSearchParams()`, which do update on navigation.
Gather's header gets that in chapter 9.

#### Templates

A `template.tsx` file works like a layout but gets a new instance on every navigation: its state resets and its
effects run again. You rarely need one. Use it for things like an enter animation that should replay on every page,
or a per-page effect such as logging a page view.

## Dynamic routes

The event pages can't each have a folder; their slugs come from data. A folder name in square brackets is a
**dynamic segment** that matches any value:

```text
app/events/[slug]/page.tsx   matches /events/anything, with params = { slug: 'anything' }
```

```tsx
// app/events/[slug]/page.tsx
import Link from 'next/link'
import { notFound } from 'next/navigation'
import { getSampleEvent } from '@/lib/sample-events'

export default async function EventPage({ params }: PageProps<'/events/[slug]'>) {
  const { slug } = await params
  const event = getSampleEvent(slug)
  if (!event) notFound()

  return (
    <main>
      <Link href="/events">← All events</Link>
      <h1>{event.title}</h1>
      <p>
        {event.venue}, {event.city}
      </p>
      <p>{event.description}</p>
    </main>
  )
}
```

Three things to notice.

**`params` is a promise.** Pages and layouts receive `params` as a `Promise<{ slug: string }>`, so the component is
`async` and awaits it. Since Next.js 15, request-specific inputs are asynchronous. That lets Next.js start rendering
everything that *doesn't* depend on the URL (the layouts, the static parts of the page) before the parts that do. The
next section shows what that buys you.

**`PageProps<'/events/[slug]'>` types the props.** You don't declare `params` by hand. Next.js generates a global
`PageProps` type from your folder structure, so `params` is typed as `Promise<{ slug: string }>` here, and TypeScript
reports an error if you pass a route string that doesn't exist. More on generated types at the end of the chapter.

**`notFound()` stops rendering** and shows the closest `not-found.tsx`. It works by throwing a special error, so code
after it doesn't run, and TypeScript knows that: after `if (!event) notFound()`, `event` is no longer `undefined`.

Add the 404 page:

```tsx
// app/not-found.tsx
import Link from 'next/link'

export default function NotFound() {
  return (
    <main>
      <h1>Page not found</h1>
      <p>That event may have been deleted, or the link is mistyped.</p>
      <Link href="/events">Browse events</Link>
    </main>
  )
}
```

`app/not-found.tsx` is used both when you call `notFound()` and when no route matches the URL at all (try
<http://localhost:3000/nope>).

### More dynamic segment forms

| Folder | Matches | `params` |
|---|---|---|
| `[slug]` | `/events/a` | `{ slug: 'a' }` |
| `[...path]` (catch-all) | `/docs/a`, `/docs/a/b/c` (not `/docs`) | `{ path: ['a', 'b', 'c'] }` |
| `[[...path]]` (optional catch-all) | `/docs`, `/docs/a`, `/docs/a/b` | `{ path: undefined }` or `{ path: ['a', 'b'] }` |

Several dynamic segments combine: `app/cities/[city]/events/[slug]/page.tsx` gets `{ city, slug }`. Chapter 13 covers
catch-all segments and `generateStaticParams`, which tells Next.js which slugs to prerender at build time.

## The query string: `searchParams`

Pages (not layouts) also receive `searchParams`, the parsed query string. Use it on the events list to filter by
city:

```tsx
// app/events/page.tsx
import Link from 'next/link'
import { EventGrid } from '@/components/event-card'
import { getSampleCities, getSampleEvents } from '@/lib/sample-events'

export default async function EventsPage({ searchParams }: PageProps<'/events'>) {
  const { city } = await searchParams
  const selected = typeof city === 'string' ? city : undefined
  const events = getSampleEvents(selected)

  return (
    <main>
      <h1>All events</h1>
      <p>
        <Link href="/events">All cities</Link>
        {getSampleCities().map((name) => (
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

`searchParams` resolves to `Record<string, string | string[] | undefined>`. A key is missing when the parameter isn't
in the URL, a string when it appears once, and an array when it appears more than once (`?city=Berlin&city=Lisbon`).
Always check the type before using it; the `typeof city === 'string'` check above ignores the array case. Never trust
query values: they are user input.

Open <http://localhost:3000/events?city=Lisbon>. The page works. But look at the terminal running `next dev`:

```text
Error: Route "/events": Next.js encountered runtime data during prerendering.

`cookies()`, `headers()`, `params`, or `searchParams` accessed outside of `<Suspense>` prevents the route from being
prerendered, blocking the page load and leading to a slower user experience.

Ways to fix this:
  - [stream] Provide a placeholder with `<Suspense fallback={...}>` around the data access
  - [block] Set `export const instant = false` to allow a blocking route

Learn more: https://nextjs.org/docs/messages/blocking-prerender-runtime
    at EventsPage (app/events/page.tsx:5:20)
> 5 |   const { city } = await searchParams
    |                    ^
```

The dev tools indicator in the corner of the page shows the same issue, and `npm run build` would fail with it. What
is Next.js complaining about?

### Runtime data and the static shell

With Cache Components, Next.js tries to **prerender every route at build time**, to produce a static HTML "shell"
that can be sent instantly. `searchParams` only exists when a real request arrives. So when the page awaits
`searchParams` at its very top, *nothing* of the page can be prerendered, and every visitor would wait for the whole
page to render on the server before seeing anything. Next.js treats that as a mistake unless you say otherwise.

The fix the error recommends is to give the part that waits for request data a **placeholder**: a Suspense boundary.
Everything outside the boundary is prerendered into the static shell; the part inside renders at request time and
streams in. The simplest way to add a boundary around a whole page is a `loading.tsx` file:

```tsx
// app/events/loading.tsx
export default function Loading() {
  return <p>Loading…</p>
}
```

`loading.tsx` wraps the page of its segment (and of the segments below it, so `/events/[slug]` too) in
`<Suspense fallback={<Loading />}>`. The layouts above it are outside the boundary, so they go into the static shell.
Reload the page: the error is gone. Build the app to see the result:

```bash
npm run build
```

```text
Route (app)
┌ ○ /
├ ○ /_not-found
├ ○ /dashboard
├ ◐ /events
└   /events/[slug]
  └ ◐ /events/[slug]


○  (Static)             prerendered as static content
◐  (Partial Prerender)  prerendered as static HTML with dynamic server-streamed content
```

`/` and `/dashboard` are fully static (`○`). `/events` and `/events/[slug]` are **partially prerendered** (`◐`): the
root layout with its header and footer, plus the "Loading…" fallback, are prerendered; the page content streams in
for each request. When you open `/events?city=Lisbon` on the production server, the browser receives the header
immediately, shows "Loading…" for a moment (here, too short to see), then the list.

A whole-page `loading.tsx` is the bluntest possible boundary. In chapter 7 you'll move the boundary down, so that the
heading, the filter links and everything else that doesn't depend on the query string are in the static shell too,
and only the list waits. For now, the rule to remember:

> With Cache Components, anything that reads request data (`params`, `searchParams`, `cookies()`, `headers()`) or
> uncached data must be inside a `<Suspense>` boundary, or the route must explicitly opt out of prerendering.

(The other option in the error, `export const instant = false`, marks a route as allowed to block; chapter 9 explains
it. `params` counts as request data here because Next.js doesn't know the possible slugs at build time. Chapter 7
tells it with `generateStaticParams`.)

### What a missing event looks like now

Open <http://localhost:3000/events/nope>. You see the "Page not found" page, as expected. But check the status code
on the production server:

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:3000/events/nope
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:3000/nope
```

```text
200
404
```

`/nope` matches no route, so it is a plain 404. `/events/nope` matches `/events/[slug]`, and by the time the page
discovers that the event doesn't exist, the static shell (header, layout) has already been sent with a `200` status
and the 404 UI is streamed into it. HTTP doesn't allow changing the status code after the first bytes are sent. To
keep search engines from indexing such pages, Next.js adds `<meta name="robots" content="noindex">` to the streamed
response. Chapter 10 discusses this trade-off and when it matters.

## Linking and navigating

`<Link>` from `next/link` renders an `<a>` element, so it works without JavaScript and supports opening in a new tab.
Once the app is interactive, clicking it performs a **client-side navigation**: the browser doesn't reload; Next.js
fetches the new page's content and React swaps it in, keeping the shared layouts mounted. Links that are visible on
screen are also **prefetched** in the background, so that most navigations feel instant. Chapter 9 covers navigation
and prefetching in depth.

```tsx
<Link href="/events">All events</Link>
<Link href={`/events/${event.slug}`}>{event.title}</Link>
<Link href={{ pathname: '/events', query: { city: 'Lisbon' } }}>Lisbon</Link>
```

The `href` can be a string or a URL object; the object form encodes query values for you. Use `<Link>` for every
link inside your app. A plain `<a href="/events">` works too, but causes a full page reload. For external links
(another domain), a plain `<a>` is right.

Try it: open the browser's developer tools, go to the Network tab, and click between Events and an event. There is no
document request, only small fetch requests for the new content.

## Organizing your project

Next.js only cares about the special files in `app/`. Everything else is up to you. Some useful facts and
conventions:

- **Colocation is safe.** A file in `app/events/` that isn't a special file (say `app/events/filters.tsx`) is never a
  route. Keeping a component next to the only route that uses it is fine.
- **Private folders.** A folder whose name starts with `_` (like `app/_components/`) is excluded from routing
  entirely, including any `page.tsx` inside it. It signals "not a route" to readers.
- **Route groups.** A folder name in parentheses, like `app/(auth)/`, organizes routes *without* adding a URL
  segment: `app/(auth)/login/page.tsx` is `/login`. Groups let routes share a layout. Gather uses one for the login
  and signup pages in chapter 15, and chapter 13 explains them in full.
- **`src/`.** You can move `app/` (and `lib/`, `components/`) into a `src/` folder. Next.js supports both layouts;
  config files stay at the root.

Gather uses a common, simple layout:

```text
app/           routes only: pages, layouts, special files, plus actions/ for Server Actions
components/    React components used by several routes
lib/           non-UI code: data access, auth, validation, formatting
public/        static files
```

## Typed routes

`PageProps` and `LayoutProps` come from types that Next.js generates into `.next/types/` from your folder
structure. Look at the generated file:

```bash
sed -n 4,20p .next/types/routes.d.ts
```

```text
type AppRoutes = "/" | "/dashboard" | "/events" | "/events/[slug]"
type PageRoutes = never
type LayoutRoutes = "/"
type RedirectRoutes = never
type RewriteRoutes = never
type Routes = AppRoutes | PageRoutes | LayoutRoutes | RedirectRoutes | RewriteRoutes


interface ParamMap {
  "/": {}
  "/dashboard": {}
  "/events": {}
  "/events/[slug]": { "slug": string; }
}
```

and further down, the global types themselves:

```ts
interface PageProps<AppRoute extends AppRoutes> {
  params: Promise<ParamMap[AppRoute]>
  searchParams: Promise<Record<string, string | string[] | undefined>>
}

type LayoutProps<LayoutRoute extends LayoutRoutes> = {
  params: Promise<ParamMap[LayoutRoute]>
  children: React.ReactNode
} & {
  [K in LayoutSlotMap[LayoutRoute]]: React.ReactNode
}
```

The types are regenerated whenever `next dev` or `next build` runs. In CI, or after switching branches, you can
generate them without a build:

```bash
npx next typegen
```

A good `typecheck` script for `package.json` is therefore:

```json
"typecheck": "next typegen && tsc --noEmit"
```

If your editor ever shows a confusing error inside `.next/types` (for example after a dev server was killed in the
middle of writing them), stop the dev server, delete the `.next` folder and start again.

> **Typed links.** With `typedRoutes: true` in `next.config.ts`, Next.js also checks the `href` of every `<Link>`
> against your routes at compile time, so `<Link href="/evnets">` is a type error. Gather doesn't enable it to keep
> the examples short, but it's worth turning on in your own projects.

## Key takeaways

- Folders in `app/` are URL segments; a `page.tsx` makes a segment reachable. Other files can be colocated safely.
- Layouts wrap a segment and all routes below it, nest inside each other, and persist across navigations: they are
  not re-rendered, so they can't read the pathname or query string. The root layout must render `<html>` and
  `<body>`.
- `[slug]` creates a dynamic segment. Pages receive `params` and `searchParams`, layouts receive `params`; all are
  promises you `await`.
- `notFound()` renders the nearest `not-found.tsx`. When the 404 is decided inside a streamed part of the page, the
  status is 200 with a `noindex` robots tag.
- With Cache Components, code that reads request data must be inside `<Suspense>` (a `loading.tsx` is the simplest
  way) so the rest of the route can be prerendered into a static shell. `next build` fails otherwise, and `next dev`
  reports it.
- Use `<Link>` for internal links: client-side navigation and prefetching come for free.
- `PageProps<'/route'>` and `LayoutProps<'/route'>` are generated global types; `next typegen` creates them without
  a build.

## Exercises

1. **An about page with a nested layout.** Add `/about` and `/about/team` pages, and an `app/about/layout.tsx` that
   renders a small sub-navigation ("About · Team") above the page. Check that the sub-navigation appears only on these
   two pages, and that the root layout's header is still above it.

<details>
<summary>Solution</summary>

```tsx
// app/about/layout.tsx
import Link from 'next/link'

export default function AboutLayout({ children }: LayoutProps<'/about'>) {
  return (
    <div>
      <nav>
        <Link href="/about">About</Link> · <Link href="/about/team">Team</Link>
      </nav>
      {children}
    </div>
  )
}
```

```tsx
// app/about/page.tsx
export default function AboutPage() {
  return <main><h1>About Gather</h1></main>
}
```

```tsx
// app/about/team/page.tsx
export default function TeamPage() {
  return <main><h1>The team</h1></main>
}
```

`LayoutProps<'/about'>` is available as soon as the dev server has regenerated the types (or after
`npx next typegen`). A nested layout doesn't render `<html>` or `<body>`; only the root layout does.

</details>

2. **Prove that layouts persist.** Add a Client Component to `app/about/layout.tsx` that counts clicks on a button.
   Click it a few times, then navigate between About and Team. Does the count survive? What happens if you rename
   `layout.tsx` to `template.tsx`?

<details>
<summary>Solution</summary>

```tsx
// app/about/counter.tsx
'use client'
import { useState } from 'react'

export function Counter() {
  const [count, setCount] = useState(0)
  return <button onClick={() => setCount(count + 1)}>Clicked {count} times</button>
}
```

Render `<Counter />` in the layout. With `layout.tsx`, the count survives navigation between `/about` and
`/about/team`: the layout and its state stay mounted, and only `children` changes. With `template.tsx`, the count
resets to 0 on every navigation, because a template gets a new instance for each route. (Navigating to `/events` and
back resets it in both cases: the layout is unmounted when you leave its segment.)

</details>

3. **Handle repeated query parameters.** `/events?city=Berlin&city=Lisbon` currently shows all events, because
   `city` is an array and the page ignores it. Change the page so it shows the events of every listed city.

<details>
<summary>Solution</summary>

```tsx
const { city } = await searchParams
const cities = city === undefined ? [] : Array.isArray(city) ? city : [city]
const events = cities.length === 0
  ? getSampleEvents()
  : getSampleEvents().filter((event) => cities.includes(event.city))
```

Normalizing `string | string[] | undefined` into an array first keeps the rest of the code simple. Gather's final
version takes only the first value (`first()` in chapter 7), which is the other reasonable choice.

</details>

4. **A catch-all route.** Create `app/cities/[...path]/page.tsx` that shows the path segments joined with " / " (for
   `/cities/germany/berlin` it shows "germany / berlin"). Then make `/cities` itself work with the same file. Which
   change did you make, and what is `params` for `/cities`?

<details>
<summary>Solution</summary>

```tsx
// app/cities/[[...path]]/page.tsx
export default async function CitiesPage({ params }: PageProps<'/cities/[[...path]]'>) {
  const { path } = await params
  return (
    <main>
      <h1>{path ? path.join(' / ') : 'All cities'}</h1>
    </main>
  )
}
```

`[...path]` (catch-all) requires at least one segment, so `/cities` is a 404. Renaming the folder to `[[...path]]`
(optional catch-all) matches `/cities` too, with `params` resolving to `{}` (so `path` is `undefined`). Like
`/events`, this route reads `params`, so it needs a Suspense boundary: add an `app/cities/loading.tsx` or the dev
server reports the same error as before.

</details>

5. **Status codes.** Explain why `/events/nope` returns status 200 while `/nope` returns 404. Then try removing
   `app/events/loading.tsx`: does `/events/nope` return a 404 now? Where would the "does this event exist?" check have
   to run to produce a real 404?

<details>
<summary>Solution</summary>

`/nope` doesn't match any route, so Next.js knows before rendering anything that the answer is a 404. `/events/nope`
matches `/events/[slug]`; the static shell (the root layout, plus the loading fallback) is sent first with status
200, and the page only discovers inside the streamed part that the event doesn't exist. The status can't change after
the response has started, so Next.js streams the not-found UI and adds a `noindex` meta tag.

Removing `loading.tsx` doesn't change the status. The dev server now reports the blocking-route error again, and even
if you allow the route to block (chapter 9), the prerendered shell of the route (at least the root layout) is still
sent before the page runs, so the response is still a 200 with `noindex`. With Cache Components, a slug that isn't
known at build time is always resolved inside a streamed response.

A real 404 needs a check that runs *before* rendering starts: in Proxy (chapter 12), which can look up the slug and
return a 404 response or rewrite to a not-found route. That costs a lookup on every request, so it's only worth it
when the exact status code matters (some analytics or compliance requirements). For search engines the `noindex`
tag is enough. Chapter 10 comes back to this trade-off.

</details>

---
← Previous: [React and TypeScript essentials](02-react-and-typescript-essentials.md) | Next: [Server and Client Components](04-server-and-client-components.md) →
