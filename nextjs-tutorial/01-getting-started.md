# Chapter 1 — Getting started

## What you'll learn

- What Next.js is, and what it adds to React
- The two halves of every Next.js app: the server and the browser, build time and request time
- The App Router and the Pages Router, and which one this tutorial uses
- Which versions to use and why: Next.js 16.4, React 19.3, Node.js 24
- How to create a project with `create-next-app`, and what every generated file is for
- How to run the development server, and what Turbopack and Fast Refresh do
- What happens, step by step, when a browser requests a page
- Where to find documentation that matches the exact version you installed

## What Next.js is

**React** is a library for building user interfaces out of components. A component is a function that returns a
description of some UI, and React turns those descriptions into DOM elements and keeps them up to date when data
changes. That is all React does. It doesn't decide which component to show for which URL, it doesn't load data, it
doesn't bundle your code for the browser, and it doesn't run a server.

To ship a real website with React alone you would have to add, at least:

| Problem | What you would build or install |
|---|---|
| Show different pages for different URLs | A router |
| Turn JSX and TypeScript into JavaScript the browser can run, split into small files | A bundler and compiler |
| Send real HTML on the first request, so the page shows up fast and search engines can read it | A server that renders React to HTML |
| Load data from a database without exposing the database to the browser | An API server, plus code in the browser to call it |
| Avoid re-rendering pages that rarely change | A cache, or a build step that pre-renders pages |
| Serve images in the right size and format, load fonts without layout shifts | Image and font tooling |
| Handle form submissions, redirects, errors, 404s, metadata for social networks | A pile of small libraries and conventions |

**Next.js** is a React *framework*: it makes all of these decisions for you and ships them as one coherent tool.
You write React components in files whose location decides their URL; Next.js compiles them, renders them on the
server, sends HTML to the browser, makes the page interactive, and handles navigation, data loading, caching and
mutations. It is also a full-stack framework: the same project contains the code that queries your database and the
buttons that the user clicks, and Next.js keeps the two apart for you.

Next.js is open source (MIT licence), maintained by Vercel together with the React team at Meta, and runs anywhere
Node.js runs. Vercel sells hosting for it, but nothing in this tutorial depends on Vercel: chapter 20 deploys Gather
as a Docker container on your own server.

### Two environments, two moments in time

The single most important idea in modern Next.js is that your code runs in **two environments**:

- **The server** — a Node.js process. Code here can read files, query databases, use secrets and call other
  services. Its output is sent over the network.
- **The client** — the user's browser. Code here can respond to clicks and keystrokes, use `window`, `localStorage`
  and browser APIs, and update the page without a round trip.

and at **two moments in time**:

- **Build time** — when you run `next build`. Next.js renders as much of every page as it can ahead of time and
  saves the result, so it can be served instantly to everyone.
- **Request time** — when a request arrives. Only the parts of a page that depend on the request (who the user is,
  what's in the query string, data that must be fresh) are rendered now, and streamed into the prerendered page.

React components in Next.js run on the server by default. They are called **Server Components**, and their code
never reaches the browser. You opt individual components into running in the browser too by marking them as **Client
Components**. Chapter 4 explains this split in depth; for now, remember that "a React app" in Next.js is not a big
JavaScript bundle running in the browser. Most of it runs on the server, and the browser receives HTML plus only the
JavaScript that the interactive parts need.

```text
                 build time                         request time
            ┌────────────────────┐          ┌──────────────────────────────┐
  server    │ prerender pages    │          │ render only what depends on  │
  (Node.js) │ into a static shell│          │ the request, stream it in    │
            └─────────┬──────────┘          └──────────────┬───────────────┘
                      │ HTML + data for the client          │
                      ▼                                     ▼
  client    ┌───────────────────────────────────────────────────────────────┐
  (browser) │ show the HTML at once; load JS for interactive parts;         │
            │ navigate between pages without full reloads                   │
            └───────────────────────────────────────────────────────────────┘
```

### Rendering strategies, briefly

You will meet these terms in documentation and blog posts. Next.js uses all of them, often in the same page:

| Name | Where and when HTML is produced | Good for |
|---|---|---|
| Client-side rendering (CSR) | In the browser, after JavaScript loads | Highly interactive widgets |
| Server-side rendering (SSR) | On the server, on every request | Personalized or always-fresh content |
| Static site generation (SSG) | On the server, once at build time | Content that is the same for everyone |
| Incremental static regeneration (ISR) | At build time, then regenerated in the background after a set time | Content that changes occasionally |
| Partial Prerendering (PPR) | A static shell at build time, plus dynamic parts streamed in at request time | Pages that are mostly the same for everyone but have a few personal or fresh parts |

Older versions of Next.js made you pick one strategy per page. Since Next.js 16 with **Cache Components** enabled
(the default for new projects), the decision is made per component: everything that can be prerendered is, and
everything that can't is streamed in. Chapter 7 is about exactly this.

### The App Router and the Pages Router

Next.js has two routers, and you will see both in tutorials and codebases:

- **The App Router** (the `app/` folder), introduced in Next.js 13 and stable since 13.4 (2023). It is built on
  React Server Components, nested layouts, streaming and Server Actions. All new features land here.
- **The Pages Router** (the `pages/` folder), the original router from 2016. It uses `getServerSideProps`,
  `getStaticProps` and API routes in `pages/api/`. It is still supported and receives fixes, but it is not where
  Next.js is going.

This tutorial teaches the App Router. Chapter 22 shows the Pages Router briefly, so you can read older code and
migrate it.

## Versions

Next.js moves fast. A few milestones, so you can place the code you find online:

| Version | Released | What changed |
|---|---|---|
| 13 / 13.4 | 2022 / 2023 | The App Router and React Server Components (stable in 13.4) |
| 14 | October 2023 | Server Actions stable |
| 15 | October 2024 | React 19; `cookies()`, `headers()`, `params` and `searchParams` became asynchronous |
| 16 | October 2025 | Turbopack is the default bundler; Cache Components; `middleware.ts` renamed to `proxy.ts` |
| 16.2 | 2026 | Documentation bundled inside the `next` package |
| 16.3 | August 2026 | Instant navigation validation, `catchError`, root params, Instant Insights in the dev tools |
| 16.4 | October 2026 | Partial Prefetching, `navigation()`/`prefetch()`, `ensureStatic`, Cache Components on by default in new projects |

This tutorial uses **Next.js 16.4.2**, the current release (October 2026). Patch releases (16.4.1, 16.4.2) only fix
bugs, so anything here works on any 16.4.x. When a feature is newer than 16.0, the text says so, like "(16.4+)".

Next.js 16 needs **Node.js 20.9 or later**. Use **Node.js 24**, the current long-term-support line: the example app
uses Node's built-in SQLite module (`node:sqlite`), which you can use without any flag in Node.js 24.

```bash
node --version
```

```text
v24.22.1
```

If you have an older Node.js, install 24 from <https://nodejs.org/> or with a version manager such as `fnm`,
`nvm` or `volta`. Next.js supports Chrome, Edge and Firefox 111+ and Safari 16.4+ without any configuration.

## Creating a project

`create-next-app` sets up a project with sensible defaults. Create Gather:

```bash
npx create-next-app@latest gather --yes --no-tailwind
```

- `@latest` makes `npx` download the newest `create-next-app` instead of a cached old one.
- `--yes` accepts the recommended defaults instead of asking questions.
- `--no-tailwind` leaves out Tailwind CSS. Gather is styled with plain CSS and CSS Modules, because they teach how
  styling works in Next.js without a second thing to learn. Chapter 5 shows Tailwind too.

The output ends like this:

```text
Installing dependencies:
- next
- react
- react-dom

Installing devDependencies:
- @types/node
- @types/react
- @types/react-dom
- eslint
- eslint-config-next
- typescript

added 345 packages, and audited 346 packages in 39s

Generating route types...
✓ Types generated successfully

Initialized a git repository.

Success! Created gather at /home/you/gather
```

Without `--yes`, `create-next-app` asks whether to use the recommended defaults. If you choose "customize", you see
every question:

```text
Would you like to use TypeScript? No / Yes
Which linter would you like to use? ESLint / Biome / None
Would you like to use React Compiler? No / Yes
Would you like to use Tailwind CSS? No / Yes
Would you like your code inside a `src/` directory? No / Yes
Would you like to use App Router? (recommended) No / Yes
Would you like to use Cache Components? No / Yes
Would you like to customize the import alias (`@/*` by default)? No / Yes
What import alias would you like configured? @/*
Would you like to include AGENTS.md to guide coding agents to write up-to-date Next.js code? No / Yes
```

The recommended defaults are: TypeScript, ESLint, no React Compiler, Tailwind CSS, no `src/` folder, the App Router,
Cache Components, and an `AGENTS.md` file. `create-next-app` remembers your answers and reuses them next time; run
it with `--reset` to forget them. `npx create-next-app --help` lists every flag, including `--js` for a JavaScript
project and `--empty` for a project without the starter page.

> **Note:** "Cache Components" became part of the recommended defaults in Next.js 16.4. A project created with an
> earlier 16.x version, or upgraded from 15, doesn't have it switched on. Chapter 7 explains what it changes, and
> chapter 16 covers the older caching model you will find in those projects.

### A tour of the generated files

```bash
cd gather
```

```text
gather/
├── app/
│   ├── favicon.ico        # the browser tab icon (a file convention, chapter 14)
│   ├── globals.css        # styles for every page
│   ├── layout.tsx         # the root layout: <html> and <body>, shared by all pages
│   ├── page.module.css    # styles scoped to page.tsx (a CSS Module)
│   └── page.tsx           # the home page, at "/"
├── public/                # files served as-is: /next.svg, /vercel.svg, ...
├── .gitignore
├── AGENTS.md              # instructions for AI coding agents
├── README.md
├── eslint.config.mjs      # ESLint with the Next.js rules
├── next-env.d.ts          # TypeScript declarations for Next.js (generated, don't edit)
├── next.config.ts         # Next.js configuration
├── package.json
├── package-lock.json
└── tsconfig.json          # TypeScript configuration
```

There is also a `.next/` folder once you run anything. It holds build output, caches and generated types. It is in
`.gitignore`; you can delete it at any time and Next.js recreates it.

**`package.json`** has four scripts and very few dependencies:

```json
{
  "name": "gather",
  "version": "0.1.0",
  "private": true,
  "scripts": {
    "dev": "next dev",
    "build": "next build",
    "start": "next start",
    "lint": "eslint"
  },
  "dependencies": {
    "next": "16.4.2",
    "react": "19.3.0",
    "react-dom": "19.3.0"
  },
  "devDependencies": {
    "@types/node": "^20",
    "@types/react": "^19",
    "@types/react-dom": "^19",
    "eslint": "^9",
    "eslint-config-next": "16.4.2",
    "typescript": "^5"
  }
}
```

| Script | Command | Use it for |
|---|---|---|
| `npm run dev` | `next dev` | Development: compiles on demand, reloads on save, shows detailed errors |
| `npm run build` | `next build` | Producing an optimized production build in `.next/` |
| `npm start` | `next start` | Serving that production build |
| `npm run lint` | `eslint` | Checking the code with ESLint (`next build` no longer runs it for you) |

`next` is the framework, the compiler and the server in one package. `react` and `react-dom` are React itself.
Notice that `@types/node` is `^20`: `create-next-app` picks the oldest Node.js version Next.js supports. Gather uses
Node.js 24 APIs, so update it now:

```bash
npm install --save-dev @types/node@24
```

**`next.config.ts`** configures Next.js. It is TypeScript, and `NextConfig` gives you autocompletion for every
option:

```ts
// next.config.ts
import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  /* config options here */
  cacheComponents: true,
  partialPrefetching: true,
};

export default nextConfig;
```

`cacheComponents: true` switches on the Cache Components programming model (chapter 7), and `partialPrefetching:
true` makes links prefetch the static parts of the next page ahead of time (chapter 9). These two lines are what
make a 16.4 project behave differently from tutorials written for Next.js 15.

**`tsconfig.json`** is a normal TypeScript configuration with two Next.js-specific parts: the `next` plugin, which
teaches your editor about Next.js rules (for example, it warns about an invalid export from a page), and a path
alias:

```json
"paths": {
  "@/*": ["./*"]
}
```

With it, `import { db } from '@/lib/db'` works from any file, however deeply nested, instead of
`'../../../lib/db'`.

**`app/layout.tsx`** is the root layout. Every page in the app renders inside it, so this is where `<html>`,
`<body>`, fonts and the site header go:

```tsx
// app/layout.tsx (generated)
import type { Metadata } from "next";
import { Geist, Geist_Mono } from "next/font/google";
import "./globals.css";

const geistSans = Geist({
  variable: "--font-geist-sans",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  title: "Create Next App",
  description: "Generated by create next app",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="en" className={`${geistSans.variable} ${geistMono.variable}`}>
      <body>{children}</body>
    </html>
  );
}
```

Three things in this small file are Next.js features you will study later: `next/font` downloads the Geist font at
build time and serves it from your own domain (chapter 5); the exported `metadata` object becomes the page's
`<title>` and `<meta>` tags (chapter 14); and `LayoutProps<"/">` is a type that Next.js *generates* from your folder
structure, so you never declare it yourself (chapter 3). `children` is the page currently being shown.

**`app/page.tsx`** is the page at `/`. A page is a React component exported as `default` from a file named `page`.

**`AGENTS.md`** contains a short block of instructions for AI coding assistants:

```markdown
<!-- BEGIN:nextjs-agent-rules -->

## This is NOT the Next.js you know

This version has breaking changes — APIs, conventions, and file structure may all differ from your training data.
Read the relevant guide in `node_modules/next/dist/docs/` ... before writing any code. Heed deprecation notices.

<!-- END:nextjs-agent-rules -->
```

The message is as useful for humans as it is for AI: Next.js changes quickly, and much of what you find online
describes older versions. The block points at the next useful thing to know.

### The documentation inside your project

Since Next.js 16.2, the complete documentation for the installed version ships inside the package:

```bash
ls node_modules/next/dist/docs/
```

```text
01-app  02-pages  03-architecture  04-community  index.md
```

These are Markdown files with the same structure as <https://nextjs.org/docs>. They always describe exactly the
version in your `package.json`, which the website doesn't: the site documents the latest release. When this
tutorial says "see the docs", both work, but when in doubt, the bundled copy wins.

```bash
grep -rl "partialPrefetching" node_modules/next/dist/docs/ | head -3
```

## Running the development server

```bash
npm run dev
```

```text
▲ Next.js 16.4.2 (Turbopack)
- Local:         http://localhost:3000
- Network:       http://192.168.1.20:3000
✓ Ready in 449ms
✓ Running next.config.ts took 53ms
- Cache Components enabled
- Partial Prefetching enabled
```

Open <http://localhost:3000>. The terminal logs the request:

```text
 GET / 200 in 377ms (next.js: 154ms, application-code: 223ms)
```

The first request to a page is slower because the dev server compiles that page on demand. **Turbopack**, the
bundler written in Rust that has been the default since Next.js 16, compiles only what the requested page needs, and
caches its work on disk in `.next/` so the next start is faster. (Webpack is still available with
`next dev --webpack` for projects that need a webpack plugin, but you won't need it.)

The development server is meant for development only. It compiles on demand, includes extra checks and readable
error messages, and doesn't prerender or cache the way production does. Never deploy `next dev`.

### Fast Refresh

Leave the browser open and replace `app/page.tsx` with Gather's first home page:

```tsx
// app/page.tsx
export default function HomePage() {
  return (
    <main>
      <h1>Gather</h1>
      <p>Tech meetups, workshops and hack nights near you.</p>
    </main>
  )
}
```

As soon as you save, the browser shows the new content, without a full reload. This is **Fast Refresh**: Next.js
sends the browser only the changed module and React re-renders the affected components. If a component has state
(you will add some in chapter 4), Fast Refresh keeps it across edits where it safely can.

You can also delete `app/page.module.css` and the SVG files in `public/`; the new page doesn't use them. In
`app/globals.css`, keep the file but you can delete its content for now. Chapter 5 gives Gather its real styles.

> **Code style in this tutorial.** The generated files use double quotes and semicolons; the tutorial's code uses
> single quotes and no semicolons. Both are fine, and nothing in Next.js depends on it. Pick one and let your editor
> or a formatter such as Prettier keep it consistent.

### Errors in development

Break something on purpose. Change the `return` to return two elements side by side without a wrapper:

```tsx
return (
  <h1>Gather</h1>
  <p>Tech meetups, workshops and hack nights near you.</p>
)
```

The browser shows an **error overlay** with the compiler message, the file and the exact line. The same message is
printed in the terminal. Fix the code and the overlay disappears by itself. In the bottom corner of the page you also
see a small Next.js icon: the **dev tools indicator**. Clicking it opens panels with the route's information, the
current issues, and (chapter 9) the Navigation Inspector. None of this exists in production.

## What happens when you request a page

Here is the life of a request for `/` in production. Each step gets a chapter of its own later; this is the map.

```text
  browser                         Next.js server (Node.js)
  ───────                         ────────────────────────
  GET /  ───────────────────────▶ 1. Proxy runs, if you have one (chapter 12)
                                  2. Match the URL to a route: app/page.tsx inside app/layout.tsx
                                  3. Prerendered at build time? Send the saved static shell at once.
                                     Otherwise, render the Server Components now.
                                  4. Anything that depends on the request (cookies, fresh data)
                                     renders now and streams in as it becomes ready
         ◀─────────────────────── 5. HTML, plus the "RSC payload": a compact description of the
                                     component tree, embedded in <script> tags
  6. Show the HTML immediately
  7. Load the JavaScript for Client Components only
  8. "Hydrate": React attaches event handlers to the existing HTML
  9. Prefetch the pages that visible links point to

  click a <Link>  ──────────────▶ 10. Return the RSC payload for the new page (no full HTML)
  11. React updates only the parts of the page that changed; layouts stay mounted
```

You can see step 5 with `curl`. Build and start the production version (stop `npm run dev` first, or use another
terminal):

```bash
npm run build
```

```text
▲ Next.js 16.4.2 (Turbopack)
- Cache Components enabled
- Partial Prefetching enabled

  Creating an optimized production build ...
✓ Compiled successfully in 240ms
  Running TypeScript ...
  Finished TypeScript in 2.0s ...
  Collecting page data using 3 workers ...
✓ Generating static pages using 3 workers (4/4) in 269ms
  Finalizing page optimization ...

Route (app)
┌ ○ /
└ ○ /_not-found


○  (Static)  prerendered as static content
```

The route table at the end is the most useful thing `next build` prints. `○` means the whole page was prerendered at
build time, because nothing in it depends on the request. `/_not-found` is the built-in 404 page. You will see other
symbols as Gather grows. Now start the server and fetch the page:

```bash
npm start
```

```bash
curl -s http://localhost:3000/ | head -c 400
```

```text
<!DOCTYPE html><html lang="en" class="T19VSG_variable _8Li5zG_variable"><head><meta charSet="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/><link rel="stylesheet" href="/_next/static/chunks/0tnav3s5bastc.css" data-precedence="next"/>
```

and further down:

```text
<main><h1>Gather</h1><p>Tech meetups, workshops and hack nights near you.</p></main>
```

The content is in the HTML. A search engine, a link preview in a chat app, or a user on a slow phone sees the page
before any JavaScript runs. The response headers say how it was produced:

```bash
curl -sI http://localhost:3000/
```

```text
HTTP/1.1 200 OK
Vary: rsc, next-router-state-tree, next-router-prefetch, next-router-segment-prefetch, Accept-Encoding
x-nextjs-cache: HIT
x-nextjs-prerender: 1
link: </_next/static/media/797e433ab948586e-s.p.0r6juujl39pe6.woff2>; rel=preload; as="font"; crossorigin=""; type="font/woff2", </_next/static/media/caa3a2e1cccd8315-s.p.0wgildi0cnwt9.woff2>; rel=preload; as="font"; crossorigin=""; type="font/woff2"
x-nextjs-stale-time: 300
X-Powered-By: Next.js
Cache-Control: s-maxage=31536000
ETag: "7iqkz3jw874ws"
Content-Type: text/html; charset=utf-8
```

`x-nextjs-prerender: 1` and `x-nextjs-cache: HIT` say the server sent the page it saved at build time instead of
rendering it again. The `link` header tells the browser to start downloading the two Geist font files right away
(chapter 5). The `Vary` header lists the request headers that the client router sends when it asks for an
RSC payload instead of HTML (step 10). Chapter 21 looks at all of this in detail.

### A first look at the static/dynamic rule

To see how seriously Next.js 16 takes prerendering, add a line that shows the current time:

```tsx
// app/page.tsx
export default function HomePage() {
  return (
    <main>
      <h1>Gather</h1>
      <p>Tech meetups, workshops and hack nights near you.</p>
      <p>Rendered at {new Date().toISOString()}</p>
    </main>
  )
}
```

and build again:

```bash
npm run build
```

```text
Error: Route "/": Next.js encountered the unstable value `new Date()` while prerendering.

This value can change between renders, so it must be either prerendered or computed later.

Ways to fix this:
  - [dynamic] Render at request time by adding a dynamic data access (e.g. `await connection()`) before this call
  - [cache] Prerender and cache the value with `"use cache"`
  - [client] Render the value on the client with `"use client"`
  - [measure] If the value is for telemetry, use a timing API such as `performance.now()`

Learn more: https://nextjs.org/docs/messages/blocking-prerender-current-time
    at <unknown> (app/page.tsx:6:23)
```

The build fails. Next.js tried to prerender the page, found a value that would be different on every request, and
refused to guess whether you wanted the build-time time (frozen forever) or the request-time time (the page can't be
static). The error lists every way to say what you mean. This is the Cache Components model in one example: nothing
is cached or made dynamic by accident. Chapter 7 teaches each of those four fixes. For now, remove the line again.

Stop the server with Ctrl+C when you're done.

## Gather, the running example

From chapter 3 on, you build **Gather**, a site for tech meetups:

- **Visitors** see upcoming events on the home page, browse and search all events by text and city, and open an
  event's page with its description, the number of people going, and a discussion.
- **Members** sign up and log in, RSVP to events (and cancel), comment, and see their events on a dashboard.
- **Organizers** create, edit and delete events.

It uses a SQLite database through Node's built-in `node:sqlite` module, so the whole app, including its data, is one
folder you can run anywhere Node.js 24 runs. The finished version is in [`examples/gather/`](examples/gather/), but
you will build it yourself, one chapter at a time.

Before moving on, make the root layout say what the app is:

```tsx
// app/layout.tsx — change only the metadata
export const metadata: Metadata = {
  title: 'Gather',
  description: 'Tech meetups, workshops and hack nights near you.',
}
```

and commit your starting point:

```bash
git add -A
git commit -m "Create Gather with create-next-app"
```

## Key takeaways

- Next.js is a React framework: routing, server rendering, bundling, data loading, caching, mutations and
  optimizations in one tool. It is full-stack: server code and UI code live in the same project.
- Code runs in two environments (server and browser) and at two moments (build time and request time). Components run
  on the server by default; only Client Components ship JavaScript to the browser.
- Use the App Router (`app/`). The Pages Router (`pages/`) is the older router you will still meet in existing code.
- This tutorial targets Next.js 16.4.2, React 19.3 and Node.js 24. New 16.4 projects have Cache Components and Partial
  Prefetching switched on, which changes how pages are prerendered and cached compared with older tutorials.
- `create-next-app` generates a TypeScript project with ESLint, an `@/*` import alias, a root layout, a home page and
  an `AGENTS.md`. `next.config.ts` holds framework configuration.
- `next dev` is for development (on-demand compilation with Turbopack, Fast Refresh, an error overlay, dev tools).
  `next build` + `next start` is production. The route table printed by `next build` tells you how each route is
  rendered.
- The documentation for your exact version is in `node_modules/next/dist/docs/`.

## Exercises

1. **Find your versions.** Print the exact versions of Next.js, React and Node.js you are running, without opening
   `package.json`.

<details>
<summary>Solution</summary>

```bash
npx next --version
npm ls react react-dom next
node --version
```

`npx next --version` prints `Next.js v16.4.2`. `npm ls` shows the installed versions (which can be newer than the
range in `package.json` if it uses `^`), and `node --version` the runtime. `npx next info` prints all of these plus
your operating system and is what bug reports to Next.js ask for.

</details>

2. **A second page.** Create a page at `/about` that says what Gather is. You haven't learned routing yet; guess from
   how `app/page.tsx` works.

<details>
<summary>Solution</summary>

A folder inside `app/` becomes a URL segment, and a `page.tsx` inside it makes the segment a page:

```tsx
// app/about/page.tsx
export default function AboutPage() {
  return (
    <main>
      <h1>About Gather</h1>
      <p>Gather lists tech meetups, workshops and hack nights, and lets you RSVP in one click.</p>
    </main>
  )
}
```

Open <http://localhost:3000/about>. The page is rendered inside `app/layout.tsx`, so it has the same `<html>` and
`<body>` (and the same fonts). Chapter 3 covers routing properly.

</details>

3. **Read the bundled docs.** Using only `node_modules/next/dist/docs/`, find out which browsers Next.js 16.4
   supports out of the box, and what the minimum Node.js version is.

<details>
<summary>Solution</summary>

```bash
grep -n -A6 "## System requirements" node_modules/next/dist/docs/01-app/01-getting-started/01-installation.md
grep -n -A6 "## Supported browsers" node_modules/next/dist/docs/01-app/01-getting-started/01-installation.md
```

Node.js 20.9 is the minimum; Chrome, Edge and Firefox 111+ and Safari 16.4+ are supported with zero configuration.
Grepping the bundled docs like this is a habit worth building: it answers version questions without guessing.

</details>

4. **Development vs. production.** Run the production build with `npm run build && npm start` and the development
   server with `npm run dev -- -p 3001` at the same time. Compare the two with `curl -sI` and by looking at the size of
   the HTML (`curl -s ... | wc -c`). What differs, and why?

<details>
<summary>Solution</summary>

```bash
curl -sI http://localhost:3000/ | grep -i -E "x-nextjs|cache-control"
curl -sI http://localhost:3001/ | grep -i -E "x-nextjs|cache-control"
curl -s http://localhost:3000/ | wc -c
curl -s http://localhost:3001/ | wc -c
```

The production response has `x-nextjs-prerender: 1`, `x-nextjs-cache: HIT` and a long `s-maxage`: the page was
prerendered at build time and is served from the saved file. The development response has
`Cache-Control: no-store` and no `x-nextjs-prerender`, because in development every request is rendered fresh so that
your edits show up at once. The development HTML is also larger (about 10 KB against 6 KB for this page): it loads
extra development-only scripts (the error overlay, the dev tools, Fast Refresh) and the JavaScript it references is
unminified.

</details>

5. **Fix the clock four ways (read ahead).** The build error above listed four fixes for `new Date()`. Without
   writing the code yet, describe in one sentence each what the page would show to two visitors who open it one minute
   apart, for each fix.

<details>
<summary>Solution</summary>

- **dynamic** (`await connection()` before the call): the page is rendered for each request, so each visitor sees the
  exact time of their own request.
- **cache** (`'use cache'`): the time is computed once and cached; both visitors see the same time (the time the
  cache entry was created) until the cache entry expires.
- **client** (a Client Component): the server sends HTML without the time (or with a placeholder), and each
  visitor's browser fills in the time on their own device when the JavaScript runs.
- **measure** (`performance.now()`): not a clock at all; it is for measuring durations, so it doesn't fit "show the
  current time" and is the fix for timing code, not for content.

Chapter 7 implements the first three.

</details>

---
← Previous: [README](README.md) | Next: [React and TypeScript essentials](02-react-and-typescript-essentials.md) →
