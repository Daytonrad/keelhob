# Snippets — stop it being embarrassing

**Version 2. BUILT AND VERIFIED 2026-09-13.** Shipped as 1.2.0 on
`feature/snippets-polish`.

Written after measuring the current screen on the rig instead of describing it,
then built against those measurements. Results at the bottom.

Version 1 of this plan proposed absorbing VibeSnip into Keelhob: five tabs, a
conditional-logic rule builder, a signed snippet Library, an importer, and a
What's New screen. That plan is withdrawn. What replaces it is narrower, aimed
at one thing, and every item in it is backed by a number taken from the running
product.

---

## Why v1 was withdrawn

Three decisions, in the owner's words, reshaped the scope:

1. **Timing: before the repo goes public**, not after. That makes the job
   "nothing here should make me wince when a stranger reads it", not "reach
   feature parity with another plugin".
2. **The page stays called Snippets.** Nobody outside this workspace knows what
   a VibeSnip is, and VibeSnip has no users to carry the name for it.
3. **Scope C — the embarrassment fix.** Library, Import, What's New and the rule
   builder are all out.

Dropping the rule builder removed the single most dangerous item in v1. Two
incompatible `conditions_json` formats meant an old snippet opened in a new rule
editor would either be silently rewritten — turning "members only" into
"everyone" without telling anybody — or refuse to open at all. With no rule
builder, Keelhob keeps the flat format it already has, which has no UI, is
only ever written by an agent over MCP, and is touched by nothing in this plan.

**Success is no longer "matches VibeSnip".** Nobody asked for VibeSnip. See
Part 8 for what replaces it.

---

## The baseline

Taken 2026-09-13 against the kept rig at `C:\wp-rig` (Keelhob 1.1.0, six
seeded snippets, Chrome at 1536x674), by rendering the page and reading the
DOM and computed styles — not by reading the source.

| # | Measured | Value |
|---|---|---|
| B1 | `.CodeMirror` element on the Add Snippet screen | **absent** |
| B2 | Inline init script position vs `code-editor.min.js` | **script 9 of 85 vs script 74** |
| B3 | Code box top, from document top | **736 px** |
| B4 | Scroll needed before the code box is in view | **636 px** |
| B5 | Fields rendered above the code box | **6** (Name, Description, Type, Runs where, Priority, Tags) |
| B6 | `.row-actions` computed position | **`left: -129987px`** — off-screen until hover |
| B7 | Inline on/off controls in the table body | **0** |
| B8 | Sortable column headers | **0** |
| B9 | Priority column in the table | **absent** |
| B10 | Status filter options | **Any status / On / Off** — no Errored |
| B11 | `Keelhob_Donate::button()` call sites | **7**, across 5 files |
| B12 | `class-admin-snippets.php` — a second, complete Snippets screen | **337 lines, referenced by nothing, shipped in the zip** |
| B13 | "Browse the library" button target | `tab=library` -> falls back to All Snippets. Silent no-op. |
| B14 | Ways to dismiss an AI plan without reloading | **0** |
| B15 | Save button shown when the syntax check says *failed* | **yes**, unchanged from the passing case |
| B16 | `Keelhob: ON` toolbar chip, in wp-admin, with `show_admin_bar` filtered false by a live snippet | **still present** |

What the baseline is **not**: broken everywhere. Pagination already works (20 a
page). The `.subsubsub` type tabs already work and already hide filters that can
only return nothing. Sorting is already implemented in
`Keelhob_Studio_List::sort()` — it simply has no UI to reach it.
`Keelhob_Snippets::save()` already parse-checks and already refuses to let
unparsed PHP go active. The engine is sound. This is a surface problem.

---

## Part 1 — The code editor has never worked

**The most important item in this plan, and it is a bug, not a feature.**

`Keelhob_Studio_Edit::render()` calls `wp_enqueue_code_editor()`, enqueues
`wp-theme-plugin-editor`, enqueues `wp-codemirror`, and then emits an inline
`<script>` in the page body that calls `wp.codeEditor.initialize(...)`.

That inline script is **script 9**. `code-editor.min.js` is **script 74**,
because everything enqueued from a screen render callback prints in the footer.
So at the moment the init runs, `wp.codeEditor` does not exist yet, the guard

```js
var editor = wp.codeEditor && wp.codeEditor.initialize ? ... : null;
```

evaluates to `null`, and the page silently keeps its plain `<textarea>`.
No error, no warning, no line numbers, no syntax colour.

Proved on the rig by running the same call by hand after load — it initialised
immediately and `.CodeMirror` appeared. The code is correct. Its timing is not.

There is a second, quieter consequence. `window.cpStudioEditorSettings` is
**never defined anywhere in the codebase**, so even on a page where the timing
worked, `initialize()` would receive `{}` instead of the settings object
`wp_enqueue_code_editor()` returns — losing the mode, the line numbers and the
lint hooks that call is there to configure.

And a third. The same inline block attaches the **"Ask AI what this does"**
click handler. That part does run, because it only needs the DOM. But it reads
the code from `editor.codemirror.getValue()` when an editor exists and falls back
to the textarea when it does not — so today it silently takes the fallback path
forever, and the branch written for the real editor has never executed anywhere.

### The fix

1. Return the settings array from `wp_enqueue_code_editor()` and pass it to the
   page through `wp_add_inline_script`, under the real name
   `cpStudioEditorSettings`. It must carry the CodeMirror mode for the snippet's
   type, `lineNumbers: true`, and `indentUnit: 4`.
2. Move the whole inline block out of the template and into
   `wp_add_inline_script( 'wp-theme-plugin-editor', ... )`, so WordPress
   guarantees it prints **after** the library rather than 65 scripts before it.
3. Delete the `wp.codeEditor && wp.codeEditor.initialize` guard. It is not a
   safety net; it is what turned a load-order bug into a silent one. If the
   editor cannot initialise after this change, the page should say so in a
   `notice-warning`, not shrug.
4. Keep the type-selector mode switch. It is correct and it has never run.

### The rule that comes out of this

**A feature that degrades silently will degrade silently forever.** The guard
was added so the page could not throw. What it actually bought was a broken
editor nobody noticed, including in a rig check written specifically to exercise
this screen. Part 8 exists because of this bug.

---

## Part 2 — Code first

The code box begins **736 px** down a page whose viewport is 674 px tall
(B3, B4). On an ordinary laptop, opening a snippet to look at its code shows you
six form fields and no code. Every single time.

Six fields (B5) sit above it: Name, Description, Type, Runs where, Priority,
Tags. Of those, exactly one — Name — is required. The other five are set once
and almost never revisited.

### The layout

```
+-------------------------------------------------------------+
| Name  [_______________________]     Type [PHP v]   [ ] On   |  <- one row
+-------------------------------------------------------------+
|                                                             |
|   1  <?php                                                  |
|   2  add_filter( 'the_content', function ( $html ) {        |  <- code, at once
|   3      return $html;                                      |
|   4  } );                                                   |
|                                                             |
+-------------------------------------------------------------+
| > Details — description, runs where, priority, tags         |  <- collapsed
+-------------------------------------------------------------+
| [ Save ]   [ Save & switch on ]       Ask AI what this does |
+-------------------------------------------------------------+
```

- **Name, Type and the on/off state** stay above the code, on one line. They are
  the three things you cannot write a snippet without.
- **Description, Runs where, Priority and Tags** move into a collapsed
  `<details>` below the code, open by default when any of them is non-default so
  an existing snippet never hides settings that are actually in use.
- **Target: the first line of code is visible on load with zero scrolling**, at
  1536x674. That is the acceptance test, not a design opinion.

### Save becomes the primary button

Today the primary (blue) button is **Save & switch on** and the secondary is
**Save (leave off)**. That is backwards for the most common action on this
screen, which is editing code that is already live: the visually loudest button
is the one that changes the site's running state.

- `Save` becomes `button-primary`.
- `Save & switch on` becomes a secondary button, and appears **only when the
  snippet is currently off**. Offering "switch on" for something already on is
  noise.
- No confirmation dialogs are added. Saving is already parse-checked and already
  refuses to activate code that does not parse.

**Not in scope:** versioning, drafts, autosave, or an undo. The editor says
plainly today that edits are not versioned. That sentence stays, and it stays
honest.

---

## Part 3 — An on/off switch you can see

`.row-actions` computes to `left: -129987px` (B6). The Switch on / Switch off
control is genuinely on the page and genuinely unreachable without first
discovering that hovering a table row reveals hidden links. There are **zero**
inline toggles in the table body (B7).

### The change

A **Status column containing a real control**, not a word plus a hidden link.

- A `<form>` per row posting to the existing `keelhob_studio` handler with
  `studio_action=activate|deactivate` — the POST-with-nonce rule stays exactly as
  it is. **State changes must never become GET links.** A GET that switches code
  on will be fired by a browser prefetch, a security scanner, or an email client
  following a link in a notification.
- The button is styled as a switch and is **visible at rest**. Measured
  acceptance: the control's bounding box intersects the viewport without a hover
  event.
- It is **keyboard reachable** — it is a `<button>` in a form, so it already is,
  but the test asserts it.
- `Edit`, `Duplicate` and `Delete` stay in `.row-actions` where WordPress puts
  them. They are not the common action and hiding them is the correct WordPress
  convention. The toggle is the one that has to come out.

**Rejected: a checkbox that toggles over AJAX.** It would be one fewer page load,
and it would mean the most consequential control on the screen has no visible
confirmation that anything happened. A full reload after a state change is slow
and completely unambiguous. Speed is not what this screen is short of.

**Rejected: bulk actions.** Selecting twelve snippets and switching them all on
at once is a way to take a site down in one click. Out of scope, and it should
stay out.

---

## Part 4 — Find the one that broke

Three gaps, all small, all measured.

### 4.1 An "Errored" status filter

The status filter offers Any / On / Off (B10). A snippet that crashed and
switched itself off is indistinguishable from one you deliberately switched off
— which is the exact moment somebody most needs to find it.

`last_error` is already stored, already rendered in the row, and already drives
the warning marker. Add a fourth option, `active=error`, filtering on
`last_error != ''`.

It appears in the `.subsubsub` bar alongside the type tabs, following the same
rule those already follow: **a filter that can only return nothing is not
shown.** No snippets have errored -> no Errored tab.

### 4.2 Sortable column headers

`Keelhob_Studio_List::sort()` already handles `name`, `priority` and
`updated`. `filters()` already reads `?orderby=` and `?order=`. There are zero
sortable headers in the markup (B8), so none of it is reachable.

Add WordPress's own `sortable` / `sorted` classes and header links. This is
markup only — no new query logic, no new sort code.

### 4.3 A Priority column

There is no priority column (B9), yet priority is the field that decides which of
two snippets wins when both touch the same thing. Add it, narrow, sortable, and
only visible once at least one snippet on the site has a non-default priority.
Showing a column of identical `10`s teaches nothing.

**Not in scope:** drag-to-reorder, saved views, per-user column preferences.

---

## Part 5 — AI Compose needs a way out

Two findings, one real and one smaller than it first looked.

### 5.1 There is no way to dismiss a plan (B14)

Once `kh-compose-plan-box` is populated it is only ever replaced, never cleared.
If the AI proposes something you do not want, your options are to plan something
else or reload the page.

Add a **Discard** button inside the plan card, next to "Looks right — write the
code". It clears both boxes, resets `approvedPlan` to `''`, and returns focus to
the prompt textarea. No network call, no state on the server.

Rephrasing already works — edit the prompt, press "Plan it" again, and the code
box is hidden on the way — but the prompt is 300 px above the plan you are
reacting to, so nobody finds it. **Plan again with changes** sits beside Discard
and scrolls the prompt back into view with its text selected.

### 5.2 Save is offered beside a failed syntax check (B15)

`Keelhob_Studio_Compose::code_html()` renders "Syntax check: **failed**" and
then renders exactly the same Save form it renders on success.

This is **not** dangerous — `save()` parse-checks again on the server and stores
failing code switched off, which is the correct behaviour and must not change
(losing somebody's work over a typo is its own bug). But it is incoherent: the
screen says the code is broken and then invites you to accept it, with no
acknowledgement that you have read the failure.

The fix is honesty, not a block:

- On failure, the Save button is `disabled` and carries the label
  **"Save anyway (it will stay switched off)"**.
- A checkbox above it — *"I have read the error and want to keep this code"* —
  enables it.
- The success path is untouched.

**Rejected: refusing to save failing code at all.** The AI produced it, the
person may want to fix the one missing semicolon by hand, and throwing the work
away to protect them from a parse error they can already see is patronising.

**Not in scope:** streaming, conversation history, retry-with-the-error-fed-back,
or multi-file snippets.

---

## Part 6 — Delete the dead things

### 6.1 `includes/class-admin-snippets.php` — 337 lines, referenced by nothing

A complete second Snippets screen: filters, a table, a details panel, an editor,
delete forms. Nothing instantiates it. Nothing registers a menu item for it.
`grep -rn Keelhob_Admin_Snippets --include=*.php` outside `tests/` returns the
class declaration and its copy under `build/` — **and nothing else** (B12).

The first person to read this repo after it goes public will find two complete
implementations of the same screen and have to work out which one runs.

**Delete the file.** Move anything worth keeping first — its per-row
"Ask your agent: *tell me about snippet 7*" hint is a genuinely good idea the
Studio does not have, and it should be carried over to the Studio row before the
file goes. Delete its render test with it, or repoint the test at the Studio.

### 6.2 "Browse the library"

The empty state offers three buttons. The third points at `tab=library`, which
`Keelhob_Studio::current_tab()` does not recognise when the Library tab is
absent, so it silently returns you to All Snippets (B13). It has never worked,
because `Keelhob_Snippet_Library` was never written.

Delete the button. The empty state keeps two honest choices: describe one to the
AI, or write one yourself.

### 6.3 Seven Donate buttons

`Keelhob_Donate::button()` is called **7 times across 5 files** (B11) — beside
the `<h1>` of Connect, Guide, Activity, Skills, Automation, the dead Snippets
screen and the Studio. A free plugin that asks for money at the top of every
screen reads as a plugin that is about the money.

Cut to **two placements**:

1. The Connect page, where somebody has just finished setting the thing up and it
   has demonstrably worked.
2. The Donate page itself.

The Plugins-row link stays — it is WordPress's own convention and costs nothing.
`Keelhob_Donate::button()` stays as a method; only the call sites go. That
keeps the diff reversible and keeps one place to change the markup.

**Not a money decision.** `keelhob-vital/FUNDING-AND-SPONSORS.md` is the
document that decides funding. This is a decision about how many times one screen
may ask.

---

## Part 7 — The abilities banner becomes dismissible

### What it actually is

`Keelhob_Admin::notice()` prints a yellow warning gated on
`strpos($screen->id, 'keelhob')`. It is on **Keelhob screens only**, not
across wp-admin — worth stating plainly, because it makes the nagging much
smaller than it sounds. It cannot be dismissed and it never goes away while
abilities are on.

### Why hiding it is safe — measured, not assumed

`Keelhob_Admin::admin_bar()` puts **`Keelhob: ON`** in the WordPress
toolbar. The obvious objection is that a snippet can remove the toolbar, so the
chip is not a reliable fallback.

Tested on the rig. A live snippet running
`add_filter('show_admin_bar', '__return_false')` everywhere produced:

| Where | Toolbar |
|---|---|
| Front end | **removed** — the snippet worked |
| Inside wp-admin | **present, chip intact** |

WordPress forces the toolbar on inside wp-admin; `show_admin_bar` does not apply
there. The banner only ever appears inside wp-admin. **The chip is guaranteed
exactly where the banner lives**, which is what makes a dismiss checkbox
defensible rather than reckless.

### The guard the test did not remove

A snippet could still call `remove_node('keelhob')`, or inject admin CSS
hiding `#wpadminbar`. So: **the dismiss checkbox is only offered while the chip is
actually rendering.** `Keelhob_Admin::notice()` checks that the toolbar node
was registered for this request; if it was not, the banner shows and cannot be
hidden. Cheap, and it closes the one hole the measurement left open.

### The rules

A checkbox on Connect, beside the other "what leaves this site" settings:

> [ ] **Hide the "AI abilities are ON" banner**
> The toolbar still shows `Keelhob: ON`, so the site never stops telling you.

Three rules that are not negotiable:

1. **Per user, not per site.** Stored in user meta. One administrator hiding a
   safety warning must not hide it from a colleague who has never seen it.
2. **It comes back when the risk changes.** Abilities toggled off and on, a new
   capability group enabled, or a plugin version change all clear the dismissal.
   A warning silenced about one situation must not stay silent about a different
   one.
3. **The toolbar chip is never hideable.** If anyone proposes a setting for it,
   the answer is no.

**Rejected: removing the banner entirely.** The first run matters. Somebody who
has just switched abilities on should be told once, loudly, on the screen where
they did it.

---

## Part 8 — How this gets measured

Two instruments. Both are built **before** the work in Parts 1-7, because a
baseline taken after the change is not a baseline.

### 8.1 `tests/rig/ui/` — assert what renders, not what is in the file

Every number in the baseline table came from a browser reading computed styles.
Every one of them would have been invisible to grep. The editor bug in Part 1 is
the proof: the source enqueues CodeMirror, calls `initialize`, and sets up a mode
switcher. Grep says the editor works. The page says it has never once loaded.

**`tests/rig/ui/check-snippets.mjs`** — `playwright-core` driving Chrome against
the rig at `localhost:8899`. One command:

```bash
node tests/rig/ui/check-snippets.mjs --wp http://localhost:8899 --user admin --pass rigpass
```

`playwright-core` directly, not the gstack `/browse` daemon, which dies with
`EPERM` on its temp state file on this machine.

It seeds its own fixtures, asserts, and **deletes them in the same run** —
including on failure, via `try/finally`. A rig left holding `ZZ TEST` snippets is
a rig that fails its own next run for the wrong reason.

The assertions, each mapped to the baseline it replaces:

| Asserts | Baseline it moves |
|---|---|
| `.CodeMirror` exists; `.CodeMirror-linenumber` exists; a `.cm-keyword` has a non-default computed colour | B1 |
| `cpStudioEditorSettings` is an object carrying `codemirror.mode` | B2 |
| The first code line's bounding box is inside the viewport with `scrollY === 0` | B3, B4 |
| The status control's computed box intersects the viewport **without** dispatching a hover | B6, B7 |
| Clicking it flips the row to On **and** the stored `active` is 1 | B7 |
| `thead th.sortable` count > 0; clicking one reorders the first row's name | B8 |
| The Errored tab appears only after a snippet has a `last_error`, and filters to exactly it | B10 |
| `Save` carries `button-primary`; `Save & switch on` does not | — |
| A Discard button exists, and clicking it empties the plan box with no reload | B14 |
| With a planted lint failure, Save is `disabled` until the acknowledgement box is ticked | B15 |
| No donate markup beside any `<h1>` except Connect and Donate | B11 |
| The banner hides after dismissal, **returns** after abilities are toggled, and the chip is present throughout | B16 |

A failing check writes a full-page screenshot to
`tests/rig/ui/failures/<name>.png`. A red line saying "the toggle was not
visible" is much less useful than the picture of it not being visible.

**CI: advisory first, then a hard gate.** A new browser layer will be flaky
before it is trustworthy, and a flaky test that blocks merges on a one-person
project blocks the one person. So the CI job runs with `continue-on-error: true`
and prints its findings. **Once it has been green for seven consecutive days on
`main`, the flag is removed** — that date goes in the PR description so it does
not quietly never happen. The existing `wp eval-file` checks stay as they are and
stay blocking; this is a new layer, not a replacement.

### 8.2 A time window on the feedback summary

`/v1/feedback/summary` already returns `COUNT(DISTINCT site_id)` grouped by
`friction, tool, skill`. The tool-name breakdown this measure needs **already
exists** — a smaller change than assumed.

What is missing is **time**. The counts are lifetime, so "did snippet friction
fall after we shipped this?" cannot be answered from them at all.

Add an optional `?days=N` (default: unbounded, so nothing about today's behaviour
changes), applied as `received_at >= now() - N days`. Clamp N to 1-90, matching
`FEEDBACK_RETENTION_DAYS`.

The public shape stays exactly as it is: grouped counts of distinct sites, never
a note, never anything that identifies a site. `?days=` narrows the window; it
does not widen what is returned.

### 8.3 What success actually means

| Measure | Baseline (measured) | Target | How it is checked |
|---|---|---|---|
| Working code editor | **none** (B1) | present, with line numbers and colour | `check-snippets.mjs` |
| Scroll before code is visible | **636 px** (B4) | **0 px** | `check-snippets.mjs` |
| Fields above the code box | **6** (B5) | **3** | `check-snippets.mjs` |
| On/off control visible without hover | **no** (B6) | **yes** | computed box vs viewport |
| Sortable headers | **0** (B8) | **3** (name, priority, updated) | `check-snippets.mjs` |
| Ways to find an errored snippet | **0** (B10) | **1 click** | `check-snippets.mjs` |
| Ways out of an unwanted AI plan without reloading | **0** (B14) | **2** (discard, re-plan) | `check-snippets.mjs` |
| Donate buttons beside an `<h1>` | **7** (B11) | **2** | `check-snippets.mjs` |
| Shipped files implementing the Snippets screen | **2** (B12) | **1** | `grep`, in CI |
| Dead controls that silently do nothing | **1** (B13) | **0** | code review |

And the honest one, which no test can assert and which is the only measure here
taken from real sites rather than from the rig:

> **`feedback_reports` rows whose `tool` names a snippet tool should fall in the
> 30 days after release, compared with the 30 days before.**

The instrument for this is 8.2, and it exists because 1.1.0 shipped the feedback
log. It is the difference between "the page got prettier" and "the page got
better". If it does not move, this plan was cosmetics, and that is worth knowing.

**Caveat, stated up front so it is not discovered later as a disappointment:**
this signal needs sites sending reports, and the public count is small today. If
the sample is too thin to read, that is the answer — *not enough data* — and it
must not be written up as *no change* or, worse, as *it worked*.

---

## Order of work

Instruments before changes, then the bug, then the rest.

1. **`tests/rig/ui/check-snippets.mjs`**, written against today's screen, with
   every assertion **expected to fail**. A red first run is the baseline table
   reproducing itself, and proof the harness can see what it claims to see.
2. **`?days=N` on `/v1/feedback/summary`** (8.2), plus a recorded snapshot of the
   snippet-tool counts for the 30 days before any of this ships.
3. **Part 1 — the code editor.** The only real bug in the list. It lands first,
   and on its own, so the browser test flips from red to green on exactly one
   change and there is no doubt about what fixed it.
4. **Part 6 — delete the dead things.** Pure subtraction, no behaviour to test.
   Cheapest possible win before the repo goes public.
5. **Part 3 — the visible on/off switch.** Most-used control, biggest gap.
6. **Part 2 — code-first layout**, including the Save-primary swap.
7. **Part 4 — Errored filter, sortable headers, priority column.**
8. **Part 5 — Discard, re-plan, and the failed-lint acknowledgement.**
9. **Part 7 — the dismissible banner**, with the chip-present guard.
10. Version bump, `readme.txt` changelog, `bin/build-readme-txt.php` drift check.
11. Archive the VibeSnip repository with a README pointing at Keelhob.

Steps 3-9 are each independently shippable and each flip a specific row of the
success table. If the work is interrupted, whatever is finished is still worth
having.

---

## Risks

| # | Risk | What holds it |
|---|---|---|
| 1 | **Part 1 moves the inline script, which changes when *both* the editor and the Ask-AI handler run.** The Ask-AI handler works today. | The browser test asserts the Ask-AI button still binds and still posts, not only that CodeMirror appeared. Fixing one and breaking the other is the obvious way for this change to go wrong. |
| 2 | **Deleting `class-admin-snippets.php` deletes code nobody has read recently.** | `grep` proves nothing references it. Its one good idea — the per-row "ask your agent" hint — is carried over *before* deletion, not after. |
| 3 | **A dismissed safety banner means somebody forgets an agent is attached to production.** | Part 7: the chip is permanent, dismissal is per user, it returns when the risk changes, and it is only offered while the chip actually renders. |
| 4 | **The browser test is flaky and blocks a solo developer's own merges.** | Advisory for seven green days before it becomes a gate. The date is written into the PR. |
| 5 | **The friction measure reads as a result when the sample is too small.** | Written into 8.3: too thin to read is reported as *not enough data*, never as a win. |
| 6 | **Changing the primary button changes muscle memory** for the one person who uses this daily. | That person is the author, and they asked for it. Noted, not mitigated. |
| 7 | **Scope creep back toward v1.** Library, Import, What's New and the rule builder are each one sentence away from returning. | They are out. If one comes back, it comes back as its own plan, after the repo is public, with its own measurements. |

**Not a risk, and worth recording so it is not re-litigated:** the conditions
format. With no rule builder in scope, nothing in this plan reads or writes
`conditions_json`. The incompatibility that dominated v1's risk list cannot fire.

---

## Verification

```bash
cd "c:/Users/FLINTSBWOY/Documents/antiGrav/Keelhob"

php tests/run.php                 # full unit suite green
composer analyse                  # PHPStan clean
php bin/build-readme-txt.php && git diff --exit-code
bash bin/build-zip.sh             # class-admin-snippets.php is NOT in the zip
bash tests/rig/sync-plugin.sh /c/wp-rig/wordpress
```

Then, with the rig served at `http://localhost:8899`:

```bash
cd /c/wp-rig/wordpress && PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 &
bash tests/rig/run-all.sh /c/wp-rig/wordpress     # existing checks still pass
node tests/rig/ui/check-snippets.mjs --wp http://localhost:8899
```

The browser run is the acceptance test for this plan. Every row of the success
table in 8.3 is one of its assertions, and it tears down every fixture it created
in the same run that asserted on them — including when it fails.

---

## Results

Built 2026-09-13. Every row below is a browser measurement, before and after.

| # | Measured | Before | After |
|---|---|---|---|
| B1 | `.CodeMirror` on the edit screen | absent | present, with line numbers |
| B1 | Distinct syntax-colour tokens | 0 | 4 |
| B2 | `cpStudioEditorSettings` | undefined | `object`, `mode: text/x-php` |
| B3/B4 | Scroll before the code is visible | 636 px | **0 px** |
| B5 | Fields above the code box | 6 | **2** |
| B6/B7 | On/off control visible without hover | no (`left: -129987px`) | yes (`left: 1246px`) |
| B7 | Clicking it changes the stored state | n/a | `active 0 -> 1` |
| B8 | Sortable headers | 0 | **3** |
| B9 | Priority column | absent | present, sortable, shown only when in use |
| B10 | Errored filter | absent | present, returns exactly the errored snippet |
| B11 | Donate buttons beside an `<h1>` | 7 | **1** (Connect), plus the Donate page |
| B12 | Files implementing the Snippets screen | 2 | **1** |
| B13 | Dead controls | 1 | **0** |
| B14 | Ways out of an unwanted AI plan | 0 | **2** |
| B15 | Save offered beside a failed parse | yes | disabled until acknowledged |
| B16 | Banner dismissible, chip survives, returns on change | no / n/a / n/a | yes / yes / yes |

**Browser checks: 29 of 34 red on the first run, 36 of 36 green at the end.**

Also green: 2343 unit assertions, PHPStan, all 3 rig check files, the skills
linter at 100/100, the readme drift check, and the zip build.

### Three bugs the browser layer found that nothing else could

1. **The code editor had never loaded on any install** — the bug this plan was
   reorganised around. Part 1.
2. **The chip guard could not work where it was placed.** Asking the toolbar for
   its nodes from `admin_notices` returns nothing, because the toolbar has not
   been built yet. A rig probe printed `nodes=0 keelhob=no` on a page whose
   toolbar rendered correctly moments later. Shipped as written, the guard would
   have removed the dismiss option entirely while reading like a careful safety
   check.
3. **The banner did not return after an off/on cycle.** `notice_fingerprint()`
   hashes state, so the cycle restores the old hash and the old dismissal still
   matched. A unit test had been written asserting exactly that, it passed, and
   it described the bug as though it were the feature.

Two of those three were mistakes made *while building this plan*. That is the
argument for the browser layer, stated as plainly as it can be.

### The two things left overnight — both done 2026-09-14

- **`includes/class-studio-library.php` is deleted**, with the `use_library`
  action and the Library tab. It could never appear: `has_items()` asked
  `Keelhob_Snippet_Library`, a class nobody ever wrote. 135 lines of screen
  plus an install path, shipping in every zip, reachable by nobody.

  **Kept on purpose:** `source = 'library'` in `Keelhob_Snippets::SOURCES` and
  its badge. That is stored data, not UI — sites that used the old private
  library still have rows carrying it, and dropping the badge would render their
  own history wrong. `?tab=library` still falls back to the list, so an old
  bookmark is not a dead end. Both asserted in `checks-studio.php`.

- **The "ask your agent" hint is carried over**, split rather than copied. The
  deleted screen printed `Ask your agent: "tell me about snippet 7"` on every
  row; on a twenty-row table that is twenty copies of one sentence, which is the
  kind of noise this plan exists to remove. So the **id** goes in the list, small
  and grey, because the number is the part you need while scanning — and the
  **sentence** goes on the edit screen, once, where you are looking at a single
  snippet. It is suppressed on a new snippet, which has no number yet.

  It matters because it is the clearest statement the UI makes that the screen
  and the agent are the same system, which is the thing people take longest to
  work out about this plugin.

### The measure that is not in this table

`?days=N` on `/v1/feedback/summary` is built and tested, and the snippet-tool
counts for the 30 days before release still need recording before 1.2.0 ships.
Without that snapshot there is nothing to compare the next 30 days against, and
the honest measure in 8.3 cannot be taken at all.

---

## Version 3 — the second pass, 2026-09-14

Built and verified the same day. The trigger was a user comparing the screen to
VibeSnip's and reporting two features as **missing**:

> "there is no revisions and no smart conditional logic like what we have in
> the vibesnip"

Both statements were about the same underlying problem, and only one of them
was about a feature that did not exist.

### What was actually wrong

| Reported | Reality |
|---|---|
| "no colour code" | Highlighting worked. They were looking at an **empty** editor, so there was nothing to colour. |
| "no revisions" | Shipped in 1.9.0. Rendered **after the submit buttons**, at the bottom of a long one-column page. Nobody scrolled that far. |
| "no smart conditional logic" | The **engine** existed — four rule kinds, implicit AND — and had **no UI at all**. Only an agent could set a condition, through `snippet_save`. |
| "doesn't look like an editor" | Correct, and the cause of the other three. |

Two of the three "missing" features were shipped and unreachable. That is worth
writing down, because it is a failure mode this project keeps producing: the
1.2.0 code-editor bug was a feature that existed in the source and not on the
screen, and this is the same shape one layer up — a feature that exists on the
screen, below the point anybody looks.

**"Shipped" and "reachable" are different claims.** The browser checks added in
this pass assert the second one: a measured position, a populated control, a
computed colour. Never the presence of markup.

### What was built

- **Two columns.** Code on the left inside a real editor frame — language dot in
  the language's own colour, `snippet.php` filename, `PHP` pill, Ask AI in the
  toolbar. All three follow the type dropdown live, because a bar that stops
  agreeing with the dropdown is a label that lies.
- **A 320px right-hand rail**: Save (with a status badge), Behaviour (the five
  settings that were behind a `<details>`), Revisions. Always open. Nothing that
  is switched on is hidden.
- **`Keelhob_Snippet_Conditions`** — seven rule kinds, ALL/ANY, operators,
  a repeatable builder, and a summary badge on the list screen.
- **One stylesheet**, `assets/css/studio.css`, replacing two `wp_add_inline_style`
  blocks that styled the same screen and had begun to disagree.

### The decision worth defending: no migration

Pre-1.2.0 conditions are **lifted on read**, not rewritten. Nothing in the
database changes, no upgrade step can half-run, a rollback to 1.1.0 still works,
and `snippet_save` still accepts the old flat shape so agents working from older
notes are not broken.

The subtle part: the old `post_types` / `post_ids` checks were wrapped in
`is_singular()`, so on an archive they did not *fail* — they did not *apply*.
Read as ordinary rules they would newly fail there, and a header snippet that had
always shown on category pages would vanish on update, on a site whose owner
changed nothing. Lifted rules carry `only_singular` and behave exactly as before;
rules built in the new builder do not. Pinned by
`tests/unit/test-snippet-conditions.php`.

### Two bugs found by teardown, not by looking

Both surfaced from the same habit: delete the test fixture, then **count what is
left**.

1. `Keelhob_Snippets::delete()` never removed a snippet's revisions.
   `snippets left: 0, revisions left: 7`. Unreachable rows, growing without
   limit, each holding a full copy of the code — so deleting a snippet because
   of what was inside it did not remove it.

2. `uninstall.php` named six tables while the plugin created ten. Four survived
   an explicit "delete all data", including the revisions table. Replaced with a
   prefix sweep, which cannot drift because nothing has to be remembered.

### Verification

```
php tests/run.php                                       2428 assertions
composer analyse                                        clean
php bin/lint.php                                        140 files
node tests/rig/ui/check-snippets.mjs --rig C:/wp-rig/wordpress
                                                        57 browser checks
```

Plus, on the rig: the real `uninstall.php` run against real data (10 tables → 0,
VibeSnip's 3 tables and 5 posts untouched), and an agent-written legacy
condition saved, read back, and lifted with its `only_singular` exemption intact.
