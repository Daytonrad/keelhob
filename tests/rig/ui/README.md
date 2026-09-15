# Browser checks

`tests/run.php` runs against stubs. `tests/rig/run-all.sh` runs against real
WordPress, from inside PHP. Neither of them opens a browser, and there is a
whole class of bug that only a browser can see.

The bug that caused this directory to exist:

`Claudpress_Studio_Edit::render()` calls `wp_enqueue_code_editor()`, enqueues
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
Claudpress 1.1.0.

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
