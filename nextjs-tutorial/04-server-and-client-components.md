# Chapter 4 — Server and Client Components

## What you'll learn

- What Server Components and Client Components are, and where each one runs
- What `'use client'` really marks: a boundary in the module graph, not a single component
- What can be passed from a Server Component to a Client Component, and what can't
- Composition: putting Server Components inside Client Components through `children`
- How to keep server-only code and secrets out of the browser with `server-only` and the `NEXT_PUBLIC_` rule
- Context providers and third-party components in a server-first app
- Hydration, and how to avoid hydration mismatches
- Gather's first interactive component: a share button

## Two kinds of components

Every component you have written so far is a **Server Component**. In the App Router, components are Server
Components unless you say otherwise. A Server Component:

- runs on the server only: during the build (when the page is prerendered) or when a request arrives;
- can be `async` and `await` data directly: a database query, a file read, a call to another service;
- can use server-only things: environment variables with secrets, `node:fs`, `node:sqlite`, private APIs;
- sends **no JavaScript** to the browser. Its output reaches the browser as HTML and as part of the RSC payload, but
  its code doesn't;
- **can't** use state, effects, event handlers, or browser APIs. It runs once to produce output; there is nothing to
  click on while it runs.

A **Client Component** is the kind of React component you know from single-page apps:

- it runs in the browser, where it can use `useState`, `useEffect`, event handlers, `window`, `localStorage`, the
  clipboard and every other browser API;
- it **also** runs on the server once, to produce the initial HTML (this is called prerendering or server-side
  rendering of Client Components), so it must not touch browser APIs during render, only in effects and event
  handlers;
- its code is sent to the browser, where React **hydrates** it: attaches event handlers to the HTML and makes it
  interactive.

| Need | Server Component | Client Component |
|---|---|---|
| Fetch data from a database or API | ✓ (directly, with `await`) | Via props from a server component, or a Route Handler / Server Action |
| Use secrets (API keys, database credentials) | ✓ | ✗ never |
| Keep large dependencies out of the browser bundle | ✓ | ✗ |
| State and lifecycle (`useState`, `useEffect`, `useReducer`) | ✗ | ✓ |
| Event handlers (`onClick`, `onChange`) | ✗ | ✓ |
| Browser APIs (`window`, `localStorage`, `navigator`) | ✗ | ✓ (in effects and handlers) |
| Custom hooks that use state or effects | ✗ | ✓ |
| React context | ✗ (can't read or provide it) | ✓ |

The guiding rule: **keep components on the server by default, and make the smallest possible piece interactive.**
A page of event details with one share button should be a Server Component that renders one small Client Component
for the button, not a Client Component page.

### Why have both?

Server Components exist to fix two problems of classic single-page apps:

- **Waterfalls and loading spinners.** A client-rendered page loads JavaScript, renders, starts fetching data, waits,
  renders again, discovers it needs more data, waits again. A Server Component fetches its data next to the
  database, before anything is sent, so the HTML arrives with the content in it.
- **Bundle size.** In a single-page app every component, every formatting library and every Markdown parser is
  downloaded by every visitor. With Server Components, code that only produces output (most of a typical page) runs
  on the server and costs the browser nothing.

Client Components still exist because interactivity needs code in the browser. The two kinds work together in one
tree, and Next.js handles the plumbing between them.

## `'use client'`: marking the boundary

You make a component a Client Component by putting the `'use client'` directive at the very top of its file, before
any imports. Gather's first interactive feature is a share button on the event page. It uses the browser's share
sheet where available (mostly phones) and the clipboard everywhere else:

```tsx
// components/share-button.tsx
'use client'

import { useState } from 'react'

// A Client Component: it needs the browser's share sheet or clipboard, a
// click handler and a bit of state. The page around it stays on the server.
export function ShareButton({ title, path }: { title: string; path: string }) {
  const [copied, setCopied] = useState(false)

  async function share() {
    const url = new URL(path, window.location.origin).toString()
    if (navigator.share) {
      try {
        await navigator.share({ title, url })
      } catch {
        // The user closed the share sheet; nothing to do.
      }
      return
    }
    await navigator.clipboard.writeText(url)
    setCopied(true)
    setTimeout(() => setCopied(false), 2000)
  }

  return (
    <button type="button" className="button secondary" onClick={share}>
      {copied ? 'Link copied' : 'Share'}
    </button>
  )
}
```

Use it on the event page, which stays a Server Component:

```tsx
// app/events/[slug]/page.tsx
import Link from 'next/link'
import { notFound } from 'next/navigation'
import { getSampleEvent } from '@/lib/sample-events'
import { ShareButton } from '@/components/share-button'

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
      <ShareButton title={event.title} path={`/events/${event.slug}`} />
      <p>{event.description}</p>
    </main>
  )
}
```

Notice that `window` and `navigator` are only used inside `share`, the click handler. During the server render of the
button they aren't touched, so the server can produce the button's HTML without a browser.

### It marks a module boundary, not a component

`'use client'` doesn't mark one component; it marks a **module** and everything it imports as client code. Think of
your app as a graph of imports starting at the page:

```text
app/events/[slug]/page.tsx        (server)
├── next/link                     (Link is a Client Component from Next.js)
├── lib/sample-events.ts          (server)
└── components/share-button.tsx   'use client'  ← boundary
    └── react                     (client: included in the browser bundle)
        everything share-button imports is also client code
```

- Files imported by a `'use client'` file become part of the client bundle too, even without the directive.
  You only need `'use client'` at the entry point of the client part.
- A Server Component can import and render a Client Component (the page renders `ShareButton`).
- A Client Component **can't import** a Server Component. If it imports a file without a directive, that file simply
  becomes client code, and any server-only API in it fails.

So you don't add `'use client'` to every interactive file, only to the files that Server Components import. And you
never add it "just in case": every file under a `'use client'` boundary ends up in the browser bundle.

### What happens if you forget

Try using state in a Server Component. Change the dashboard placeholder to:

```tsx
// app/dashboard/page.tsx — don't keep this
import { useState } from 'react'

export default function DashboardPage() {
  const [tab, setTab] = useState('upcoming')
  return <main><h1>Your dashboard</h1><button onClick={() => setTab('past')}>{tab}</button></main>
}
```

```text
./app/dashboard/page.tsx:1:10
Error: You're importing a module that depends on `useState` into a React Server Component module. This API is only
available in Client Components. To fix, mark the file (or its parent) with the `"use client"` directive.
```

The error appears in the dev overlay and fails the build. Restore the file.

## Passing data across the boundary

Props passed from a Server Component to a Client Component travel over the network: the server serializes them into
the RSC payload and the browser deserializes them. So they must be **serializable by React**:

| Can be passed | Can't be passed |
|---|---|
| Strings, numbers, booleans, `null`, `undefined`, `bigint` | Functions (except Server Functions, chapter 8) |
| Plain objects and arrays of serializable values | Class instances (your own classes, objects with a custom prototype) |
| `Date`, `Map`, `Set`, typed arrays, `ArrayBuffer` | Symbols that aren't registered with `Symbol.for` |
| Promises of serializable values (read them with `use`, chapter 6) | DOM nodes, streams, sockets |
| JSX (including Server Components rendered as elements) | |
| Server Functions marked with `'use server'` | |

Passing a function is the most common mistake:

```tsx
// ✗ a Server Component passing an event handler
<ShareButton title={event.title} path={path} onShared={() => console.log('shared')} />
```

```text
Error: Event handlers cannot be passed to Client Component props.
  {title: "x", path: ..., onShared: function onShared}
                                    ^^^^^^^^^^^^^^^^^
If you need interactivity, consider converting part of this to a Client Component.
```

The function exists only on the server; there is no way to send it to the browser. Either move the logic into the
Client Component, or (for code that must run on the server) pass a Server Action, which the browser calls over the
network (chapter 8).

Two practical consequences for Gather:

- **Pass only what the Client Component needs.** `ShareButton` receives `title` and `path`, not the whole event.
  Everything you pass ends up in the page's HTML and RSC payload, readable by anyone who views the source. Never pass
  a whole user record "for convenience"; it may contain a password hash or an email address.
- **Pass plain data.** Rows from some database drivers are objects with special prototypes. Chapter 6 shows that
  `node:sqlite` returns rows with a `null` prototype, which is why Gather copies them into plain objects before they
  reach a Client Component.

### What the browser receives

You can see the boundary in the RSC payload, the data format the server uses to describe the rendered tree. The
client router requests it with an `rsc: 1` header. Build and start the app, then:

```bash
curl -s -L -H "rsc: 1" http://localhost:3000/events/typescript-101 | grep -a "ShareButton\|TypeScript 101"
```

```text
20:I[41164,["$3","$4"],"ShareButton"]
f:["$","main",null,{"children":[["$","$L5",null,{"href":"/events","children":"← All events"}],["$","h1",null,{"children":"TypeScript 101"}],["$","p",null,{"children":["iHub, Senteu Plaza",", ","Nairobi"]}],["$","$L20",null,{"title":"TypeScript 101","path":"/events/typescript-101"}],["$","p",null,{"children":"A hands-on introduction to TypeScript for JavaScript developers."}]]}]
```

Two lines tell the whole story:

- `20:I[41164,[...],"ShareButton"]` is a **client reference**: "row 20 is the `ShareButton` export of module 41164,
  which is in these JavaScript chunks". The browser downloads those chunks to run the button.
- The `main` element is fully rendered output: plain elements (`h1`, `p`) with their text, already computed on the
  server. Where the button goes, there is only `"$L20"` (a lazy reference to row 20) with its props, `title` and
  `path`. The code of `EventPage` and `getSampleEvent`, and the other events in the sample data, are nowhere in the
  response.

(The numbers will differ on your machine. Chapter 21 reads this format in more detail.)

## Composition: Server Components inside Client Components

A Client Component can't *import* a Server Component, but it can *render* one that it receives as a prop. The
standard way is `children`:

```tsx
// components/collapsible.tsx
'use client'

import { useState } from 'react'

export function Collapsible({ title, children }: { title: string; children: React.ReactNode }) {
  const [open, setOpen] = useState(false)
  return (
    <section>
      <button onClick={() => setOpen(!open)} aria-expanded={open}>
        {title}
      </button>
      {open && children}
    </section>
  )
}
```

```tsx
// a Server Component, for example a page
<Collapsible title="Show attendees">
  <AttendeeList eventId={event.id} />  {/* a Server Component that queries the database */}
</Collapsible>
```

This works because the *page* (a Server Component) renders both. `AttendeeList` is rendered on the server, and its
output is passed to `Collapsible` as an already-rendered piece of UI. `Collapsible` only decides whether to show it;
it never runs `AttendeeList`'s code and doesn't need to know it exists.

```text
Page (server)
├── renders <AttendeeList> on the server → output (HTML/RSC)
└── renders <Collapsible> with that output as children
        Collapsible (client) shows or hides the children
```

The same pattern works with any prop that holds JSX, not only `children`: `<Tabs upcoming={<UpcomingList />}
past={<PastList />} />`. In chapter 13, Gather's attendee modal uses exactly this: a Client Component `Modal`
(which needs browser APIs to open a `<dialog>`) wraps the server-rendered attendee list.

### Pushing the boundary down

When a component is "mostly static with one interactive bit", split it so the interactive bit is its own small
Client Component. Compare:

```tsx
// ✗ the whole header becomes client code just for one button
'use client'
export function SiteHeader() { /* logo, nav links, user menu, and a theme toggle with useState */ }
```

```tsx
// ✓ the header stays on the server; only the toggle ships JavaScript
export function SiteHeader() {
  return (
    <header>
      <Logo />
      <Nav />
      <ThemeToggle /> {/* 'use client' */}
    </header>
  )
}
```

## Keeping server code on the server

Because modules can be imported from both sides, it's possible to import server code into the client by mistake. Two
safeguards.

### Environment variables

Only environment variables whose names start with `NEXT_PUBLIC_` are included in client bundles. Every other
`process.env.SOMETHING` in client code is replaced with an empty value at build time. So a secret can't leak through
`process.env`, but code that reads it on the client silently gets nothing, which can be confusing. Chapter 17 covers
environment variables in full.

### The `server-only` package

Mark modules that must never reach the browser by importing `server-only` at the top:

```bash
npm install server-only
```

```ts
// lib/secrets.ts (an example; Gather's database module does the same in chapter 6)
import 'server-only'

export const apiKey = process.env.PAYMENTS_API_KEY
```

If a Client Component (directly or through other imports) ever imports this module, the build fails and the error
shows the import chain that pulled it in:

```text
Error: 'server-only' cannot be imported from a Client Component module

It should only be used from a Server Component.

Import traces:
  Client Component Browser:
    ./lib/secret.ts [Client Component Browser]
    ./components/leak.tsx [Client Component Browser]
    ./components/leak.tsx [Server Component]
    ./app/dashboard/page.tsx [Server Component]
```

Next.js understands `server-only` without the package being installed, but installing it keeps linters that check
dependencies happy. Gather imports it in every module under `lib/` that touches the database or the session. The
opposite package, `client-only`, marks modules that must only run in the browser.

## Context providers

React context only works in Client Components, but the root layout is a Server Component. To provide context to the
whole app, create a Client Component provider and render it in the layout, passing the rest of the app as `children`:

```tsx
// app/providers.tsx
'use client'

import { createContext, use, useState } from 'react'

type Theme = 'light' | 'dark'
const ThemeContext = createContext<{ theme: Theme; setTheme: (t: Theme) => void } | null>(null)

export function Providers({ children }: { children: React.ReactNode }) {
  const [theme, setTheme] = useState<Theme>('light')
  return <ThemeContext value={{ theme, setTheme }}>{children}</ThemeContext>
}

export function useTheme() {
  const value = use(ThemeContext)
  if (!value) throw new Error('useTheme must be used inside <Providers>')
  return value
}
```

```tsx
// app/layout.tsx
<body>
  <Providers>{children}</Providers>
</body>
```

Thanks to composition, wrapping `children` in a Client Component doesn't turn the pages into Client Components: they
are still rendered on the server and passed in as `children`. Two tips:

- Render providers as deep as possible. A provider that only the dashboard needs belongs in the dashboard's layout,
  not the root layout.
- Don't use context for data that comes from the server and doesn't change in the browser. Pass it as props, or
  fetch it where it's needed in Server Components (chapter 6 shows why fetching the same data twice is cheap).

Gather doesn't need context at all, which is typical for a server-first app.

## Third-party components

Many npm packages contain components that use state or effects but don't have a `'use client'` directive (they were
written before Server Components existed). Using one directly in a Server Component fails with the same "only works
in Client Components" error. The fix is a one-line wrapper:

```tsx
// components/carousel.tsx
'use client'

export { Carousel } from 'some-carousel-library'
```

Now Server Components import `Carousel` from your wrapper, and the boundary is in the right place. Modern libraries
mark their client entry points with `'use client'` themselves, so the wrapper is only needed for older ones.

## Hydration and hydration mismatches

When the browser loads a page, it first shows the server-rendered HTML. Then React runs the Client Components again in
the browser and **hydrates**: it walks the existing DOM and attaches event handlers instead of creating new elements.
For that to work, the first browser render of every Client Component must produce exactly the same output as the
server render.

If it doesn't, React reports a **hydration mismatch** in the console (and in the dev overlay), and re-renders that
part of the tree from scratch in the browser, which is slower and can make the page flicker. Typical causes:

| Cause | Example | Fix |
|---|---|---|
| Time | `new Date().toLocaleTimeString()` during render | Render the time on the server only, or read it on the client after hydration (below) |
| Time zones and locales | `date.toLocaleDateString()` on a server in UTC and a browser in Lisbon | Format with an explicit `timeZone` (Gather formats all dates in UTC) |
| Randomness | `Math.random()` or `crypto.randomUUID()` during render | Generate on the server and pass as a prop; use `useId` for element IDs |
| Browser-only values | `typeof window !== 'undefined' ? A : B` during render | `useSyncExternalStore` with a server value (below), or read it in an event handler |
| Invalid HTML | A `<div>` inside a `<p>`, or nested `<a>` | Fix the markup; the browser "repairs" it differently than React expects |
| Browser extensions | Extensions that inject attributes into `<body>` | Not your bug; React ignores some of these |

When a Client Component really needs a browser-only value, give React a separate value for the server with
`useSyncExternalStore` (chapter 2). React uses the server value for the server render *and* for hydration, so the two
always match, and then immediately re-renders with the browser value:

```tsx
'use client'

import { useSyncExternalStore } from 'react'

const noSubscription = () => () => {} // the value never changes while the page is open

export function LocalTime({ iso }: { iso: string }) {
  const local = useSyncExternalStore(
    noSubscription,
    () => new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }),
    () => null, // on the server and during hydration
  )
  return <time dateTime={iso}>{local ?? `${iso.slice(0, 16).replace('T', ' ')} UTC`}</time>
}
```

For `<LocalTime iso="2026-11-05T18:00:00.000Z" />`, the server's HTML contains `2026-11-05 18:00 UTC`; a visitor in
Nairobi sees it change to `5 Nov 2026, 21:00` as soon as the page hydrates, with no hydration error.

Gather avoids the problem by formatting every date in UTC with an explicit time zone, so the server and every browser
produce the same text. You'll see that helper (`lib/format.ts`) in chapter 6.

## Common mistakes

- **`'use client'` at the top of every page.** It works, but throws away the benefits: the page's code and all its
  imports ship to the browser, and data has to be loaded some other way. Keep pages and layouts on the server; extract
  the interactive leaf.
- **Fetching data in a Client Component with `useEffect`** when the parent could fetch it on the server and pass it
  as a prop (or as a promise; chapter 6).
- **Importing a server module into a Client Component "for one helper function".** Move the helper into a module
  with no server dependencies (Gather's `lib/format.ts` is shared by both sides), or mark the server module with
  `server-only` so the mistake fails loudly.
- **Passing big objects to Client Components.** Everything passed is serialized into the page. Pass IDs and the few
  fields the component displays.
- **Assuming a Client Component runs only in the browser.** It also runs on the server for the initial HTML. Browser
  APIs belong in effects and event handlers.

## Key takeaways

- Components are Server Components by default: they run only on the server, can be `async`, can use secrets and
  server APIs, and send no JavaScript to the browser.
- `'use client'` marks a module (and everything it imports) as client code. Client Components run on the server for
  the initial HTML and in the browser, where they are hydrated and can use state, effects, events and browser APIs.
- Server Components can render Client Components. Client Components can't import Server Components, but can render
  them when they receive them as `children` or other JSX props.
- Props crossing the boundary must be serializable: no functions (except Server Actions), no class instances. Pass
  only what the component needs; everything is visible in the page source.
- Use `import 'server-only'` in modules that must never reach the browser. Only `NEXT_PUBLIC_` environment variables
  are available in client code.
- Context providers are Client Components that wrap `children`; render them as deep in the tree as you can.
- Hydration requires identical server and browser output. Avoid time, randomness, locale differences and browser
  checks during render.

## Exercises

1. **Spot the boundary.** For each component, decide whether it must be a Client Component, should be a Server
   Component, or could be either: (a) a list of comments loaded from the database; (b) a "Show more" toggle; (c) a
   footer with copyright text; (d) a map widget from a library that uses `useEffect`; (e) a form field that shows the
   number of characters left as you type.

<details>
<summary>Solution</summary>

(a) Server Component: it loads data and has no interactivity. (b) Client Component: it needs state and a click
handler. (c) Server Component: it's static; there's no reason to ship its code. (d) Client Component: it uses effects
(wrap it in your own `'use client'` file if the library lacks the directive). (e) Client Component: it reacts to every
keystroke with state. Only (b), (d) and (e) need `'use client'`, and each of them can be a small leaf rendered by a
Server Component parent.

</details>

2. **A collapsible description.** Add a Client Component `ToggleDetails` to the event page that hides the event
   description behind a "Show description" button. The description text must be rendered by the Server Component page,
   not passed as a string prop. Which pattern do you use?

<details>
<summary>Solution</summary>

Composition with `children`:

```tsx
// components/toggle-details.tsx
'use client'

import { useState } from 'react'

export function ToggleDetails({ children }: { children: React.ReactNode }) {
  const [open, setOpen] = useState(false)
  return (
    <>
      <button type="button" onClick={() => setOpen(!open)} aria-expanded={open}>
        {open ? 'Hide description' : 'Show description'}
      </button>
      {open && children}
    </>
  )
}
```

```tsx
// in app/events/[slug]/page.tsx
<ToggleDetails>
  <p>{event.description}</p>
</ToggleDetails>
```

The `<p>` is rendered by the page on the server and passed in as `children`. Note that the description is still in
the RSC payload (React needs it to show the content when you click), but not in the initial HTML, since `open` starts
as `false`. If search engines should index the description, it's better to render it visible and collapse it with
CSS instead.

</details>

3. **Find the leak.** A teammate wrote this. What's wrong with it, and how would you have caught it automatically?

```tsx
// components/admin-badge.tsx
'use client'
import { db } from '@/lib/db'

export function AdminBadge({ userId }: { userId: number }) {
  const isAdmin = db.prepare('SELECT is_admin FROM users WHERE id = ?').get(userId)
  return isAdmin ? <span>Admin</span> : null
}
```

<details>
<summary>Solution</summary>

A Client Component imports the database module. The import pulls `lib/db.ts` (and the database driver) into the
browser bundle, where it can't work: there's no database in the browser. Depending on the driver, the build fails
with a confusing "module not found" error for a Node.js built-in, or worse, the code ships and fails at runtime.

The fix: make `AdminBadge` a Server Component (remove `'use client'`) and make it `async` if the query is async;
nothing in it needs the browser. To catch the mistake automatically, put `import 'server-only'` at the top of
`lib/db.ts`. Then any import of it from client code fails the build with an import trace that points at
`admin-badge.tsx`.

</details>

4. **Measure the difference.** Build the app and compare the JavaScript that the browser downloads for
   `/events/typescript-101` when the page is a Server Component (as now) and when you add `'use client'` to the page
   file. How do you measure it, and what else breaks when you add the directive?

<details>
<summary>Solution</summary>

Open the page in the browser with the Network tab filtered to JS, disable the cache, reload, and compare the total
transferred size; or compare the route's chunks in the `.next` folder (chapter 19 introduces the bundle analyzer,
which shows this per route).

Adding `'use client'` to `page.tsx` breaks more than the bundle size: a page that is a Client Component can't be
`async`, so `await params` is no longer allowed (you'd have to read `params` with `use(params)` or `useParams()`), and
`getSampleEvent` with the whole sample data array is now bundled for the browser. With a real database, the build
would fail outright. The point of the exercise: the page's data access, formatting and markup belong on the server,
and the only client code needed is the share button.

</details>

5. **Fix a hydration mismatch.** This component causes a hydration error on some visitors' machines but not others.
   Explain why, and fix it so that the server and browser always agree while still showing the visitor's local time.

```tsx
'use client'
export function StartsAt({ iso }: { iso: string }) {
  return <time dateTime={iso}>{new Date(iso).toLocaleString()}</time>
}
```

<details>
<summary>Solution</summary>

`toLocaleString()` uses the time zone and locale of wherever it runs. The server (often in UTC, with an `en-US`
locale) and a browser in Nairobi produce different strings, so the HTML from the server doesn't match the first
browser render. Visitors whose browser settings happen to match the server see no error.

To show local time, render something time-zone-independent on the server and during hydration, and the local time
right after, which is exactly what `LocalTime` in this chapter does:

```tsx
'use client'

import { useSyncExternalStore } from 'react'

const noSubscription = () => () => {}

export function StartsAt({ iso }: { iso: string }) {
  const local = useSyncExternalStore(
    noSubscription,
    () => new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }),
    () => null,
  )
  return <time dateTime={iso}>{local ?? `${iso.slice(0, 16).replace('T', ' ')} UTC`}</time>
}
```

The server and the hydration render both output the UTC string; React then re-renders with the local time. (Setting
state in a `useEffect` also works, but renders twice and is flagged by the `react-hooks/set-state-in-effect` lint
rule.) Alternatively, show UTC everywhere with an explicit `timeZone: 'UTC'`, which is what Gather does.

</details>

---
← Previous: [Routing: pages and layouts](03-routing-layouts-and-pages.md) | Next: [Styling, fonts and images](05-styling-fonts-and-images.md) →
