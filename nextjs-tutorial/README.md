# The Next.js Tutorial — from first page to production

A long-form, text-only tutorial that takes you from "what is Next.js?" to designing, building, testing, securing and
shipping real full-stack React applications with the App Router. It covers the everyday tools in depth (file-system
routing, layouts, Server and Client Components, data fetching, Server Actions, navigation, Route Handlers, Proxy),
the programming model that defines modern Next.js (Cache Components: a prerendered static shell, `'use cache'`, cache
tags, streaming with `<Suspense>`, Partial Prefetching and instant navigations), and everything around it in
production: authentication and authorization, metadata and SEO, testing, performance, observability, self-hosting
with Docker, and how the framework works inside. It closes with a capstone project that puts everything together.

The tutorial targets **Next.js 16.4.2** (the current release, published on 2026-10-15) with **React 19.3** and
**Node.js 24**. Every version-specific fact was checked against the documentation that ships inside the `next`
package (`node_modules/next/dist/docs/`) and the 16.3 and 16.4 release notes, and every example was run with Next.js
16.4.2 on Node.js 24.22. Features that arrived in recent releases are labelled with the version that introduced them,
for example "Next.js 16.4+", so you know what works on an older project.

## Who it's for

- You know JavaScript and Node.js: you can write an HTTP server, you know what `package.json`, `npm install`,
  `async`/`await` and ES modules are, and you have used `fetch`.
- You know a little HTML and CSS, and maybe some React. Chapter 2 covers the React and TypeScript you need, so you
  don't have to be a React developer to start.
- You have seen Next.js before, or used an older version with `getServerSideProps`, and want to understand the current
  model properly: what runs on the server and what runs in the browser, why a page is static or dynamic, what is
  cached and for how long, and how to change data safely.
- You want to become *skilled*: able to build a full-stack Next.js application from an empty folder, reason about its
  performance and security, test it, and deploy it on your own server.

The tutorial does not teach Node.js. Where a Node detail matters (the `node:sqlite` module, `node:crypto`,
environment variables, process signals), it is explained briefly in place.

## Prerequisites

| You need | Why | Check |
|---|---|---|
| Node.js 24 (20.9 is the minimum Next.js 16 supports) | Running Next.js and the example app | `node --version` |
| npm 11 (bundled with Node.js 24) | Installing packages | `npm --version` |
| A terminal with `curl` | Looking at raw responses, headers and redirects | `curl --version` |
| A code editor with TypeScript support (VS Code or similar) | Writing the app; Next.js adds a TypeScript plugin | |
| A modern browser with developer tools | Watching requests, the RSC payload and the console | |
| Optional: Docker | Chapter 20 (building and running a production image) | `docker version` |
| Optional: Git | Keeping track of your changes chapter by chapter | `git --version` |

The example app uses the SQLite module built into Node.js (`node:sqlite`), so there is no database server to
install. Chapter 1 explains why Node.js 24 is the recommended version.

## How to use this tutorial

- **Read in order.** Each chapter builds on the previous ones, and the running example grows as you go.
- **Type the code and run it.** Where output matters (a build's route table, a `curl` response, an error message), it
  is shown in a `text` block right after the command. Hashes, timings, dates and generated IDs will differ on your
  machine; the chapter says what to look at.
- **Keep the dev server running.** Most chapters assume `npm run dev` is running in a second terminal. Some behaviours
  (caching, prerendering, prefetching) only show up in a production build, and those sections say so and use
  `npm run build && npm start` instead.
- **Do the exercises.** Every chapter ends with 3–6 exercises from easy to hard. Solutions are hidden in collapsible
  "Solution" sections. Try first, then compare.
- **Read the docs that came with your version.** Since Next.js 16.2, the complete documentation for the installed
  version ships inside the package at `node_modules/next/dist/docs/`. It always matches the code you are running,
  which matters for a framework that changes this quickly. The online version is at <https://nextjs.org/docs>.

### The running example: Gather

Throughout the tutorial you build **Gather**, a site for tech meetups. Visitors browse and search upcoming events;
members sign up, RSVP and join the discussion under each event; organizers create, edit and delete events. It starts
in chapter 1 as a fresh `create-next-app` project, gets its pages, layouts and navigation in chapter 3, its first
interactive component in chapter 4, styles, fonts and images in chapter 5, a real SQLite database in chapter 6, a
prerendered static shell with cached data in chapter 7, forms backed by Server Actions in chapter 8, and instant
navigations and error pages in chapters 9 and 10. Part II adds a JSON API and calendar downloads, Proxy, a modal
attendee list with parallel and intercepting routes, metadata and Open Graph images, real authentication with roles,
and an on-demand revalidation webhook. Part III tests it with Vitest and Playwright, measures and monitors it, and
ships it as a small Docker image. The capstone (chapter 23) adds waitlists and in-app notifications across the whole
stack. The finished app is in [`examples/gather/`](examples/gather/), and the capstone's changes are in
[`examples/capstone/`](examples/capstone/).

## Markers

One marker appears throughout. It always uses the same wording, so you can search for it.

> **Just a taste — you don't need to learn or fully understand this now.** This marks a snippet that only shows that a
> feature exists. A later chapter (named in the note) teaches it properly, or the feature is niche enough that knowing
> it exists is all you need.

Snippets without a marker are meant to be learned.

## Table of contents

### Part I — Foundations

1. [Getting started](01-getting-started.md) — what Next.js is and what it adds to React, the App Router and the Pages
   Router, versions and release cadence, creating a project with `create-next-app`, the generated files (including
   `AGENTS.md` and the bundled docs), `next dev` and Turbopack, and what happens when you request a page.
2. [React and TypeScript essentials](02-react-and-typescript-essentials.md) — JSX, components and props, children,
   lists and keys, state and events, effects and when not to use them, the React 19 additions Next.js builds on
   (`use`, actions, `useActionState`, `useOptimistic`), and the TypeScript you need for typed components.
3. [Routing: pages and layouts](03-routing-layouts-and-pages.md) — the `app` folder, `page` and `layout` files,
   nested routes and layouts, dynamic segments and `params`, `searchParams`, `<Link>`, the root layout, colocation and
   private folders, and typed routes with `PageProps` and `LayoutProps`.
4. [Server and Client Components](04-server-and-client-components.md) — where code runs, the `'use client'`
   boundary, what can cross it (serializable props), composition patterns, `server-only`, context providers, third-
   party components, and avoiding common mistakes.
5. [Styling, fonts and images](05-styling-fonts-and-images.md) — global CSS and CSS Modules, Tailwind CSS and other
   options, `next/font` and self-hosted fonts, `next/image` with sizes and placeholders, and static assets in
   `public/`.
6. [Fetching data and streaming](06-fetching-data-and-streaming.md) — async Server Components, a SQLite data layer
   with `node:sqlite`, `fetch` in Server Components, parallel and sequential requests, `<Suspense>` and `loading.tsx`,
   streaming HTML, and passing promises to Client Components with `use`.
7. [Cache Components and rendering](07-cache-components-and-rendering.md) — the static shell and Partial
   Prerendering, runtime data (`cookies`, `headers`, `searchParams`, `params`), `'use cache'`, `cacheLife` and
   `cacheTag`, `connection()`, reading the build output, and the rules that decide what gets prerendered.
8. [Server Actions and forms](08-server-actions-and-forms.md) — Server Functions and `'use server'`, forms with
   `action`, validation, `useActionState` and `useFormStatus`, `updateTag`, `revalidateTag`, `revalidatePath` and
   `refresh`, redirects, optimistic updates, progressive enhancement, and security.
9. [Navigation and prefetching](09-navigation-and-prefetching.md) — client-side navigation, the router cache,
   prefetching and Partial Prefetching (16.4+), instant navigations and the `instant` config (16.3+), `navigation()`
   and `prefetch()` (16.4+), `next/form`, `useRouter`, `usePathname` and friends, scroll, and View Transitions.
10. [Error handling](10-error-handling.md) — expected errors as return values, `error.tsx` and `retry`, `catchError`
    (16.3+), `global-error.tsx`, `notFound()` and `not-found.tsx`, `forbidden()` and `unauthorized()`, and what users
    and crawlers see.

### Part II — Building a full application

11. [Route Handlers](11-route-handlers.md) — `route.ts`, HTTP methods, `Request`/`Response` and `NextRequest`/
    `NextResponse`, dynamic segments with `RouteContext`, caching handlers, a JSON API, file downloads, webhooks, CORS,
    and when to use a Route Handler instead of a Server Action.
12. [Proxy, redirects and headers](12-proxy-redirects-and-headers.md) — `proxy.ts` (formerly middleware), matchers,
    rewriting, redirecting and setting headers, `redirects`, `rewrites` and `headers` in `next.config.ts`, and what
    belongs where.
13. [Advanced routing](13-advanced-routing.md) — route groups, catch-all and optional catch-all segments,
    `generateStaticParams`, parallel routes and slots, intercepting routes and modals, `default.tsx`, root params
    (16.3+), and multiple root layouts.
14. [Metadata and SEO](14-metadata-and-seo.md) — the `metadata` object and `generateMetadata`, title templates,
    `metadataBase`, Open Graph images with `ImageResponse`, `sitemap.ts`, `robots.ts`, icons and manifests, streaming
    metadata, and how bots are served.
15. [Authentication and authorization](15-authentication-and-authorization.md) — sessions vs. tokens, password
    hashing, secure cookies, a session store in SQLite, the data access layer, protecting pages, actions and Route
    Handlers, roles, Proxy as an optimistic check, and auth libraries.
16. [Caching in depth](16-caching-in-depth.md) — the layers (request memoization, `'use cache'`, the router cache,
    HTTP caching), cache keys and serialization, `'use cache: private'` and `'use cache: remote'`, custom cache
    handlers, on-demand revalidation by webhook, and the previous caching model for older projects.
17. [Configuration, build and Turbopack](17-configuration-build-and-turbopack.md) — `next.config.ts`, environment
    variables and `NEXT_PUBLIC_`, the `.env` files, TypeScript and ESLint setup, `next build` and its output, Turbopack
    and its filesystem cache, the CLI, and the experimental flags worth knowing.

### Part III — Production

18. [Testing](18-testing.md) — what to test where, unit tests with Vitest, testing Server Actions and data access,
    end-to-end tests with Playwright against a production build, testing instant navigations with `@next/playwright`
    (16.3+), and running tests in CI.
19. [Performance and observability](19-performance-and-observability.md) — Core Web Vitals, measuring with
    `useReportWebVitals`, bundle size and the bundle analyzer, lazy loading, `after()`, `instrumentation.ts` and
    `onRequestError`, OpenTelemetry, and logging.
20. [Deployment and self-hosting](20-deployment-and-self-hosting.md) — what a deployment needs, `next start` behind a
    reverse proxy, `output: 'standalone'`, a production Docker image for Gather, caching and multiple instances,
    static exports, managed platforms, and a production checklist.
21. [How Next.js works inside](21-how-nextjs-works-inside.md) — the React Server Components payload, how a request
    is rendered, prerendering and resuming, the client router and segment cache, Server Action requests, the build
    output on disk, and Turbopack's architecture.
22. [Upgrading and the ecosystem](22-upgrading-and-the-ecosystem.md) — release channels, `next upgrade` and codemods,
    agent-assisted upgrades (16.3+), migrating from the Pages Router, the Pages Router in brief, and the libraries
    people use with Next.js.
23. [Capstone project: waitlists and notifications](23-capstone-project.md) — designing a feature across the whole
    stack: schema changes, a transactional waitlist, Server Actions, cache tags, a notifications inbox with an unread
    badge, background work with `after()`, an organizer CSV export, tests, and deployment.

## Repository layout

```text
nextjs-tutorial/
  README.md              # this file
  01-...md … 23-...md    # the chapters
  examples/
    gather/              # the finished Gather app (end of chapter 22)
    capstone/            # the files the capstone adds or changes
```

## Conventions

- Code blocks are tagged with their language: `tsx` for components, `ts` for other TypeScript, `bash` for shell
  commands, `css`, `json`, `sql`, `dockerfile`, `http` for raw requests and `text` for output.
- File names appear in a comment on the first line of a code block (`// app/page.tsx`), and paths are relative to the
  project root.
- Shell commands start without a prompt character, so you can paste them directly. They are written for a Linux or
  macOS shell; on Windows, use WSL 2 or adjust `export`, `rm -rf` and line continuations.
- The tutorial uses TypeScript throughout, because that is what `create-next-app` sets up and what Next.js generates
  types for. Every file works as JavaScript too if you remove the types and rename `.tsx` to `.jsx`.
- When code changes a file shown earlier, the chapter shows the whole new version unless the change is one or two
  lines, in which case it says exactly where they go.
