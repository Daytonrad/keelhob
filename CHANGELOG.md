# Changelog

All notable changes to Claudpress are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

`readme.txt` carries a changelog too, because WordPress requires one there. This
file is the one written for developers: it names files, says what broke and why,
and keeps the pre-release history that `readme.txt` presents as if it were
public. When the two disagree, this file is correct.

## [1.0.3] - 2026-09-07

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
  `define('CLAUDPRESS_SNIPPETS_OFF', true);` to `wp-config.php` stops every
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
  `Claudpress_Health::guard()`.

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
  `wp-content/uploads/claudpress-backups` with a hashed filename and the same
  `index.php` + `.htaccess` hardening as the sandbox — a backup can hold a live
  plugin file. Capped at 5MB per file and 50MB in total; a skipped backup means
  no automatic revert for that write, never that the write is refused.

  The restore is byte-exact, which is the whole value of it. A file that loses
  its trailing newline or gains a stray `` is not the file the owner had.

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

### Changed

- **Agents are now told about both**, in the two places they read before acting:
  the operating protocol gains rules 6a and 6b, and the `snippet_save`
  description carries the same warning. Never send `4xx`/`5xx` from the front
  end — serve the page at `200` with a `noindex` meta tag, which achieves the
  same thing and cannot take the site off the internet. Never gate
  `wp-login.php`, `/wp-admin`, `/wp-json`, `wp-cron.php` or `xmlrpc.php`.

### Fixed

- **`site_readiness` reported a coming-soon gate's holding page as if it were
  every page on the site.** Fetched anonymously, a gate/maintenance-mode/
  catch-all-redirect answers every URL with the same holding page at `200`,
  and the tool was analyzing that page per URL — four separate
  `placeholder-text` HIGH findings all matching "coming soon", an
  `lcp-image-over-budget` for the holding page's own image, and
  `missing-meta-description`/`missing-og-*` for its head, none of which
  describe a page the audit ever actually saw. Found for real on
  claudpress.com. It now hashes the body of every fetched page; when two or
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
  `claudpress-api/.env`**, where it had been left behind after that service
  stopped signing anything.

  If you somehow have 1.0.1 installed, update to 1.0.2 before skill updates will
  verify again. The plugin update itself is unaffected — it is a zip download
  and is not gated on this signature.

### Fixed

- **`readme.txt` named the wrong update endpoint.** The External services
  section — the privacy disclosure people read before installing — said the
  update check goes to `https://claudpress.com`. It goes to
  `https://api.claudpress.com/v1/version`, which is what `PRIVACY.md` and the
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
  `CHECKIN_ENDPOINT` named `claudpress.com/wp-json/claudpress/v1/version`; that
  route was never written and returns 404. It now points at
  `api.claudpress.com/v1/version`, which is a real service. Combined with the
  GitHub fallback added in 1.0.0, updates work whether or not that service is
  reachable.
- **`class-tools-seo.php` called `Claudpress_Tools_Seo`**, but the class is
  declared `Claudpress_Tools_SEO`. PHP resolves class names case-insensitively
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
- **`claudpress.php` no longer lists 52 `require_once` lines.** A small
  autoloader maps class name to file path. No namespaces and no renaming — a
  rename would break the `claudpress_register_tools` extension seam.

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
  `/wp-json/claudpress/v1/`.
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
  `claudpress.com` that returns 404 and was never built. Because that check-in
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

- Claudpress became free in full and permanently: no paid add-on, no licence, no
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
  `_claudpress_seo_og_description` while `seo_meta` wrote
  `_claudpress_seo_og_desc`.
- Fixed: `/llms.txt` was served with a 404 status, so the AI crawlers that are
  its only readers discarded it.
- Fixed: the SEO title never reached `<title>`; nothing read
  `_claudpress_seo_title` back. Now applied via `pre_get_document_title`.
- Fixed: non-`is_singular()` views (shop archive, blog index, static front page)
  got no meta at all.
- Added `og:url`, `og:type`, `og:site_name`, `twitter:card`.
- 672 assertions.

### 1.9.0 — snippets control page

- New Claudpress → Snippets admin page: every installed snippet with per-row
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
  `claudpress_operating_protocol` filter.
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

[Unreleased]: https://github.com/calflint/claudpress/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/calflint/claudpress/releases/tag/v1.0.0
