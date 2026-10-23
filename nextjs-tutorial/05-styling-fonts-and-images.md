# Chapter 5 — Styling, fonts and images

## What you'll learn

- Global CSS, CSS custom properties and dark mode
- CSS Modules: locally scoped class names, and how Next.js turns them into unique classes
- How Tailwind CSS is set up in a Next.js 16.4 project, and the other styling options (Sass, CSS-in-JS)
- How CSS is ordered, chunked and loaded
- `next/font`: self-hosted Google and local fonts without layout shift
- `next/image`: responsive, lazy-loaded, optimized images, `sizes`, placeholders and priority
- Remote images and the security settings of the image optimizer
- The `public/` folder

By the end of the chapter Gather looks like a real site: a header, a hero with an illustration, event cards in a
responsive grid, and a styled event page that follows the system's light or dark mode.

## Global CSS

`app/globals.css` is imported once, in the root layout, so its rules apply to every page. Use it for things that are
truly global: CSS custom properties (design tokens), the base styles of elements, and a few shared utility classes.
Replace its content with Gather's global styles:

```css
/* app/globals.css */
:root {
  --bg: #ffffff;
  --fg: #1b1b1f;
  --muted: #5d5d6b;
  --border: #e4e4ea;
  --card: #f7f7fa;
  --accent: #5b3df5;
  --accent-fg: #ffffff;
  --danger: #c62828;
  --radius: 10px;
  --font-sans: var(--font-geist-sans), system-ui, sans-serif;
}

@media (prefers-color-scheme: dark) {
  :root {
    --bg: #111114;
    --fg: #ececf1;
    --muted: #a0a0ad;
    --border: #2b2b33;
    --card: #1a1a20;
    --accent: #8b74ff;
  }
  html {
    color-scheme: dark;
  }
}

*,
*::before,
*::after {
  box-sizing: border-box;
}

body {
  margin: 0;
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  background: var(--bg);
  color: var(--fg);
  font-family: var(--font-sans);
  line-height: 1.5;
}

main {
  flex: 1;
  width: 100%;
  max-width: 960px;
  margin: 0 auto;
  padding: 2rem 1rem 4rem;
}

a {
  color: var(--accent);
}

h1,
h2,
h3 {
  line-height: 1.2;
}

/* Small shared building blocks; component-specific styles live in CSS Modules. */
.button {
  display: inline-block;
  padding: 0.55rem 1rem;
  border: 1px solid transparent;
  border-radius: var(--radius);
  background: var(--accent);
  color: var(--accent-fg);
  font: inherit;
  font-weight: 600;
  text-decoration: none;
  white-space: nowrap;
  cursor: pointer;
}

.button[disabled] {
  opacity: 0.6;
  cursor: progress;
}

.button.secondary {
  background: transparent;
  color: var(--fg);
  border-color: var(--border);
}

.button.danger {
  background: var(--danger);
}

.muted {
  color: var(--muted);
}

.error {
  color: var(--danger);
  font-size: 0.9rem;
}

.skeleton {
  border-radius: var(--radius);
  background: var(--card);
  animation: pulse 1.2s ease-in-out infinite;
}

@keyframes pulse {
  50% {
    opacity: 0.5;
  }
}

.form {
  display: grid;
  gap: 1rem;
  max-width: 560px;
}

.form label {
  display: grid;
  gap: 0.3rem;
  font-weight: 600;
}

.form input,
.form textarea,
.form select {
  padding: 0.55rem 0.7rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg);
  color: var(--fg);
  font: inherit;
  font-weight: 400;
}
```

A few design decisions worth noticing:

- **Design tokens as custom properties.** Colors, the radius and the font are variables on `:root`. Every other rule
  uses `var(--accent)` and friends, so a theme change is a change in one place.
- **Dark mode with one media query.** The `prefers-color-scheme: dark` block only redefines the variables.
  `color-scheme: dark` tells the browser to use dark scrollbars and form controls too.
- **A sticky footer with flexbox.** `body` is a column flex container and `main` has `flex: 1`, so the footer sits at
  the bottom on short pages.
- **Only a few global classes.** `.button`, `.muted`, `.error`, `.skeleton` and `.form` are used by many components,
  so they are global. Everything specific to one component goes in a CSS Module.

Global CSS can only be imported from files inside `app/` and component files, but it applies to the whole document
once loaded. In the App Router, a global stylesheet that one page imports is **not removed** when you navigate away
from that page, so global rules from different pages can collide. Keep global CSS in the root layout.

## CSS Modules

A **CSS Module** is a CSS file whose name ends in `.module.css`. When you import it, you get an object that maps the
class names in the file to unique, generated class names:

```css
/* components/event-card.module.css */
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 1rem;
}

.card {
  padding: 1rem 1.1rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--card);
}

.date {
  margin: 0;
  color: var(--accent);
  font-size: 0.85rem;
  font-weight: 600;
  text-transform: uppercase;
}

.title {
  margin: 0.35rem 0;
  font-size: 1.15rem;
}

.title a {
  color: var(--fg);
  text-decoration: none;
}

.title a:hover {
  text-decoration: underline;
}

.meta {
  margin: 0.5rem 0 0;
  font-size: 0.9rem;
}

.skeletonCard {
  height: 150px;
}
```

```tsx
// components/event-card.tsx
import Link from 'next/link'
import type { EventSummary } from '@/lib/sample-events'
import { formatEventDate, spotsLeft } from '@/lib/format'
import styles from './event-card.module.css'

export function EventCard({ event }: { event: EventSummary }) {
  return (
    <article className={styles.card}>
      <p className={styles.date}>{formatEventDate(event.startsAt)}</p>
      <h3 className={styles.title}>
        <Link href={`/events/${event.slug}`}>{event.title}</Link>
      </h3>
      <p className="muted">
        {event.venue}, {event.city}
      </p>
      <p className={styles.meta}>
        {event.going} going · {spotsLeft(event.capacity, event.going)}
      </p>
    </article>
  )
}

export function EventGrid({ events }: { events: EventSummary[] }) {
  if (events.length === 0) return <p className="muted">No events found.</p>
  return (
    <div className={styles.grid}>
      {events.map((event) => (
        <EventCard key={event.id} event={event} />
      ))}
    </div>
  )
}
```

The cards show dates and remaining spots through two small helpers that both the server and (later) Client Components
use. They format every date in UTC, so the server and every browser produce the same text (chapter 4 explained why
that matters for hydration):

```ts
// lib/format.ts
// Formatting helpers shared by Server and Client Components. Times are
// stored and shown in UTC, so the server and the browser always render the
// same text (no hydration mismatch from different time zones).

const dateTime = new Intl.DateTimeFormat('en-GB', {
  weekday: 'short',
  day: 'numeric',
  month: 'short',
  hour: '2-digit',
  minute: '2-digit',
  timeZone: 'UTC',
})

export function formatEventDate(iso: string): string {
  return `${dateTime.format(new Date(iso))} UTC`
}

export function spotsLeft(capacity: number, going: number): string {
  const left = capacity - going
  if (left <= 0) return 'Full'
  return left === 1 ? '1 spot left' : `${left} spots left`
}
```

Two cards can both use `.title` and `.date`, and a different module can define its own `.title` without any
conflict, because the class names in the browser are generated. Look at the HTML of the home page in production and
you will find classes like this:

```html
<img ... class="E0kJGG_heroImage" ...>
```

Next.js 16.4 generates short class names in production builds (a prefix derived from the file plus your class name);
in development they are longer and easier to trace back to the file. Never depend on the generated names: in tests,
select elements by role or text (chapter 18), not by class.

Rules of thumb for CSS Modules:

- **One module per component**, named after it (`event-card.tsx` + `event-card.module.css`), next to the component.
- **Class selectors only** at the top level. A rule like `h1 { ... }` in a module isn't scoped and affects the whole
  page (Next.js rejects "pure" element selectors in modules for that reason). Nest element selectors under a class
  instead: `.event h1 { ... }`.
- Use camelCase class names (`.skeletonCard`) so you can write `styles.skeletonCard` instead of `styles['skeleton-card']`.
- Combine a module class with a global one in a template string: ``className={`skeleton ${styles.skeletonCard}`}``.
  For complex conditional classes, the tiny `clsx` package helps:
  `className={clsx(styles.link, active && styles.active)}`.
- Custom properties from `globals.css` work inside modules, since they are inherited through the DOM.

### Styling the rest of Gather

The header gets its own module and a small `NavLink` component for the menu links. (Chapter 9 turns `NavLink` into a
Client Component that highlights the current page.)

```css
/* components/site-header.module.css */
.header {
  border-bottom: 1px solid var(--border);
}

.nav {
  display: flex;
  align-items: center;
  gap: 1.25rem;
  max-width: 960px;
  margin: 0 auto;
  padding: 0.9rem 1rem;
}

.logo {
  font-size: 1.25rem;
  font-weight: 800;
  color: var(--fg);
  text-decoration: none;
}

.spacer {
  flex: 1;
}
```

```css
/* components/nav-link.module.css */
.link {
  color: var(--muted);
  text-decoration: none;
  font-weight: 500;
}

.link:hover {
  color: var(--fg);
}

.active {
  color: var(--fg);
  text-decoration: underline;
  text-underline-offset: 6px;
}
```

```tsx
// components/nav-link.tsx
import Link from 'next/link'
import styles from './nav-link.module.css'

export function NavLink({ href, children }: { href: string; children: React.ReactNode }) {
  return (
    <Link href={href} className={styles.link}>
      {children}
    </Link>
  )
}
```

```tsx
// components/site-header.tsx
import Link from 'next/link'
import { NavLink } from './nav-link'
import styles from './site-header.module.css'

export function SiteHeader() {
  return (
    <header className={styles.header}>
      <nav className={styles.nav}>
        <Link href="/" className={styles.logo}>
          Gather
        </Link>
        <NavLink href="/events">Events</NavLink>
        <NavLink href="/dashboard">Dashboard</NavLink>
        <div className={styles.spacer} />
      </nav>
    </header>
  )
}
```

The spacer pushes everything after it to the right edge; chapter 15 puts the user menu there. In the root layout,
give the footer a little style:

```tsx
// app/layout.tsx — the footer
<footer className="muted" style={{ textAlign: 'center', padding: '2rem 1rem' }}>
  Gather is the example app of the Next.js tutorial.
</footer>
```

The event page gets a module colocated with the route:

```css
/* app/events/[slug]/event.module.css */
.event {
  margin-top: 1.5rem;
}

.event h1 {
  margin: 0.3rem 0;
  font-size: clamp(1.8rem, 5vw, 2.6rem);
}

.date {
  margin: 0;
  color: var(--accent);
  font-weight: 600;
  text-transform: uppercase;
}

.rsvp {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 1rem;
  margin: 1.5rem 0;
  padding: 1rem;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--card);
}

.description {
  max-width: 65ch;
  font-size: 1.1rem;
  white-space: pre-line;
}
```

```tsx
// app/events/[slug]/page.tsx
import Link from 'next/link'
import { notFound } from 'next/navigation'
import { getSampleEvent } from '@/lib/sample-events'
import { formatEventDate, spotsLeft } from '@/lib/format'
import { ShareButton } from '@/components/share-button'
import styles from './event.module.css'

export default async function EventPage({ params }: PageProps<'/events/[slug]'>) {
  const { slug } = await params
  const event = getSampleEvent(slug)
  if (!event) notFound()

  return (
    <main>
      <Link href="/events">← All events</Link>
      <article className={styles.event}>
        <p className={styles.date}>{formatEventDate(event.startsAt)}</p>
        <h1>{event.title}</h1>
        <p className="muted">
          {event.venue}, {event.city}
        </p>
        <div className={styles.rsvp}>
          <p>
            <strong>{event.going}</strong> going · {spotsLeft(event.capacity, event.going)}
          </p>
          <ShareButton title={event.title} path={`/events/${event.slug}`} />
        </div>
        <p className={styles.description}>{event.description}</p>
      </article>
    </main>
  )
}
```

`white-space: pre-line` on the description keeps the line breaks that organizers type, without allowing any HTML.
The `.rsvp` box is where the RSVP button goes in chapter 8; later chapters add a few more rules to this module (the
finished version is in `examples/gather/`).

## Tailwind CSS and other options

Gather uses plain CSS to keep one less thing to learn, but **Tailwind CSS** is the most popular way to style Next.js
apps and is part of `create-next-app`'s recommended defaults. Tailwind gives you small utility classes
(`flex`, `gap-4`, `text-lg`, `dark:bg-black`) that you compose in `className`, and generates only the CSS for the
classes you actually use.

A project created with the defaults (`npx create-next-app@latest my-app --yes`) is wired up like this:

```css
/* app/globals.css */
@import "tailwindcss";

@theme inline {
  --color-background: var(--background);
  --color-foreground: var(--foreground);
  --font-sans: var(--font-geist-sans);
  --font-mono: var(--font-geist-mono);
}
```

```ts
// next.config.ts
const nextConfig: NextConfig = {
  cacheComponents: true,
  partialPrefetching: true,
  turbopack: {
    rules: {
      '*.css': {
        loaders: ['@tailwindcss/turbopack'],
        as: '*.css',
      },
    },
  },
}
```

with `tailwindcss` and `@tailwindcss/turbopack` (Tailwind 4) as dev dependencies. The `turbopack.rules` entry runs
every CSS file through Tailwind's Turbopack loader. If you add Tailwind to an existing project by hand, the
documentation shows the PostCSS route instead: install `tailwindcss` and `@tailwindcss/postcss`, and create a
`postcss.config.mjs`:

```js
// postcss.config.mjs
export default {
  plugins: {
    '@tailwindcss/postcss': {},
  },
}
```

Both work; the Turbopack loader skips PostCSS and is what new projects get. Then use the utilities anywhere:

```tsx
<article className="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-900">
  <p className="text-sm font-semibold uppercase text-violet-600">{formatEventDate(event.startsAt)}</p>
  <h3 className="mt-1 text-lg font-bold">{event.title}</h3>
</article>
```

Tailwind and CSS Modules can live in the same project. The documentation recommends Tailwind for most styling and CSS
Modules where utilities aren't enough.

Other options, briefly:

| Option | How | Notes |
|---|---|---|
| Sass | `npm install --save-dev sass`, then use `.scss` / `.module.scss` files | Works out of the box; configure with `sassOptions` in `next.config.ts` |
| Zero-runtime CSS-in-JS (vanilla-extract, Panda, StyleX) | Generate CSS files at build time | Work in Server Components, since there is no runtime |
| Runtime CSS-in-JS (styled-components, Emotion) | Inject styles from JavaScript at runtime | Only in Client Components, and they need a style registry to work with streaming; avoid for new projects |
| Inline `style` | `style={{ ... }}` | Fine for one-off, dynamic values (Gather's footer); no media queries or hover states |

## How CSS is loaded

- In development, CSS changes apply instantly with Fast Refresh, without losing state.
- In production, Next.js merges and minifies CSS into a few files per route, and adds them as `<link
  rel="stylesheet">` tags in the `<head>`. CSS works without JavaScript.
- **Order follows imports.** If a component imports `base-button.module.css` and the page imports
  `page.module.css` after importing that component, the button's CSS comes first. Two rules with the same specificity
  in different files can therefore win or lose depending on import order. Keep specificity simple (one class per
  selector) and avoid fighting over the same element from two files.
- Ordering in development can differ from production. Check style-ordering issues in a production build.

## Fonts with `next/font`

Web fonts are a classic source of slow pages and **layout shift**: the page first renders with a fallback font, then
jumps when the web font arrives and the text reflows. `next/font` fixes both problems:

- **Self-hosting.** `next/font/google` downloads the font files at build time and serves them from your own domain.
  The browser never talks to Google (better privacy, one less DNS lookup and connection).
- **Preloading.** The font files for the route are preloaded with a `Link` header (you saw it in chapter 1's `curl
  -I` output), so they start downloading immediately.
- **No layout shift.** Next.js generates a fallback font face with adjusted metrics (`size-adjust`, ascent and
  descent overrides) so the fallback takes up the same space as the web font, and text doesn't move when it swaps.

Gather loads Geist in the root layout:

```tsx
// app/layout.tsx (excerpt)
import { Geist } from 'next/font/google'

const geist = Geist({ subsets: ['latin'], variable: '--font-geist-sans' })

export default function RootLayout({ children }: LayoutProps<'/'>) {
  return (
    <html lang="en" className={geist.variable}>
      ...
```

- `Geist(...)` is a function call that runs at build time. Each font is a named export (`Inter`, `Roboto_Mono`, …;
  spaces become underscores).
- `subsets` limits the font to the characters you need. `latin` covers English and Western European languages.
- **Variable fonts** (like Geist and Inter) contain every weight in one file, so you don't list weights. For
  non-variable fonts, pass `weight: ['400', '700']`.
- `variable: '--font-geist-sans'` makes `geist.variable` a class that defines the CSS custom property
  `--font-geist-sans`. Putting that class on `<html>` makes the variable available everywhere, and `globals.css` uses
  it: `--font-sans: var(--font-geist-sans), system-ui, sans-serif`. The alternative, `geist.className`, applies the
  font directly to one element.

Using CSS variables is the most flexible approach: it works with CSS Modules, with Tailwind's `@theme`, and lets you
use several fonts (one variable each).

For a font file you have yourself (a brand font, or a font not on Google Fonts), use `next/font/local`:

```tsx
import localFont from 'next/font/local'

const brand = localFont({
  src: [
    { path: './fonts/Brand-Regular.woff2', weight: '400', style: 'normal' },
    { path: './fonts/Brand-Bold.woff2', weight: '700', style: 'normal' },
  ],
  variable: '--font-brand',
})
```

The paths are relative to the file that calls `localFont`. Call each font function once, in a module that is
imported where needed (for example `app/fonts.ts`), rather than in every component: each call creates a separate
font instance.

## Images with `next/image`

Images are usually the largest bytes on a page. A plain `<img src="/photo.jpg">` sends the same full-size file to a
phone and a 4K monitor, loads even when far below the fold, and causes layout shift because the browser doesn't know
its size until it arrives. The `<Image>` component from `next/image` handles all three:

- **Responsive sizes.** It generates a `srcset` with several widths, and the browser downloads the smallest one that
  is big enough.
- **Modern formats.** Images are converted on the fly to WebP (and optionally AVIF) when the browser supports it.
- **Lazy loading** by default: images below the fold load when the user scrolls near them.
- **No layout shift.** It always renders `width` and `height`, so the browser reserves the space.

Add an illustration to Gather's home page. Put an image file at `public/gathering.png` (the one Gather uses is in
`examples/gather/public/`, or use any landscape PNG or JPEG), then:

```tsx
// app/page.tsx
import Image from 'next/image'
import Link from 'next/link'
import { EventGrid } from '@/components/event-card'
import { getSampleEvents } from '@/lib/sample-events'
import styles from './page.module.css'
import gathering from '@/public/gathering.png'

export default function HomePage() {
  const events = getSampleEvents().slice(0, 3)
  return (
    <main>
      <section className={styles.hero}>
        <h1>Meet the people who build the web.</h1>
        <p className="muted">
          Gather lists tech meetups, workshops and hack nights. RSVP in one click, or organize your
          own.
        </p>
        <Link href="/events" className="button">
          Browse all events
        </Link>
        {/* A static import gives next/image the width, height and a blur
            placeholder at build time. The hero is the largest thing on the
            page, so it loads eagerly with high priority. */}
        <Image
          src={gathering}
          alt="Illustration of people sitting around a table"
          placeholder="blur"
          loading="eager"
          fetchPriority="high"
          sizes="(min-width: 900px) 420px, 100vw"
          className={styles.heroImage}
        />
      </section>

      <h2>Coming up</h2>
      <EventGrid events={events} />
    </main>
  )
}
```

```css
/* app/page.module.css */
.hero {
  display: grid;
  gap: 0 2rem;
  padding: 3rem 0 2.5rem;
}

.heroImage {
  width: 100%;
  height: auto;
  border-radius: 12px;
  margin-top: 2rem;
}

@media (min-width: 900px) {
  .hero {
    grid-template-columns: 1fr 420px;
    align-items: center;
  }

  .heroImage {
    grid-column: 2;
    grid-row: 1 / span 3;
    margin-top: 0;
  }
}

.hero h1 {
  max-width: 18ch;
  margin: 0 0 1rem;
  font-size: clamp(2rem, 6vw, 3.2rem);
}

.hero p {
  max-width: 50ch;
  margin: 0 0 1.5rem;
  font-size: 1.15rem;
}

.hero > a {
  justify-self: start;
}
```

Each prop has a job:

- **`src={gathering}` as a static import.** Importing the image file gives Next.js its intrinsic width and height
  at build time (here 1200 × 900), so you don't write them, and it lets Next.js generate a tiny blurred version.
  `@/public/gathering.png` uses the path alias; a relative path works too.
- **`alt`** is required. Describe the image for screen readers, or use `alt=""` for purely decorative images.
- **`placeholder="blur"`** shows the blurred preview (inlined as a data URL, so it costs no request) until the image
  loads. It works automatically for statically imported JPEG, PNG, WebP and AVIF files.
- **`sizes`** tells the browser how wide the image will be displayed, so it can pick the right file from the
  `srcset` *before* layout is known. Here: 420 px wide on screens at least 900 px wide, otherwise the full viewport
  width. Without `sizes`, Next.js assumes the image is displayed at its `width` and generates only a `1x`/`2x`
  `srcset` (exercise 4 shows what that costs). Always set `sizes` for images that are sized with CSS.
- **`loading="eager"` and `fetchPriority="high"`** because this is the largest element above the fold, the one that
  determines the Largest Contentful Paint metric (chapter 19). Lazy loading it would delay it. The `preload` prop is
  an alternative that adds a `<link rel="preload">` to the `<head>`; the documentation recommends `loading="eager"`
  or `fetchPriority="high"` in most cases. (The older `priority` prop, which you will see in tutorials and in the
  page `create-next-app` generates, is deprecated since Next.js 16.)
- **`className`** styles the `<img>` element. `width: 100%; height: auto` makes it fluid while keeping the aspect
  ratio.

Look at what it renders (shortened):

```html
<img alt="Illustration of people sitting around a table" fetchPriority="high" loading="eager"
  width="1200" height="900" decoding="async" class="E0kJGG_heroImage"
  style="color:transparent;background-size:cover;...;background-image:url(&quot;data:image/svg+xml;...&quot;)"
  sizes="(min-width: 900px) 420px, 100vw"
  srcSet="/_next/image?url=%2F_next%2Fstatic%2Fmedia%2Fgathering.0ht-eq2pous35.png&amp;w=640&amp;q=75 640w,
          /_next/image?url=...&amp;w=750&amp;q=75 750w, ... /_next/image?url=...&amp;w=3840&amp;q=75 3840w"
  src="/_next/image?url=...&amp;w=3840&amp;q=75"/>
```

Every URL points at `/_next/image`, the **image optimizer** built into the Next.js server. It reads the original,
resizes it to the requested width (`w`), encodes it at the requested quality (`q`), converts the format based on the
browser's `Accept` header, and caches the result:

```bash
curl -s -o /dev/null -w "%{content_type} %{size_download}\n" -H "Accept: image/avif,image/webp,*/*" \
  "http://localhost:3000/_next/image?url=%2F_next%2Fstatic%2Fmedia%2Fgathering.0ht-eq2pous35.png&w=640&q=75"
```

```text
image/webp 10022
```

The 17 KB PNG became a 10 KB WebP at 640 px. Optimized images are cached on disk (in `.next/cache/images`) and served
with a long `Cache-Control` header.

### Images whose size you don't know: `fill`

For images whose dimensions aren't known in advance (user uploads, CMS content), use `fill`. The image then fills its
parent, which must be positioned, and you control the size with CSS:

```tsx
<div style={{ position: 'relative', aspectRatio: '16 / 9' }}>
  <Image src={event.coverUrl} alt="" fill sizes="(min-width: 960px) 960px, 100vw" style={{ objectFit: 'cover' }} />
</div>
```

With a URL string as `src` (instead of a static import) and without `fill`, you must pass `width` and `height`
yourself.

### Remote images and the optimizer's security settings

The image optimizer fetches and processes images on your server, so it is locked down by default:

- **Remote images must be allowed explicitly** with `images.remotePatterns` in `next.config.ts`. Anything else is
  refused, so nobody can use your server to resize arbitrary images from the internet:

  ```ts
  // next.config.ts
  const nextConfig: NextConfig = {
    images: {
      remotePatterns: [new URL('https://images.example-cdn.com/gather/**')],
    },
  }
  ```

- **Widths are limited** to the configured `deviceSizes` and `imageSizes`. A request for `w=641` returns 400.
- **Qualities are limited** to an allowlist, `[75]` by default since Next.js 16. Requesting another quality fails:

  ```text
  "q" parameter (quality) of 80 is not allowed
  ```

  If you use `quality={90}` on an image, add it to `images.qualities: [75, 90]`.
- **Formats.** The default is `['image/webp']`. Add AVIF with `formats: ['image/avif', 'image/webp']`; AVIF files are
  smaller but slower to encode.
- **SVG** isn't optimized by default (it's a vector format, and SVG can contain scripts). An `<Image>` whose `src`
  ends in `.svg` is automatically served unoptimized.

In chapter 20 you will see that the optimizer needs CPU and disk on your server; behind a CDN, or with a lot of
images, a custom `loader` that points at an image CDN is an option.

## The `public` folder

Files in `public/` are served as-is from the root of your site: `public/robots.txt` is at `/robots.txt`,
`public/gathering.png` at `/gathering.png`. Use it for files that need a fixed URL (verification files, downloads,
images referenced from outside the app).

- Don't put files there whose names collide with routes; a page and a public file at the same path conflict.
- Files in `public/` are served with `Cache-Control: public, max-age=0` (browsers revalidate them every time), since
  their content can change without the name changing.
  Statically imported images and fonts get hashed file names under `/_next/static/` and are cached forever, which is
  one more reason to import images instead of referencing `/gathering.png`.
- Only files present at build time are served. Uploaded files belong in object storage, not in `public/`.

Favicons, Open Graph images and `robots.txt` have special file conventions inside `app/` that do more than `public/`
can; chapter 14 covers them.

## Key takeaways

- Keep global CSS small: design tokens as custom properties, element defaults, and a few shared classes. Import it
  once, in the root layout. Global stylesheets aren't removed on navigation.
- CSS Modules (`.module.css`) scope class names per file and are the default choice for component styles in plain
  CSS. Use class selectors, camelCase names, and don't depend on generated names.
- Tailwind CSS is in `create-next-app`'s defaults, wired through the `@tailwindcss/turbopack` loader; the manual
  setup uses PostCSS. Sass works out of the box; prefer zero-runtime CSS-in-JS over runtime libraries.
- CSS order follows import order; check ordering issues in a production build.
- `next/font` self-hosts fonts, preloads them and prevents layout shift with metric-adjusted fallbacks. Expose fonts
  as CSS variables on `<html>`.
- `next/image` serves responsive, lazy-loaded, format-converted images with reserved space. Statically import local
  images, always set `sizes` for CSS-sized images, and load the hero eagerly with `fetchPriority="high"`.
- The optimizer only processes allowed remote patterns, widths and qualities (`[75]` by default).

## Exercises

1. **Dark mode check.** Switch your operating system (or the browser's developer tools: Rendering → Emulate CSS media
   feature `prefers-color-scheme`) to dark mode and look at Gather. Which colors change, and why didn't you have to
   touch any CSS Module?

<details>
<summary>Solution</summary>

The background, text, borders, card backgrounds and accent change. The `@media (prefers-color-scheme: dark)` block in
`globals.css` redefines the custom properties, and every module uses `var(--bg)`, `var(--card)` and so on instead of
hard-coded colors. That's the payoff of design tokens: a theme is a set of variable values. `color-scheme: dark`
also switches native form controls and scrollbars.

</details>

2. **An active card state.** Give event cards that are almost full (fewer than 5 spots left) a colored left border.
   Do it with a second CSS Module class combined conditionally, without inline styles.

<details>
<summary>Solution</summary>

```css
/* components/event-card.module.css */
.almostFull {
  border-left: 4px solid var(--accent);
}
```

```tsx
const almostFull = event.capacity - event.going < 5
return (
  <article className={almostFull ? `${styles.card} ${styles.almostFull}` : styles.card}>
```

With `clsx` installed: `className={clsx(styles.card, almostFull && styles.almostFull)}`. Both classes come from the
same module, so the order of the two rules in the file decides which `border` wins where they overlap (here only the
left side changes).

</details>

3. **A second font.** Use a monospace font (for example `Geist_Mono` from `next/font/google`) for the dates on event
   cards, exposed as a CSS variable. Where do you load it and how does the CSS Module use it?

<details>
<summary>Solution</summary>

```tsx
// app/layout.tsx
import { Geist, Geist_Mono } from 'next/font/google'

const geist = Geist({ subsets: ['latin'], variable: '--font-geist-sans' })
const geistMono = Geist_Mono({ subsets: ['latin'], variable: '--font-geist-mono' })

// ...
<html lang="en" className={`${geist.variable} ${geistMono.variable}`}>
```

```css
/* components/event-card.module.css */
.date {
  font-family: var(--font-geist-mono), ui-monospace, monospace;
  /* ...existing rules */
}
```

Load fonts once in the root layout (or a shared `app/fonts.ts`) and expose them as variables; modules then just use
`var(...)`. Both fonts are self-hosted and preloaded.

</details>

4. **Measure `sizes`.** Open the home page on a narrow viewport (the developer tools' device toolbar, 390 px wide, 3×
   pixel ratio) and on a 1280 px desktop window. Which width (`w=`) does the browser download for the hero image in
   each case? Then remove the `sizes` prop and repeat. Explain the difference.

<details>
<summary>Solution</summary>

With `sizes="(min-width: 900px) 420px, 100vw"`, the phone needs 390 CSS px × 3 = 1170 device pixels and picks the
next larger candidate, `w=1200`; the desktop needs 420 × its pixel ratio (840 on a 2× screen, so `w=1080`; 420 on a
1× screen, so `w=640`).

Without `sizes`, Next.js generates a density-based `srcset` from the image's intrinsic width (1200): `w=1200 1x,
w=3840 2x`. The browser then downloads the 1200 px variant on 1× screens and the 3840 px variant on high-density
screens, phones included, regardless of how small the image is displayed. On the
desktop layout that's several times more bytes than needed. `sizes` is what makes a responsive image actually
responsive.

</details>

5. **Remote images, safely.** Gather wants to show organizers' profile pictures hosted at
   `https://avatars.example.com/u/<id>.png`. Write the configuration and the `<Image>` usage, and explain what happens
   if an attacker requests `/_next/image?url=https://evil.example/huge.png&w=3840&q=75` against your server.

<details>
<summary>Solution</summary>

```ts
// next.config.ts
images: {
  remotePatterns: [new URL('https://avatars.example.com/u/**')],
},
```

```tsx
<Image src={`https://avatars.example.com/u/${organizer.id}.png`} alt="" width={48} height={48} />
```

The attacker's request is rejected with a 400 error because the URL doesn't match any remote pattern, so your server
never downloads or processes the file. Keep patterns as narrow as possible (host *and* path prefix): a pattern like
`https://**` would turn your server into a free image-processing proxy for anyone, costing you CPU, bandwidth and disk.

</details>

---
← Previous: [Server and Client Components](04-server-and-client-components.md) | Next: [Fetching data and streaming](06-fetching-data-and-streaming.md) →
