# Chapter 8 — Server Actions and forms

## What you'll learn

- What Server Functions and Server Actions are, and what `'use server'` compiles into
- Why every Server Action is a public endpoint, and the checks that belong at the top of each one
- Validating `FormData` on the server
- Forms with `useActionState`: returning errors, keeping the user's input, showing a pending state
- Passing extra arguments to an action with `bind`
- Updating the cache after a write: `updateTag`, `revalidateTag`, `revalidatePath` and `refresh`
- Calling actions from event handlers, with `useTransition` and `useOptimistic`
- `useFormStatus` for submit buttons
- Progressive enhancement, and where it stops
- The framework's own protections: the origin check, the body size limit, encrypted closures, and action IDs

## Where we are

Gather can show events, and since chapter 7 it shows them fast. It can't change anything yet. In this chapter you'll
add every write in the app: creating, editing and deleting events, RSVPing, and commenting. All of them are Server
Actions. You'll also use the cache tags from chapter 7 to make each change show up immediately.

Real accounts arrive in chapter 15. Until then the actions need *somebody* to act as, so you'll start with a small
stand-in for the auth module.

## Server Functions and Server Actions

A **Server Function** is an async function that runs on the server but can be called from the browser. You mark one
with the `'use server'` directive, either at the top of a file (every export becomes a Server Function) or as the
first line of an async function's body inside a Server Component:

```ts
// app/actions/events.ts: every export is a Server Function
'use server'

export async function createEvent(formData: FormData) {
  // runs on the server
}
```

```tsx
// Inline, inside a Server Component
export default function Page() {
  async function save(formData: FormData) {
    'use server'
    // runs on the server
  }
  return <form action={save}>{/* ... */}</form>
}
```

When a Server Function is used for a mutation (passed to a form's `action`, a button's `formAction`, or called inside
`startTransition`), it's called a **Server Action**. The two terms are mostly used interchangeably.

Client Components can't *define* Server Functions, but they can import them from a `'use server'` file, or receive
them as props. Gather keeps all of its actions in `app/actions/`, one file per area.

### What the directive compiles into

`'use server'` is not a hint, it's a compiler instruction. At build time, Next.js replaces the function in every
client bundle with a **reference**: an action ID plus a small dispatcher. Calling it in the browser sends a `POST`
request to the current URL. These are the headers of a real request from the form you'll build below, captured from
Gather:

```text
POST /events/new
next-action: 6021a91cf97fda0899bf9b653062db2d939c66d6d2
accept: text/x-component
content-type: multipart/form-data; boundary=----WebKitFormBoundary...
```

The `next-action` header names the function; the body carries its arguments (here, the form fields). The response is
an RSC payload (`text/x-component`), and that's what makes actions feel integrated: one response can carry both the
function's return value *and* a freshly rendered version of the page. You'll see this when the RSVP count updates
without a second request.

Two properties follow from this design:

- **Actions only respond to `POST`.** A link or a prefetch can never trigger one.
- **Actions are dispatched one at a time per browser tab.** If a user fires three actions in quick succession, the
  second waits for the first. That keeps the UI consistent with the server's answers, but it means
  `Promise.all([actionA(), actionB()])` doesn't run them in parallel. If you need parallel work, do it inside one
  action. (The server doesn't queue anything: this is purely the client dispatcher.)

## Every action is a public endpoint

This is the most important idea in the chapter. Rendering a form only for organizers does **not** mean only
organizers can call the action behind it. Anyone who has seen the action ID (it's in your JavaScript bundle and in
your HTML) can send the same `POST` with any arguments they like, using `curl` or a script.

You can watch this happen. With a production build running, this request calls `createEvent` with an empty argument
list, the way a hand-written script might:

```bash
curl -X POST http://localhost:3000/events/new \
  -H "Origin: http://localhost:3000" \
  -H "Next-Action: 6021a91cf97fda0899bf9b653062db2d939c66d6d2" \
  -H "Content-Type: text/plain;charset=UTF-8" \
  --data '[]'
```

The server log shows the action running and then failing, because `formData` was `undefined`:

```text
⨯ TypeError: Cannot read properties of undefined (reading 'get')
```

No harm done here (validation crashed before anything was written), but the lesson is that **TypeScript types are
not checked at runtime**. The signature says `formData: FormData`; the network can send anything. So every action
starts the same way:

1. **Authenticate**: who is calling?
2. **Authorize**: is this user allowed to do *this*, to *this* row?
3. **Validate**: are the inputs the right type, shape and size?

Only then does it touch the database. Gather's actions follow that order without exception, and you'll see each step
below.

### A stand-in for authentication

Chapter 15 builds real sign-up, login and sessions. To write the actions now, create `lib/auth.ts` with the same
three exports the real module will have, backed by something much simpler:

```ts
// lib/auth.ts
import 'server-only'
import { cookies } from 'next/headers'
import { redirect } from 'next/navigation'
import { db } from './db'

// A STAND-IN until chapter 15. There are no passwords or sessions yet: the
// current user is whoever the `demo_user` cookie names, and Ada (an
// organizer) when there is no cookie. Anyone can set that cookie, so this
// file must never ship. Chapter 15 replaces it with real sessions and keeps
// the same three exports, so nothing that imports them has to change.

export type User = {
  id: number
  name: string
  email: string
  role: 'member' | 'organizer'
}

export async function getCurrentUser(): Promise<User | null> {
  const email = (await cookies()).get('demo_user')?.value ?? 'ada@example.com'
  const row = db().prepare('SELECT id, name, email, role FROM users WHERE email = ?').get(email) as
    | User
    | undefined
  return row ? { id: row.id, name: row.name, email: row.email, role: row.role } : null
}

export async function requireUser(returnTo = '/'): Promise<User> {
  const user = await getCurrentUser()
  if (!user) redirect(`/?login-needed=${encodeURIComponent(returnTo)}`)
  return user
}

export async function requireOrganizer(returnTo = '/'): Promise<User> {
  const user = await requireUser(returnTo)
  if (user.role !== 'organizer') redirect('/dashboard?error=organizers-only')
  return user
}
```

By default you're Ada, an organizer. To test as someone else, set the cookie in the browser's DevTools console:

```js
document.cookie = 'demo_user=grace@example.com; path=/' // another organizer
document.cookie = 'demo_user=linus@example.com; path=/' // a member
document.cookie = 'demo_user=; path=/; max-age=0' // back to Ada
```

Two details matter even in this fake version. First, `getCurrentUser()` reads `cookies()`, so anything that calls it
is runtime data, exactly as it will be with real sessions. Second, the row is copied into a plain object, because
`node:sqlite` rows have a null prototype and can't be passed to Client Components (chapter 4).

`redirect()` works in Server Components and in Server Actions. It throws a special error that Next.js catches, so
code after it never runs and TypeScript knows `requireUser` returns a `User`.

## Validating input

Browsers enforce `required` and `maxLength`, but a script doesn't use a browser. Validation has to happen on the server,
in plain code you can test. Gather keeps it in `lib/validation.ts`:

```ts
// lib/validation.ts
// Plain-TypeScript validation for the forms. Server Actions receive
// untrusted FormData, so every field is checked here, on the server, even
// when the browser already enforced `required` or `maxLength`.

export type FieldErrors<Field extends string> = Partial<Record<Field, string>>

export type EventInput = {
  title: string
  description: string
  city: string
  venue: string
  startsAt: string // ISO 8601, UTC
  capacity: number
}

type EventField = keyof EventInput

export function parseEventForm(
  formData: FormData,
): { ok: true; data: EventInput } | { ok: false; errors: FieldErrors<EventField> } {
  const text = (name: string) => String(formData.get(name) ?? '').trim()
  const errors: FieldErrors<EventField> = {}

  const title = text('title')
  if (title.length < 3 || title.length > 80) errors.title = 'Use 3 to 80 characters.'

  const description = text('description')
  if (description.length < 10 || description.length > 2000)
    errors.description = 'Use 10 to 2,000 characters.'

  const city = text('city')
  if (city.length < 2 || city.length > 60) errors.city = 'Enter a city.'

  const venue = text('venue')
  if (venue.length < 2 || venue.length > 120) errors.venue = 'Enter a venue.'

  // <input type="datetime-local"> sends "2026-11-05T18:30" with no zone;
  // Gather treats every time as UTC to keep the example simple.
  const startsAtRaw = text('startsAt')
  const startsAt = new Date(`${startsAtRaw}:00Z`)
  if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(startsAtRaw) || Number.isNaN(startsAt.getTime()))
    errors.startsAt = 'Pick a date and time.'
  else if (startsAt.getTime() < Date.now()) errors.startsAt = 'The event must be in the future.'

  const capacity = Number(text('capacity'))
  if (!Number.isInteger(capacity) || capacity < 1 || capacity > 10_000)
    errors.capacity = 'Enter a whole number from 1 to 10,000.'

  if (Object.keys(errors).length > 0) return { ok: false, errors }
  return {
    ok: true,
    data: { title, description, city, venue, startsAt: startsAt.toISOString(), capacity },
  }
}

// "Next.js Meetup: Berlin!" -> "nextjs-meetup-berlin"
export function slugify(title: string): string {
  return title
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '') // drop accents: "Görlitz" -> "Gorlitz"
    .toLowerCase()
    .replace(/\./g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 60)
}
```

`String(formData.get(name) ?? '')` is deliberate. `formData.get` returns `string | File | null`, and a forged request
could send a file where you expect text; `String()` turns anything into a string you can then measure.

Gather validates by hand to keep dependencies at zero. In a larger app you'd likely use a schema library such as Zod
or Valibot, which do the same job with less code. Either way, remember what validation *doesn't* do: a perfectly
valid form can still refer to an event the caller doesn't own. That's authorization, and it's a separate check.

## Creating events

### The action

```ts
// app/actions/events.ts
'use server'

import { redirect } from 'next/navigation'
import { updateTag } from 'next/cache'
import { requireOrganizer } from '@/lib/auth'
import { db } from '@/lib/db'
import { parseEventForm, slugify, type EventInput, type FieldErrors } from '@/lib/validation'

export type EventFormState = {
  errors?: FieldErrors<keyof EventInput>
  message?: string
  values?: Record<string, string>
}

function submittedValues(formData: FormData): Record<string, string> {
  const values: Record<string, string> = {}
  for (const [key, value] of formData) if (typeof value === 'string') values[key] = value
  return values
}

function uniqueSlug(title: string): string {
  const base = slugify(title) || 'event'
  const taken = db().prepare('SELECT 1 FROM events WHERE slug = ?')
  let slug = base
  for (let n = 2; taken.get(slug); n++) slug = `${base}-${n}`
  return slug
}

export async function createEvent(
  _prev: EventFormState,
  formData: FormData,
): Promise<EventFormState> {
  // Authorize first: a Server Action is a public POST endpoint.
  const user = await requireOrganizer('/events/new')

  const parsed = parseEventForm(formData)
  if (!parsed.ok) return { errors: parsed.errors, values: submittedValues(formData) }

  const e = parsed.data
  const slug = uniqueSlug(e.title)
  db()
    .prepare(
      `INSERT INTO events (slug, title, description, city, venue, starts_at, capacity, organizer_id)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
    )
    .run(slug, e.title, e.description, e.city, e.venue, e.startsAt, e.capacity, user.id)

  updateTag('events')
  updateTag(`dashboard:${user.id}`)
  redirect(`/events/${slug}`)
}
```

Walk through it in order:

- **The signature** `(prevState, formData)` is what `useActionState` (next section) passes. A plain
  `<form action={fn}>` would call `fn(formData)` instead.
- **`requireOrganizer`** authenticates and authorizes in one call. A member who forges the request is redirected
  before anything else runs.
- **Validation failure returns state** instead of throwing. Expected problems (a too-short title) are *results* the
  form displays; exceptions are for the unexpected (chapter 10). The submitted values are returned too, for a reason
  you'll see in the form.
- **The organizer comes from the session**, never from the form. If the form had an `organizerId` field, anyone could
  create events in someone else's name.
- **`updateTag('events')`** expires every cached entry tagged `events` (the home page list, search results, city
  list). The dashboard tag is for chapter 15's dashboard.
- **`redirect()` comes last.** It throws, so anything after it would never run. Invalidate *before* redirecting so the
  destination renders with fresh data.

### The form

The form is a Client Component, because it uses a hook:

```tsx
// components/event-form.tsx
'use client'

import { useActionState } from 'react'
import type { EventFormState } from '@/app/actions/events'

type Values = Partial<
  Record<'title' | 'description' | 'city' | 'venue' | 'startsAt' | 'capacity', string>
>

export function EventForm({
  action,
  initialValues = {},
  submitLabel,
}: {
  action: (state: EventFormState, formData: FormData) => Promise<EventFormState>
  initialValues?: Values
  submitLabel: string
}) {
  const [state, formAction, pending] = useActionState(action, {})
  // After a failed submit, show what the user typed rather than the original.
  const values: Values = state.values ?? initialValues
  const errors = state.errors ?? {}

  return (
    <form action={formAction} className="form">
      <label>
        Title
        <input name="title" defaultValue={values.title} required maxLength={80} />
        {errors.title && <span className="error">{errors.title}</span>}
      </label>
      <label>
        Description
        <textarea
          name="description"
          defaultValue={values.description}
          rows={6}
          required
          maxLength={2000}
        />
        {errors.description && <span className="error">{errors.description}</span>}
      </label>
      <label>
        City
        <input name="city" defaultValue={values.city} required />
        {errors.city && <span className="error">{errors.city}</span>}
      </label>
      <label>
        Venue
        <input name="venue" defaultValue={values.venue} required />
        {errors.venue && <span className="error">{errors.venue}</span>}
      </label>
      <label>
        Starts at (UTC)
        <input name="startsAt" type="datetime-local" defaultValue={values.startsAt} required />
        {errors.startsAt && <span className="error">{errors.startsAt}</span>}
      </label>
      <label>
        Capacity
        <input
          name="capacity"
          type="number"
          min={1}
          max={10000}
          defaultValue={values.capacity}
          required
        />
        {errors.capacity && <span className="error">{errors.capacity}</span>}
      </label>
      {state.message && (
        <p className="error" role="alert">
          {state.message}
        </p>
      )}
      <div>
        <button className="button" disabled={pending}>
          {pending ? 'Saving…' : submitLabel}
        </button>
      </div>
    </form>
  )
}
```

`useActionState(action, initialState)` returns three things:

| Value | What it is |
|---|---|
| `state` | The last value the action returned (or `initialState` before the first submit) |
| `formAction` | A wrapped action to pass to `<form action>`. It calls your action with `(state, formData)` |
| `pending` | `true` while the action runs |

Why return `values`? Because **React resets a form after its action completes**, just as a browser does after a
classic form submission. The inputs are uncontrolled (`defaultValue`, no `useState`), so after a failed submit they'd
go back to their initial values and the user would lose what they typed. Returning the submitted values and using them
as `defaultValue` puts the text back. (The other approach is controlled inputs with state for every field, which is
more code for the same result.)

The component receives the action as a prop instead of importing it, so the same form serves both "create" and
"edit". A Server Action is one of the few functions that *can* be passed from a Server Component to a Client
Component (chapter 4): what's actually passed is the reference.

### The page

```tsx
// app/events/new/page.tsx
import type { Metadata } from 'next'
import { Suspense } from 'react'
import { requireOrganizer } from '@/lib/auth'
import { createEvent } from '@/app/actions/events'
import { EventForm } from '@/components/event-form'

export const metadata: Metadata = { title: 'New event' }

export default function NewEventPage() {
  return (
    <main>
      <h1>Create an event</h1>
      <Suspense fallback={<div className="skeleton" style={{ height: '30rem' }} />}>
        <NewEventForm />
      </Suspense>
    </main>
  )
}

async function NewEventForm() {
  await requireOrganizer('/events/new')
  return <EventForm action={createEvent} submitLabel="Create event" />
}
```

The page checks `requireOrganizer` too, so members don't see a form they can't use. This check is for user
experience; the check inside the action is the one that keeps the data safe. You need both.

`requireOrganizer` reads cookies, so it lives in a component inside `<Suspense>`, following chapter 7's rule. The
heading is in the static shell; the form streams in.

To reach the page, give the placeholder dashboard a link (chapter 15 replaces this page entirely):

```tsx
// app/dashboard/page.tsx
import Link from 'next/link'

export default function DashboardPage() {
  return (
    <main>
      <h1>Your dashboard</h1>
      <p>Your events appear here once you can log in (chapter 15).</p>
      <p>
        <Link className="button" href="/events/new">
          Create an event
        </Link>
      </p>
    </main>
  )
}
```

### Trying it

Run `npm run build && npm start` and open `/events/new`. Fill in the form with the title "Hi" and the description
"short", and submit. The two errors appear under their fields, "Hi" is still in the title box, and the URL is still
`/events/new`:

```text
Use 3 to 80 characters.
Use 10 to 2,000 characters.
```

Fix both fields (for example "Rust in Oslo: Async deep dive" with a proper description) and submit again. You land on
`/events/rust-in-oslo-async-deep-dive`, and the new event is already in `/events?city=Oslo`. That second part is
`updateTag('events')` at work: without it, the cached search results would keep the old list for up to their
`cacheLife('minutes')` lifetime.

## Updating the cache after a write

Next.js gives you four tools. Choosing between them is a question of *who* needs to see the change and *how soon*:

| Function | What it does | Where it works | Use it when |
|---|---|---|---|
| `updateTag(tag)` | Expires the tag immediately; the next read waits for fresh data | Server Actions only | The user who made the change must see it right away ("read your own writes") |
| `revalidateTag(tag, 'max')` | Marks the tag stale; readers get the old data while fresh data loads in the background | Server Actions and Route Handlers | Others can see the change a moment later (webhooks, CMS updates) |
| `revalidatePath(path)` | Invalidates one route's cached content | Server Actions and Route Handlers | One page changed and you haven't tagged its data |
| `refresh()` | Re-renders the current page without touching any cache | Server Actions only | The page shows something that isn't cached and the action just changed it |

Gather's actions use `updateTag` almost everywhere, because the person clicking "Save" expects to see their save.
`revalidateTag` with a profile appears in chapter 11, in a webhook-style Route Handler. Its single-argument form,
`revalidateTag(tag)`, from older versions is deprecated in 16; always pass a profile (`'max'` is the recommended one),
or use `updateTag` inside actions.

When an action calls `updateTag`, `revalidatePath` or `refresh`, or sets a cookie, Next.js re-renders the current route
on the server and sends the new RSC payload **in the same response** as the action's result. The page updates without
a second round trip. `revalidateTag(tag, 'max')` deliberately skips that re-render: the change shows up on a later
visit. A `redirect()` streams the *destination's* payload in the same response instead.

## Editing events

Editing needs one thing creating didn't: the action must know *which* event. You could put the slug in a hidden input,
but there's a neater tool: **`bind`**. Calling `fn.bind(null, a)` creates a new function with `a` pre-filled as the
first argument. React supports `bind` on Server Actions and sends the bound arguments along with the form data.

```ts
// app/actions/events.ts (continued)
export async function updateEvent(
  slug: string,
  _prev: EventFormState,
  formData: FormData,
): Promise<EventFormState> {
  const user = await requireOrganizer(`/events/${slug}/edit`)

  const event = db().prepare('SELECT id, organizer_id FROM events WHERE slug = ?').get(slug) as
    | { id: number; organizer_id: number }
    | undefined
  // Authorization, not just authentication: only this event's organizer.
  if (!event || event.organizer_id !== user.id) {
    return { message: 'You can only edit events you organize.' }
  }

  const parsed = parseEventForm(formData)
  if (!parsed.ok) return { errors: parsed.errors, values: submittedValues(formData) }

  const e = parsed.data
  db()
    .prepare(
      `UPDATE events SET title = ?, description = ?, city = ?, venue = ?, starts_at = ?, capacity = ?
       WHERE id = ?`,
    )
    .run(e.title, e.description, e.city, e.venue, e.startsAt, e.capacity, event.id)

  updateTag('events')
  updateTag(`event:${slug}`)
  updateTag(`dashboard:${user.id}`)
  redirect(`/events/${slug}`)
}
```

Notice that the bound `slug` is **just as untrusted as the form data**. Bound arguments travel through the browser
like everything else (look for `["nextjs-meetup-berlin"]` in the edit page's RSC payload), and a forged request can
send any slug. The
action therefore looks the event up again and compares its `organizer_id` with the *session's* user. Being an
organizer isn't enough; you must be *this event's* organizer.

The slug in the URL doesn't change when the title does. That's on purpose: links people have shared keep working.

```tsx
// app/events/[slug]/edit/page.tsx
import type { Metadata } from 'next'
import { notFound } from 'next/navigation'
import { Suspense } from 'react'
import { requireOrganizer } from '@/lib/auth'
import { getEvent } from '@/lib/data'
import { updateEvent } from '@/app/actions/events'
import { EventForm } from '@/components/event-form'

export const metadata: Metadata = { title: 'Edit event' }

export default function EditEventPage({ params }: PageProps<'/events/[slug]/edit'>) {
  return (
    <main>
      <h1>Edit event</h1>
      <Suspense fallback={<div className="skeleton" style={{ height: '30rem' }} />}>
        <EditForm params={params} />
      </Suspense>
    </main>
  )
}

async function EditForm({ params }: Pick<PageProps<'/events/[slug]/edit'>, 'params'>) {
  const { slug } = await params
  const user = await requireOrganizer(`/events/${slug}/edit`)
  const event = await getEvent(slug)
  if (!event) notFound()
  // Chapter 10 turns this into a proper 403 page with forbidden().
  if (event.organizer.id !== user.id) {
    return <p className="error">You can only edit events you organize.</p>
  }

  return (
    <EventForm
      action={updateEvent.bind(null, slug)}
      submitLabel="Save changes"
      initialValues={{
        title: event.title,
        description: event.description,
        city: event.city,
        venue: event.venue,
        startsAt: event.startsAt.slice(0, 16), // "2026-11-05T18:30"
        capacity: String(event.capacity),
      }}
    />
  )
}
```

`updateEvent.bind(null, slug)` has the type `(prev, formData) => Promise<EventFormState>`, exactly what `EventForm`
expects, so TypeScript checks the whole chain.

**Bound values versus closures.** An inline `'use server'` function inside a Server Component can also *capture*
variables from its surroundings. Next.js encrypts captured values before sending them to the browser and decrypts them
on the way back, so they can't be read in the page. Bound arguments are sent as they are (the slug above is plain
text), which is fine for a slug but not for a secret. In neither case should you rely on the value being unmodified: re-check permissions on the server, as `updateEvent`
does. (When you run several server instances, they must share one encryption key; chapter 20 sets
`NEXT_SERVER_ACTIONS_ENCRYPTION_KEY`.)

## Deleting events

Deleting doesn't need state, so the form can call the action directly. A small Client Component adds a confirmation:

```ts
// app/actions/events.ts (continued)
export async function deleteEvent(slug: string) {
  const user = await requireOrganizer(`/events/${slug}`)
  const result = db()
    .prepare('DELETE FROM events WHERE slug = ? AND organizer_id = ?')
    .run(slug, user.id)
  if (result.changes === 0) throw new Error('Event not found or not yours')

  updateTag('events')
  updateTag(`event:${slug}`)
  updateTag(`dashboard:${user.id}`)
  redirect('/dashboard')
}
```

```tsx
// components/delete-event-button.tsx
'use client'

import { deleteEvent } from '@/app/actions/events'

export function DeleteEventButton({ slug }: { slug: string }) {
  return (
    <form
      action={deleteEvent.bind(null, slug)}
      onSubmit={(e) => {
        if (!confirm('Delete this event? Everyone’s RSVPs are deleted too.')) e.preventDefault()
      }}
    >
      <button className="button danger">Delete</button>
    </form>
  )
}
```

The ownership check is folded into the SQL: `WHERE slug = ? AND organizer_id = ?` can only ever delete the caller's
own event. If nothing was deleted, the action throws. A legitimate user never gets here (the button only appears for
the organizer), so a failure means something is wrong, and failing loudly is right for a destructive operation. The
`ON DELETE CASCADE` foreign keys from chapter 6 remove the event's RSVPs and comments with it.

`confirm()` blocks the browser's UI, which is crude but dependable. You'll replace it with a proper dialog in an
exercise of chapter 13.

## RSVPs: calling an action from an event handler

An RSVP is a toggle, not a form with fields. You can call a Server Action like any async function from an event
handler. Here's the action:

```ts
// app/actions/rsvp.ts
'use server'

import { updateTag } from 'next/cache'
import { requireUser } from '@/lib/auth'
import { db } from '@/lib/db'

export type RsvpResult = { going: boolean; count: number; error?: string }

export async function toggleRsvp(eventId: number, slug: string): Promise<RsvpResult> {
  const user = await requireUser(`/events/${slug}`)
  const database = db()

  const event = database
    .prepare(
      `SELECT capacity, starts_at, (SELECT count(*) FROM rsvps WHERE event_id = events.id) AS going
       FROM events WHERE id = ?`,
    )
    .get(eventId) as { capacity: number; starts_at: string; going: number } | undefined
  if (!event) return { going: false, count: 0, error: 'This event no longer exists.' }

  const attending = database
    .prepare('SELECT 1 FROM rsvps WHERE event_id = ? AND user_id = ?')
    .get(eventId, user.id)

  if (attending) {
    database.prepare('DELETE FROM rsvps WHERE event_id = ? AND user_id = ?').run(eventId, user.id)
  } else {
    if (new Date(event.starts_at).getTime() < Date.now()) {
      return { going: false, count: event.going, error: 'This event has already started.' }
    }
    if (event.going >= event.capacity) {
      return { going: false, count: event.going, error: 'Sorry, this event is full.' }
    }
    database.prepare('INSERT INTO rsvps (event_id, user_id) VALUES (?, ?)').run(eventId, user.id)
  }

  // Everything that shows attendance: lists, the event page, the attendee
  // list, and this user's dashboard.
  updateTag('events')
  updateTag(`event:${slug}`)
  updateTag(`rsvps:${eventId}`)
  updateTag(`dashboard:${user.id}`)

  const { count } = database
    .prepare('SELECT count(*) AS count FROM rsvps WHERE event_id = ?')
    .get(eventId) as { count: number }
  return { going: !attending, count }
}
```

The capacity check runs on the server against the database, not against the count the button displayed. The button's
count could be minutes old; the database is the truth. The return value is deliberately small (`going`, `count`,
`error`): action results are sent to the browser, so return what the UI needs, never whole database rows.

This action uses a few more tags than you might expect. The event's attendee count appears in the event lists
(`events`), on the event page (`event:<slug>`), in the "is this user going?" check (`rsvps:<id>`) and on the user's
dashboard. Missing one of them would leave one view showing an old count.

It needs one new read function, for the RSVP button's initial state:

```ts
// lib/data.ts (added at the end)
export async function isAttending(eventId: number, userId: number): Promise<boolean> {
  'use cache'
  cacheLife('hours')
  cacheTag(`rsvps:${eventId}`)
  const row = db()
    .prepare('SELECT 1 FROM rsvps WHERE event_id = ? AND user_id = ?')
    .get(eventId, userId)
  return row !== undefined
}
```

The function takes a `userId` instead of reading the session itself: functions with `'use cache'` can't call
`cookies()` (chapter 7). The caller resolves the user first, outside the cache, and passes the id in, which becomes
part of the cache key. Update the comment at the top of `lib/data.ts` to record this rule:

```ts
// The read side of Gather's data access layer: every page reads data through
// these functions, never with SQL of its own. Every function is cached with
// 'use cache' and tagged, so mutations in app/actions can invalidate exactly
// what they changed. Nothing here reads cookies: callers that need the
// current user resolve it first (lib/auth.ts) and pass the id in.
```

### The optimistic button

Clicking "RSVP" and waiting for a round trip before anything changes feels sluggish. Chapter 2 introduced
`useOptimistic` for exactly this: show the expected result at once, and let the server's answer replace it.

```tsx
// components/rsvp-button.tsx
'use client'

import { useOptimistic, useState, useTransition } from 'react'
import { toggleRsvp } from '@/app/actions/rsvp'

type Props = {
  eventId: number
  slug: string
  initialGoing: boolean
  initialCount: number
  capacity: number
}

export function RsvpButton({ eventId, slug, initialGoing, initialCount, capacity }: Props) {
  const [state, setState] = useState({ going: initialGoing, count: initialCount })
  const [error, setError] = useState<string>()
  const [isPending, startTransition] = useTransition()

  // While the Server Action runs, show the result we expect. If the action
  // fails, React drops the optimistic value and `state` shows the truth.
  const [optimistic, setOptimistic] = useOptimistic(state, (current, going: boolean) => ({
    going,
    count: current.count + (going ? 1 : -1),
  }))

  const full = !optimistic.going && optimistic.count >= capacity

  function onClick() {
    setError(undefined)
    startTransition(async () => {
      setOptimistic(!optimistic.going)
      const result = await toggleRsvp(eventId, slug)
      if (result.error) setError(result.error)
      setState({ going: result.going, count: result.count })
    })
  }

  return (
    <>
      <button
        className={optimistic.going ? 'button secondary' : 'button'}
        onClick={onClick}
        disabled={isPending || full}
        aria-pressed={optimistic.going}
      >
        {optimistic.going ? '✓ You’re going (cancel)' : full ? 'Full' : 'RSVP'}
      </button>
      {error && (
        <span className="error" role="alert">
          {error}
        </span>
      )}
    </>
  )
}
```

The pieces fit together like this:

1. `startTransition(async () => { ... })` marks the work as an action. Optimistic updates are only allowed inside a
   transition (or a form action), and `isPending` is `true` until the whole async function finishes.
2. `setOptimistic(!optimistic.going)` runs the reducer immediately: the button flips and the count changes before the
   request has even left.
3. When the action returns, `setState` stores the server's answer. As the transition ends, React discards the
   optimistic value and renders from `state`.
4. If the server said no ("Sorry, this event is full"), `state` keeps the real values, so the button flips back and the
   error appears. No rollback code is needed.

The button is disabled while pending. Since actions are dispatched one at a time anyway, a second click couldn't run
in parallel; disabling the button makes that visible.

### The event page

The RSVP button depends on who is looking, so it needs runtime data. Chapter 7's event page keeps all of that inside
Suspense boundaries, so the change is local. Replace the attendance paragraph with an `RsvpPanel`, and give the
comments a form (next section):

```tsx
// app/events/[slug]/page.tsx (changed parts)
import { getComments, getEvent, getUpcomingEvents, isAttending, type EventDetail } from '@/lib/data'
import { getCurrentUser } from '@/lib/auth'
import { formatEventDate, spotsLeft } from '@/lib/format'
import { RsvpButton } from '@/components/rsvp-button'
import { CommentForm } from '@/components/comment-form'
import { DeleteEventButton } from '@/components/delete-event-button'
import { ShareButton } from '@/components/share-button'

// ...inside EventDetails:
      <div className={styles.rsvp}>
        <Suspense fallback={<div className={`skeleton ${styles.rsvpSkeleton}`} />}>
          <RsvpPanel event={event} />
        </Suspense>
        <ShareButton title={event.title} path={`/events/${event.slug}`} />
      </div>
      {/* ... */}
        <Suspense fallback={<p className="muted">Loading comments…</p>}>
          <Comments event={event} />
        </Suspense>

// Request-time: depends on who is looking.
async function RsvpPanel({ event }: { event: EventDetail }) {
  const user = await getCurrentUser()
  const attending = user ? await isAttending(event.id, user.id) : false
  const isOrganizer = user?.id === event.organizer.id

  return (
    <div className={styles.panel}>
      <p>
        <strong>{event.going}</strong> going · {spotsLeft(event.capacity, event.going)}
      </p>
      {user && (
        <RsvpButton
          eventId={event.id}
          slug={event.slug}
          initialGoing={attending}
          initialCount={event.going}
          capacity={event.capacity}
        />
      )}
      {isOrganizer && (
        <>
          <Link className="button secondary" href={`/events/${event.slug}/edit`}>
            Edit
          </Link>
          <DeleteEventButton slug={event.slug} />
        </>
      )}
    </div>
  )
}
```

Add the panel's styles to `event.module.css`:

```css
/* app/events/[slug]/event.module.css (added) */
.panel {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
}

.panel p {
  margin: 0;
}

.rsvpSkeleton {
  width: 16rem;
  height: 2.4rem;
}
```

The skeleton has roughly the panel's size, so the layout doesn't jump when the panel streams in.

Build again and compare the event pages with chapter 7's output:

```text
├   /events/[slug]
│ ├ ◐ /events/[slug]
│ ├ ◐ /events/svelte-zagreb                 1m      1h
│ ├ ◐ /events/nextjs-meetup-berlin          1m      1h
│ └ ◐ [+2 more paths]
├   /events/[slug]/edit
│ └ ◐ /events/[slug]/edit
└ ◐ /events/new
```

The known event pages changed from `○` to `◐`. They're still prerendered with their cached details (and still
revalidate after a minute), but the RSVP panel now reads cookies and streams in per request. This is Partial
Prerendering doing its job: one personalised box doesn't make the whole page dynamic.

## Comments: `useActionState` with `bind`

The comment form combines both techniques: a bound event id and slug, and state for errors.

```ts
// app/actions/comments.ts
'use server'

import { updateTag } from 'next/cache'
import { requireUser } from '@/lib/auth'
import { db } from '@/lib/db'

export type CommentState = { error?: string; body?: string }

export async function addComment(
  eventId: number,
  slug: string,
  _prev: CommentState,
  formData: FormData,
): Promise<CommentState> {
  const user = await requireUser(`/events/${slug}`)
  const body = String(formData.get('body') ?? '').trim()
  if (body.length < 1 || body.length > 500) return { error: 'Write 1 to 500 characters.', body }

  const exists = db().prepare('SELECT 1 FROM events WHERE id = ?').get(eventId)
  if (!exists) return { error: 'This event no longer exists.' }

  db()
    .prepare('INSERT INTO comments (event_id, user_id, body) VALUES (?, ?, ?)')
    .run(eventId, user.id, body)
  updateTag(`comments:${eventId}`)
  return {}
}
```

```tsx
// components/comment-form.tsx
'use client'

import { useActionState } from 'react'
import { addComment, type CommentState } from '@/app/actions/comments'

export function CommentForm({ eventId, slug }: { eventId: number; slug: string }) {
  // bind() pre-fills the first arguments; React passes (prevState, formData).
  const [state, formAction, pending] = useActionState<CommentState, FormData>(
    addComment.bind(null, eventId, slug),
    {},
  )

  // React resets a form after its action finishes. On success that clears
  // the textarea; on an error, defaultValue puts the user's text back.
  return (
    <form action={formAction} className="form">
      <label>
        Add a comment
        <textarea name="body" rows={3} maxLength={500} required defaultValue={state.body} />
      </label>
      {state.error && <p className="error">{state.error}</p>}
      <div>
        <button className="button" disabled={pending}>
          {pending ? 'Posting…' : 'Post comment'}
        </button>
      </div>
    </form>
  )
}
```

Here the automatic form reset is exactly what you want: after a successful post the textarea is empty, ready for the
next comment. And since the action called `updateTag('comments:<id>')`, the response re-renders the page, so the new
comment appears in the list in the same round trip.

`addComment.bind(null, eventId, slug)` creates a new function on every render. That's fine: `useActionState` always
calls the latest one.

In the event page, the comment list gets the form below it. The form is wrapped in its own boundary, because it reads
the current user:

```tsx
// app/events/[slug]/page.tsx (continued)
async function Comments({ event }: { event: EventDetail }) {
  const comments = await getComments(event.id)
  return (
    <>
      {comments.length === 0 && <p className="muted">No comments yet. Ask the organizer something!</p>}
      <ul className={styles.comments}>
        {comments.map((comment) => (
          <li key={comment.id}>
            <strong>{comment.author.name}</strong>{' '}
            <span className="muted">{formatEventDate(comment.createdAt)}</span>
            <p>{comment.body}</p>
          </li>
        ))}
      </ul>
      <Suspense fallback={null}>
        <CommentBox event={event} />
      </Suspense>
    </>
  )
}

async function CommentBox({ event }: { event: EventDetail }) {
  const user = await getCurrentUser()
  if (!user) return null
  return <CommentForm eventId={event.id} slug={event.slug} />
}
```

## `useFormStatus`: pending state for a nested button

`useActionState` gives the *form's owner* a `pending` flag. Sometimes the button is a separate, reusable component
that doesn't know which action its form runs. `useFormStatus` from `react-dom` answers "is the form I'm inside
submitting?":

```tsx
// components/submit-button.tsx
'use client'

import { useFormStatus } from 'react-dom'

// Must be rendered inside a <form>: it reports on its parent form.
export function SubmitButton({ children }: { children: React.ReactNode }) {
  const { pending } = useFormStatus()
  return (
    <button className="button" disabled={pending}>
      {pending ? 'Saving…' : children}
    </button>
  )
}
```

It only works in a component rendered *inside* the `<form>`; called in the component that renders the form, it always
reports `pending: false`. Gather's forms already get `pending` from `useActionState`, so they don't need it, but it's
the right tool for a design-system button. (Exercise 3 uses it.)

## Progressive enhancement, and where it stops

A form whose `action` is a Server Action works before JavaScript has loaded. React renders it as an ordinary HTML form
with hidden inputs that identify the action, so a submit is a normal browser `POST`. Here is the start of the event
form as the server sends it:

```html
<form class="form" action="" encType="multipart/form-data" method="POST">
  <input type="hidden" name="$ACTION_REF_1"/>
  <input type="hidden" name="$ACTION_1:0" value="{&quot;id&quot;:&quot;6021a91cf97f...&quot;,&quot;bound&quot;:&quot;$@1&quot;}"/>
  <input type="hidden" name="$ACTION_1:1" value="[{}]"/>
  <input type="hidden" name="$ACTION_KEY" value="k..."/>
```

If someone submits before hydration, the browser posts this form and the server runs the action and returns a full
page. Once hydrated, React intercepts the submit and calls the action without a page load. The `<form>` element
must render on the server for this to work.

There is a limit, and Gather hits it. The event form sits inside a `<Suspense>` boundary, because the page checks the
user first. Streamed content arrives in a hidden `<div>` and is moved into place by a small inline script (chapter
6). With JavaScript **disabled**, that script never runs: a visitor to `/events/new` sees the heading and the
skeleton, and the form stays hidden in the document. We checked this with a browser that has JavaScript turned off:

```text
visible main: "Create an event"
forms in DOM: 1 visible: 0
```

So progressive enhancement covers the common case, a slow network or a large bundle that hasn't finished loading. It
does not make a streamed, personalised page work for visitors who turn JavaScript off. If that matters for a form,
render it in the static shell and check permissions only in the action.

## What Next.js protects for you

Some protections are built in. Know them, so you know what they *don't* cover:

- **Origin check (CSRF).** Next.js compares the request's `Origin` header with the host. A cross-site request is
  rejected before your code runs. Sending the earlier `curl` request with `-H "Origin: https://evil.example"` makes
  the server log this and refuse:

  ```text
  `x-forwarded-host` header with value `localhost:3000` does not match `origin` header with value `evil.example` from a forwarded Server Actions request. Aborting the action.
  ⨯ Error: Invalid Server Actions request.
  ```

  If your app sits behind a proxy that rewrites the host, list the public domain in
  `experimental.serverActions.allowedOrigins` in `next.config.ts`. A request with *no* `Origin` header at all (curl,
  by default) is let through with a warning, which is why your own checks still matter.
- **Body size limit.** Action requests are capped at 1 MB by default (`experimental.serverActions.bodySizeLimit`).
  Raise it if a form uploads files, and keep it as low as your forms allow.
- **Unguessable IDs and dead code elimination.** Action IDs are long and generated at build time. New builds usually
  get new IDs, and even an unchanged codebase gets new ones at least every 14 days. Server Functions that no client code uses get no ID at all, so they can't be
  called.
- **Encrypted closures**, covered in the editing section.

None of these knows who your users are. Authentication, authorization and validation are always your job.

### "Failed to find Server Action"

Because IDs change between builds, a browser tab that loaded the *previous* deployment can send an action ID the new
server doesn't recognise. The server logs a "Failed to find Server Action" error and the action fails. You can provoke
the same message by sending a made-up ID:

```text
Error: The Server Reference ID did not match the expected format. Received "deadbeef".
Read more: https://nextjs.org/docs/messages/failed-to-find-server-action
```

In practice this happens briefly after each deploy. Reloading the page fixes it; chapter 10 shows how to offer that
in your error UI, and chapter 20 covers deployment strategies that reduce it.

### Preserved pages keep form drafts

With Cache Components, Next.js doesn't unmount a page when you navigate away; it hides it with React's `<Activity>`
and keeps up to three recent routes. Type half a description into `/events/new`, click to another page, and come
back: the draft is still there. It's convenient for users. For tests it means a hidden copy of a page can still be in
the DOM, so end-to-end tests must use visibility-aware selectors (chapter 18).

## How this differs from older Next.js versions

- **`useActionState` replaced `useFormState`** (from `react-dom`) in React 19. It lives in `react` and adds the
  `pending` value.
- **`updateTag` and `refresh` are new in Next.js 16.** Before 16, actions called `revalidateTag(tag)` or
  `revalidatePath`. The one-argument `revalidateTag` is deprecated: use `updateTag` in actions, or pass a profile.
- **Older tutorials call `router.refresh()`** from the client after an action. With `updateTag`, `revalidatePath`
  or `refresh()` in the action, the updated page arrives in the same response, so that extra request is unnecessary.
- **Route Handlers were the old way to mutate** (`fetch('/api/events', { method: 'POST' })` plus client state).
  They're still the right choice for non-React clients (chapter 11), but not for your own forms.

## Key takeaways

- `'use server'` turns a function into a reference that the browser calls with a `POST`; the code stays on the server.
- Every Server Action is a public endpoint. Start each one with authentication, authorization and validation, in that
  order. Checks in pages are for user experience; checks in actions are for security.
- Never trust anything that came from the browser, including bound arguments and hidden fields. Derive identity from
  the session and re-read ownership from the database.
- `useActionState` gives you the action's last result and a `pending` flag; return expected errors (and the user's
  input) as state instead of throwing.
- React resets forms after an action. Return the submitted values to restore them on errors.
- Use `updateTag` for read-your-own-writes, `revalidateTag(tag, 'max')` for background refreshes, `revalidatePath`
  for untagged routes, and `refresh()` for uncached data. The re-rendered page comes back in the same response.
- Invalidate before `redirect()`, because `redirect()` throws.
- `useOptimistic` inside `startTransition` makes interactions instant; the server's answer replaces the guess.
- Progressive enhancement works for forms in the HTML. Streamed content needs JavaScript to appear.

## Exercises

1. **Authorization in practice.** Switch to Grace with
   `document.cookie = 'demo_user=grace@example.com; path=/'` and open the edit page of one of Ada's events. Then
   switch to Linus and open `/events/new`. What happens in each case, and which line of code causes it?

<details>
<summary>Solution</summary>

As Grace, the edit page shows "You can only edit events you organize.", from the `event.organizer.id !== user.id`
check in `EditForm`. The Edit and Delete buttons don't appear on Ada's event pages either, because `isOrganizer` is
false in `RsvpPanel`. Grace *is* an organizer, so she passes `requireOrganizer`; the per-event check is what stops
her.

As Linus (a member), `/events/new` redirects to `/dashboard?error=organizers-only`, from the `role !== 'organizer'`
line in `requireOrganizer`. The same line protects `createEvent`, so even a forged request from Linus can't create an
event.

</details>

2. **Forget a tag.** In `createEvent`, comment out `updateTag('events')`. Rebuild, create an event in a city that
   already exists, and visit `/events?city=<that city>` and the home page. When does the new event show up? Restore
   the line afterwards.

<details>
<summary>Solution</summary>

The event page itself works, because a new slug has never been cached. But the lists keep serving their cached
results: `searchEvents` (behind `/events`) until its `minutes` profile revalidates it (after about a minute, on the
next visit after that, served stale first), and `getUpcomingEvents` on the home page for up to an hour. That is the
bug class tags exist to prevent. The fix is the one line you removed; `updateTag` makes the creator's very next
request wait for fresh data.

</details>

3. **Extract a submit button.** Use the `SubmitButton` from this chapter in `CommentForm` instead of the inline
   button. What must stay in `CommentForm`, and why can't `SubmitButton` just be a function inside `CommentForm` that
   calls `useFormStatus`?

<details>
<summary>Solution</summary>

```tsx
// components/comment-form.tsx (the button part)
import { SubmitButton } from './submit-button'

// ...
      <div>
        <SubmitButton>Post comment</SubmitButton>
      </div>
```

`useActionState` (and with it the `state` for the error and `defaultValue`) stays in `CommentForm`. You could drop
`pending` from the destructuring, as `SubmitButton` now finds out by itself. (Its label says "Saving…" rather than
"Posting…"; pass a `pendingLabel` prop if you care.)

It *can* be a component declared in the same file, but it must be its own component rendered *inside* the
`<form>`. `useFormStatus` reports on the nearest parent `<form>` of the component that calls it. Called directly in
`CommentForm`, which renders the form, there's no parent form, so `pending` would always be `false`.

</details>

4. **Optimistic comments.** Make a new comment appear in the list immediately, greyed out, while it's being saved.
   Hint: the list is rendered by a Server Component, so move the rendering of *pending* comments into
   `CommentForm`, using `useOptimistic` with an array.

<details>
<summary>Solution</summary>

```tsx
// components/comment-form.tsx
'use client'

import { useActionState, useOptimistic } from 'react'
import { addComment, type CommentState } from '@/app/actions/comments'

export function CommentForm({ eventId, slug }: { eventId: number; slug: string }) {
  const [pendingComments, addPending] = useOptimistic<string[], string>([], (list, body) => [
    ...list,
    body,
  ])
  const [state, formAction, pending] = useActionState<CommentState, FormData>(
    async (prev, formData) => {
      addPending(String(formData.get('body') ?? ''))
      return addComment(eventId, slug, prev, formData)
    },
    {},
  )

  return (
    <>
      {pendingComments.map((body, i) => (
        <p key={i} className="muted" aria-busy="true">
          {body} (posting…)
        </p>
      ))}
      <form action={formAction} className="form">
        {/* ...same fields as before... */}
      </form>
    </>
  )
}
```

The function passed to `useActionState` runs inside a transition, so calling `addPending` there is allowed. The
optimistic list is always reset to `[]` when the action finishes. By then the response has re-rendered the server's
comment list with the real comment, so the greyed-out copy is replaced by the saved one with no flicker. If the
action returns an error, the pending copy disappears and the error shows.

</details>

5. **A simple rate limit.** Stop anyone from posting more than 5 comments per minute, across all events. Where does
   the check go, and what should the action return when the limit is hit?

<details>
<summary>Solution</summary>

In `addComment`, after `requireUser` and before the insert, count the user's recent comments:

```ts
const { recent } = db()
  .prepare('SELECT count(*) AS recent FROM comments WHERE user_id = ? AND created_at > ?')
  .get(user.id, new Date(Date.now() - 60_000).toISOString()) as { recent: number }
if (recent >= 5) return { error: 'You’re commenting fast. Try again in a minute.', body }
```

It belongs in the action, because the action is the endpoint a script would hit; a disabled button in the browser
stops nobody. It returns an error *state* (with the body, so the text isn't lost), because hitting the limit is an
expected outcome, not a crash. `created_at` holds ISO 8601 strings like `2026-10-23T09:14:05.120Z` (chapter 6), and
those sort correctly as plain text, so comparing with another ISO string works. Be careful with SQLite's own
`datetime('now', '-1 minute')`: it produces `2026-10-23 09:13:05`, with a space instead of the `T`, and comparing the
two formats as strings gives wrong answers. For limits across many server instances you'd use a
shared store such as Redis; chapter 12 discusses limits at the proxy level.

</details>

---
← Previous: [Cache Components and rendering](07-cache-components-and-rendering.md) | Next: [Navigation and prefetching](09-navigation-and-prefetching.md) →
