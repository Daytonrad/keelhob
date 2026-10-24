# Browser checks

`tests/run.php` runs against stubs. `tests/rig/run-all.sh` runs against real
WordPress, from inside PHP. Neither of them opens a browser, and there is a
whole class of bug that only a browser can see.

The bug that caused this directory to exist:

`Keelhob_Studio_Edit::render()` calls `wp_enqueue_code_editor()`, enqueues
`wp-codemirror`, calls `wp.codeEditor.initialize()`, and wires up a mode
switcher for the type selector. Read the source and the screen has a proper
code editor. Render it and it has a plain `<textarea>` — the inline init script
prints as script 9 of 85 and the library it needs is script 74, so
`wp.codeEditor` does not exist yet and a truthy guard swallows the whole thing
in silence.

Grep could not see it. The PHP rig checks could not see it. It shipped.

## Run it

```bash
# Serve the rig first.
cd /c/wp-rig/wordpress && PHP_CLI_SERVER_WORKERS=4 php -S localhost:8899 &

npm install                 # once: playwright-core, nothing else
node tests/rig/ui/check-snippets.mjs --wp http://localhost:8899 --rig /c/wp-rig/wordpress
node tests/rig/ui/check-compose.mjs  --wp http://localhost:8899 --rig /c/wp-rig/wordpress
```

Two files, because they cover two screens:

Both files were last run green on **2026-09-23** (54 + 26 = 80) against a
freshly built rig on Keelhob 2.0.0. `tests/unit/test-doc-counts.php` reads the
per-file numbers in this table and fails if ROADMAP.md stops agreeing with them,
so **update this table in the same commit that adds or removes a check.**

On Windows you must serve the rig with several `php -S` instances, not one, or
every check here times out at login — see "Serving it" in `tests/rig/README.md`.

| File | Screen | Checks |
|---|---|---|
| `check-snippets.mjs` | the snippet list and the editor | 54 |
| `check-compose.mjs`  | AI Compose, which is a chat | 26 |

`check-compose.mjs` makes **no AI call**. Every assistant turn it asserts on is
server-rendered markup injected through the same path the live screen uses, so
the run is free and deterministic — what is under test is the screen, not the
model. It is also the only layer that can see two things nothing else can: that
model output is ESCAPED rather than executed (a snippet containing a `<script>`
tag must not run inside wp-admin), and that the message box is still on screen
after twenty messages. The second one failed on its first run, at y=682 in a
674px window.

Options: `--user`, `--pass`, `--chrome <path to an executable>`, `--headed`,
`--cli "php /c/wp-cli/wp-cli.phar"`.

No browser download is needed. `playwright-core` ships none on purpose — a PHP
plugin repo should not carry 150 MB of Chromium in its dev dependencies — so
the harness finds whatever Chrome or Chromium the machine already has, newest
first, and falls back to the system install.

## What it asserts

Every check names the baseline row it replaces. The baselines are the sixteen
measurements in `docs/plans/snippets-rebuild.md`, taken on 2026-09-13 against
Keelhob 1.1.0.

The first run of this file, against the unfixed screen, was **29 of 34 red**.
That was the point: a browser check written after the fix can only tell you the
fix is still there. Written before it, it tells you the problem was real.

## Rules this file follows

**Fixtures are torn down in the same run that asserts on them.** The `finally`
block at the bottom runs after a pass, after a failed assertion and after a
thrown error. One of the fixtures carries a deliberate `last_error`; a rig left
holding it fails its own next run for the wrong reason.

**Nothing is asserted from markup read off disk.** Computed styles, bounding
boxes and live DOM only. `onScreen()` in `harness.mjs` exists because
WordPress's `.row-actions` are `visibility: visible`, have real dimensions, and
sit at `left: -129987px` — every naive visibility test passes on them.

**Assertions never throw.** A thrown assertion would report one failure out of
twenty-nine on the run that was supposed to show all of them.

## In CI

Advisory first. A new browser layer is flaky before it is trustworthy, and a
flaky gate on a one-person project blocks the one person. The job runs with
`continue-on-error: true` and prints what it found. Once it has been green for
seven consecutive days on `main`, that flag comes off.

## `check-admin.mjs` — the admin panel

A third file, added on **2026-10-24** for the admin redesign on
`feature/admin-redesign`:

```bash
node tests/rig/ui/check-admin.mjs --wp http://localhost:8899 --rig /c/wp-rig/wordpress
node tests/rig/ui/check-admin.mjs --dark      # switches the admin colour scheme, then restores it
node tests/rig/ui/check-admin.mjs --width 782 # tablet, where wp-admin restacks
```

It walks all six Keelhob screens — Connect, Skills, Automation, Guide, Activity
and the Snippets Studio — and asserts, per screen, that the `.kh-ui` panel root
is present, that the `--kh-*` custom properties actually **resolve** in the
browser, that the panel background is painted rather than inherited, that the
panel ink clears 4.5:1 against it, that `is-dark` tracks the WordPress admin
colour scheme, that the tab strip is `a[href]` links with exactly one
`aria-current="page"` and no buttons, that nothing overflows the viewport
horizontally, and that every muted and accent word on the screen clears 4.5:1
measured from its **composited** colour against the first non-transparent
background above it.

It was written before the redesign on purpose, and its first run — 2026-10-24,
against the un-redesigned screens — was **30 of 60 red**, failing on the panel
root, the token layer, the panel background and the tab strip on all six
screens. That red run is the baseline reproducing itself.

**Its count is deliberately NOT in the table above yet.** The number moves with
every step of the redesign, and `tests/unit/test-doc-counts.php` would then
require a matching edit to ROADMAP.md's browser-check total on each one. It gets
folded into the counted table, with ROADMAP.md updated in the same commit, at
step 8 — once the figure has stopped moving.
