# Chapter 2 — React and TypeScript essentials

## What you'll learn

- JSX: how markup and JavaScript mix, and the small ways it differs from HTML
- Components and props, `children`, and composing components
- Rendering lists with keys, and conditional rendering
- Why rendering must be pure
- State with `useState`, event handlers, and updating objects and arrays without mutating them
- Controlled and uncontrolled form inputs
- Effects: what `useEffect` is for, and the many cases where you don't need it
- Refs and context
- The React 19 features Next.js builds on: Suspense, `use`, actions, `useActionState`, `useFormStatus`,
  `useOptimistic` and `useTransition`
- The TypeScript you need to type components, props, events and data

This chapter is a fast, practical course in the React and TypeScript that the rest of the tutorial relies on. If you
already write React with TypeScript every day, skim the headings, read "React 19 features Next.js builds on", and
move on to chapter 3.

## A place to experiment

Interactive examples (state, events, effects) need to run in the browser. In Next.js that means a **Client
Component**: a file that starts with the line `'use client'`. Chapter 4 explains exactly what that line does; for
now, treat it as "this file's components also run in the browser".

Create a playground in your Gather project:

```tsx
// app/playground/page.tsx
import { Playground } from './playground'

export default function PlaygroundPage() {
  return (
    <main>
      <h1>Playground</h1>
      <Playground />
    </main>
  )
}
```

```tsx
// app/playground/playground.tsx
'use client'

export function Playground() {
  return <p>Examples go here.</p>
}
```

Open <http://localhost:3000/playground>. Paste the examples of this chapter into `playground.tsx` (and render them
from `Playground`) to try them. Delete the folder at the end of the chapter; it isn't part of Gather.

## JSX

JSX is a syntax extension that lets you write markup inside JavaScript. The compiler turns every tag into a function
call that creates a plain object describing the element:

```tsx
const heading = <h1 className="title">Gather</h1>
// compiles to roughly:
// const heading = jsx('h1', { className: 'title', children: 'Gather' })
```

So JSX is an *expression*: you can store it in a variable, return it from a function, pass it as an argument, or put
it in an array. Inside JSX, curly braces switch back to JavaScript:

```tsx
const event = { title: 'Next.js Meetup Berlin', city: 'Berlin', going: 42 }

const card = (
  <article>
    <h3>{event.title}</h3>
    <p>
      {event.city} · {event.going} going
    </p>
    <p>{event.going > 40 ? 'Popular!' : 'Spots available'}</p>
  </article>
)
```

Any expression works inside the braces: variables, function calls, ternaries, template literals. Statements (`if`,
`for`, `const`) don't, because braces hold a value, not a block of code.

### How JSX differs from HTML

| HTML | JSX | Why |
|---|---|---|
| `class="card"` | `className="card"` | `class` is a reserved word in JavaScript |
| `for="email"` | `htmlFor="email"` | `for` is a reserved word too |
| `onclick="..."` | `onClick={handleClick}` | Event props are camelCase and take a function, not a string |
| `style="color: red; font-size: 14px"` | `style={{ color: 'red', fontSize: 14 }}` | Style is an object with camelCase keys; numbers mean pixels |
| `<br>`, `<img ...>` | `<br />`, `<img ... />` | Every tag must be closed |
| `tabindex`, `maxlength` | `tabIndex`, `maxLength` | DOM property names, camelCase |
| `<!-- comment -->` | `{/* comment */}` | A comment is a JavaScript comment inside braces |

`aria-*` and `data-*` attributes keep their HTML spelling: `aria-label="Close"`, `data-id="7"`.

A component must return **one** element. To return several siblings without adding a wrapper element to the DOM,
use a **fragment**, written `<>...</>`:

```tsx
function EventMeta() {
  return (
    <>
      <dt>City</dt>
      <dd>Berlin</dd>
    </>
  )
}
```

JSX escapes everything you put inside braces. `<p>{userInput}</p>` with `userInput = '<script>alert(1)</script>'`
displays that text literally; it never runs. This is why React apps are safe from the most common kind of cross-site
scripting by default. The escape hatch, `dangerouslySetInnerHTML`, is named to make you stop and think: only use it
with HTML you have sanitized yourself.

## Components and props

A **component** is a function whose name starts with a capital letter and that returns JSX. **Props** are its
arguments, passed as attributes and received as one object:

```tsx
type EventCardProps = {
  title: string
  city: string
  going: number
  featured?: boolean // optional: may be left out
}

function EventCard({ title, city, going, featured = false }: EventCardProps) {
  return (
    <article className={featured ? 'card featured' : 'card'}>
      <h3>{title}</h3>
      <p>
        {city} · {going} going
      </p>
    </article>
  )
}

// Using it:
<EventCard title="Next.js Meetup Berlin" city="Berlin" going={42} featured />
```

- String props can be written as plain attributes (`city="Berlin"`); everything else goes in braces
  (`going={42}`). A bare attribute (`featured`) means `true`.
- Destructuring in the parameter list, with defaults (`featured = false`), is the normal style.
- The capital letter matters: `<eventCard />` would be treated as an unknown HTML tag.

Props are **read-only**. A component never changes its props; if something needs to change, it's state (below), or
the parent passes different props.

### Passing objects, and spreading

Often it's simpler to pass the whole object:

```tsx
type Event = { id: number; title: string; city: string; going: number }

function EventCard({ event }: { event: Event }) {
  return (
    <article>
      <h3>{event.title}</h3>
      <p>{event.city}</p>
    </article>
  )
}
```

You can also spread an object into props, `<EventCard {...event} />`, which passes each property as a separate prop.
It's convenient but hides what a component receives, so use it sparingly.

### Children

Whatever you put between a component's opening and closing tags arrives as the `children` prop:

```tsx
function Card({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="card">
      <h2>{title}</h2>
      {children}
    </section>
  )
}

<Card title="About">
  <p>Gather lists tech meetups.</p>
  <p>RSVP in one click.</p>
</Card>
```

`React.ReactNode` is the type for "anything React can render": elements, strings, numbers, arrays of those, `null`,
`undefined` and booleans (the last three render nothing). Layouts in Next.js are exactly this pattern: a layout
receives the current page as `children` (chapter 3).

### Composition

React has no inheritance between components. You build bigger components by putting smaller ones inside each other,
and you make components flexible by passing them other components or elements as props:

```tsx
function PageHeader({ title, actions }: { title: string; actions?: React.ReactNode }) {
  return (
    <header className="page-header">
      <h1>{title}</h1>
      {actions && <div className="actions">{actions}</div>}
    </header>
  )
}

<PageHeader title="Your events" actions={<a href="/events/new">New event</a>} />
```

This "pass JSX as a prop" pattern becomes important in chapter 4, where it lets a server-only component sit inside
an interactive one.

## Lists and keys

To render a list, map an array to an array of elements:

```tsx
function EventList({ events }: { events: Event[] }) {
  return (
    <ul>
      {events.map((event) => (
        <li key={event.id}>{event.title}</li>
      ))}
    </ul>
  )
}
```

Each element in a list needs a **`key`** that is unique among its siblings and stable over time. React uses keys to
match items between renders: when an item is added, removed or moved, the key tells React which DOM node (and which
state) belongs to which item. Use the item's ID from the database. Don't use the array index if the list can be
reordered or filtered, because then the "same" key points at a different item after a change, and state (an open
menu, text typed into an input) jumps to the wrong row. React warns in the console when a key is missing.

`key` is special: it is not passed to the component as a prop.

## Conditional rendering

Since JSX is an expression, conditions are ordinary JavaScript:

```tsx
function Spots({ capacity, going }: { capacity: number; going: number }) {
  const left = capacity - going

  // An early return for a whole different output
  if (left <= 0) return <span className="full">Full</span>

  return (
    <span>
      {left} {left === 1 ? 'spot' : 'spots'} left
      {left < 5 && <strong> — almost full!</strong>}
    </span>
  )
}
```

- `condition ? a : b` chooses between two outputs.
- `condition && a` renders `a` or nothing. Watch out for numbers on the left: `{count && <p>...</p>}` renders `0`
  when `count` is 0, because `0` is a value React renders. Write `{count > 0 && ...}` instead.
- Returning `null` renders nothing at all.

## Rendering must be pure

React calls your component functions whenever it needs to know what the UI should look like: on first render,
after state changes, when a parent re-renders, and sometimes more often than you expect (in development, Strict Mode
deliberately calls them twice to catch mistakes). So a component must behave like a **pure function** of its props
and state:

- Same inputs → same JSX.
- No side effects *during* rendering: don't change variables outside the component, don't write to `localStorage`,
  don't start network requests, don't mutate props.

Side effects belong in event handlers (when the user does something) or, if they must happen because of rendering,
in effects (below). Server Components on the server are the one exception to "no data loading during render": they
are allowed to be `async` and await data, because they render once per request on the server (chapter 6).

## State and events

**State** is data that a component remembers between renders and that changes over time, usually because of user
input. `useState` returns the current value and a function to change it:

```tsx
'use client'

import { useState } from 'react'

export function LikeButton() {
  const [likes, setLikes] = useState(0)

  return <button onClick={() => setLikes(likes + 1)}>♥ {likes}</button>
}
```

Calling `setLikes` doesn't change `likes` in the current render. It asks React to render the component again, and
*in that next render* `useState` returns the new value. Two consequences:

```tsx
function handleClick() {
  setLikes(likes + 1)
  setLikes(likes + 1)
  console.log(likes) // still the old value
}
// After the click, likes went up by 1, not 2: both calls computed "old + 1".
```

When the new state depends on the old one, pass an **updater function**. React calls it with the latest value:

```tsx
function handleClick() {
  setLikes((n) => n + 1)
  setLikes((n) => n + 1) // now +2
}
```

React batches state updates from the same event and re-renders once.

### Hooks rules

`useState` is a **hook**: a function starting with `use` that lets a component use a React feature. Hooks have two
rules, which ESLint checks for you:

1. Call hooks only at the top level of a component (or another hook), never inside conditions, loops or nested
   functions. React identifies each hook by the order of the calls.
2. Call hooks only from components and custom hooks, never from ordinary functions.

### Event handlers

Event props take a function. React passes it a synthetic event object that wraps the browser event and works the
same in every browser:

```tsx
function SearchBox() {
  const [query, setQuery] = useState('')

  function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault() // stop the browser from reloading the page
    console.log('Searching for', query)
  }

  return (
    <form onSubmit={handleSubmit}>
      <input value={query} onChange={(event) => setQuery(event.target.value)} />
      <button>Search</button>
    </form>
  )
}
```

Pass the function, don't call it: `onClick={handleClick}`, not `onClick={handleClick()}` (that would call it during
render). Use an arrow function when you need arguments: `onClick={() => remove(event.id)}`.

### Objects and arrays in state: never mutate

React decides whether to re-render by comparing the old and new state with `Object.is`. If you change an object in
place and set the same object again, nothing appears to happen. Always create a new object or array:

```tsx
const [filters, setFilters] = useState({ city: 'Berlin', onlyFree: false })

// ✗ mutates the existing object; React sees the same object and may skip the render
filters.city = 'Lisbon'
setFilters(filters)

// ✓ a new object with one property changed
setFilters({ ...filters, city: 'Lisbon' })
```

The same goes for arrays. Use the methods that return a new array:

| Instead of | Use |
|---|---|
| `items.push(x)` | `[...items, x]` |
| `items.splice(i, 1)` | `items.filter((item) => item.id !== id)` |
| `items[i] = x` | `items.map((item) => (item.id === id ? x : item))` |
| `items.sort()` | `items.toSorted()` (or `[...items].sort()`) |

### Where state lives: lifting it up

State belongs to the closest common parent of the components that need it. If a search box and a results list both
need the query, the query lives in their parent, which passes the value down to both and a setter down to the box:

```tsx
function EventSearch({ events }: { events: Event[] }) {
  const [query, setQuery] = useState('')
  const visible = events.filter((e) => e.title.toLowerCase().includes(query.toLowerCase()))

  return (
    <>
      <input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search" />
      <EventList events={visible} />
    </>
  )
}
```

Notice that `visible` is not state. It's **derived** from state and props, so it is computed during render. Storing
derived values in state is one of the most common React mistakes: the copies drift out of sync.

### Controlled and uncontrolled inputs

The `input` above is **controlled**: its value comes from state (`value={query}`) and every keystroke goes through
`onChange`. React is the single source of truth, which you need when you react to every keystroke (live filtering,
formatting as you type).

An **uncontrolled** input keeps its own value in the DOM. You give it an initial value with `defaultValue` and read
it when the form is submitted:

```tsx
<form action={handleSubmit}>
  <input name="title" defaultValue="Untitled event" />
  <button>Save</button>
</form>
```

Uncontrolled inputs are simpler and, combined with form actions (later in this chapter and in chapter 8), they are
the normal choice in Next.js forms: the browser collects the values into a `FormData` object for you.

## Effects

An **effect** runs code *after* React has updated the screen, to synchronize a component with something outside
React: a browser API, a timer, a WebSocket, a third-party widget. This search box lets the user press `/` anywhere on
the page to jump into it (`useRef`, which points at the input element, is explained in the next section):

```tsx
'use client'

import { useEffect, useRef } from 'react'

export function SearchBox() {
  const inputRef = useRef<HTMLInputElement>(null)

  useEffect(() => {
    function onKeyDown(event: KeyboardEvent) {
      if (event.key === '/' && document.activeElement !== inputRef.current) {
        event.preventDefault()
        inputRef.current?.focus()
      }
    }
    window.addEventListener('keydown', onKeyDown)
    // The cleanup function undoes the setup.
    return () => window.removeEventListener('keydown', onKeyDown)
  }, []) // dependencies: [] means "after the first render only"

  return <input ref={inputRef} placeholder="Search events (press /)" />
}
```

- The **dependency array** lists the props and state the effect uses. React re-runs the effect (cleanup first) when
  one of them changes. Leave it out and the effect runs after every render. ESLint's `react-hooks/exhaustive-deps`
  rule tells you when the list is wrong.
- Don't call a state setter directly in the body of an effect (`useEffect(() => setX(...), [])`). It renders the
  component twice for nothing, and the `react-hooks/set-state-in-effect` lint rule that Next.js enables reports it.
  Effects should talk to the outside world, and set state only from callbacks (an event listener, a timer, a
  response).
- In development, React's Strict Mode mounts every component, unmounts it and mounts it again, so you see setup →
  cleanup → setup. This is deliberate: if your effect breaks under it, it would break in real situations too (a user
  navigating away and back). Next.js enables Strict Mode by default in the App Router.
- Effects never run on the server. Code that touches `window` or `document` is safe inside an effect.

### You might not need an effect

In a Next.js app you will write very few effects. Most uses people reach for have better tools:

| You want to… | Instead of an effect, use |
|---|---|
| Load data for a page | An async Server Component (chapter 6) |
| Compute a value from props or state | A plain variable during render |
| Reset state when a prop changes | A `key` on the component, so React creates a new instance |
| Respond to a click or a submit | The event handler or the form action itself |
| Send data to the server | A Server Action (chapter 8) |
| Read the URL | `useSearchParams` / `usePathname` (chapter 9), or `searchParams` in a page |
| Subscribe to a browser value (online status, window size, media queries) | `useSyncExternalStore` |

`useSyncExternalStore` deserves a short example, because it is the right tool for reading browser state and it also
handles server rendering correctly:

```tsx
'use client'

import { useSyncExternalStore } from 'react'

function subscribe(onChange: () => void) {
  window.addEventListener('online', onChange)
  window.addEventListener('offline', onChange)
  return () => {
    window.removeEventListener('online', onChange)
    window.removeEventListener('offline', onChange)
  }
}

export function OnlineStatus() {
  const online = useSyncExternalStore(
    subscribe,
    () => navigator.onLine, // the current value in the browser
    () => true, // the value to use on the server and during hydration
  )
  return <p>{online ? 'Online' : 'Offline'}</p>
}
```

React calls `subscribe` once, re-renders whenever the callback fires, and reads the value with the second function.
The third function provides a value for the server, where there is no `navigator` (chapter 4 explains why that
matters).

## Refs

A **ref** holds a value that survives re-renders but doesn't trigger one when it changes. Its most common use is
pointing at a DOM element:

```tsx
'use client'

import { useRef } from 'react'

export function CommentBox() {
  const inputRef = useRef<HTMLTextAreaElement>(null)

  return (
    <>
      <textarea ref={inputRef} />
      <button onClick={() => inputRef.current?.focus()}>Write a comment</button>
    </>
  )
}
```

`inputRef.current` is `null` during the first render and the DOM element after React has attached it. Since React
19, `ref` is an ordinary prop for your own components too: a component can accept `ref` and pass it to an element,
without the old `forwardRef` wrapper.

## Context

Passing a value down through many levels of components ("prop drilling") gets tedious. **Context** lets a component
provide a value that any descendant can read:

```tsx
'use client'

import { createContext, use, useState } from 'react'

type Theme = 'light' | 'dark'
const ThemeContext = createContext<Theme>('light')

export function ThemeProvider({ children }: { children: React.ReactNode }) {
  const [theme, setTheme] = useState<Theme>('light')
  return (
    <ThemeContext value={theme}>
      <button onClick={() => setTheme(theme === 'light' ? 'dark' : 'light')}>Toggle theme</button>
      {children}
    </ThemeContext>
  )
}

export function ThemedBadge() {
  const theme = use(ThemeContext) // or useContext(ThemeContext)
  return <span className={`badge ${theme}`}>{theme}</span>
}
```

Since React 19 you render the context itself as the provider (`<ThemeContext value={...}>`) instead of
`<ThemeContext.Provider>`, and you can read it with `use`. Context is for values that many components need and that
change rarely: the theme, the current locale, the logged-in user in a client-side widget. It is not a replacement for
passing props between nearby components. In Next.js, context only works in Client Components; chapter 4 shows how
to place a provider so the rest of your app stays on the server.

## React 19 features Next.js builds on

Next.js 16.4 uses React 19.3. The features below are what make the App Router work. You will use each of them in
Gather, so here is a first look at what they do.

### Suspense

`<Suspense>` shows a fallback while something inside it is not ready yet:

```tsx
<Suspense fallback={<p>Loading comments…</p>}>
  <Comments eventId={7} />
</Suspense>
```

"Not ready" means a component is waiting for data: an async Server Component that is still awaiting a database query,
or a component reading a promise with `use`. React shows the fallback, and replaces it with the real content when
the data arrives. On the server, Next.js streams the fallback in the HTML first and the real content later, in the
same response. Suspense is the foundation of streaming (chapter 6) and of the static shell (chapter 7).

### `use`

`use` reads the value of a promise (or a context). If the promise hasn't resolved, the component *suspends*: React
shows the nearest Suspense fallback and tries again when the promise resolves.

```tsx
'use client'

import { use } from 'react'

export function AttendeeCount({ countPromise }: { countPromise: Promise<number> }) {
  const count = use(countPromise)
  return <p>{count} going</p>
}
```

Unlike other hooks, `use` can be called inside conditions. Don't create the promise inside the same Client Component
(a new promise on every render would suspend forever); in Next.js, a Server Component starts the work and passes the
promise down (chapter 6).

### Actions and form actions

In React 19, a form's `action` prop can be a **function**. When the form is submitted, React calls it with the form's
data as a `FormData` object:

```tsx
function NewsletterForm() {
  async function subscribe(formData: FormData) {
    const email = formData.get('email')
    await fetch('/api/subscribe', { method: 'POST', body: JSON.stringify({ email }) })
  }

  return (
    <form action={subscribe}>
      <input name="email" type="email" required />
      <button>Subscribe</button>
    </form>
  )
}
```

No `preventDefault`, no `useState` for every field. React calls the function inside a **transition** (below), and
after a successful action it resets the form's uncontrolled fields. In Next.js, the function is usually a **Server
Action**: a function that runs on the server, which the form calls over the network for you (chapter 8).

### `useActionState`

`useActionState` wraps an action and keeps the value it returned last, which is how a form shows validation errors
or a success message:

```tsx
'use client'

import { useActionState } from 'react'

type State = { error?: string; message?: string }

async function joinWaitlist(previous: State, formData: FormData): Promise<State> {
  const email = String(formData.get('email') ?? '')
  if (!email.includes('@')) return { error: 'Enter a valid email address.' }
  await new Promise((resolve) => setTimeout(resolve, 500)) // pretend to save it
  return { message: `We'll email ${email} when a spot opens.` }
}

export function WaitlistForm() {
  const [state, formAction, isPending] = useActionState(joinWaitlist, {})

  return (
    <form action={formAction}>
      <input name="email" />
      <button disabled={isPending}>{isPending ? 'Joining…' : 'Join waitlist'}</button>
      {state.error && <p className="error">{state.error}</p>}
      {state.message && <p>{state.message}</p>}
    </form>
  )
}
```

The action receives the previous state as its first argument and the form data as its second. `isPending` is `true`
while it runs.

### `useFormStatus`

A component *inside* a form can ask whether that form is submitting, without props:

```tsx
'use client'

import { useFormStatus } from 'react-dom'

export function SubmitButton({ children }: { children: React.ReactNode }) {
  const { pending } = useFormStatus()
  return <button disabled={pending}>{pending ? 'Saving…' : children}</button>
}
```

Note that it comes from `react-dom`, not `react`, and that it only works in a component rendered inside the
`<form>`, not in the component that renders the form.

### `useTransition`

A **transition** marks a state update as non-urgent. React keeps the current UI responsive and shows the update when
it's ready. `startTransition` also accepts an async function, which turns it into an action you can start from any
event, not just a form:

```tsx
const [isPending, startTransition] = useTransition()

function onClick() {
  startTransition(async () => {
    await saveRsvp()
    setGoing(true)
  })
}
```

`isPending` is `true` until the async function finishes. Next.js uses transitions for navigation: clicking a link
starts a transition, so the old page stays interactive until the new one is ready.

### `useOptimistic`

`useOptimistic` shows the result you *expect* while an action is still running, and falls back to the real state
when the action ends:

```tsx
'use client'

import { useOptimistic, useState, useTransition } from 'react'

export function RsvpToggle({ save }: { save: (going: boolean) => Promise<boolean> }) {
  const [going, setGoing] = useState(false)
  const [optimisticGoing, setOptimisticGoing] = useOptimistic(going)
  const [, startTransition] = useTransition()

  function onClick() {
    startTransition(async () => {
      setOptimisticGoing(!going) // shown immediately
      const confirmed = await save(!going) // the real result from the server
      setGoing(confirmed)
    })
  }

  return <button onClick={onClick}>{optimisticGoing ? '✓ Going' : 'RSVP'}</button>
}
```

If `save` fails or returns the old value, the button snaps back on its own. Gather's real RSVP button (chapter 8)
works exactly like this.

### Memoization and the React Compiler

You will see `useMemo`, `useCallback` and `memo` in React code. They cache a computed value, a function or a
component's output between renders, to skip work when inputs haven't changed. In a Next.js app most components run
on the server, where they render once per request, so you need these much less. The **React Compiler** (offered by
`create-next-app`, and enabled with `reactCompiler: true` in `next.config.ts`) adds this memoization automatically at
build time. Gather doesn't need either, so this tutorial won't use them.

## The TypeScript you need

Next.js projects are TypeScript by default, and Next.js generates types for your routes. You don't need advanced
TypeScript, but you need to be fluent with the following.

### Types for data

```ts
// An object type
type EventSummary = {
  id: number
  slug: string
  title: string
  startsAt: string // ISO 8601 date-time
  capacity: number
  going: number
}

// Extending a type with &
type EventDetail = EventSummary & {
  description: string
  organizer: { id: number; name: string }
}

// A union of string literals: only these values are allowed
type Role = 'member' | 'organizer'

// Optional properties and readonly arrays
type SearchFilters = {
  q?: string
  city?: string
  tags: readonly string[]
}
```

`interface EventSummary { ... }` works too; for the shapes in this tutorial, `type` and `interface` are
interchangeable. This tutorial uses `type`.

### Typing components

Props are an object type. `children` is `React.ReactNode`. The return type is inferred, so you don't write it:

```tsx
type ButtonProps = {
  variant?: 'primary' | 'secondary'
  children: React.ReactNode
} & React.ComponentProps<'button'> // also accept every normal <button> prop

export function Button({ variant = 'primary', children, ...rest }: ButtonProps) {
  return (
    <button className={`button ${variant}`} {...rest}>
      {children}
    </button>
  )
}
```

`React.ComponentProps<'button'>` is the type of all the props `<button>` accepts (`onClick`, `disabled`, `type`,
`aria-*`, …). It's the easiest way to write a component that wraps an HTML element.

The `React` namespace in `React.ReactNode` is available as a global type in Next.js projects, without importing
React. You can also import types explicitly, which this tutorial does when it makes code clearer:

```ts
import type { ReactNode } from 'react'
```

`import type` imports only the type. It is erased at compile time, so it never pulls code into a bundle.

### Typing events and hooks

```tsx
const [query, setQuery] = useState('') // inferred: string
const [user, setUser] = useState<{ name: string } | null>(null) // explicit when the initial value is too narrow
const inputRef = useRef<HTMLInputElement>(null)

function onChange(event: React.ChangeEvent<HTMLInputElement>) {
  setQuery(event.target.value)
}

function onSubmit(event: React.FormEvent<HTMLFormElement>) {
  event.preventDefault()
}
```

When you don't know an event's type, write the handler inline (`onChange={(e) => ...}`) and hover over `e` in your
editor.

### Narrowing

TypeScript follows your `if` checks and narrows types. You use this constantly with values that may be missing:

```ts
function greet(user: { name: string } | null) {
  if (!user) return 'Hello, stranger'
  return `Hello, ${user.name}` // user is { name: string } here
}

// Discriminated unions: a common shape for results
type Result = { ok: true; data: EventSummary } | { ok: false; error: string }

function show(result: Result) {
  if (result.ok) {
    console.log(result.data.title) // TypeScript knows data exists
  } else {
    console.log(result.error)
  }
}
```

`FormData.get()` returns `FormDataEntryValue | null` (a string, a `File`, or `null`), so form handling code narrows
or converts: `String(formData.get('title') ?? '')`.

### Promises and async functions

```ts
async function getEvent(slug: string): Promise<EventDetail | null> {
  // ...
}

type Params = Promise<{ slug: string }> // Next.js passes route params as a promise (chapter 3)
const { slug } = await params
```

An `async` function always returns a `Promise`. `Awaited<T>` is the type a promise resolves to:
`Awaited<ReturnType<typeof getEvent>>` is `EventDetail | null`.

### `as const` and `satisfies`

```ts
const cities = ['Berlin', 'Lisbon', 'Nairobi'] as const
type City = (typeof cities)[number] // 'Berlin' | 'Lisbon' | 'Nairobi'

const limits = {
  title: 80,
  description: 2000,
} satisfies Record<string, number> // checked against the type, but keeps its exact keys
```

`as const` makes a literal deeply readonly and keeps literal types. `satisfies` checks a value against a type without
widening it. You'll see `satisfies` in Next.js configuration and in metadata files.

### What not to do

- Don't silence errors with `any`. Use `unknown` for values you haven't checked yet (like JSON from a request), and
  narrow it.
- Don't use the non-null assertion `!` to make an error go away unless you are certain the value exists.
- Don't type what TypeScript can infer: `const count: number = 0` adds nothing.

## Key takeaways

- JSX is JavaScript: tags become objects, braces hold expressions, and attribute names follow DOM properties
  (`className`, `htmlFor`, camelCase events).
- Components are functions of their props. `children` and "JSX as a prop" are how you compose them. Rendering must be
  pure: same inputs, same output, no side effects.
- Lists need stable, unique `key`s. Watch out for `0 && ...`.
- State changes trigger a re-render; the new value appears in the next render. Use updater functions for state that
  depends on itself, and never mutate objects or arrays in state. Compute derived values during render.
- Effects synchronize with things outside React and never run on the server. In Next.js you rarely need them: data
  loading belongs in Server Components and mutations in Server Actions.
- React 19 gives Next.js its building blocks: Suspense, `use`, function form actions, `useActionState`,
  `useFormStatus`, `useTransition` and `useOptimistic`.
- Type props as object types, `children` as `React.ReactNode`, HTML-wrapping components with
  `React.ComponentProps<'tag'>`, and let TypeScript infer the rest. Prefer `unknown` and narrowing over `any`.

## Exercises

1. **A list with a count.** Write an `EventList` component that takes `events: { id: number; title: string; going:
   number }[]` and renders a `<ul>` with one `<li>` per event, plus a line "N events" above the list, where the line
   reads "1 event" for one event and "No events" for an empty array.

<details>
<summary>Solution</summary>

```tsx
type Item = { id: number; title: string; going: number }

export function EventList({ events }: { events: Item[] }) {
  const label =
    events.length === 0 ? 'No events' : events.length === 1 ? '1 event' : `${events.length} events`

  return (
    <>
      <p>{label}</p>
      {events.length > 0 && (
        <ul>
          {events.map((event) => (
            <li key={event.id}>
              {event.title} ({event.going} going)
            </li>
          ))}
        </ul>
      )}
    </>
  )
}
```

`events.length > 0 &&` rather than `events.length &&`, which would render a stray `0` for an empty list.

</details>

2. **Fix the bug.** This component is supposed to add a tag when the button is clicked, but the list never updates.
   Explain why and fix it.

```tsx
'use client'
import { useState } from 'react'

export function Tags() {
  const [tags, setTags] = useState(['react'])
  return (
    <>
      <button onClick={() => { tags.push('nextjs'); setTags(tags) }}>Add</button>
      <ul>{tags.map((t) => <li key={t}>{t}</li>)}</ul>
    </>
  )
}
```

<details>
<summary>Solution</summary>

`tags.push` mutates the existing array, and `setTags(tags)` passes the same array back. React compares the old and
new state with `Object.is`, sees the same reference and skips the re-render. Create a new array instead:

```tsx
<button onClick={() => setTags((current) => [...current, 'nextjs'])}>Add</button>
```

There's a second, hidden bug: clicking twice would add `'nextjs'` twice, and two siblings with the key `nextjs`
make React warn about duplicate keys. Either prevent duplicates
(`current.includes('nextjs') ? current : [...current, 'nextjs']`) or give each tag an ID.

</details>

3. **Derived state.** A colleague wrote a search box that keeps both `query` and `results` in state and updates
   `results` in a `useEffect` whenever `query` changes. Rewrite it without the effect and without the second piece
   of state. What problems does your version avoid?

<details>
<summary>Solution</summary>

```tsx
'use client'
import { useState } from 'react'

export function EventSearch({ events }: { events: { id: number; title: string }[] }) {
  const [query, setQuery] = useState('')
  const results = events.filter((event) =>
    event.title.toLowerCase().includes(query.trim().toLowerCase()),
  )

  return (
    <>
      <input value={query} onChange={(e) => setQuery(e.target.value)} />
      <ul>
        {results.map((event) => (
          <li key={event.id}>{event.title}</li>
        ))}
      </ul>
    </>
  )
}
```

`results` is derived from `query` and `events`, so it is computed during render. The effect version renders twice
per keystroke (once with stale results, once after the effect sets the new ones), shows stale results for a frame,
and goes out of sync if `events` changes but `query` doesn't (unless the dependency array lists both).

</details>

4. **A form with `useActionState`.** Build a "Suggest a city" form with one text input. The action should reject
   names shorter than 2 characters with an error message, and otherwise return a message "Thanks! We'll look into
   <city>." The button should say "Sending…" while the action runs (simulate a 700 ms delay), and should be a separate
   `SubmitButton` component that uses `useFormStatus`.

<details>
<summary>Solution</summary>

```tsx
'use client'

import { useActionState } from 'react'
import { useFormStatus } from 'react-dom'

type State = { error?: string; message?: string }

async function suggestCity(_previous: State, formData: FormData): Promise<State> {
  const city = String(formData.get('city') ?? '').trim()
  if (city.length < 2) return { error: 'Enter at least 2 characters.' }
  await new Promise((resolve) => setTimeout(resolve, 700))
  return { message: `Thanks! We'll look into ${city}.` }
}

function SubmitButton() {
  const { pending } = useFormStatus()
  return <button disabled={pending}>{pending ? 'Sending…' : 'Suggest'}</button>
}

export function SuggestCityForm() {
  const [state, formAction] = useActionState(suggestCity, {})
  return (
    <form action={formAction}>
      <label>
        City <input name="city" />
      </label>
      <SubmitButton />
      {state.error && <p role="alert">{state.error}</p>}
      {state.message && <p>{state.message}</p>}
    </form>
  )
}
```

`SubmitButton` must be a separate component rendered inside the `<form>`: `useFormStatus` reads the status of the
form that *contains* the component calling it. Notice that after a successful submit the input is empty again: React
resets uncontrolled fields after an action completes. Chapter 8 shows how to keep the user's input when validation
fails.

</details>

5. **Type a wrapper component.** Write a `TextField` component that renders a `<label>` containing a text and an
   `<input>`, and accepts a `label: string`, an optional `error?: string`, and every prop a normal `<input>` accepts.
   It should mark the input `aria-invalid` when there is an error.

<details>
<summary>Solution</summary>

```tsx
type TextFieldProps = { label: string; error?: string } & React.ComponentProps<'input'>

export function TextField({ label, error, ...inputProps }: TextFieldProps) {
  return (
    <label>
      {label}
      <input {...inputProps} aria-invalid={error ? true : undefined} />
      {error && <span className="error">{error}</span>}
    </label>
  )
}

// <TextField label="Title" name="title" required maxLength={80} error={errors.title} />
```

Spreading `inputProps` forwards `name`, `required`, `defaultValue`, `ref` (a normal prop since React 19) and everything
else. `aria-invalid` is set only when there's an error. In a real form, also connect the error to the input with
`aria-describedby` so screen readers announce it.

</details>

---
← Previous: [Getting started](01-getting-started.md) | Next: [Routing: pages and layouts](03-routing-layouts-and-pages.md) →
