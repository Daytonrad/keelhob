# Changelog

All notable changes to Keelhob are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

`readme.txt` carries a changelog too, because WordPress requires one there. This
file is the one written for developers: it names files, says what broke and why,
and keeps the pre-release history that `readme.txt` presents as if it were
public. When the two disagree, this file is correct.

## [2.1.0] - unreleased

The admin screens get a design system, and the plugin learns to look after a
site rather than only build one. 2.0.0 ships first and unchanged; this is
deliberately a separate release, so that a regression in the redesign is
unambiguously a UI regression.

Counts moved, and three agents edited them in turn, so they are restated here
against the code rather than against the last doc that mentioned them. Since
2.0.0: tools **69 → 85**, capability groups **21 → 24**, groups shipping
switched off **8 → 10**, browser checks **80 → 332**.

### Added — agent-written abilities

- **`ability_create` and `ability_improve`**, in a new `abilities` capability
  group that is **sensitive and ships off**. These let a language model author
  PHP that then runs on the owner's site as a Keelhob tool, which is the single
  most dangerous thing in this codebase, so the gate is described in full in
  `DOCUMENTATION.md` rather than summarised. They were briefly registered in
  **no capability group at all**, which left model-authored PHP ungated on a
  fresh install; that is the hole `1368491` closed.
- **A pending → approve → live flow.** A submitted ability is stored `pending`
  and is *not registered as a tool*, so the agent that wrote it cannot call it.
  Approval is a form in wp-admin with a `required` confirm checkbox, and it
  works with JavaScript disabled.
- **Versions, each with its own rating, and roll back.** A new version arrives
  pending while the live one keeps serving. Retired versions are kept, not
  deleted, and **Roll back to this** makes one live again.
- **A `code_hash` guard.** The approved bytes are hashed at approval and
  re-hashed before every run. Code edited in the database after approval is
  refused. Proved by tampering with the row directly: 231 bytes became 91 with
  the hash untouched, and the body never ran.
- **Auto-retire on fatal error**, so a crashing ability takes the site down once
  rather than on every request.
- **Documented honestly.** The new section says what the hash does *not* prove:
  that approval is once and the running is forever, that the AI writes its own
  description of its own code and the two are not checked against each other,
  that the body is not sandboxed, and that switching the capability back off
  does **not** stop an ability already approved.

### Added — ratings, the run log, thumbs and lessons

- **A per-tool score out of 100**, earned from that tool's real results on that
  site, injected into the tool's own description so the agent reads it on every
  connection.
- **A run log** behind it — tool, arguments, outcome, duration, source — shown
  on **Keelhob → Activity**. It is a **ring buffer**, and the documentation now
  says so: a vote arriving after a trim fails visibly with a 404 rather than
  being dropped.
- **Thumbs up and down on Activity**, with no optimistic UI: the pressed state
  is set only from the server's response, and a failed vote reverts to exactly
  what it was and says so in a `role="alert"` message.
- **Lessons injected into tool descriptions.** The documentation now states
  plainly that a lesson is a standing instruction to something that can change
  the site — 300 characters, six per tool, every one visible, editable and
  deletable by the owner, and a lesson written by an agent is always recorded as
  the agent's and cannot claim the owner said it.

### Added — site care

- **Five read-only reports** in a new `sitecare` group that ships **on**, because
  nothing in it writes: `speed_audit`, `db_cleanup_preview`,
  `plugin_security_check`, `plugin_conflicts`, `broken_links`. A test fails if
  any of those files ever gains a write call.
- **Five tools that act on what the reports found**, in a separate
  `sitecare_fix` group that ships **off**: `image_compress`, `db_cleanup`,
  `link_fix`, `media_set_alt`, `plugin_install`. Backups go through
  `Keelhob_Undo::record_file()`. `db_cleanup` **cannot be undone** and refuses to
  run until a preview has been taken; the manual says both things in those
  words.

### Added — scroll-craft

- **A twenty-fifth skill**, `scroll-craft`, reached through `web-design-master`:
  scroll-driven pages built as three snippets plus a custom page template. It
  does **not** work inside Elementor and says so before building. Verified on
  desktop, at 390×844, and under reduced motion.

### Added — the admin design system

- **`assets/css/admin.css`** — one scoped stylesheet for every Keelhob screen.
  Everything lives under a `.kh-ui` root with `kh-u-` components, because over
  forty `kh-*` classes already belonged to the Snippets Studio and three of them
  — `kh-tabs`, `kh-tab`, `kh-panel` — meant something else entirely. Warm greys
  and copper, taken from the values already on keelhob.com rather than invented.
- **A dark variant**, keyed to the WordPress admin colour scheme and not to the
  operating system: the seam being fixed is the one against the sidebar, and the
  sidebar follows the scheme. Eight of the nine schemes WordPress registers have
  a dark menu, so `modern` and `fresh` — the current and former defaults — are
  deliberately excluded. A site on a default scheme has expressed no preference,
  and flipping every install to a dark panel is not a call a restyle gets to
  make. **Known consequence: a stock install has a dark sidebar beside a light
  panel.**
- **A header line and a tab strip on every screen.** Every tab is a real
  `<a href>` to the page slug it already had. No URL changed, nothing needs
  JavaScript, the Back button works, and the nineteen references in
  `DOCUMENTATION.md` and `docs/` still land where they point.
- **Three state cards on Connect** reporting which band the site is in —
  Reading only, Building, or Full access. **They are a view. They write
  nothing**: no option, no preset, no new stored state. Keelhob's real default
  already has snippets, files and code execution on, so a "Build" preset would
  switch off code a live site is running, and a "Full access" preset would grant
  payments, orders, users and deploys in one click. `test-admin-overview.php`
  checks the band against every distinguishable stored array, including the
  empty "owner never said" case.
- **`tests/rig/ui/check-admin.mjs`** — a browser gate that asserts computed
  styles. It walks every text node, resolves the first opaque ancestor
  background and alpha-blends before measuring.

### Fixed

- **The "AI abilities are ON" banner was illegible in dark mode — 1.43:1.**
  `root_class()` keeps `wrap` so WordPress moves admin notices inside `.kh-ui`,
  where they inherited the panel's near-white ink while keeping core's cream
  background. A safety control that is present and unreadable is worse than one
  that is absent, because it still looks safe. Now 14.67:1.
- **The capability switch never painted.** It rendered as a 16px
  WordPress-blue checkbox: core's `input[type=checkbox]` outranks a bare class.
- **The Guide screen failed WCAG AA in light mode**, 26 failures, worst 2.85:1 —
  inherited, not introduced, but it was wearing the new panel while carrying old
  hardcoded colours. The root cause was core's `.card`, which paints white
  unconditionally and knows nothing about `.kh-ui`, so every token inside it was
  measured against white. Now zero failures; fixing it also repaired two dark
  defects that had been blamed elsewhere.
- **Status pills across the Studio.** A naive colour-to-token swap measured
  2.99–4.41:1. The construction is now a soft tint fill, the status colour as a
  border or dot, and `--kh-ink` for the words: 16.32–17.38:1, up from 4.86–5.70.
- **`class-admin-automation.php` rendered empty `style` attributes.**
  `$t_table` / `$t_th` / `$t_td` were locals in `render_automation()` but used in
  two sibling methods, where they were undefined. Now class constants.
- **`.kh-panel` rendered unstyled on Connect**, because the stylesheet defining
  it is enqueued only on Studio screens.

### A note on what the tests could not see

The browser gate reported **96/96 in dark while all of the above was live**,
because it measured only `kh-u-*` elements and never the core components
WordPress renders inside the panel — and because it ran against an *empty*
snippets list, so the broken rows never rendered at all. It now seeds real rows
before measuring and destroys them in a `finally`. A gate nobody has watched
fail is not a gate.

## [2.0.0] - unreleased

### Changed

- **The project is renamed from its previous name to Keelhob.** The old name
  contained "Claud", one letter from Claude, which is a registered trademark of
  Anthropic, PBC and also the AI client this plugin is most often pointed at.
  In January 2026 Anthropic made an open-source project called Clawdbot rename
  over a looser resemblance than that one. We moved before being asked.
  Full reasoning, and the upgrade order for an existing site, in
  **[docs/why-the-name-changed.md](docs/why-the-name-changed.md)**.

  Releases 1.0.0 through 1.2.0 were published under the old name. The entries
  below describe the same code; only the product name in them has been updated,
  so that the old name survives in exactly one deliberate place rather than
  scattered through release notes where it would read as an oversight.

  Mechanically: plugin folder and main file, class prefix, constants, text
  domain, the MCP route, ten database tables, every option, transient and post
  meta key, and the internal `cp_`/`cp-`/`CP_` prefixes. 4,597 occurrences
  across 208 files.

  **Every MCP client must be reconnected.** The route is now
  `/wp-json/keelhob/v1/mcp`; the old one is gone and an existing configuration
  will get a 404. Copy the new command from the Connect screen.

- **1.2.0 was never released.** Its work ships here instead, so that no further
  release carries the old name. Everything listed under it below is in 2.0.0.

### Added

- **`site_readiness` now runs the security baseline.** The automatic half of
  `skills/security-standard.md` — A1 file editor, A3 guessable admin logins, A4
  user enumeration, A5 HTTPS enforcement, A8 keys and salts, A9 debug exposure,
  A11/A18 directory listing, A13 version disclosure, A17 XML-RPC — is now
  detected by the tool that operating-protocol rule 9 already forces an agent to
  run before reporting work done. The parts that cannot be measured from inside
  a site (cookie flags, file permissions, custom-code review, backups) are
  listed in `not_verified` with what the owner has to do, rather than omitted.

  The skill has described all twenty checks correctly since it was written. That
  was never the problem. The gate checked three of them, so an agent had to
  think to load a 588-line reference and then finish it — and on this project's
  own site it closed two of the three user-enumeration routes and stopped,
  leaving the admin login in `/wp-sitemap-users-1.xml`, which `robots.txt`
  advertised to every crawler that read it. Knowing a thing and running it are
  different, and only one of them is a guarantee.

  Verified against two real sites, not only fixtures: the hardened one reports
  one owner-decision observation and passes; an unhardened one fails with two
  HIGH enumeration findings and three version-disclosure findings.

- **`snippet_revisions` tool** — list, read and restore a snippet's history. The
  revisions table has existed since 1.9 and the admin Studio has always shown
  it, but nothing exposed it to an agent, and `skills/code-snippets.md` stated
  in writing that snippet edits "are not versioned". So an agent that overwrote
  a snippet believed the previous code was gone, and told the owner so, while it
  sat one query away. A restore goes through the normal save path, so it is
  linted, front-end checked, and recorded as a revision of its own.

- **`snippet_save` reports what it replaced** — `replaced_bytes`, `new_bytes`
  and the `revision_id` now holding the old code, whenever a save changes
  existing code. "Saved" reads identically whether you changed one line or
  pushed a stale file over newer work; during this project's own site build a
  local source file that predated a palette rebuild was pushed over the live
  snippet and silently reverted the whole site's colours. The front-end check
  passed, because the site was up. The byte count was the only tell and it was
  not in the response.

- **`security.baseline` seeded memory.** `design.reference` has pointed agents
  at the design skill since the beginning; security had no equivalent, and the
  result was exactly what you would expect. Seeded on activation and on upgrade,
  so existing sites get it too.

- **Skill receipts survive an interactive session.** `Keelhob_Skills::record_load()`
  keeps a bounded, durable record of which skills were loaded, reported by
  `agent_runs` as `skill_loads`. Over MCP every tool call is its own request, so
  the registry's per-request static only ever held one name, and only
  `Agent_Runs::finish()` read it — which runs for `run_agent` and scheduled
  tasks and nothing else. This project's own site was built entirely over
  interactive MCP: zero agent runs, ten snippets, and no record anywhere that a
  single skill had ever been loaded. Rule 13 asks an agent to name the skills it
  used; now there is something to check that answer against.

### Changed

- **A new snippet cannot be saved without `name`, `purpose` and `impact`.**
  `snippet_save` has said "ALWAYS fill name, tags, purpose and impact" since the
  beginning while the schema required only `code`, so it stayed advice — and on
  this project's own site 3 of 10 snippets had neither purpose nor impact, one
  of them named "admin greeting" with no description at all. Nobody switches
  that off, which makes the off-switch worthless exactly when it matters.
  **Required on create only**: an update that changes one line of code keeps the
  stored text, so fixing a typo never costs a paragraph and no agent is pushed
  into inventing purpose text it does not know.

- **`security-standard` is now a declared verifier** for `code-snippets`,
  `website-building` and `store-launch-and-deploy`. Eighteen skills declared a
  `verified_by` and not one of them named it, so the skill nothing had to finish
  was the security one. Rule 13 now forces an agent to say whether it ran, or
  why not.

- **`skills/code-snippets.md` no longer claims snippet edits are unversioned.**
  They always were. The line cost real recovery work.


- **Existing installs are adopted automatically.** `includes/class-legacy-adopt.php`
  renames the ten tables in place, carries the settings option across (provider
  keys, hashed connection token, every capability switch), rewrites the
  `_*_seo_*` post meta keys, clears the old scheduled hook and drops the stale
  transients. `RENAME TABLE`, not a copy, so nothing is duplicated and there is
  no window where two half-tables exist.

  It **refuses to run while the old plugin is still active**, because renaming
  tables out from under a running plugin throws a database error on every
  request rather than warning. It also refuses to overwrite anything already
  written under the new name. Both refusals are tested by breaking them.

- **`tests/unit/test-no-legacy-brand.php` fails the build if the old name
  appears anywhere.** It prints every file and line. Two files are allowed to
  contain it and both have to: the document explaining the rename, and the
  adoption step that has to say where the old data is. This is how the rename is
  known to be complete, rather than believed to be.

### Fixed

- **The autoloader hardcoded the length of the old product name** in three
  places (`10`, `11`, `11`). The rename left them behind and every single class
  silently failed to load. The lengths are now derived with `strlen()` from the
  prefix literal. `tests/unit/test-integrity.php` caught it, and turned out to
  carry a duplicate copy of the same bug in its own re-implementation of the
  autoloader.

## [1.2.0] - not released; folded into 2.0.0

### Added

- **Gemini works again: two schema details were killing the whole tool list.**
  `includes/class-provider.php`, `includes/tools/class-tools-elementor.php`,
  `includes/tools/class-tools-woocommerce.php`.

  Reported from a live Telegram chat on Google:

  ```
  HTTP 400: Invalid value at 'tools[0].function_declarations[28]
  .parameters.properties[3].value.enum[0]' (TYPE_STRING), 301
  ```

  `seo_redirect` declares `type: integer, enum: [301, 302]`. Anthropic and
  OpenAI accept that without comment; Google allows `enum` only on a STRING and
  rejects the ENTIRE request. So one integer in one tool took all 68 tools down
  with it, and the agent could not answer anything at all on that provider —
  which is what "I switched to the Google model and got the same silence" really
  was.

  `clean_schema()` — which already exists to bridge exactly this gap — now
  stringifies enum values and their type, for scalars only, so a container's
  type can never be rewritten. Anthropic and OpenAI still receive the honest
  integer. The site is unaffected: `seo_redirect` casts its own input with
  `(int)`, so "301" and 301 arrive at the same place.

  `tests/unit/test-tool-surface.php` now walks EVERY shipped tool's declaration
  as Google would, rather than only the one that broke, because the next tool
  added will be written the same way.

  **And then it happened again**, on the very next message:

  ```
  HTTP 400: * GenerateContentRequest.tools[0].function_declarations[44]
  .parameters.properties[data].items: missing field.
  ```

  `elementor_page.data` and `wc_order.line_items` declared `type: array` with no
  `items`. Anthropic and OpenAI accept that; Google rejects the whole request
  again. Both now declare `items: {type: object}`, which is the honest shape —
  an Elementor tree is recursive and free-form.

  Fixing one rule at a time, each found by the owner in a live chat, is not a
  process. So the test was widened from "no bad enum" to every rule Google is
  known to refuse — bad enum, array without items, object without properties,
  union types — and an audit of all 68 tools found exactly these two remaining.
  Nested objects were already covered: `clean_schema()` fills an empty
  `properties` map.

  The test also asserts it inspected every declaration. The first version of it
  lost its walking loop during an edit and passed green on an empty result set
  while the bug sat in the file — a test that reports success without running is
  worse than no test.

- **A new Telegram bot is no longer silenced by the previous one.**
  `includes/class-telegram.php`, `includes/class-telegram-router.php`.

  Reported from a live site: a freshly created bot was completely silent.
  Configuration was perfect, the webhook was registered, Telegram reported zero
  pending deliveries and no errors, and nothing ever got a reply.

  `update_id` is monotonic PER BOT, and the replay high-water mark was stored
  globally. The old bot had reached 412,002,449; a new bot starts its own
  sequence far below that, so every message it delivered looked like a replay of
  work already done and was dropped at the first `if`. The endpoint answered
  200, Telegram was satisfied, and nothing downstream ever ran — which is why
  switching the AI model changed nothing: the code never reached the model.

  The mark is now tied to a hash of the bot token, so swapping bots is
  self-healing with no button to press. A legacy bare integer is discarded
  rather than adopted, because there is no way to know which bot wrote it and
  the two failures are not equal: a stale mark is total, permanent silence that
  looks like a broken product, while a missing one risks at most one duplicated
  message. The token itself is never stored in the option.

- **Scheduled tasks now fire on time outside UTC.** `includes/class-tasks.php`,
  `tests/bootstrap.php`.

  Reported from a live site in Asia/Dubai: a digest set to HOURLY arrived every
  four to five hours, for over a week.

  `record_run()` stores `current_time('mysql')` — the site's LOCAL wall clock.
  WordPress pins PHP's default timezone to UTC, so reading that back with a bare
  `strtotime()` interprets "14:00 in Dubai" as "14:00 UTC": a timestamp four
  hours in the FUTURE. `is_due()` then computed `elapsed = now - last = -4h` and
  refused to run until it climbed back past the interval, so every task fired at
  `offset + interval`. On UTC+4 an hourly task took 4h58m — the reported gap,
  arrived at from the arithmetic rather than from guessing.

  New `Keelhob_Tasks::last_run_ts()` converts through `get_gmt_from_date()`,
  so named timezones and DST are right, and the column keeps storing local time
  because three screens display it.

  It was invisible everywhere it could have been caught: the unit stub returned
  plain UTC whatever it was asked, and the rig, CI and every other test
  WordPress run at UTC, where the offset the bug depends on is zero. So
  `tests/bootstrap.php` can now put the site somewhere real
  (`$GLOBALS['kh_test_gmt_offset']`, default 0 so nothing else changes), and
  `tests/rig/checks-cron.php` moves a REAL WordPress to +04:00 and restores it
  in the same run. The tests assert both directions — a site east of UTC ran
  late, and a naive fix written only for that would make a site west of UTC fire
  EARLY and send duplicates.

- **A new token now clears the failed-attempt lock.** `includes/class-auth.php`,
  `includes/class-keelhob.php`, `includes/class-admin-screens.php`.

  Found the hard way against a live site. Ten failed attempts from one address
  in fifteen minutes locks the MCP endpoint, and `is_locked_out()` is checked
  BEFORE any credential work — so a brand-new, perfectly valid token still got
  429 and regenerating appeared to do nothing at all.

  Worse, it could not resolve itself. A client still holding the OLD token
  re-locks the site the instant the window opens, so the wait never ends and the
  only visible symptom is "connection failed" forever. Two stale entries
  pointing at one site made it certain: every client start burned failures.

  Minting a token needs `manage_options`, which is stronger proof of ownership
  than surviving a fifteen-minute wait, so there is nothing left for the lock to
  defend. `Keelhob_Auth::unlock()` clears every address's counter, not just
  the caller's — the lock is keyed on the MCP CLIENT's IP while the button is
  pressed from wp-admin, usually a different address. Clearing only "my" IP
  would leave the client locked and the owner staring at a button that did
  nothing. The unit test caught exactly that in the first draft.

  The 429 also stops being vague: it names the real number of minutes, carries
  `retry_after`, and says that regenerating clears it. And the Connect screen
  now shows the countdown when locked, plus a standing warning that every client
  must be updated and duplicates removed, because one client left on the old
  token re-locks the site for all of them.

- **Operating-protocol rule 16: precision, and not wasting the owner's tokens.**
  `includes/class-mcp-server.php`.

  Adapted from Andrej Karpathy's `CLAUDE.md` at the owner's request, and
  deliberately not a copy of it: half that file is already rule 2 (smallest
  change) and rule 7 (name the ambiguity), and restating them would cost every
  session tokens to say the same thing twice — which is the exact failure the
  rule is about. What is kept is the half nothing above covered: the size of the
  code, and the size of the context spent producing it.

  `16a` think first and name the assumption · `16b` write the minimum, with
  snippets called out as the sharpest case because they run on every page load ·
  `16c` clean up only your own mess · `16d` decide the check before you start ·
  `16e` spend context like money — do not re-read, do not fetch a whole file for
  one function, do not give the plan, the code and then a summary of the code.

  It is in PHP rather than in a skill for the same reason as rules 9–15: a site
  can switch a skill off, and this applies to every task rather than to
  building. `operating_protocol()` is the single source both pipes read, so it
  reaches interactive MCP sessions and unattended automation runs alike —
  asserted on both in `tests/unit/test-agent-runtime.php`, and verified over
  real HTTP through `initialize`.

  Cost, stated plainly: +1,656 bytes, about **414 tokens per session**. The
  protocol is now ~2,500 tokens, and a test fails the build if it passes 12 KB.

- **AI Compose is a conversation, not a three-step wizard.**
  New `includes/class-studio-chat.php`; rewritten `includes/class-studio-compose.php`
  and `assets/js/studio-compose.js`; chat styles appended to `assets/css/studio.css`;
  new `ajax_chat` and `ajax_render` in `includes/class-studio-actions.php`.

  Compose was plan → approve → build: three separate one-shot requests, each
  carrying exactly one message. The model had no memory of the step before, and
  that produced two failures with no way out from the screen.

  **One.** `plan()` demanded a JSON object back and returned
  `WP_Error('…not in a form this screen could read')` for anything else. A model
  answering in ordinary sentences — the normal thing for a model asked to think
  out loud — had its whole reply discarded, and no amount of rephrasing could
  help, because the instruction asking for JSON is a request and not a
  guarantee. That error string no longer exists anywhere in the plugin.

  **Two.** When generated code failed the parse gate, the error was rendered for
  the PERSON and never shown to the MODEL, because there was no next turn to put
  it in. A missing semicolon was therefore a dead end: save it broken, or start
  again from a blank prompt.

  Both are the same root cause, so both are fixed by the same change. The
  browser holds the transcript and posts all of it on every send; the server
  normalises it, trims it to a budget, and keeps none of it. Code arrives in
  fenced blocks rather than JSON — a model told to answer with a fenced block
  complies far more often, and a malformed fence still leaves readable prose on
  screen where malformed JSON left nothing at all. Every block is linted with
  the same `Keelhob_Snippets::lint_php()` the save path uses, the result is
  shown as a badge, and a failure is posted back to the model automatically,
  ONCE, then offered as a button. Once, not a loop: a loop that keeps resending
  a parse error spends real money while looking like progress.

  Every code block carries its own Save, so a reply containing a PHP snippet and
  the CSS that styles it is two snippets rather than one plus a copy-paste.

  The model is also told where it is — WordPress and PHP versions, active theme
  and plugins, whether WooCommerce exists, the snippet types and locations, and
  the rule it most often breaks: snippets are stored as BARE code with no
  opening `<?php` tag. Compose previously sent none of this.

  Nothing about the safety promise moved: nothing is written to the snippets
  table until a person clicks Save, and Save still stores the snippet switched
  OFF. The chat itself is never persisted — no table, no option, no post meta.
  It is mirrored into `sessionStorage` so a stray reload does not lose it, and
  restored only when Navigation Timing says the load WAS a reload; a fresh
  navigation clears it. Only plain text is stored and the markup is rebuilt
  server-side on restore, because storing rendered HTML and injecting it back is
  how a stored-XSS bug gets written.

  Removed with it: `Keelhob_Studio_AI::plan()` and `::build()`,
  `Keelhob_Studio_Compose::plan_html()` and `::code_html()`, and the
  `keelhob_studio_plan` / `keelhob_studio_build` AJAX actions.

### Fixed

- **The Compose message box could sit below the fold.** `assets/css/studio.css`.
  The transcript was capped at `58vh`, which sounds conservative and is not: the
  admin bar, page heading, tab strip and status line all come before it, so on a
  674px-tall window a full transcript pushed the message box to y=682 — eight
  pixels off screen, on the one control the screen exists for. Now
  `calc(100vh - 380px)`, with the composer `position: sticky` at the bottom as a
  second guard. Found by `tests/rig/ui/check-compose.mjs`; no server-side test
  could have seen it.

- **"Ask AI" threw away a review that arrived as prose.**
  `includes/class-studio-ai.php`. `review()` returned the same
  "not in a form this screen could read" error as `plan()` did. It now keeps the
  words and says plainly that the structured fields are missing, with the verdict
  defaulting to `caution` rather than `safe` — an unparsed review has not
  established that anything is fine.

- **The Snippets editor is an editor now.** `includes/class-studio-edit.php`,
  `assets/css/studio.css`, `assets/js/studio-edit.js`.

  It was one column: a name field, a bare `<textarea>`, five settings collapsed
  behind a `<details>`, then the buttons, then the history table right at the
  bottom. Every piece worked. It still read as "a form with a big box in it",
  and the feedback that prompted this rebuild was a user reporting two features
  as MISSING that had shipped months earlier — because they were below the fold.

  Now two columns. The code takes the whole left side inside a proper editor
  frame: a language dot in the language's own colour, a `snippet.php` filename,
  a `PHP` pill, and the "Ask AI" button in the toolbar where it belongs. All
  three follow the type dropdown live. Everything that is a *setting* rather
  than the work moved to a 320px right-hand rail, in labelled panels that are
  always open — nothing that is switched on is hidden behind a disclosure
  triangle any more.

- **Revisions are visible.** They existed since 1.9.0 and were rendered after
  the submit buttons at the bottom of a long page, which is why they were
  reported as a missing feature. They are now a panel in the rail, next to
  everything else about the snippet, with the Restore button on each row.

  Restore is driven by a single form outside the editor form (forms cannot
  nest), reached with the HTML5 `form` attribute; each button carries its own
  revision id as its own submitted value.

- **Smart conditional logic, with a UI.**
  `includes/class-snippet-conditions.php` (new).

  The old engine was twenty-five lines inside `class-snippets.php`: four rule
  kinds, implicit AND, and **no screen at all** — only an agent could set a
  condition, through `snippet_save`. A person could not.

  The new engine has seven rule kinds (user status, user role, page type, post
  type, post ID, URL path, device), `is` / `is not`, `contains` / `equals` /
  `starts with` for paths, and an explicit ALL/ANY relation. The editor has a
  repeatable rule builder, and the list screen shows a badge summarising the
  rules — because a snippet whose location says "everywhere" but which only runs
  for logged-out mobile visitors was being described falsely by the one screen
  whose job is to say what is running.

  **There is no migration, on purpose.** Conditions written before 1.2.0 are
  lifted into the new shape every time they are read, so nothing in the database
  is rewritten, no upgrade step can half-run, and a site that rolls back still
  works. `snippet_save` also still accepts the old flat shape.

  One subtlety is pinned by tests: the old `post_types` and `post_ids` checks
  were wrapped in `is_singular()`, so on an archive they did not *fail*, they
  did not *apply*. Read as ordinary rules they would newly fail there, and a
  header snippet that had always shown on category pages would vanish on update
  for a site whose owner changed nothing. Lifted rules carry `only_singular` and
  behave exactly as they did; rules built in the new UI do not.

- **The list screen wears the same language pill as the editor**, dot and all,
  instead of a grey `<code>php</code>` that looked like a different product.

### Fixed

- **Deleting a snippet left its revisions behind, for ever.**
  `includes/class-snippets.php`.

  `delete()` removed the snippet row and nothing else. Every edit records a
  revision, so a snippet edited twenty times and deleted left twenty rows keyed
  to an id nothing would ever look up again: unreachable from any screen or
  tool, growing without limit, and **each one holding a full copy of the code**.

  That last part is why this is not merely untidy. Somebody who deletes a
  snippet because it contained a key, a password or a customer's data has been
  told the snippet is gone. It was not.

  Found by a teardown step that deleted a test snippet and counted what
  remained: `snippets left: 0, revisions left: 7`.

- **Uninstall did not delete everything it promised to.** `uninstall.php`.

  Two hand-written lists, and they had drifted. By 1.2.0 the plugin created TEN
  tables and the list named six, so `keelhob_snippet_revisions`,
  `keelhob_chat`, `keelhob_agent_runs` and `keelhob_feedback` survived
  a delete the owner had explicitly ticked "delete all data" for — the revisions
  table still holding every version of every snippet. Nine options, every
  transient, the user meta and the cron jobs were in the same position.

  Replaced with a prefix sweep, which cannot drift because nothing has to be
  remembered: every table, option, transient and meta key this plugin writes is
  prefixed, and a new feature that follows that convention is now cleaned up on
  the day it is written. Every pattern is anchored and escaped, and the table
  sweep is fenced to this site's own `$wpdb->prefix`.

- **The second Studio tab said "Add Snippet" while editing.** Opening an
  existing snippet gave a highlighted tab reading "Add Snippet" directly above a
  heading reading "Edit Snippet" — two labels for one screen, disagreeing.

- **1.0.3 is labelled honestly.** It carried a date, which reads as "released on
  this day", and it never was — there is no 1.0.3 tag and no 1.0.3 release. It
  was written on 2026-09-08 and folded into 1.1.0, which shipped all of its code
  on 2026-09-13. The heading now says so in both changelogs, so its absence from
  the releases page reads as the decision it was rather than as a gap.

- **The Snippets code editor never loaded, on any install.**
  `includes/class-studio-edit.php`, `assets/js/studio-edit.js`.

  `render()` called `wp_enqueue_code_editor()`, enqueued `wp-codemirror`, called
  `wp.codeEditor.initialize()` and wired a mode switcher to the type selector.
  Read the source and the screen had a syntax-highlighting editor. Render it and
  it had a `<textarea>`.

  Everything enqueued from a screen render callback prints in the FOOTER, so the
  inline init block ran as script 9 of 85 while `code-editor.min.js` loaded as
  script 74. `wp.codeEditor` did not exist yet, and this swallowed it:

      var editor = wp.codeEditor && wp.codeEditor.initialize ? ... : null;

  Two more consequences of the same block. `window.cpStudioEditorSettings` was
  never defined anywhere in the codebase, so a correctly-ordered init would still
  have received `{}` rather than the mode and `lineNumbers` it needed. And the
  "Ask AI" handler reads `editor.codemirror.getValue()` when an editor exists and
  falls back to the textarea otherwise — so it has taken the fallback path
  forever, and the branch written for the real editor had never once run.

  It is a real file now with a declared `code-editor` dependency. WordPress
  orders the graph. The truthy guard is gone: if the editor cannot start, the
  page says so.

  **How it was found.** `tests/rig/ui/check-snippets.mjs`, added in the same
  release, opens the page in Chrome and asserts on `.CodeMirror`. Neither
  `tests/run.php` nor `tests/rig/checks-studio.php` could see it, and
  `checks-studio.php` was written specifically to exercise this screen.

- **PHP snippets were not syntax-coloured even once the editor worked.**

  `wp_get_code_editor_settings()` maps `application/x-httpd-php` to CodeMirror
  mode `php`, which is the HTML-with-embedded-PHP mode: everything before a
  `<?php` is treated as HTML. Snippet code is stored and `eval`'d WITHOUT an
  opening tag, so an entire snippet rendered as unhighlighted text. The mode is
  overridden to `text/x-php` on the returned settings, because the type branch
  inside WordPress `array_merge`s over anything passed in.

  Measured before and after: 0 distinct token colours, then 4.

- **The abilities banner did not return after abilities were toggled off and
  on.** `includes/class-admin.php`.

  `notice_fingerprint()` hashes current state, so an off/on cycle returns the
  hash to the value it had before, and a dismissal recorded beforehand still
  matched. The banner stayed hidden through exactly the event it most needs to
  reappear for. A unit test asserting "off then on returns to the first value"
  had been written, passed, and described the bug as though it were the feature.

  `rewarn_on_change()` now clears every dismissal on any settings write that
  alters abilities or capability groups — driven by the change, not the resulting
  state, and for all users rather than the one who made it.

### Added

- **A browser test layer.** `tests/rig/ui/`, `package.json`, CI job `browser`.

  `playwright-core` driving Chrome against the rig, asserting computed styles and
  bounding boxes rather than markup. Its first run against the unfixed screen was
  29 of 34 red, reproducing every baseline measurement in
  `docs/plans/snippets-rebuild.md`.

  `onScreen()` compares bounding boxes against the viewport because WordPress's
  `.row-actions` are `visibility: visible`, have real dimensions, and sit at
  `left: -129987px`. Every naive visibility test passes on them.

  Fixtures are torn down in the same run that asserts on them, from a `finally`
  that also runs after a thrown error.

  Advisory in CI (`continue-on-error: true`) until it has been green for seven
  consecutive days on `main`. The existing PHP rig job stays blocking.

- **A visible on/off switch in the Snippets list.** `includes/class-studio-list.php`.
  Still a POST in a form with a nonce — a GET that switches code on gets fired by
  browser prefetch, security scanners and email clients following links.

- **An `Errored` status filter**, a **Priority column** and **sortable headers**.
  The sorting logic already existed in `Keelhob_Studio_List::sort()` and
  `filters()` already read `?orderby`; there were simply zero sortable headers in
  the markup, so none of it was reachable. Priority and Errored appear only when
  a snippet on the site is actually using them.

- **Discard and Plan again on an AI Compose plan**, plus an acknowledgement
  checkbox before Save on a failed syntax check.
  `includes/class-studio-compose.php`, `assets/js/studio-compose.js`.

- **A dismissible abilities banner**, per user, with `chip_watchdog()` clearing
  the dismissal if anything removes the toolbar indicator.

- **`?days=N` on `/v1/feedback/summary`** (keelhob-api). The counts were
  lifetime, which can only go up, so they could not answer whether a problem got
  rarer after a release.

- `tests/unit/test-abilities-notice.php`, and user-meta stubs in
  `tests/bootstrap.php`.

### Changed

- **The snippet editor is code-first.** Six `form-table` rows pushed the code box
  to 736px down a 674px viewport — measured, not estimated. Name, type and state
  stay above; description, location, priority and tags moved into a `<details>`
  below, `open` when any is non-default. Now 267px and two fields.

- **`Save` is `button-primary`**; `Save & switch on` is secondary and only
  rendered while the snippet is off.

- **Donate call sites cut from 7 to 1** (Connect), plus the Donate page and the
  Plugins-row link. `tests/unit/test-donate.php` previously enforced the
  opposite, with `$heads === $found`; it now scans all of `includes/` so a stray
  button cannot creep back.

- `tests/rig/checks.php` deletes the two snippets it creates. One carries a
  deliberate syntax error, and on a rig kept between sessions they accumulated
  and failed an unrelated check. A fixture that outlives its test becomes
  somebody else's bug.

- **The Plugins screen now says the snippets engine works on its own**, and
  links straight to it. `keelhob.php` header, `includes/class-donate.php`.

  The description described one product: a thing that hands an AI agent control
  of a site. True, and incomplete. The snippets engine has a real code editor,
  a per-snippet on/off switch, search, filters and a parse check that refuses to
  let broken PHP run — and none of it needs an agent connected or abilities
  switched on. Somebody who wants a code-snippets plugin and nothing else has
  one here, and the one screen where they would look never said so.

  The row link is third, after Donate and Settings. Settings is what most people
  came for, and a row of links that buries it has made itself worse. The slug
  comes from `Keelhob_Studio::SLUG` rather than being typed out, so renaming
  the page cannot leave a dead link on the Plugins screen — the one place nobody
  thinks to re-check.

  Asserted in a browser: WordPress runs plugin descriptions through `wp_kses`,
  so whether the emphasis survives is a property of WordPress rather than of our
  string, and reading the source could not answer it.

### Removed

- `includes/class-admin-snippets.php` — 337 lines implementing a complete second
  Snippets screen that nothing instantiated and no menu pointed at, shipping in
  the zip. `tests/unit/test-admin-render.php` was the only thing keeping it
  alive.

- The "Browse the library" button in the empty state. It pointed at
  `tab=library`, which `current_tab()` does not recognise when the Library tab is
  absent, so it silently returned you to All Snippets. It has never worked:
  `Keelhob_Snippet_Library` was never written.

## [1.1.0] - 2026-09-13

### Added

- **The feedback log.** `includes/class-feedback.php`, `includes/tools/class-tools-feedback.php`.

  The loop this exists for: somebody asks for a hero section, the agent gets it
  wrong, they correct it, it gets it wrong differently, three rounds later it
  lands and they are tired. That happens on every install and none of it reaches
  the project. One person shrugs; twenty people is the most useful thing this
  project could know.

  Friction is captured automatically in `Keelhob_Tool_Registry::call()` — the
  one place every tool call passes through — because an agent's own account of
  what went badly is the least reliable evidence there is. It sees three things
  by itself: a tool that errored, a refusal that reads as a policy block, and the
  same tool called with near-identical arguments three times. Zero tokens, zero
  conversation content, and an agent cannot decline to report its own failures.

  The agent adds a line only for the category no machine sees: *it did what was
  asked and the result looked wrong.*

  **Nothing is sent without a person reading the whole draft and saying yes.**
  That rule is operating-protocol **rule 15**, in PHP, for the same reason as
  rules 9–14: a skill can be switched off and consent must not be. The two tools
  split on that boundary — `feedback_status` drafts and can never send;
  `feedback_send` requires `approved` and is the only thing that touches the
  network.

  Offered only when friction exists **and** seven days have passed since the last
  offer, whatever the answer was. A prompt every session is a nag, a nag gets the
  setting switched off, and that costs more reports than the nagging gains.

  Retention: 30 days or 50 rows, tighter than the 90 days the server keeps,
  because this table sits on somebody else's site.

- **`Keelhob_Feedback::redact()`**, applied on write and again before sending.
  Strips URLs, bare domains, emails, IPv4/IPv6, absolute paths, key-shaped runs,
  anything following `key`/`token`/`password`/`secret`, and the site's own name
  and hostname. In PHP, not in a skill, because a skill can be edited away.

  `tests/unit/test-feedback-redaction.php` plants each of those as a deliberate
  leak. A no-op `redact()` fails 22 of its 30 assertions — verified, not assumed.
  That file is what makes the claim in PRIVACY.md checkable rather than stated.

- **Keelhob → Activity → Feedback log.** Every row, in full, with two buttons:
  one that wipes the local log, one that also sends
  `DELETE /v1/feedback?site_id=…` so the server drops what it already holds.
  Anonymous data normally cannot honour a deletion request because nothing can
  find it; the site knows its own UUID, so it can ask.

- **The "Help improve Keelhob" setting**, on by default, beside the public-count
  setting because both answer the same question. Default-ON is defensible only
  because of the two rules under it: nothing is recorded when it is off, and
  nothing is ever sent without a yes. Default-ON *sending* would not be.

- **`skills/contribute-feedback.md`** — how to word a note, with a good and a bad
  example side by side. 100/100 on `bin/lint-skills.php`.

- **API:** `POST /v1/feedback`, `DELETE /v1/feedback`, `GET /v1/feedback/summary`,
  the `feedback_reports` table (migration `0005`), and `src/domain/feedback.ts`,
  which re-derives the whole guarantee server-side. The plugin is GPL and runs on
  somebody else's server, so a modified client can skip every client-side rule.

### Changed

- `PRIVACY.md` and `readme.txt` document the feedback report field by field,
  including the part it is fair to be uneasy about: a client's name typed into a
  note during approval is sent, because redaction catches addresses and keys and
  cannot know that "Acme Dental" is a customer.

- `Keelhob_Capabilities::always_on()` gains `feedback_status` and
  `feedback_send`. A report the owner must enable a capability to give is a
  report nobody gives, and neither tool can change anything on the site.

### Known gaps

- **The private inbox is not built.** `GET /v1/feedback/summary` returns grouped
  counts of *distinct sites* and never a note. Reading the notes needs owner
  authentication, and this service deliberately has none — the two guards that
  once provided it read `SUPABASE_SERVICE_KEY` and were deleted with the paid
  tier. Adding auth back is a decision, not a detail, so it is named here rather
  than half-done.

- **`POST /v1/feedback` is rate limited at 30 a DAY per address**, not the 5 an
  hour the plan wrote.

  Three numbers were considered. 5/hour assumed one site per address, which is
  false for the callers most worth hearing from — agencies and shared hosts put
  dozens of installs behind one outbound IP — and reports arrive in bursts, so a
  round of maintenance across twenty sites on one afternoon would mostly be
  thrown away. 30/hour fixed the burst but allowed 720 a day from one address,
  a generous budget for anyone wanting to skew the roadmap. 30/day absorbs the
  burst and caps abuse at roughly a twentieth of that.

  **A throttled report is delayed, never lost**, and that is what makes a daily
  limit safe. `Keelhob_Feedback::send()` returns the status rather than a
  bool, and `feedback_send` tells 429 apart from every other failure: the rows
  stay in the local log, the site's weekly offer is **not** spent, a 24-hour
  hold is set, and the agent is told in words that it is a queue and not a fault
  on their site. `tests/unit/test-feedback-throttle.php` asserts all of it; a
  `send()` that cannot see a 429 fails 6 of its assertions.

  The person is asked again the next day rather than the approval being stored
  and replayed. Storing it would be the first step toward "remember my answer
  and stop asking", which is the line where this stops being consent.

## [1.0.3] - never released on its own; shipped inside 1.1.0

> **There is no 1.0.3 tag and no 1.0.3 release, on purpose.**
>
> It was written on 2026-09-08 and never published. 1.0.4 was cut the same day
> and folded straight back into it (`0f1cae4`), under a rule worth repeating:
> *a version number is a promise to people holding the old one, and nobody held
> 1.0.3.* Renumber freely until something is published; never after.
>
> Then 1.1.0 shipped on 2026-09-13 carrying all of this code. So everything
> below IS running on every install of 1.1.0 and later — it simply arrived
> under a different number. The entries stay here, under their own heading,
> because renaming them to 1.1.0 now would rewrite a release that is already
> out and put this file out of step with the `readme.txt` inside the zip people
> already have.

### Security

- **A snippet can no longer silently take a site off the internet.** After
  activating a snippet an anonymous visitor could execute, the plugin now loads
  the home page with no cookies and checks the site still answers. If it does
  not, the snippet is switched back off and the reason is returned to whoever
  saved it.

  The failure this exists for: a coming-soon snippet returned `503` from the
  front end. That is the textbook status for "temporarily unavailable" and
  WordPress core uses it for maintenance mode — but managed and containerised
  hosts poll the home page to decide whether a site is alive, read the `503` as
  *this server is dead*, and stop routing to it. `wp-admin` and the REST API go
  down with the front end, so the agent that installed the snippet cannot remove
  it. Nothing errored, so the existing crash guard never fired: it catches a
  snippet that **crashes**, and this one ran exactly as written.

  Two things the check refuses to conclude, because a wrong deactivation
  disables working code and hides the real cause. A failing probe is followed by
  a **control** — switch the snippet off, probe again — so a site that was
  already broken does not get blamed on whatever was saved next. And a
  **transport error is not a failure**: plenty of hosts block loopback requests,
  and treating that as proof would disable working snippets on every one of
  them.

  `admin_only` and `shortcode` snippets are not checked; they cannot reach an
  anonymous visitor.

- **A kill switch that does not need a working site.** Adding
  `define('KEELHOB_SNIPPETS_OFF', true);` to `wp-config.php` stops every
  snippet, whatever the admin page says. Nothing is deleted; remove the line to
  switch them back on.

  The existing `run_snippets` setting is useless in the scenario that matters,
  because reaching it means reaching `wp-admin`. `wp-config.php` can be edited
  over FTP or a host's file manager with no working site and no database client.
  An admin notice says why snippets are inert, so nobody debugs a switch they
  set themselves during an emergency.

- **A css/js/html snippet saved at a location that cannot emit it is now
  refused, not silently stored.** `everywhere`, `admin_only`, `frontend_only`
  and `page_template` only ever run PHP — `run_php_everywhere()` skips any
  snippet whose type is not `php`, and `page_template` `eval()`s PHP but echoes
  anything else raw as the whole page body. `snippet_save()` now checks the
  resolved type and location before writing anything, and returns an error
  naming the type, the location it was given, and the locations that would
  work (`header`, `footer`, `before_content`, `after_content`, `shortcode`).

  The failure this exists for: site CSS was saved at `frontend_only`, reported
  `success: true` and active, and rendered nothing — the page shipped
  unstyled. A warning next to `success: true` gets ignored by an agent that has
  already reported success to a human; a refusal is the only outcome it can
  act on. A metadata-only update (`snippet_save({id, tags})`) is unaffected —
  the check runs against the resolved, already-stored location, not a missing
  one.

- **Every other way of taking a site down is now checked too.** The reachability
  check was wired to exactly one caller. `execute_php`, a write query through
  `execute_sql`, `write_file`, `edit_file`, `delete_file`, `write_sandbox_file`
  and plugin/theme activation can all cause the identical failure — by
  succeeding — and none of them looked. All of them now do, through a shared
  `Keelhob_Health::guard()`.

  **The response differs by whether the action can be undone**, and that
  distinction is the design. Auto-reverting something that cannot actually be
  reverted would be worse than doing nothing:

  | Tool | On failure |
  |---|---|
  | `manage_plugin action=activate` | deactivated again |
  | `manage_theme action=activate` | switched back |
  | `write_file`, `edit_file`, `delete_file`, `write_sandbox_file` | restored from the backup |
  | `execute_php`, `execute_sql` | reported, and nothing else — but reported loudly, because the agent is about to tell a human it succeeded |

  Reads never probe: a `SELECT`, `read_file`, `list_directory`,
  `manage_plugin action=list`. Neither does `install`, which writes files but
  activates nothing.

- **File writes now keep the previous bytes**, so a change that breaks the site
  can be undone. One backup per path, most recent only, under
  `wp-content/uploads/keelhob-backups` with a hashed filename and the same
  `index.php` + `.htaccess` hardening as the sandbox — a backup can hold a live
  plugin file. Capped at 5MB per file and 50MB in total; a skipped backup means
  no automatic revert for that write, never that the write is refused.

  The restore is byte-exact, which is the whole value of it. A file that loses
  its trailing newline or gains a stray `
` is not the file the owner had.

  A **new** file's revert is deleting it, not restoring empty content. A 0-byte
  file left behind is still there, still loaded, and now looks deliberate.

  The sandbox is guarded despite already having a quarantine, because the two
  catch different failures: quarantine fires when a file **fatals**, and this
  fires when it runs exactly as written and takes the site down anyway.

- **`front_end_check: "passed"` no longer reads as all clear when it is not.**
  It means *not your fault* — and the control probe only ever runs because the
  front page was already failing. An agent skimming for that field would read it
  as fine and move on, which is the exact shape of the bug this whole feature
  exists to catch, so a plain `site_down: true` now says the other half.

- **Operating protocol rule 6c** tells agents these checks exist and how to read
  them: `front_end_check: "FAILED"` means undone, `site_down: true` means stop
  and tell the owner, and neither present means it passed or the host blocks
  loopback requests — in which case the check proved nothing and rule 5 still
  applies. Rule 6 now also carries the snippet location matrix, so a refused
  `css` save is avoidable rather than merely explained.

### Added

- **Three skills, and the wiring that makes all eighteen findable.** Fifteen
  shipped, and three jobs an agent is routinely asked to do were thin, missing,
  or steering the wrong way.

  **`seo-audit-and-fix`** — the whole-site pass `seo-optimization` never
  covered: five layers (on-page, technical and Core Web Vitals, AI-overview
  readiness, duplicate and doorway pages, local), a tool ladder that asks what
  the owner has connected rather than assuming, and a free path that works with
  nothing connected at all — because that is what most owners have. Kept
  separate from `seo-optimization` on purpose: per-page work runs constantly
  during a build and must stay cheap to load, while a site-wide audit is rare
  and expensive, and merging them would drag the audit machinery into every
  page. It builds a before-and-after report as a WordPress page with an
  unguessable slug, noindex, at status 200 — which is the only way an owner on
  Telegram or Slack can see the evidence at all — and it never deletes that
  page on its own.

  **`motion-and-interaction`** — a grep for `gsap`, `framer`, `scrolltrigger`,
  `lenis` or `21st.dev` across all fifteen skills returned nothing, and
  `web-design-master` carried a two-line section headed "Motion (subtle
  only)". That was a ceiling where a floor was needed, and it is the
  instruction that made every generated page read as a template. The new skill
  teaches the craft — duration ladder, easing that is not `ease-in-out`,
  8-24px of travel rather than 100, stagger, one signature moment per page —
  and puts CSS-native motion first, because scroll-driven animations,
  `@starting-style` and view transitions need no library and cannot be broken
  by a theme update. GSAP where the job genuinely needs it, enqueued properly
  and version-pinned. It also says plainly that **Framer Motion is a React
  library and a PHP-rendered WordPress theme cannot use it**, which saves an
  afternoon that would otherwise end in a broken build.

  **`launch-readiness-and-compliance`** — twenty specific things that make a
  site read as cheap, each with a way to detect it rather than a warning about
  it; and the legal duties nothing in the plugin mentioned. The one most sites
  fail is not a missing cookie banner but a banner that does nothing: analytics
  firing before Accept is pressed. The skill verifies it instead of assuming —
  load with no cookies, list what is set before the banner is touched — and
  covers GDPR/ePrivacy, CCPA/CPRA, WCAG 2.2 AA and the European Accessibility
  Act, and e-commerce duties including the EU right of withdrawal. It also
  notes the answer nobody offers: a site that sets no non-essential cookies
  needs no banner, so removing unused analytics removes the banner with it.

  Third-party design and SEO resources are **linked and taught in our own
  words, never vendored** — nothing in the zip carries someone else's licence.

  The skills now form a connected tree. `blog-publishing`, `code-snippets`,
  `skill-creator` and `woocommerce-store-operations` named no other skill, so
  an agent could write and publish a whole post without ever learning that
  `seo-optimization` or `web-design-master` existed. `tests/unit/test-skill-tree.php`
  now fails on any skill that names none (a dead end) or is named by none (a
  skill nothing routes to), and checks every file's front matter parses — the
  parser is not YAML, and a description that wraps onto a second line silently
  becomes empty, which makes the skill unroutable with no error anywhere.

- **The Keelhob mark, in the four places WordPress gives a plugin to put
  one.** The admin sidebar icon (previously a generic `dashicons-superhero`,
  which taught nobody anything), the `<h1>` of all eight Keelhob admin
  screens, the admin bar item, and the Dashboard → Updates row — which until
  now showed the grey puzzle-piece placeholder, an unfortunate look for a
  plugin that updates itself from GitHub rather than wordpress.org.

  **The mark is never cropped and never squashed.** A first attempt cut it
  down to the C bowl so it would fit a 20x20 square, and the result read as a
  broken image rather than a logo — the ribbon running out of the bowl is the
  mark. The shape keeps its own 1.7:1 proportions everywhere; only the colour
  changes with size, because below roughly 32px the copper and teal average
  into brown mush.

  **The small marks are drawn as a CSS mask filled with `currentColor`, not as
  an `<img>`.** That is the one thing an image cannot do: take whatever colour
  WordPress has given the text beside it. The alternative was a white PNG, and
  it was wrong twice over — eight of the nine admin colour schemes have a dark
  sidebar and toolbar, but "Light" has neither, so white vanished there; and
  inverting it for that scheme then broke the *current* menu item, whose
  background is already mid-grey with white text. Each fix needed the next one.
  A mask has no states to enumerate, and it is how WordPress draws its own menu
  icons — dashicons is a font, so every core icon inherits `color` for free.
  The mask sits behind `@supports`, because `background-color` with no mask
  would paint a solid rectangle in the sidebar, which is worse than no icon.

  All of it lives in one class, `Keelhob_Brand`, so a redraw is one edit.
  `bin/build-zip.sh` now names each of the four image files rather than
  counting them: a count passes when a file is renamed, and a renamed brand
  file is exactly the quiet failure this guards — the plugin still installs,
  still works, and shows a broken image in the sidebar of every site that has
  it. `tests/unit/test-brand.php` opens each file, checks it is really a PNG of
  the size its name claims, asserts the menu mark is *not* square so it cannot
  quietly be cropped again, and reads the generated CSS to confirm no rule
  names a colour scheme.

- **`tests/rig/sync-plugin.sh`**, for pushing a code change into a rig you are
  keeping. `build-rig.sh` deletes and recreates the whole WordPress install,
  taking every snippet, page and setting with it — correct for CI, wrong for a
  rig kept across sessions to test fixes against. The new script copies only
  the plugin, and parse-checks every file before copying anything, because a
  syntax error copied into a running rig takes down the admin screen you would
  use to find it.

### Changed

- **Agents are now told about both**, in the two places they read before acting:
  the operating protocol gains rules 6a and 6b, and the `snippet_save`
  description carries the same warning. Never send `4xx`/`5xx` from the front
  end — serve the page at `200` with a `noindex` meta tag, which achieves the
  same thing and cannot take the site off the internet. Never gate
  `wp-login.php`, `/wp-admin`, `/wp-json`, `wp-cron.php` or `xmlrpc.php`.

### Fixed

- **A branch named anything but `feature/**` got no CI, and said nothing about
  it.** The push trigger listed `[main, dev, 'feature/**']`, so a branch called
  `fix/rig-silent-failures` ran no jobs at all — not a skip, not a warning.
  GitHub has nothing to run, so the branch shows no check marks rather than a
  failing one, and no check marks looks exactly like nothing to report.

  Fourteen conventional prefixes are now listed — `fix/`, `chore/`, `docs/`,
  `refactor/`, `test/`, `ci/`, `release/` and the rest — so a contributor
  naming a branch the ordinary way does not have to know the workflow file
  exists. `CONTRIBUTING.md` now says which prefixes run, that a pull request
  runs CI whatever the branch is called, and how to run the same checks
  locally.

- **Two bugs in `tests/rig/build-rig.sh`, both of which reported success while
  being wrong.** Neither ships to a user; both cost developer time, which is
  the only reason the rig exists.

  `KH_RIG_LINK=1` claimed to symlink the plugin. In Git Bash on Windows,
  without `MSYS=winsymlinks:nativestrict` and with Developer Mode off, `ln -s`
  recursively **copies** the target and exits 0 — silently. The rig then built,
  loaded, and passed every check while holding a frozen copy, so edits to the
  checkout never appeared and the plugin took the blame. The link is now
  checked with `-L`, the bogus copy is deleted rather than handed back, and the
  error names the two ways out. Verified by running it on a machine where it
  fails.

  The rig's own "is `KEELHOB_DIR` inside `wp-content/plugins`" check
  compared raw strings, so it reported `OUTSIDE` for a perfectly correct rig
  whenever the path held a Windows 8.3 short name — PHP resolved one side to
  `C:/Users/FLINTSBWOY/...` and the other to `C:/Users/FLINTS~1/...`. Same
  directory, no shared prefix. Both sides go through `realpath()` now. A check
  that cries wolf on a correct rig is one that gets ignored on a broken one.

- **`elementor_page` accepted any settings key without looking at it.** A wrong
  control name was written into `_elementor_data`, saved, reported successful,
  and did nothing. On a real build an `xpro-horizontal-menu` was given `menu`
  and `menu_color`; the real controls are `nav_menu` and `menu_text_color`, and
  the result was a menu with default dark-on-light colours on a near-black
  header with nothing anywhere to say why. `set` and `create` now walk the tree
  against each element's real `get_controls()` and return `settings_warnings`.

  A warning, never a refusal, and only when there is something to say.
  Elementor's control stacks are not a closed set — third-party widgets, dynamic
  tags and global references all put legitimate keys where `get_controls()` does
  not list them — so a gate here would block correct work. Responsive suffixes
  are stripped before comparing and every `_`-prefixed key is allowed through,
  because the common and advanced controls come from a stack a widget's own
  `get_controls()` never returns. Verified against a real 42-element page: zero
  warnings. A check that flags a correct page is worse than no check at all.

- **`snippet_save` was throwing away the reachability verdict.** It assigned its
  generic "Active PHP snippet…" note straight over `$res['note']`, which is
  where the verdict travels, so a response could report `front_end_status: 503`
  with no explanation at all — the one message the caller needed. Now appended.

- **`site_readiness` reported a coming-soon gate's holding page as if it were
  every page on the site.** Fetched anonymously, a gate/maintenance-mode/
  catch-all-redirect answers every URL with the same holding page at `200`,
  and the tool was analyzing that page per URL — four separate
  `placeholder-text` HIGH findings all matching "coming soon", an
  `lcp-image-over-budget` for the holding page's own image, and
  `missing-meta-description`/`missing-og-*` for its head, none of which
  describe a page the audit ever actually saw. Found for real on
  keelhob.com. It now hashes the body of every fetched page; when two or
  more distinct URLs come back byte-identical, one `duplicate-body-gate`
  finding is raised instead (`blocker` if every fetched URL matches — the
  audit did not run at all; `high` if only some do, since the rest of the
  crawl is still real signal) and every page-level check derived from those
  bodies moves to `skipped`, never `findings`, naming the affected URLs and
  telling the operator to re-run once the gate is off.

- **`analyze_legal_pages()` demanded a refund page and a shipping page from
  sites with no store.** `terms` and `privacy` still raise HIGH when missing
  or unpublished on every site, but `refund` and `shipping` — and the
  WooCommerce settings that wire pages into them, which do not exist without
  WooCommerce — now move to `skipped` when WooCommerce is not active, instead
  of a false-positive HIGH for a page nothing on the site needs.

## [1.0.2] - 2026-09-07

### Security

- **The skill-signing key has been rotated.** The previous Ed25519 keypair was
  retired and a fresh one generated; `PUBLIC_KEY_B64` in
  `includes/class-skills-remote.php` now carries the new public half, and the
  release assets are re-signed with the new private half.

  The old private key was never committed, never in a zip and never on GitHub —
  it was confirmed absent from every tracked file and from the whole of git
  history before rotating. It was nonetheless exposed somewhere it should not
  have been, and a signing key whose exposure you cannot fully account for is a
  signing key to replace.

  Doing it now costs nothing: the repository is still private, the v1.0.1 assets
  had zero downloads, and no install has ever verified against the old key. The
  same rotation after launch would break skill updates on every site until each
  one updated. **A stale copy of the key was also deleted from
  `keelhob-api/.env`**, where it had been left behind after that service
  stopped signing anything.

  If you somehow have 1.0.1 installed, update to 1.0.2 before skill updates will
  verify again. The plugin update itself is unaffected — it is a zip download
  and is not gated on this signature.

### Fixed

- **`readme.txt` named the wrong update endpoint.** The External services
  section — the privacy disclosure people read before installing — said the
  update check goes to `https://keelhob.com`. It goes to
  `https://api.keelhob.com/v1/version`, which is what `PRIVACY.md` and the
  code have said since 1.0.1. A wrong URL in the one section whose entire job is
  telling people where their data goes.

### Changed

- **`readme.txt`'s three shared sections are now generated from `README.md`.**
  "How risky this is, honestly", "About `eval()`" and "What it cannot do yet"
  say the same thing in both files, and they had already drifted: `README.md`
  grew an eleventh limitation ("not on WordPress.org, and it will not be") that
  `readme.txt` never received.

  `bin/build-readme-txt.php` converts them, and `tests/unit/test-integrity.php`
  fails — naming the line — if `readme.txt` is stale. Everything else in
  `readme.txt` stays hand-written, including its changelog: that one is aimed at
  the person clicking update, while this file is aimed at developers, and
  collapsing the two would lose a deliberate distinction rather than a
  duplication.

## [1.0.1] - 2026-09-06

Contributor-facing, plus one fix that matters to every install.

### Fixed

- **The update check pointed at a route that has never existed.**
  `CHECKIN_ENDPOINT` named `keelhob.com/wp-json/keelhob/v1/version`; that
  route was never written and returns 404. It now points at
  `api.keelhob.com/v1/version`, which is a real service. Combined with the
  GitHub fallback added in 1.0.0, updates work whether or not that service is
  reachable.
- **`class-tools-seo.php` called `Keelhob_Tools_Seo`**, but the class is
  declared `Keelhob_Tools_SEO`. PHP resolves class names case-insensitively
  so this always worked — right up until something mapped class names to
  filenames, which is exactly what the new autoloader does.
- **A capability toggle described its own scope wrongly.** `agent_runs` was
  listed as always-on *and* inside the Autonomy group, so switching Autonomy
  off did not refuse it while the dashboard said it did.

### Changed

- **The three largest files are split.** `class-admin.php` went from 2,440
  lines to 212, `class-providers.php` from 1,781 to eleven files, and
  `class-telegram.php` from 1,322 to 637. The largest file in `includes/` is
  now 818 lines.

  Every method registered as a WordPress hook is still a method on the original
  class, delegating to the new one. What WordPress stores is a callable, so
  repointing a hook would silently break a third party's
  `remove_action([$admin, 'ajax_test_media'])`. **No behaviour changed**, and
  that was verified by diffing every method body byte-for-byte against the
  previous release rather than by trusting a green suite.
- **`keelhob.php` no longer lists 52 `require_once` lines.** A small
  autoloader maps class name to file path. No namespaces and no renaming — a
  rename would break the `keelhob_register_tools` extension seam.

### Added

- **`composer.json`, for tooling only.** Its `require` block is empty on
  purpose. The plugin still ships zero runtime dependencies and `vendor/` is
  not in the zip.
- **PHPStan in CI**, at level 1 with a committed baseline, analysing against
  the PHP 8.0 floor rather than whatever the runner happens to be.
- **Five `good first issue` tickets**, because `CONTRIBUTING.md` had been
  pointing at that label since before any issue carried it.

### Security

- The updater fix above is filed here too. An install that silently never
  updates never receives a security patch either.

## [1.0.0] - 2026-09-06

The first public release.

### About this version number

The repository contains tags reading `v1.10.1-pro-final` and `v2.0.0`, and the
history below goes up to 2.0.0. None of those versions was ever published. The
project was developed privately for its whole life, shipped to exactly one live
site, and numbered as though it had users.

Rather than open with 2.0.1 and imply two major versions of public history that
nobody could have installed, the first release anyone can actually get is
numbered 1.0.0. The old numbers are kept in [Pre-release
history](#pre-release-history) for the record, and because deleting them would
be a worse kind of dishonest than explaining them.

The practical consequence: **1.0.0 below is not the same release as 1.0.0 in the
pre-release section**, and 1.0.0 is newer than the 2.0.0 listed there. Version
comparisons across that boundary are meaningless, which is why nothing in the
plugin does one any more — see the migration fix under _Fixed_.

### Added

Everything, in the sense that none of it has been public before. In summary:

- A self-contained MCP server inside a WordPress plugin. No external service,
  no account, no proxy — the site itself is the server, reachable at
  `/wp-json/keelhob/v1/`.
- Around 60 tools across content, design, media, SEO, files, PHP/SQL execution,
  code snippets, Elementor, WooCommerce (products, orders, payments, shipping,
  tax), site operations, updates, memory and skills.
- Capability groups, so the owner decides which of those an agent may reach.
  Eight risky groups — payments, shipping, tax, orders, users, updates, comms
  and deploy — are off by default and stay off until switched on deliberately.
- An on-site autonomous agent runtime with policy guardrails, budget caps
  (per-run and per-day, in spend, tokens and runs), retry with backoff, and a
  recorded run history.
- Bring-your-own-model: Anthropic, OpenAI or Google, with real per-vendor
  adapters rather than a URL swap, plus OpenAI-compatible gateways.
- A Telegram front-end: chat to the site from a phone, with voice notes, image
  input, one-tap approvals for guarded actions, `/undo`, and per-chat roles.
- Skills — versioned playbooks the agent loads on demand — installed from this
  project's signed GitHub releases. Nothing about the site is transmitted.
- `FUNDING.md`, which states what sponsorship money buys, starting with an
  independent security review of `execute_php` and the snippet engine. It also
  states what continues at zero funding: security fixes, compatibility, triage
  and data safety.

### Changed

- `ROADMAP.md` is a product roadmap again. Every milestone used to be gated on
  an install count and denominated in the maintainer's employment status, with
  a monthly figure beside it. All of that was true and none of it belonged in a
  document a stranger reads before deciding whether to depend on the plugin, or
  to spend a weekend contributing to it. The roadmap is now Now / Next / Later,
  keyed to versions; the funding side moved to `FUNDING.md`.
- The paid-tier migration is named for what it does — `upgrade_from_paid_tier()`
  rather than `upgrade_to_2_0_0()` — and takes no version argument, so no future
  renumbering can reach it.

### Fixed

- **Updates now actually arrive.** The updater checked a version endpoint on
  `keelhob.com` that returns 404 and was never built. Because that check-in
  is the default path rather than an opt-in, and because failures in
  `includes/class-updater.php` are deliberately silent, a default install
  received no plugin updates at all, indefinitely, with nothing on screen to
  say so. Only sites that had opted out of the counter were being offered
  updates. `latest()` now falls through to GitHub releases whenever the
  check-in returns nothing usable: transport error, non-200, or a 200 whose
  body carries no release. That last case is the nastiest, because a live
  endpoint answering with something unreadable is otherwise indistinguishable
  from "you are up to date". An opted-out site still makes exactly one request
  and is never sent to the counter.
- **The paid-tier migration no longer fires on sites that never had a paid
  tier.** It was guarded on `version_compare()` against `2.0.0`, and the guard
  returned early on an empty stored version — so a fresh install never wrote the
  run-once flag. Renumbering to 1.0.0 would then have made every user's *first
  update* compare 1.0.0 against 2.0.0, find it lower, and force-disable all
  eight risky capability groups. The migration that exists to stop capabilities
  being silently switched on would have been silently switching them off, for
  everybody, on day two. It is now guarded on stored evidence of pre-merge
  free/paid settings (`disclose_pro`, `hide_paid_notices`, `skills_token`, a
  stored licence), which identifies the population it is actually for and which
  no renumbering can alter.
- `uninstall.php` deletes the migration's run-once flag. It had been leaving an
  orphan option row behind since the flag was introduced.
- The test suite runs to completion on PHP 8.0, the floor `readme.txt` claims.
  Nine reflection calls were missing `setAccessible(true)`, which 8.0 requires
  and 8.1 made unnecessary, so the 8.0 CI leg had been dying at its first fatal
  since 3 September. "1521 assertions pass" was only ever true of 8.1 and above.

### Security

- **An install that never updates never receives a security patch.** The
  updater bug above is listed here as well as under _Fixed_ because that is its
  real severity: for the whole time it was live, the delivery path for every
  future security fix was broken by default, silently, on every site that had
  not opted out of the download counter.
- The Ed25519 release signing key no longer lives in the repository. It was
  gitignored and the history is clean, but one `git add -A -f` publishes it, and
  the blast radius of that is every signed skill anyone has ever installed. The
  build script now looks in the environment first, then at a path outside the
  repository resolved relative to the repo root, then at the old in-repo
  location so an existing checkout keeps working; if none of the three has it,
  the error names all three. The check that refuses to build unless the private
  key derives to the public key embedded in the plugin is unchanged.

---

## Pre-release history

Everything below happened before the project was public. These versions were
never published to wordpress.org, never tagged on a public remote, and could not
be installed by anyone. They are kept because the code they describe is the code
in 1.0.0, and because a reader who finds `v1.10.1-pro-final` in the tag list
deserves to know what it was.

Read these as development notes, not as releases.

### 2.0.0 — free forever, one plugin

- Keelhob became free in full and permanently: no paid add-on, no licence, no
  tier. Payments, shipping, tax, orders and reports, user management, updates,
  email and notifications, deploy to production, the Snippets Studio and all
  fifteen skills were folded into the one plugin.
- Eight capability groups (payments, shipping, tax, orders, users, updates,
  comms, deploy) are force-disabled on upgrade, with an admin notice naming
  them. The paywall had been the only thing standing between a connected agent
  and those tools; removing it without replacing the guard would have handed
  every site the ability to email its customers, edit its users, change its
  payment gateways and push to production, delivered by an automatic update.
- Skill updates come from signed public GitHub releases instead of a
  project-operated server. No account, no key, nothing about the site
  transmitted; an unsigned skill is discarded rather than installed.
- Removed: the private snippet library and site pairing, both of which only ever
  worked by talking to that server. Snippets stored on the site are untouched.

### 1.10.1 — security and a data-loss fix

- Fixed: deleting the plugin dropped every snippet, memory, skill and scheduled
  task, permanently and without warning. Uninstall now preserves everything by
  default, with an opt-in setting for owners who genuinely want it all erased.
- Fixed: `write_file` could be pointed outside its allowed directory with `..`,
  which meant it could write a PHP file anywhere the site could write. Reading,
  editing and deleting were unaffected.
- API keys are encrypted at rest instead of stored as plain text, and are no
  longer printed into the settings page source; the last four characters are
  shown so a key is still identifiable.
- Secrets no longer reach the activity log. Payment gateway keys passed to the
  store tools had been written to the log table in clear text.
- Failed logins are rate limited, and the connection endpoint accepts only
  Application Passwords, not account passwords — closing a password-guessing
  path that security and 2FA plugins could not see, because they watch the login
  page and this was not the login page.
- The connection token is stored as a hash and shown once, at creation. Existing
  tokens convert on first use.
- The unauthenticated status endpoint no longer reveals the exact version.
- Fixed a "require HTTPS" bypass via a forged `Host` header.

### 1.10.0 — "done" means done

- Three rules added to the operating protocol every connected agent receives:
  run `pre-launch-audit` against your own work before reporting anything done;
  never invent a statistic, review, testimonial, customer or staff member the
  site cannot substantiate; load the `build-standard` skill before building. The
  protocol is PHP, injected into every session, and cannot be switched off.
- New skills `build-standard` (the definition of done, with real numbers) and
  `security-standard` (threat model, twenty hardening checks, owner intake,
  WooCommerce card-skimming addendum, compromise-detection sweep).
- New tool `site_readiness`: read-only, always registered, reports `pass` and
  `complete` separately so a skipped check is never counted as a pass.
- Seven skills became blocking gates rather than advice.
- 836 assertions.

### 1.9.1 — SEO head injection fixes

- Fixed: `og:description` never rendered — the injector read
  `_keelhob_seo_og_description` while `seo_meta` wrote
  `_keelhob_seo_og_desc`.
- Fixed: `/llms.txt` was served with a 404 status, so the AI crawlers that are
  its only readers discarded it.
- Fixed: the SEO title never reached `<title>`; nothing read
  `_keelhob_seo_title` back. Now applied via `pre_get_document_title`.
- Fixed: non-`is_singular()` views (shop archive, blog index, static front page)
  got no meta at all.
- Added `og:url`, `og:type`, `og:site_name`, `twitter:card`.
- 672 assertions.

### 1.9.0 — snippets control page

- New Keelhob → Snippets admin page: every installed snippet with per-row
  activate/deactivate, an explanation panel, in-browser editing and deletion.
- Fixed: `snippet_save` rebuilt the whole row on update, so saving one field
  (adding a tag, say) silently wiped the snippet's code. It merges now.
- PHP snippets are parse-checked before storage. Code that does not parse is
  still saved — the work is not lost — but forced inactive with the error
  attached, rather than fataling the site and relying on the crash guard.
- Portable snippet uids and per-site labels, so a snippet can be copied between
  connected sites and re-copied without duplicating.
- Bundle export/import is idempotent for snippets. Bundle version 2 → 3;
  version 2 bundles still import.
- New skill `pre-launch-audit`. Bundled skills now update when the plugin does,
  without overwriting skills edited on the site; new tool `skill_refresh`.
- 648 assertions.

### 1.8.0 — SEO/GEO, site-build kickoff

- `seo_analyze` returns `geo_score` alongside the classical on-page score,
  covering the signals AI answer engines weight when deciding what to cite.
- `seo_schema` generates valid JSON-LD (article, faq, howto, local_business,
  person) and layers multiple schemas into one `@graph`.
- `seo_sitemap` gained `llms.txt` management and per-AI-crawler access control.
  Sitemap ping targets Bing only; Google retired theirs in 2023.
- New skills `site-build-kickoff` and `solace-header-footer`.
- 583 assertions.

### 1.7.1 — filesystem safety hotfix

- `write_file`, `edit_file` and `write_sandbox_file` refuse to write broken PHP
  to disk, naming the reason. Previously nothing checked a `.php` write to a
  live plugin file — only the sandbox had crash recovery — so a bad write
  fataled the whole site on the next request, recoverable only with direct
  server access.
- 583 assertions.

### 1.7.0 — voice, photos, approvals, undo, roles

- Voice notes are transcribed (OpenAI Whisper/`gpt-4o-transcribe`, or Gemini
  audio) and shown before being acted on.
- Real vision input in each vendor's own format; the image is also filed in the
  media library and its attachment ID handed to the agent.
- One-tap approvals for guarded actions: single-use, chat-bound, one-hour
  expiry, instruction stored server-side. Approving re-runs against current
  state rather than replaying a minute-old plan.
- Progress is edited into one message rather than 40 seconds of silence.
- `/undo` restores content changed in the last turn — including changes made via
  `execute_php`, because recording hooks WordPress core rather than the tool
  layer. It says plainly what it could not undo.
- Chat roles: `viewer` and `editor`. A viewer cannot call `read_file`,
  `list_directory` or `export_bundle`, because "read-only" and "can read your
  database password" are not the same claim.
- Event notifications: paid orders above a floor, low stock, a snippet that
  crashed and auto-disabled itself, available updates. Nothing announced twice.
- 573 assertions.

### 1.6.0 — Telegram

- Message a Telegram bot and the on-site agent does the work: no MCP client, no
  terminal, no laptop.
- Multi-turn memory with configurable depth, `/clear`, automatic pruning at 30
  days.
- Built-in commands answered without an API call: `/status`, `/usage`,
  `/tasks`, `/model`, `/history`, `/whoami`, `/help`.
- Access control: allowlisted chat IDs only (an unconfigured allowlist refuses
  everyone, including the owner), constant-time secret comparison, rotatable
  secret. An unrecognised chat is told nothing but its own chat ID.
- Destructive and financial tools stay locked for chat; the owner arms them, the
  chat opts in with `/risky on`, and the opt-in lapses after 30 minutes.
- Deduplicated by `update_id` and acked before the run, so Telegram's retries
  cannot run the same instruction twice; a per-chat lock stops overlapping runs.
- 468 assertions.

### 1.5.0 — bring your own model, and guardrails

- Anthropic, OpenAI or Google, each with a real adapter for its request format,
  tool-calling schema and response shape. OpenAI-compatible gateways (Azure,
  OpenRouter, Groq, Ollama) via the endpoint override.
- Multi-provider image generation.
- Operating protocol: explicit working rules for every connected agent —
  read before write, minimal changes, prefer specific tools over `execute_php`
  and `execute_sql`, verify by reading back, treat live snippets as the
  highest-risk action, report honestly. Extendable via the
  `keelhob_operating_protocol` filter.
- Security: the unauthenticated `/status` check no longer advertises whether AI
  abilities are on or how many tools are exposed.
- Full i18n pass across all four admin screens.
- Budget caps state plainly that costs are estimates from built-in list prices.
- 370 assertions, including a render suite that asserts balanced markup, a nonce
  on every form, and that keys and tokens only ever appear inside password
  inputs.

### 1.4.0 — reliability, budgets, migration depth

- The agent loop retries transient API failures (429/408/5xx, network errors)
  with exponential backoff and jitter, honouring `Retry-After`. Permanent
  failures still fail fast.
- Budget caps: per-run and per-day spend, token and run limits. A capped run
  halts cleanly and reports what it finished.
- Agent run history, plus a read-only `agent_runs` tool.
- Bundles carry nav menus (relinked by slug) and widgets/sidebars. Variable
  products migrate as content only, and say so.
- `wc_shipping` gained `list_available_methods`, `get_method`,
  `configure_method` and `quote`, the last running WooCommerce's real shipping
  engine including live carrier API rates.
- The dependency-free test suite, the live MCP smoke test, and CI on PHP
  8.0–8.4.

### 1.3.3 — autonomy hotfix

- Fixed autonomous runs failing when the model called a tool with no arguments:
  `tool_use.input` serialised as `[]` instead of `{}`, which the Anthropic API
  rejects.

### 1.3.2 — guidance

- New auto-generated Guide admin page, and hover explanations on every
  capability toggle covering what it does, which tools it covers, and the risk.

### 1.3.1 — capability toggles

- Capabilities panel. Disabled capabilities are hidden from the tool list,
  refused if called, and named as off-limits in the session instructions, for
  both interactive and autonomous runs. Read-only status tools stay available.

### 1.3.0 — commerce and deploy

- `wc_payments`, `wc_shipping`, `wc_tax`.
- `export_bundle` / `import_bundle` — portable, idempotent JSON; secrets are
  never exported — and `deploy_push`.
- New authenticated `/import` endpoint, and the `store-launch-and-deploy` skill.

### 1.2.0 — autonomy

- Autonomous agent runtime over the internal tools, with policy guardrails.
- Scheduled automations via WP-Cron: digest tasks (no API key needed) and
  autonomous tasks.
- `get_dashboard`, multi-channel notifications (email, webhook/Slack, Telegram),
  financial caps, destructive-tool opt-in for unattended runs, always-block
  list.

### 1.1.1

- New `web-design-master` skill.
- Memory keys keep dots and colons, so `brand.accent` survives.

### 1.1.0

- Code snippets engine (PHP/CSS/JS/HTML) with locations, conditions, custom page
  templates and crash auto-deactivation.
- Elementor tools, ops tools (`manage_updates`, `send_email`, `image_search`,
  `image_generate`).
- Auto-upgrade routine: updating creates new tables without reactivation.

### 1.0.0

- Initial private release.

[Unreleased]: https://github.com/calflint/keelhob/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/calflint/keelhob/releases/tag/v1.0.0
