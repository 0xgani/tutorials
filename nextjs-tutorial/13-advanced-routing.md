# Chapter 13 — Advanced routing

## What you'll learn

- Route groups: organizing routes and sharing layouts without changing URLs
- Parallel routes: a layout with more than one slot, and what `default.tsx` is for
- Intercepting routes: showing a route inside the current page, as a modal, while keeping a real URL
- A modal built on the native `<dialog>` element that behaves correctly with Back, Escape and links
- How `generateStaticParams` works with nested dynamic segments
- Multiple root layouts, and root params with `next/root-params` (new in 16.3)

## Where we are

Gather has every route it needs to work, each one a page that replaces the previous one. Real apps also need routes
that *combine*: a list of attendees that opens on top of the event page, but still has its own URL that can be shared
and reloaded. That's what parallel and intercepting routes are for, and they're the main project of this chapter.
Along the way you'll meet the rest of the App Router's routing features. Chapter 3 introduced dynamic and catch-all
segments; this chapter assumes you know those.

## Route groups

A folder whose name is in parentheses is a **route group**. It organizes routes without becoming part of the URL:

```text
app/
├── (auth)/
│   ├── layout.tsx        ← wraps only the pages in this group
│   ├── login/page.tsx    → /login
│   └── signup/page.tsx   → /signup
└── events/page.tsx       → /events
```

Groups are useful for three things:

- **A layout for some routes but not others.** Chapter 15 puts the login and signup pages in `(auth)`, with a narrow
  layout that shows the demo accounts. `/events` doesn't get that layout, and nobody sees `auth` in the URL.
- **Organizing a large `app` folder** by team or area: `(marketing)`, `(shop)`, `(admin)`.
- **Multiple root layouts**, later in this chapter.

Two groups must not produce the same URL. With `app/(x)/about/page.tsx` and `app/(y)/about/page.tsx`, the build
fails:

```text
Error: You cannot have two parallel pages that resolve to the same path. Please check /(x)/about and /(y).
```

## Parallel routes

So far, a layout has had exactly one place for content: `children`. A layout can have more. A folder whose name
starts with `@` is a **slot**: its page is passed to the layout of the same level as an extra prop, next to
`children`.

```text
app/
├── layout.tsx            ← receives { children, modal }
├── @modal/
│   └── default.tsx
└── events/...            ← fills children
```

Slots aren't URL segments, so `app/@modal/x/page.tsx` is matched by the URL `/x`, like `app/x/page.tsx` would be.
For each URL, Next.js renders `children` *and* the slot side by side. That's why they're called **parallel routes**.
Classic uses are dashboards whose panels each have their own loading and error states, or a page plus a modal, which
is what Gather needs.

### `default.tsx`

A slot doesn't have a page for every URL. Gather's `@modal` slot only has something to show for one route; for `/`,
`/events` and every other URL it has nothing. What it renders then depends on how you got there:

- **Client-side navigation:** a slot that has no match for the new URL keeps showing whatever it showed before.
- **A full page load (or reload):** Next.js has no "before", so it renders the slot's `default.tsx`.

For a modal slot, the default is "nothing":

```tsx
// app/@modal/default.tsx
// What the @modal slot renders when no intercepted route is active
// (and on a full page load, where Next.js can't know the slot's state).
export default function Default() {
  return null
}
```

In Next.js 16, every slot must have a `default.tsx`. Without it the build fails and lists every URL that's missing
one:

```text
Error: Parallel route slots cannot render the same URLs
The following layouts have parallel route slots that cannot render the same URLs:
app/layout.tsx
- / is missing a matching page or default.tsx in @modal
- /dashboard is missing a matching page or default.tsx in @modal
- /events is missing a matching page or default.tsx in @modal
...
Every URL matched by one slot must have a matching page or default.tsx in every sibling slot.
```

`children` is a slot too, an implicit one. If you have other slots with sub-pages that `children` doesn't have, you
need an `app/default.tsx` as well. Gather's `children` has a page for every URL, so it doesn't.

## Intercepting routes

A slot alone can't show the attendees list "on top of" the event page, because a URL still maps to one page per slot.
The missing piece is **interception**: during a client-side navigation, Next.js can serve a *different* route than
the URL normally maps to, and render it in the slot.

An intercepting route is a folder whose name starts with a marker that works like a relative path, but counts route
segments, not folders:

| Marker | Intercepts routes |
| --- | --- |
| `(.)name` | at the same level |
| `(..)name` | one level up |
| `(..)(..)name` | two levels up |
| `(...)name` | from the root of `app` |

Slots don't count as levels. So `app/@modal/(.)events/[slug]/attendees/page.tsx` sits at the root level (the `@modal`
folder doesn't count) and intercepts `/events/<slug>/attendees`. The result:

- **Clicking a link** to `/events/react-reading-group/attendees` from inside Gather renders the intercepting page in
  the `@modal` slot. `children` keeps showing the event page, because a slot with no match for the new URL keeps its
  content. The URL in the address bar changes to `/events/react-reading-group/attendees`.
- **Opening that URL directly** (or reloading) is not a client-side navigation, so nothing is intercepted. The normal
  page at `app/events/[slug]/attendees/page.tsx` renders in `children`, and `@modal` shows its `default.tsx`.

The second case means every intercepting route needs a normal route at the same URL. Next.js checks:

```text
Error: Interception routes must have a canonical route
The following interception routes do not have a canonical route:
- /(.)events/[slug]/attendees (expected /events/[slug]/attendees)

Every interception route must have a matching non-interception route so the URL can be loaded directly or refreshed.
```

That's a good rule for users too: the modal's URL can be shared, bookmarked and reloaded, and it always shows
something sensible.

## Building the attendees modal

Five pieces: a data function, a component that shows the list, the full page, the intercepting page, and the modal
itself. Then two small edits to wire it in.

### The data

Add an `Attendee` type and a cached query to `lib/data.ts`:

```ts
// lib/data.ts
export type Attendee = { id: number; name: string }

export async function getAttendees(eventId: number): Promise<Attendee[]> {
  'use cache'
  cacheLife('hours')
  cacheTag(`rsvps:${eventId}`)
  const rows = db()
    .prepare(
      `SELECT u.id, u.name FROM rsvps r JOIN users u ON u.id = r.user_id
       WHERE r.event_id = ? ORDER BY r.created_at`,
    )
    .all(eventId) as Attendee[]
  return rows.map((row) => ({ id: row.id, name: row.name }))
}
```

The `rsvps:<id>` tag isn't new: chapter 8's RSVP action already calls `` updateTag(`rsvps:${eventId}`) ``, so the list
refreshes the moment someone RSVPs. The function returns only ids and names, never email addresses: like chapter 11's
API responses, data leaving the data layer is shaped explicitly instead of passing raw rows along.

### The list

Both the full page and the modal show the same list, so it's one Server Component:

```tsx
// components/attendee-list.tsx
import { notFound } from 'next/navigation'
import { getAttendees, getEvent } from '@/lib/data'

// Used by both the full page and the intercepted modal.
export async function AttendeeList({ slug }: { slug: string }) {
  const event = await getEvent(slug)
  if (!event) notFound()
  const attendees = await getAttendees(event.id)

  return (
    <>
      <h2>Who’s going to {event.title}</h2>
      {attendees.length === 0 ? (
        <p className="muted">Nobody yet. Be the first!</p>
      ) : (
        <ul>
          {attendees.map((a) => (
            <li key={a.id}>{a.name}</li>
          ))}
        </ul>
      )}
    </>
  )
}
```

### The full page

```tsx
// app/events/[slug]/attendees/page.tsx
import Link from 'next/link'
import { Suspense } from 'react'
import { AttendeeList } from '@/components/attendee-list'

// The full page, shown on a direct visit or a reload of /events/<slug>/attendees.
export default function AttendeesPage({ params }: PageProps<'/events/[slug]/attendees'>) {
  return (
    <main>
      <Suspense fallback={<p className="muted">Loading attendees…</p>}>
        {params.then(({ slug }) => (
          <>
            <Link href={`/events/${slug}`}>← Back to the event</Link>
            <AttendeeList slug={slug} />
          </>
        ))}
      </Suspense>
    </main>
  )
}
```

`params.then(...)` inside `<Suspense>` is chapter 7's pattern: the page itself doesn't wait for `params`, so the
shell (here, the `<main>` and the fallback) can be prerendered and sent instantly.

### The modal

The modal is a Client Component around the browser's own `<dialog>` element. `dialog.showModal()` gives you a lot
for free: a backdrop, focus moved into the dialog and kept there, Escape to close, and everything behind it made
inert (not clickable, and hidden from screen readers).

```tsx
// components/modal.tsx
'use client'

import { useRouter } from 'next/navigation'
import { useEffect, useRef } from 'react'
import styles from './modal.module.css'

export function Modal({ children }: { children: React.ReactNode }) {
  const router = useRouter()
  const dialogRef = useRef<HTMLDialogElement>(null)
  const hiding = useRef(false)

  useEffect(() => {
    const dialog = dialogRef.current
    if (!dialog) return
    hiding.current = false
    // showModal() gives us focus trapping, Escape to close and a backdrop.
    if (!dialog.open) dialog.showModal()
    return () => {
      // The URL changed some other way (the Back button, a link) and Next.js
      // is hiding or removing this page. Close the dialog without navigating,
      // or it would keep the rest of the page inert.
      hiding.current = true
      dialog.close()
    }
  }, [])

  return (
    <dialog
      ref={dialogRef}
      className={styles.dialog}
      // Closed by the user (Escape or the Close button): go back to the page
      // underneath, which also removes /attendees from the URL.
      onClose={() => {
        if (!hiding.current) router.back()
      }}
    >
      {children}
      <form method="dialog">
        <button className="button secondary">Close</button>
      </form>
    </dialog>
  )
}
```

```css
/* components/modal.module.css */
.dialog {
  width: min(480px, calc(100vw - 2rem));
  padding: 1.5rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg);
  color: var(--fg);
}

.dialog::backdrop {
  background: rgb(0 0 0 / 0.5);
}
```

How it closes:

- **The user closes it** with Escape or the Close button. `<form method="dialog">` is plain HTML: submitting it
  closes the dialog it's in, no JavaScript needed. Either way the dialog fires a `close` event, and `onClose` calls
  `router.back()`. Going *back* (rather than pushing the event's URL) matters: the user opened the modal with one
  step forward in history, so closing it should take exactly one step back. Otherwise Back would reopen the modal.
- **The URL changes some other way**, say with the browser's Back button. Then the modal must close without
  navigating again. That's what the effect's cleanup function and the `hiding` flag are for.

The cleanup function deserves a closer look, because the first version of this component didn't have one, and it had
a bug that's easy to miss. Chapter 9 showed that Next.js keeps recently visited pages alive but hidden, with React's
`<Activity>`, so going back is instant. A hidden page's DOM stays in the document; React only runs the cleanup of
its effects. A `<dialog>` opened with `showModal()` stays modal until someone calls `close()`, hidden or not. Without
the cleanup, leaving the modal by a link made the dialog invisible, but the page behind it stayed inert: nothing
could be clicked. With the cleanup, React closes the dialog whenever the page is hidden, and the effect runs again
(opening the dialog) if the page is shown again, for example when you press Forward.

### The intercepting page

```tsx
// app/@modal/(.)events/[slug]/attendees/page.tsx
import { Suspense } from 'react'
import { AttendeeList } from '@/components/attendee-list'
import { Modal } from '@/components/modal'

// Intercepting route: when the user navigates to /events/<slug>/attendees
// from inside the app, this renders in the root layout's @modal slot, on top
// of the event page, instead of replacing it. The URL still changes, so the
// link is shareable, and a reload shows the full page instead.
export default function AttendeesModal({ params }: PageProps<'/events/[slug]/attendees'>) {
  return (
    <Modal>
      <Suspense fallback={<p className="muted">Loading attendees…</p>}>
        {params.then(({ slug }) => (
          <AttendeeList slug={slug} />
        ))}
      </Suspense>
    </Modal>
  )
}
```

The `Modal` is a Client Component and `AttendeeList` a Server Component, passed in as `children`. That's chapter 4's
rule at work: a Client Component can't *import* a Server Component, but it can render one it receives as a prop.

Add `app/@modal/default.tsx` from the previous section if you haven't yet.

### Wiring it in

Render the slot in the root layout:

```tsx
// app/layout.tsx
export default function RootLayout({ children, modal }: LayoutProps<'/'>) {
  return (
    <html lang="en" className={geist.variable}>
      <body>
        <SiteHeader />
        {children}
        {modal}
        <footer className="muted" style={{ textAlign: 'center', padding: '2rem 1rem' }}>
          Gather is the example app of the Next.js tutorial.
        </footer>
      </body>
    </html>
  )
}
```

`LayoutProps<'/'>` knows about `modal` because the types are generated from your `app` folder. If your editor says
`Property 'modal' does not exist`, run `npm run build`, `npm run dev` or `npx next typegen` to regenerate them.

And add a link on the event page, next to the RSVP panel:

```tsx
// app/events/[slug]/page.tsx, in EventDetails
<Link href={`/events/${event.slug}/attendees`} scroll={false}>
  See who’s going
</Link>
```

`scroll={false}` (chapter 9) keeps the page where it is. Without it, Next.js would scroll to the top, which is right
for a new page and wrong for a modal over the current one.

### Trying it

Build and start. The route list shows both routes:

```text
├   /(.)events/[slug]/attendees
│ └ ◐ /(.)events/[slug]/attendees
...
├   /events/[slug]/attendees
│ └ ◐ /events/[slug]/attendees
```

Open an event and click **See who’s going**. The list appears over the event page, which is dimmed behind it, and
the address bar shows `/events/react-reading-group/attendees`. Here is what a browser test found for every way in and
out (the "clickable" column checks whether the page *behind* the modal accepts clicks):

| Action | URL afterwards | Modal visible | Page clickable |
| --- | --- | --- | --- |
| Click "See who’s going" | `/events/react-reading-group/attendees` | yes | no (correct: the modal is modal) |
| Press Escape | `/events/react-reading-group` | no | yes |
| Click Close | `/events/react-reading-group` | no | yes |
| Browser Back | `/events/react-reading-group` | no | yes |
| Reload while the modal is open | `/events/react-reading-group/attendees` | no, the full page shows | yes |

The scroll position also stayed the same (120 pixels before and after the click, in the test).

## `generateStaticParams` and nested segments

Look at the build output again: `/events/[slug]` lists four prerendered slugs, but `/events/[slug]/attendees` lists
none. Chapter 7's `generateStaticParams` is exported from `app/events/[slug]/page.tsx`, and a page's
`generateStaticParams` applies only to that page.

The rules for routes with several dynamic segments:

- A `generateStaticParams` can return values for its own segment and any dynamic segments *above* it, but not below.
- If it's exported from a `layout.tsx`, it applies to every page under that layout.
- If a parent and a child segment both have one, the child's runs once for each set of params the parent returned,
  and receives them as its argument:

```tsx
// app/shop/[category]/[product]/page.tsx
export async function generateStaticParams({ params }: { params: { category: string } }) {
  const products = await getProducts(params.category)
  return products.map((product) => ({ product: product.slug }))
}
```

Exercise 3 prerenders the attendee pages.

## Multiple root layouts

Gather has one root layout, `app/layout.tsx`, with the `<html>` and `<body>` tags. If you remove it and put a layout
with its own `<html>` and `<body>` in each top-level route group, each group gets its own **root layout**:

```text
app/
├── (site)/
│   └── [lang]/
│       ├── layout.tsx    ← root layout for the public site: <html lang=...>
│       ├── page.tsx      → /en, /de
│       └── events/page.tsx
└── (admin)/
    └── admin/
        ├── layout.tsx    ← a second root layout, a completely different shell
        └── page.tsx      → /admin
```

This is for parts of an app that share nothing visually, like a public site and an admin tool. The price is that
navigating between root layouts is a full page load. A test that stored a value in `window` and then clicked links
showed it:

```text
/en -> /de: still here de Willkommen bei Gather
/de -> /admin: reloaded Admin
```

`/en` to `/de` stayed in the same root layout, so it was a client-side navigation (and `<html lang>` updated to
`de`). `/de` to `/admin` crossed into another root layout, so the browser loaded a new document and the value was
gone.

There's also no longer a single place for a custom 404 page that wraps every URL. Next.js has an experimental
`global-not-found.tsx` for that case (enabled with `experimental.globalNotFound`).

## Root params

In the example above, `[lang]` is a dynamic segment *above* the root layout. Such segments are **root params**, and
Next.js 16.3 added a way to read them anywhere on the server, without passing them down as props:

```tsx
// app/(site)/[lang]/layout.tsx
import Link from 'next/link'
import { notFound } from 'next/navigation'
import { lang } from 'next/root-params'

const LANGUAGES = ['en', 'de']

export async function generateStaticParams() {
  return LANGUAGES.map((lang) => ({ lang }))
}

export default async function SiteLayout({ children }: LayoutProps<'/[lang]'>) {
  const language = await lang()
  if (!language || !LANGUAGES.includes(language)) notFound()
  return (
    <html lang={language}>
      <body>
        <nav>
          <Link href="/en">English</Link> <Link href="/de">Deutsch</Link> <Link href="/admin">Admin</Link>
        </nav>
        {children}
      </body>
    </html>
  )
}
```

`next/root-params` exports one async getter per root param, named after the folder: `[lang]` gives `lang()`. It works
in any Server Component or server-side helper, so a translation function needs no arguments:

```ts
// lib/t.ts
import { lang } from 'next/root-params'

const messages = {
  en: { welcome: 'Welcome to Gather', events: 'Upcoming events' },
  de: { welcome: 'Willkommen bei Gather', events: 'Kommende Veranstaltungen' },
}

export async function t(key: keyof (typeof messages)['en']) {
  const language = (await lang()) ?? 'en'
  return (messages[language as keyof typeof messages] ?? messages.en)[key]
}
```

```tsx
// app/(site)/[lang]/events/page.tsx
import { t } from '@/lib/t'

export default async function Events() {
  return <h1>{await t('events')}</h1>
}
```

```text
/en 200 <html lang="en" <h1>Welcome to Gather
/de/events 200 <html lang="de" <h1>Kommende Veranstaltungen
/fr 404
/fr/events 404
/admin 200 <html lang="en" <h1>Admin
```

Things to know:

- **With Cache Components, `generateStaticParams` is required** for root params, with at least one value, or the
  build fails. The build then prerenders `/en`, `/de`, `/en/events` and `/de/events` as static pages.
- **Unknown values aren't rejected for you.** Before the `notFound()` check was added, `/fr` returned `200` with
  `<html lang="fr">` and English text. Validate root params yourself, in the root layout.
- **`string | undefined`.** With multiple root layouts, a page under `(admin)` has no `lang`, so the getter's type
  includes `undefined` (and `/admin` really gets `undefined`). That's why the check above starts with `!language`.
  TypeScript catches it: without that part, the build failed with `Argument of type 'string | undefined' is not
  assignable to parameter of type 'string'`.
- **Server Components only.** Not in Client Components, Server Actions or Route Handlers (Route Handler support is
  planned). In a Server Action, read the language from the form or the URL instead.
- **Smarter caching.** Inside a `'use cache'` function, calling `lang()` adds only `lang` to the cache key, so you
  don't have to pass it in as an argument.

Gather is English-only, so its final app doesn't use root params. For a real multilingual site, the
internationalization guide in the Next.js docs combines them with locale detection in Proxy (chapter 12).

## How this differs from older Next.js versions

- **`default.tsx` is required** for every parallel route slot since Next.js 16. Before, a missing default meant a
  404 at runtime on a full page load. Now the build fails.
- **Route structure is checked at build time.** Interception routes without a canonical route, and pages that can't
  be reached because a sibling slot has no match, are build errors. The build prints a hint that the old, looser
  behavior can be restored temporarily with `deprecated.looseRouteMatching`; fix the structure instead.
- **`next/root-params`** is new in 16.3. Older apps passed `params.lang` down through props or read it in every page.
- **Hidden pages stay in the DOM.** Since Next.js 16 keeps recent pages alive with `<Activity>`, components that
  change global browser state (modal dialogs, scroll locks, document titles, event listeners) must undo it in an
  effect cleanup, as the modal does.

## Key takeaways

- `(group)` folders organize routes and share layouts without changing URLs. Two groups can't produce the same URL.
- `@slot` folders become extra layout props. Slots aren't URL segments. Every slot needs a `default.tsx`.
- On client-side navigation, a slot without a match keeps its content; on a full load, it renders `default.tsx`.
- `(.)`, `(..)` and `(...)` intercept routes during client-side navigation. A normal route must exist at the same
  URL, and it's what direct visits and reloads show.
- A modal route = a slot in the layout + an intercepting page + `default.tsx` returning `null` + a Client Component
  that closes with `router.back()`.
- Effects that change global state need a cleanup, because hidden pages are kept alive, not unmounted.
- Multiple root layouts separate unrelated parts of an app, at the cost of a full page load between them.
- Root params (`next/root-params`) can be read from any Server Component. Validate them yourself.

## Exercises

1. **A real confirmation dialog.** Chapter 8's `DeleteEventButton` uses the browser's `confirm()`, which can't be
   styled and looks different in every browser. Replace it with a `<dialog>` that has a "Delete event" and a "Cancel"
   button. What do you lose compared to the `confirm()` version, if anything?

<details>
<summary>Solution</summary>

```tsx
// components/delete-event-button.tsx
'use client'

import { useRef } from 'react'
import { deleteEvent } from '@/app/actions/events'
import styles from './modal.module.css'

export function DeleteEventButton({ slug }: { slug: string }) {
  const dialogRef = useRef<HTMLDialogElement>(null)

  return (
    <>
      <button type="button" className="button danger" onClick={() => dialogRef.current?.showModal()}>
        Delete
      </button>
      <dialog ref={dialogRef} className={styles.dialog} aria-labelledby="delete-title">
        <h2 id="delete-title">Delete this event?</h2>
        <p>Everyone’s RSVPs are deleted too. This can’t be undone.</p>
        <form action={deleteEvent.bind(null, slug)}>
          <button className="button danger">Delete event</button>{' '}
          <button type="button" className="button secondary" onClick={() => dialogRef.current?.close()}>
            Cancel
          </button>
        </form>
      </dialog>
    </>
  )
}
```

A browser test: clicking Delete opened a dialog named "Delete this event?" (thanks to `aria-labelledby`); Escape and
Cancel closed it and stayed on the event page; "Delete event" deleted the event and redirected to `/dashboard`; the
event's URL then showed the 404 page. No native `confirm()` appeared.

This dialog doesn't need the cleanup trick from the modal: it's only open while the user is on this page, and
deleting navigates away through the action's `redirect`, which unmounts it.

What you lose: without JavaScript, the "Delete" button does nothing, because opening the dialog needs JavaScript. The
`confirm()` version degraded to "delete without asking". For a destructive action, doing nothing without JavaScript
is arguably the better failure.

</details>

2. **A link inside the modal.** Add a "Browse other events" link to `/events` at the bottom of `AttendeeList`. Open
   the modal and click it. What happens, and why? Fix it.

<details>
<summary>Solution</summary>

The URL changes to `/events` and the events list renders, but the modal stays on top of it, and the page behind it
can't be clicked. A browser test reported:

```text
link inside modal | url /events | visible dialogs 1 | modal in top layer true | clickable NO (page is inert)
```

This is the "slot keeps its content" rule. `children` has a page for `/events`, but `@modal` doesn't, so during a
client-side navigation the slot keeps showing the modal. The fix is to give the slot a match for those URLs that
renders nothing:

```tsx
// app/@modal/[...catchAll]/page.tsx
// Any other URL closes the modal: during a client-side navigation a slot keeps
// showing its last page unless something in the slot matches the new URL.
export default function CatchAll() {
  return null
}
```

Now the slot switches to the catch-all page, the modal page is hidden, its effect cleanup closes the dialog, and the
events page works normally:

```text
link inside modal | url /events | visible dialogs 0 | modal in top layer false | clickable yes
```

The intercepting route is more specific, so it still wins for `/events/<slug>/attendees`; the same browser test
that opens and closes the modal passed with the catch-all in place. Remember from chapter 3 that `[...catchAll]`
needs at least one segment, so it doesn't match `/`. Gather's final app has no links inside the modal, so it doesn't
need the catch-all.

</details>

3. **Prerender the attendee pages.** Make the build prerender `/events/<slug>/attendees` for the same four events as
   the event pages. What changes in the build output?

<details>
<summary>Solution</summary>

Export a `generateStaticParams` from the attendees page. It returns values for `[slug]`, a segment above the page,
which is allowed:

```tsx
// app/events/[slug]/attendees/page.tsx
import { getUpcomingEvents } from '@/lib/data'

export async function generateStaticParams() {
  const events = await getUpcomingEvents(4)
  return events.map((event) => ({ slug: event.slug }))
}
```

```text
├   /events/[slug]/attendees
│ ├ ◐ /events/[slug]/attendees
│ ├ ○ /events/svelte-zagreb/attendees                 1h      1d
│ ├ ○ /events/nextjs-meetup-berlin/attendees          1h      1d
│ └ ◐ [+2 more paths]
```

The four pages are fully static (`○`), because everything on them is cached: `getEvent` and `getAttendees`. Their
revalidation times come from `getAttendees`'s `cacheLife('hours')`. RSVPs still show up right away, because the RSVP
action's `` updateTag(`rsvps:${eventId}`) `` invalidates them. The intercepting route still lists no slugs: this
`generateStaticParams` belongs to the full page only.

</details>

4. **Where is `lang`?** In the root params example, which of these can call `lang()` from `next/root-params`: the
   `(site)/[lang]/layout.tsx`, a Client Component `LanguageSwitcher`, a Server Action that saves a user's
   preference, `lib/t.ts` called from a Server Component, a `'use cache'` function. For the ones that can't, how do
   they get the language?

<details>
<summary>Solution</summary>

The layout, `lib/t.ts` (when called from a Server Component) and the `'use cache'` function can, and in the cached
function only `lang` becomes part of the cache key. The Client Component can't: the import fails the build. Pass the
language down as a prop, or read the URL with `useParams()`. The Server Action can't either: send the language as a
form field (or a bound argument), and validate it there like any other input.

</details>

---
← Previous: [Proxy, redirects and headers](12-proxy-redirects-and-headers.md) | Next: [Metadata and SEO](14-metadata-and-seo.md) →
