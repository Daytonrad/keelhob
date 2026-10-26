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
node tests/rig/ui/check-admin.mjs    --wp http://localhost:8899 --rig /c/wp-rig/wordpress --cli "php C:/wp-cli/wp-cli.phar"
node tests/rig/ui/check-snippets.mjs --wp http://localhost:8899 --rig /c/wp-rig/wordpress
node tests/rig/ui/check-compose.mjs  --wp http://localhost:8899 --rig /c/wp-rig/wordpress
node tests/rig/ui/check-thumbs.mjs   # needs one row in the tool log first
node tests/rig/check-abilities.mjs   --wp http://localhost:8899 --rig /c/wp-rig/wordpress --user admin --pass rigpass
```

`check-thumbs.mjs` measures a real thumbs form, and the form only exists if the
run log has a row in it, so drive one tool call through the MCP endpoint first —
or run `check-abilities.mjs`, which leaves log rows behind. Those rows are
residue, not fixtures: clear them when you are done.

Five files, because they cover different screens and different claims. The
table at the bottom is the record; the notes below it say what each one is for.

Every file in the table was last run green on **2026-10-25**, against a rig on
Keelhob 2.0.0 — 162 + 54 + 26 + 11 + 79 = **332**.
`tests/unit/test-doc-counts.php` reads the per-file numbers in this table and
fails if ROADMAP.md stops agreeing with them, so **update this table in the same
commit that adds or removes a check.**

On Windows you must serve the rig with several `php -S` instances, not one, or
every check here times out at login — see "Serving it" in `tests/rig/README.md`.

| File | Screen | Checks |
|---|---|---|
| `check-admin.mjs` | all seven Keelhob screens, tokens and contrast | 162 |
| `check-snippets.mjs` | the snippet list and the editor | 54 |
| `check-compose.mjs`  | AI Compose, which is a chat | 26 |
| `check-thumbs.mjs`   | the thumbs control, and that it reverts rather than lying | 11 |
| `check-abilities.mjs` | agent-written abilities, end to end | 79 |

Two notes on that table, because two of its rows are not quite like the others.

`check-admin.mjs` is **162 on its default run**, and that is the number counted
here. Its flags add assertions rather than replacing them: `--dark` adds 6 and
`--rtl` adds 8, so the eight cells of the full matrix run 162, 168, 162, 168,
170, 176, 170 and 176 — **1,352 assertions in all**. Counting the default run is
the convention that keeps one file to one number.

`check-abilities.mjs` lives in **`tests/rig/`, not in this directory**, because
it is not a screen check: it drives the real MCP endpoint, asserts against the
database and the filesystem, and only then opens a browser to approve what it
created. It is counted here anyway, because it opens a browser and because a
figure kept in two places is a figure that rots. Its 79 are not double-counted
against the end-to-end total ROADMAP.md states separately: that total belongs to
`tests/rig/e2e-mcp.mjs`, a different file, and this one is not in it.

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
node tests/rig/ui/check-admin.mjs --dark      # a pinned administrator on a dark colour scheme
node tests/rig/ui/check-admin.mjs --width 782 # tablet, where wp-admin restacks
node tests/rig/ui/check-admin.mjs --rtl       # a genuinely right-to-left install
node tests/rig/ui/check-admin.mjs --dark --rtl --width 782
```

The three flags are independent and combine, which is the whole matrix:
`{light, dark}` × `{LTR, RTL}` × `{1440, 782}`. Each cell runs as its own pinned
administrator — `kh_rig_light`, `kh_rig_dark`, `kh_rig_rtl_light`,
`kh_rig_rtl_dark` — created if missing and then left in place, so two agents
running cells at once cannot restore one another's shared state mid-flight.

**`--rtl` is a real RTL install, not an injected `body.rtl` class.** Forcing the
class mirrors the panel's own negative margin and nothing else: it does not make
WordPress enqueue `wp-admin-rtl.css` or `colors-<scheme>-rtl.css`, and those are
what move `#wpcontent`'s padding and the admin menu to the other side. The first
RTL pass on this build faked it that way and reported a 20px horizontal overflow
that does not exist. So `--rtl` installs the `ar` language pack if the rig has
not got it and signs in as an administrator whose `locale` user meta is `ar`,
then asserts the proof on every screen: `<html dir="rtl" lang="ar">`,
`body.rtl`, and a core stylesheet actually served in its RTL form. A faked RTL
goes red instead of quietly passing.

Overflow is swept against **both** viewport edges for the same reason. "Past the
right" is the inline-end edge in LTR and the inline-start edge in RTL, so a
right-only sweep reports every RTL screen clean whatever it does. At 600px — below
the 782 floor — Connect overflows 23px and Automation 82px, past the right in LTR
and past the left in RTL: the same two elements, mirrored exactly.

It walks all seven Keelhob screens — Connect, Skills, Automation, Guide,
Activity, the Snippets Studio and Donate — and asserts, per screen, that the
`.kh-ui` panel root
is present, that the `--kh-*` custom properties actually **resolve** in the
browser, that the panel background is painted rather than inherited, that the
panel ink clears 4.5:1 against it, that `is-dark` tracks the WordPress admin
colour scheme, that the tab strip is `a[href]` links with exactly one
`aria-current="page"` and no buttons, that nothing overflows the viewport
horizontally, and that every muted and accent word on the screen clears 4.5:1
measured from its **composited** colour against the first non-transparent
background above it.

Donate was the seventh, and it was added to `SCREENS` only after it passed.
It had been absent for the whole redesign, which is exactly why it was the one
screen never migrated: it rendered a bare `<div class="wrap">`, no token
resolved on it, and it carried one AA failure in all eight cells. Because it is
not one of the six tabs, its entry is marked `inStrip: false` — it must carry
the strip as the way back out of the ask, and **no** tab in that strip may claim
to be the current page. That is asserted, not skipped.

It was written before the redesign on purpose, and its first run — 2026-10-24,
against the un-redesigned screens — was **30 of 60 red**, failing on the panel
root, the token layer, the panel background and the tab strip on all six
screens it then listed. That red run is the baseline reproducing itself.

**Its count was held out of the table during the redesign**, because the number
moved with every step and `tests/unit/test-doc-counts.php` would have demanded a
matching edit to ROADMAP.md each time. The redesign is finished and the figure
has stopped moving, so as of **2026-10-25** it is in the table at 162, and
ROADMAP.md was updated in the same commit.

**Run it without `--user`.** The `--user` / `--pass` flags are an escape hatch:
given an explicit user, the harness touches no colour scheme, and every dark
cell then measures against `admin-color-fresh` and reports around fourteen
failures that are not real. Pass `--cli "php C:/wp-cli/wp-cli.phar"` and let it
pick its own pinned administrator.
