# Claudpress — Free/Pro split: task board

**Plan:** [`docs/plans/free-pro-split.md`](docs/plans/free-pro-split.md)
**Branch:** `feature/free-pro-split`
**Started:** 2026-08-20

Legend: `[ ]` pending · `[~]` in progress · `[x]` done + verified · `[!]` blocked on you

---

## BLOCKED ON YOU (everything else is built around these)

| # | What I need | Why | Where it plugs in |
|---|---|---|---|
| B1 | Freemius product ID + secret key | Licence validation for the Pro endpoint | `claudpress.php` bootstrap, skills endpoint |
| B2 | Supabase project URL + service key | Cloud library + skills server | Edge functions, `.env` |
| B3 | Domain name + DNS to Coolify | Updater, site, endpoints | Site config |
| B4 | Ed25519 signing key (you generate, you keep the private half) | Skill bundle signing | Public key embedded in plugin |
| B5 | Business details for ToS/privacy | Legal pages before Pro ships | Site |
| B6 | Three real store builds' token cost | The "$X per build" line on pricing | Pricing page |

Everything below is being built to work the moment these land.

---

## Phase 0 — security + data-loss bugs (1.10.1) ✅ DONE, 919/919 assertions pass

- [x] S1 `write_file` path traversal — `includes/tools/class-tools-filesystem.php`
- [x] S2 API keys plaintext + echoed into HTML — `includes/class-admin.php`
- [x] S3 Secrets in the activity log — `includes/class-logger.php`
- [x] S4 No rate limit + password fallback — `includes/class-auth.php`
- [x] S5 Bearer token hardening — `includes/class-auth.php`
- [x] S6 `/status` version leak — `includes/class-rest.php`
- [x] S7 Host-header HTTPS bypass — `includes/class-auth.php`
- [x] U1 `uninstall.php` deletes everything unconditionally — flip to preserve-by-default
- [x] Verify: traversal test, log redaction test, lockout test, uninstall both directions

## Phase 1 — settings, consent, activity (1.11.0) — mostly done

- [x] Consent gate on "Turn on AI abilities" + consent_by/at/version
- [x] Provider picker: per-provider rows, live swap, model dropdown (10, newest first)
- [x] `parse_models()` on each provider + `fetch_models()` + models cache (1 week)
- [x] `test_connection()` + Refresh button + Advanced custom endpoint
- [x] Two AJAX handlers with nonce AND `manage_options`
- [~] Digest destinations (logic + tests done in Claudpress_Notifier; needs its checkboxes on screen)
- [x] `Claudpress_Notifier::send()` gains a channel-list argument
- [~] Hide-paid-notices setting (stored + honoured in code; needs its checkbox on screen)
- [x] Activity page: 3 sections, 10 rows each, independent paging
- [x] Snippet-activity table + 200-row trim; logger 500 -> 200; memory uncapped

## Phase 2 — tiering + two zips (2.0.0)

- [x] Split `code` capability into `snippets` (free) + `code_exec` (free, separate toggle)
- [x] `tier` field per capability group + `is_pro()`
- [x] Tool registry skips Pro tools when unlicensed
- [x] Tier disclosure in `server_instructions()` + toggle
- [x] `get_site_info` capability block
- [ ] `seed_defaults()` in `class-memory.php` (idempotent)
- [ ] Move Snippets page out of free core
- [ ] Two-zip build script
- [ ] Self-hosted updater (port from VibeSnip)

## Phase 3 — live skills

- [x] Skill catalogue: 15 skills, 9 free / 6 Pro, names+descriptions only, drift test
- [ ] `source` column on skills (bundled/remote/local)
- [ ] Remote fetch, 7-day TTL, 30-day grace, never blocks
- [ ] Ed25519 signature verification, markdown-only validation
- [ ] Refresh never touches `local` rows
- [ ] Skills admin screen: On this site / Available in Pro / changelog
- [ ] `skill_list` returns `available` + `tier` over MCP
- [ ] `build-standard.md` falls back to `site_readiness` on free

## Phase 4 — Snippets Studio (Pro add-on)

- [ ] Add-on plugin scaffold
- [ ] Schema: description, priority, source, ai_*, error_last + revisions table
- [ ] Tab 1 All Snippets (WP_List_Table, filters, badges)
- [ ] Tab 2 Add/Edit + Ask AI (advisory, parse is the gate)
- [ ] Tab 3 AI Compose (plan -> approve -> build -> save inactive)
- [ ] Tab 4 Library (bundled + private)
- [ ] Move deploy/automation/money-Woo/seo_analyze tools to add-on

## Phase 5 — cloud (built against a local stub until B2 lands)

- [ ] Supabase schema + RLS
- [ ] Edge functions: pair, skills/free, skills/pro, library push/pull
- [ ] Device pairing (10-min single-use codes)
- [ ] `wp_claudpress_cloud_map` + 3-way hash sync + conflict UI
- [ ] No delete/export path reachable from the plugin
- [ ] Local stub server so all of this is testable now

## Phase 6 — site

- [ ] Next.js: 3 landing pages, docs, pricing
- [ ] Account dashboard: library manager, delete, export, tags, linked sites
- [ ] Legal pages (needs B5)

---

## Test rig

- [ ] WP-CLI + SQLite + `php -S` rig up
- [ ] AI mock mu-plugin (no key, no spend)
- [ ] Local cloud stub server

---

## Log

| When | What |
|---|---|
| 2026-08-20 | Branch `feature/free-pro-split` created; plan copied to `docs/plans/`. |
| 2026-08-20 | **Phase 0 done.** All 7 security fixes + the uninstall data-loss bug. Version 1.10.1. |
| 2026-08-20 | Wrote the missing test runner (`php tests/run.php`) — the framework existed but nothing ran it. |
| 2026-08-20 | Proved each fix against the pre-fix code first: S1 allowed 4 attacks, U1 destroyed all data. |
| 2026-08-20 | **Tier split done.** Had to split two capability groups: `code`→snippets+code_exec, `store`→store+store_orders. |
| 2026-08-20 | **Phase 1 mostly done** (two subagents in parallel + my own work). Activity page, provider picker, notifier channels, tier disclosure, consent gate. |
| 2026-08-20 | Test suite grew 919 → **1261 assertions**, all passing. |
