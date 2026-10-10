# AGENTS.md — How to create a tutorial in this folder

This folder holds long-form, text-only tutorials, one per directory, named `<topic>-tutorial/`
(e.g. `docker-tutorial/`, `postgresql-tutorial/`, `php-tutorial/`). Read this file before you create or continue one.

## What the user wants

- **Text tutorials in Markdown**, from absolute basics to advanced topics. The goal is a reader who is *skilled*: able to
  design, build, debug, secure and ship real work. Cover how things work inside, production concerns, common mistakes
  and best practices, not just syntax.
- **The reader already knows JavaScript/Node.js.** Use that knowledge depending on the kind of tutorial:
  - **Tools and platforms** (Docker, CI, cloud, …): use Node.js apps for every example. Briefly explain any Node detail
    that matters to the topic, but don't teach Node itself.
  - **Other programming languages** (PHP, …): you may use a Node.js concept *only where it makes an idea click faster*.
    Put it in a short optional callout (format below). The tutorial must **never read as a comparison with Node.js**.
    Every explanation has to make sense to someone who has never used Node.
  - **Databases and other topics:** keep the main material topic-pure (e.g. SQL and `psql`). Show app-integration
    examples in Node.js, plus PHP where it fits.
- **Mark "just a taste" snippets.** A snippet that only shows that a feature exists, and that the reader doesn't need to
  learn yet, must say so clearly (exact marker below).

## Defaults (the user's earlier answers; ask again only if a new topic makes them unclear)

| Question | Default |
|---|---|
| Layout | `README.md` index plus numbered chapter files, never one giant file |
| Exercises | 3–6 per chapter, easy → hard, solutions hidden in `<details>`; a capstone project as the last chapter |
| Language tutorials | Cover the core language in depth, then one closing chapter introducing the major frameworks |
| App-integration examples | Node.js and PHP |
| Size | Roughly 19–24 chapters for a big topic. Typical chapter is ~300–900 lines; the capstone can be longer |

## Before writing

1. **Check the installed toolchain** and target the current stable versions, e.g. `docker --version`,
   `node --version`, `php --version`, `psql --version`, `composer --version`. On 2026-10-11 the machine had Docker 29.7,
   Node 24, PHP 8.5 and PostgreSQL 18; Composer was *not* installed. Re-check, don't trust these numbers.
2. **Verify version-specific facts** in official docs (WebSearch/WebFetch) and by running them locally. Never invent
   flags, functions or settings. Label newer features with the version that introduced them, e.g. "PHP 8.4+",
   "PostgreSQL 18+".
3. **Outline the chapters**, from basics to advanced, ending with a capstone. Pick **one running example** (an app, a
   schema, …) that grows from chapter to chapter, so later chapters build on earlier ones.

## Writing order (keeps the work resumable)

Long runs get stopped and resumed. Agents switched mid-task lose their in-memory plan, so put the plan on disk:

1. **Write `README.md` first**, with the full planned table of contents (links to chapters not written yet are fine
   until the end). It must cover what the tutorial is, who it's for, prerequisites, how to use it, the markers below,
   and the linked table of contents.
2. **Write chapters strictly in order, one complete chapter per file write.** A chapter file that exists is finished.
3. **Write example code together with the chapter that introduces it**, not all up front. Otherwise long stretches
   pass with no chapter on disk.
4. **When continuing someone else's tutorial:** read `README.md` and the last chapter fully, and skim the others to
   match their voice, template, footer format and running example. Don't rewrite finished chapters. Check that any
   example code they left behind is complete and runs.

## Directory layout

```text
<topic>-tutorial/
  README.md                 # overview, prerequisites, how to use, markers, linked TOC
  01-<kebab-title>.md       # chapters: two-digit number + kebab-case title
  02-<kebab-title>.md
  ...
  NN-capstone-project.md
  examples/                 # optional: runnable multi-file code (apps, capstone source)
  sql/                      # optional: setup/seed/reset scripts (database tutorials)
```

Chapters still show the important code inline, so the tutorial reads fine without opening `examples/`. Write only
inside the tutorial's own directory.

## Chapter template

```markdown
# Chapter N — Title

## What you'll learn
- bullet list

...body: explain *why* before *how*; mental models, internals, pitfalls, best practices;
show commands/queries with their expected output where it helps...

## Key takeaways
- bullet list

## Exercises

1. **Exercise title.** Task description.

<details>
<summary>Solution</summary>

Solution code and explanation. (Keep the blank lines after <summary> and before </details>
so the Markdown inside renders.)

</details>

---
← Previous: [Title](NN-file.md) | Next: [Title](NN-file.md) →
```

The first chapter's "Previous" and the last chapter's "Next" point to `README.md`.

## Markers (use the exact wording so they can be grepped)

**Just a taste.** Put this directly before any snippet that only shows that a feature exists:

```markdown
> **Just a taste — you don't need to learn or fully understand this now.** <one line: why it's shown / which chapter covers it>
```

Snippets the reader is expected to learn get no marker. Use it honestly, e.g. a preview of later material in
chapter 1, framework or orchestration code, or niche extensions.

**Node.js bridge** (language tutorials only):

```markdown
> **If you know Node.js:** <one to three sentences>
```

Use zero to two per chapter, and many chapters should have none. Never write "unlike Node…" in the main text.

## Style

- Every fenced code block has a language tag (`bash`, `dockerfile`, `yaml`, `js`, `sql`, `php`, `text` for output, …).
- Be thorough and precise, not padded. Use tables and ASCII diagrams where they genuinely clarify.
- Light cross-links between tutorials are fine (e.g. `../postgresql-tutorial/README.md`), but each tutorial stands alone.

## Verifying code (in a scratch directory only, never inside the tutorial)

- **PHP:** `php -l file.php`, then run the snippet and compare its output with what the chapter shows. Don't install
  Composer or anything else globally. Check Composer/PHPUnit-dependent code by careful review.
- **Node.js:** `node --check file.js`, and run small servers briefly on non-default ports, then stop them.
- **PostgreSQL:** use a throwaway cluster that can't touch any real database:
  ```bash
  S=<scratch>/pg; mkdir -p "$S/sock"
  initdb -D "$S/data" -U postgres --auth=trust
  pg_ctl -D "$S/data" -l "$S/pg.log" -o "-p 54329 -k $S/sock -c listen_addresses=''" start
  psql -h "$S/sock" -p 54329 -U postgres
  # ...when finished:
  pg_ctl -D "$S/data" stop && rm -rf "$S/data"
  ```
- **Docker:** validate compose files with `docker compose -f <file> config`. Don't pull images, build images or start
  containers unless the user allows it. Check Dockerfiles by careful review.
- **Before finishing, stop every process you started** (dev servers, database clusters).

## Final checks (run on the tutorial directory before reporting)

```bash
python3 - <tutorial-dir> <<'PY'
import re, sys, pathlib
root, bad = pathlib.Path(sys.argv[1]), 0
for md in sorted(root.rglob('*.md')):
    text = md.read_text()
    for target in re.findall(r'\]\(([^)#\s]+)(?:#[^)]*)?\)', text):
        if '://' not in target and not (md.parent / target).exists():
            print(f'{md}: broken link -> {target}'); bad += 1
    if text.count('<details>') != text.count('</details>'):
        print(f'{md}: unbalanced <details>'); bad += 1
    if re.match(r'\d\d-', md.name) and md.parent == root:
        for heading in ('## Key takeaways', '## Exercises'):
            if heading not in text:
                print(f'{md}: missing "{heading}"'); bad += 1
print('OK' if not bad else f'{bad} problem(s)'); sys.exit(1 if bad else 0)
PY
grep -c "Just a taste" <tutorial-dir>/*.md          # markers per chapter
grep -c "If you know Node.js" <tutorial-dir>/*.md   # language tutorials: keep these sparse
```

Then spot-read an early, a middle and an advanced chapter for depth and accuracy.

## Writing several tutorials at once

- Use **one agent per tutorial**, all launched in parallel in the background at **medium effort** (the user switched to
  medium during the first attempt).
- Give each agent this file to read, plus the topic, its chapter outline and its own scratch subdirectory. Each agent
  writes only inside its own tutorial directory.
- When they finish, run the final checks above on each tutorial and report: the chapter list, line counts, marker
  counts, and anything left unverified.
