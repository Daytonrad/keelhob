# Claudpress — Free core + Pro add-on

**Complete build plan.** Version 1.10.0 → 2.0.0 (free core) + 1.0.0 (`claudpress-pro`).
Written 2026-08-19. Every open question in this document is answered; nothing is left "TBD"
except two items explicitly marked **MEASURE** with instructions for how to settle them.

---

## Contents

1. [Am I ready to build this](#0)
2. [Context and locked decisions](#1)
3. [What changed in the code](#2)
4. [Why not wordpress.org](#3)
5. [Free / Pro split](#4)
6. [Order of work](#5)
7. [Snippets Studio](#6)
8. [Live skills delivery](#7)
9. [Private Library + cloud](#8)
10. [Security fixes](#9)
11. [Settings, consent, tier awareness](#10)
12. [Distribution and pricing](#11)
13. [Files](#12)
14. [Verification](#13)
15. [Decisions register](#14)

---

<a id="0"></a>
## 0. Am I ready to build this, A to Z?

**Yes for the code. No for the parts that need you personally.** The honest division:

**I can build, unattended:**

- All seven security fixes (§9)
- The capability split, `is_pro()`, tier disclosure, the Settings toggles
- The consent gate, the provider picker, the uninstall fix
- The Activity page rebuild with pagination and retention
- The Snippets Studio, all four tabs, ported from VibeSnip
- The skills manifest, catalogue screen, signature verification, cache and grace logic
- The cloud connector, device pairing, push/pull/conflict handling
- The two-zip build scripts, the self-hosted updater
- The Supabase schema and edge functions
- The marketing site, docs and account dashboard
- Every test in §13

**I cannot do these — they need you:**

| Blocker | Why | What I need from you |
|---|---|---|
| Freemius account | Needs your identity and payout details | Set it up, give me the product ID + secret key |
| Domain | Needs a purchase | Register it, point DNS at the Coolify box |
| Supabase project | Needs an account | Create it, give me the URL + service key |
| Ed25519 signing key | **You must hold the private key.** If I generate it in a session, it is in a transcript | Generate with `sodium_crypto_sign_keypair()`, store the secret in CI, give me only the public key |
| Legal pages | Need your real business details and a lawyer's eye | Give me the details; I draft, you review |
| The videos | It is your face and your channel | Record; I can script and edit via `tubergen` |

**The one thing I would change if you let me:** do §9 (security) and §10.3b (the uninstall
data-loss bug) **first and alone**, ship them as 1.10.1, and only then start the split. Those are
live bugs in code you run in production today. Everything else is new work that can wait.

---

<a id="1"></a>
## 1. Context and locked decisions

Claudpress (`antiGrav/Claudpress`, v1.10.0, ~16,300 lines of PHP, 64 MCP tools, 15 skills) lets an
AI agent run a whole WordPress site over MCP. It is free, self-contained, and **not yet public —
you are the only user**, so breaking changes and destructive testing are fine.

| # | Decision | Status |
|---|---|---|
| D1 | **Two tiers only: Free and Pro.** No donations, no Buy Me a Coffee, no third option. | Locked |
| D2 | **wordpress.org is off the table.** Self-hosted distribution only. (§3) | Locked |
| D3 | **Customer brings their own AI key.** You broker no AI and pay no inference cost. | Locked |
| D4 | **One shared cloud backend and one account** for Claudpress and VibeSnip. The *plugins* stay separate codebases — a delegation was built and reverted Aug 2026, do not redo it. | Locked |
| D5 | **Two zips**: free core, and a `claudpress-pro` add-on only customers can download. | Locked |
| D6 | **The Snippets Studio UI is Pro.** The snippet *engine* and MCP tools stay free. | Locked |
| D7 | **Deletion from the cloud library happens only on the website**, never from WordPress. | Locked |
| D8 | **No rebrand.** The plugin stays "Claudpress". `docs/plans/rebrand.md` is out of scope. | Locked |
| D9 | **No lifetime licences**, ever. The library costs money every month, forever. | Locked |
| D10 | **14-day Pro trial, no card required**, started from inside the plugin. | Locked (§11.6) |

`VibeSnip/PLAN-CLOUD.md` and `VibeSnip/MONETIZATION.md` already contain a finished design for Ask
AI, the two libraries, cloud sync, the Supabase schema and the edge functions. **Read both before
writing code.** Take their architecture; ignore their wordpress.org constraints.

---

<a id="2"></a>
## 2. What changed in the code since planning began

Reviewed at `81009bd` on `main`.

**a. `site_readiness` — a new 818-line tool** (`includes/tools/class-tools-readiness.php`). Crawls
the site and grades it: page HTML, schema, image weights, security headers, URL status, 404
handling, tax, stock, legal pages, snippets, open registration. Read-only, and added to
`always_on()` because it is the exit code the `build-standard` skill checks against.

*Decision: it stays free and always-on.* "The AI built it **and graded itself**" is the strongest
possible ending to the viral video, and gating it breaks the demo. Sell the *repeat* instead —
**Readiness monitoring** is Pro (§4.3).

**b. Tool registry hardened** (`class-tool-registry.php:23-45`). `add()` no longer type-hints
`callable` and logs instead of fatally erroring, after a real production outage on 2026-08-17.
This is the exact seam the Pro gate hooks into, and it now fails soft. Keep that pattern.

**c. Skills grew from 13 to 15** — `build-standard` and `security-standard` landed. §4.4 splits all
fifteen.

**d. `docs/plans/rebrand.md`** exists, untracked. Out of scope (D8). One useful thing in it:
**Novamira (novamira.ai) is the direct competitor**, which drives both the tier split (§4.1) and
the pricing (§11.6).

Also landed: four SEO head-injection fixes and a snippet-save merge change. Neither affects this plan.

---

<a id="3"></a>
## 3. Why not wordpress.org

The VibeSnip rejection is a **policy rejection at the category level**, not a fixable defect:

> "The scan is correctly identifying the plugin's **core functionality** rather than an incidental
> use of `eval()`."
>
> "Capability checks, nonces, syntax validation, rollback mechanisms, and unobfuscated calls…
> **cannot make arbitrary code execution secure enough** for distribution through the directory."
>
> "Their presence **does not establish precedent** for accepting new plugins of this kind."
>
> "It would need a fundamentally different approach — offering **predefined, non-modifiable,
> thoroughly vetted** functionality… Otherwise, please consider **developing a different plugin**."

Every mitigation we had was named and dismissed in advance. A stripped Claudpress would not pass
either — it is a *bigger* version of the same concern: a REST endpoint handing a remote AI
administrator-level control, including `manage_plugin` and `manage_option`. The same applies to
VibeSnip. Both products are self-hosted, consistent with the shared backend.

**Keep the two-zip split anyway** (D5), for a better reason: piracy. A single zip with Pro code
behind `if (is_pro())` is a five-minute crack. A separate Pro zip gives free users nothing to
crack. And the real locks — the Private Library and the Pro skills endpoint — live on your server
where no local edit can reach them.

**What dropping wp.org frees up:** no §6 (locked-functionality rule), no §8 (code through third
parties), no §11 (admin upsell rules), no Plugin Check, no trademark rename, no review queue.

---

<a id="4"></a>
## 4. Free / Pro split

**The rule: the viral moment must be 100% free.** Free to BUILD, pay to RUN and GROW.

### 4.1 The competitive correction

Novamira is the direct competitor. **Their free tier already includes PHP execution, database
access, and file operations.** Their Pro (€49/yr, 3 sites) sells *persistent memory and builder
expertise for Elementor, Bricks and 15+ plugins* — meaning **they monetize skills.**

Two consequences, the second reversing an earlier decision in this plan:

1. **Their Pro validates the skills endpoint** (§7). Expertise is what this market already pays
   for. It becomes the flagship, not a footnote.
2. **`code_exec` and `files` move to FREE.** Charging for PHP/SQL/file access when the direct
   competitor gives it away loses every feature comparison, and the wp.org reason for gating them
   is gone. It also resolves a contradiction this plan carried for three revisions: gating
   `execute_php` while `snippet_save` was free never made sense, because a snippet *is* PHP.

Net: **your free tier beats Novamira's, and your Pro is much bigger than theirs.**

### 4.2 FREE

| Group | Tools |
|---|---|
| `content` | create_post, update_post, delete_post, get_post, list_posts, upload_media, manage_taxonomy |
| `store` (build) | wc_product, wc_coupon, wc_settings |
| `design` | elementor_page, elementor_settings, elementor_kit |
| `images` | image_search, image_generate |
| `seo` (setup) | seo_meta, seo_schema, seo_sitemap |
| `site_admin` | manage_option, manage_plugin, manage_theme, manage_menu |
| `memory` | memory_save, memory_delete |
| `snippets` | snippet_save, snippet_list, snippet_get, snippet_toggle, snippet_delete + the safety loop |
| `code_exec` | execute_php, execute_sql |
| `files` | read_file, write_file, edit_file, delete_file, list_directory, write_sandbox_file |
| `skills` | skill_save, skill_delete, skill_refresh + the 9 free skills |
| always-on | get_site_info, get_dashboard, **site_readiness**, list/get reads, **the Activity page** |

**Structural change:** the current `code` group in `class-capabilities.php` bundles `execute_php`,
`execute_sql` and all seven `snippet_*` tools together. **Split it into `snippets` and `code_exec`
anyway** — different things, different risk profiles, and the owner should toggle them separately
even though both are free.

**Unlimited installs on free.** Seat counts belong to the Pro licence only.

### 4.3 PRO — `claudpress-pro`

| Feature | What it is, and why it is Pro |
|---|---|
| **Live Pro skills** (§7) | The flagship. New expertise delivered from your server, continuously. What Novamira charges for, and the only feature that answers "why am I still paying in month twelve". |
| **Snippets Studio** (§6) | The 4-tab admin screen. Free users get snippets *working*; Pro users get to **see and manage** them. Novamira has no snippet manager at all. |
| **Private Library + sync** (§8) | Your snippet locker on our server, across all your sites. Server-side, so unpirateable; value compounds monthly. |
| `automation` — run_agent, task_* | Scheduled and autonomous work. **The only feature that earns while the owner sleeps**, which is what justifies a recurring price. |
| **Readiness monitoring** | Run `site_readiness` on a schedule, keep history, alert when the score drops. Free gives the audit; Pro watches it forever. Built from `automation` + `comms`, so nearly free to add — and it is the "will it break?" insurance that sells to non-store sites. |
| **`store` money side** — wc_order, wc_report, wc_payments, wc_shipping, wc_tax | Orders and refunds, sales reports, checkout config. A demo store needs none of it. **The person who hits this wall is, by definition, earning money from the plugin** — it never blocks a beginner and always catches a business. |
| `deploy` — export_bundle, import_bundle, deploy_push | Staging → live in one action. Only makes sense with two sites: freelancers and agencies. |
| `comms` — send_email, notify_owner, Telegram, daily digest | The site talks back. Convenience on a working site, not a requirement for building one. |
| `seo` ongoing — seo_analyze, seo_redirect | Free gives **setup** (titles, schema, sitemap). Pro gives the **improvement loop** (keyword analysis, redirects). Setup happens once; optimization happens forever. |
| `users`, `updates` | Account management and core/plugin/theme updates — maintenance work people are paid to do for others. |
| **Extended undo** | Free keeps the existing 5-deep stack (`class-undo.php`, `KEEP = 5`). Pro *adds* a durable revisions table and rollback screen. Additive. |
| Extra sites | Agency seats. |

**No usage caps.** No "10 posts a month". Gate by category, never by count.

**"Pro adds, free never loses."** Hold this discipline even with no wp.org enforcing it. It is the
difference between an upgrade prompt that reads as generous and one that reads as a hostage note.

**One removal to plan for:** the free plugin currently registers a Snippets page
(`class-admin.php:28` → `render_snippets` at `:162`). It **moves out of the free core** into the
add-on. Since you are the only user, no migration is needed — but the menu registration, the
render method and `class-admin-snippets.php` all move, not just get hidden.

### 4.4 The 15 skills

**FREE (9)** — the build journey, plus everything to do with safety:

| Skill | What it does |
|---|---|
| `site-build-kickoff` | First skill for any new site: menu + intake Q&A, saves answers to memory, hands off. |
| `website-building` | Build a site, landing page, homepage, header or footer from a brief, screenshot or design. |
| `elementor-page-building` | Build/edit client-editable Elementor pages, headers, footers, templates. |
| `blog-publishing` | Draft, structure, illustrate, categorize and publish posts end to end. |
| `seo-optimization` | Meta, OG/Twitter, JSON-LD, sitemap, robots.txt, llms.txt — verified in rendered HTML. |
| `code-snippets` | Add PHP/CSS/JS/HTML behaviour via live snippets: hooks, filters, shortcodes, pixels. |
| `site-safety` | Baseline reversibility and verification rules for every create/update/delete. |
| `security-standard` | 20 automatic checks, owner-decision intake, card-skimming specifics, compromise sweep. |
| `build-standard` | The Definition of Done: routes the work, defines the fix loop and completion report. |

`site-safety` and `security-standard` are free **on principle** — safety is never a paid feature,
and charging someone to find out whether their site is hacked is bad ethics and bad PR.
`code-snippets` is free because the snippet tools are. `build-standard` is free because without it
the free tier's builds are visibly worse, which damages the demo.

**PRO (6)** — the run-and-grow half:

| Skill | What it does |
|---|---|
| `web-design-master` | A designer's taste: layout, type, colour, spacing, components, an anti-slop bar. |
| `pre-launch-audit` | A scored human-readable report — design, responsiveness, performance, accessibility, conversion. |
| `store-launch-and-deploy` | Empty store → able to take real orders: payments, shipping, tax, staging→production. |
| `woocommerce-store-operations` | Run the shop: products, stock, pricing, orders, fulfilment, coupons, reports. |
| `skill-creator` | Turn a repeatable workflow into a new skill the agent follows automatically. |
| `solace-header-footer` | Solace theme header/footer/mega-menu, distilled from the full builder reference. |

Plus every new skill written from now on — that is the point of §7.

**Integration detail this creates.** `build-standard` (free) routes to `pre-launch-audit` (Pro) for
its fix loop. That reference must degrade, not break: **on free, `build-standard` uses the free,
always-on `site_readiness` tool as its checker.** The split is honest — `site_readiness` is the
automated grader a machine can run; `pre-launch-audit` is the judgement on design, accessibility
and conversion that a tool cannot make. Edit `skills/build-standard.md` to name both paths.

### 4.5 Two skills questions, answered

**Can a user tell the agent to create a skill?** Yes, both tiers, today. `skill_save` is in the free
`skills` group.

**Can an agent copy a skill between sites over MCP?** Yes, already — `skill_get` on A, `skill_save`
on B. Manual and easy to get wrong, but it works.

That is also a leak path: a Pro user can copy Pro skills to a free site. **Accept it.** Skills are
markdown, not executable code, and moving your own skills between your own sites is exactly right.
Chasing it would break the legitimate use to stop a harmless one. Do it properly instead: **ship
"Skills sync"** as a Pro feature reusing the §8 sync engine unchanged, one release after launch.

### 4.6 No grandfathering needed

Earlier drafts planned a `legacy` flag. **Dropped** — you are the only user and the plugin is not
public. The one live install (pranknologia.shop) is yours. Migrate it deliberately, not by
auto-update, and snapshot first: the deploy method is Coolify + a built zip, **never**
file-by-file over MCP.

---

<a id="5"></a>
## 5. Order of work

| Phase | What | Ships as |
|---|---|---|
| **0** | Security fixes (§9) + the uninstall data-loss bug (§10.3b) | **1.10.1**, immediately |
| **1** | Consent gate, provider picker, Settings, Activity page rebuild (§10) | 1.11.0 |
| **2** | Capability split, `is_pro()`, tier disclosure, two-zip build | 2.0.0 + pro 0.1.0 |
| **3** | Backend: Supabase, device pairing, skills endpoints, library endpoints | — |
| **4** | Snippets Studio in the add-on (§6) | pro 0.9.0 |
| **5** | Site, docs, pricing, legal pages, Freemius | — |
| **6** | Launch: videos, MCP directories, GitHub, Product Hunt | 2.0.0 + pro 1.0.0 |
| **7** | Skills sync (§4.5) | pro 1.1.0 |

Phase 0 is independent and must not wait for anything.

---

<a id="6"></a>
## 6. The Snippets Studio

One page, horizontal tabs, WordPress's own `nav-tab-wrapper` pattern — the same look as the
WooCommerce → Settings screen. **Ships only in the Pro add-on.**

**A port, not a rewrite.** `includes/class-snippets.php` already has `uid`, `tags`,
`conditions_json`, `lint_php()`, an `on_shutdown()` fatal guard, locations, shortcode support, page
templates, `origin_site` and `origin_uid`. VibeSnip's schema (`class-vibesnip-db.php:45-90`) is the
target shape.

**Schema** — additive `dbDelta` columns on `wp_claudpress_snippets`: `description`,
`priority INT DEFAULT 10`, `source VARCHAR(20)` (manual|ai|library|cloud), `ai_prompt`, `ai_model`,
`ai_review LONGTEXT`, `ai_review_at DATETIME NULL DEFAULT NULL`, `error_last`. Plus a `revisions`
table ported from VibeSnip. **`DEFAULT NULL` on every datetime** — MySQL strict mode rejects
zero-dates.

### Tab 1 — All Snippets

`WP_List_Table` with sub-filters (All / PHP / CSS / JS / HTML / Text), search, tag filter,
active/inactive filter. Columns: title, type, location, priority, tags, source badge (an
**AI-generated** badge on `source=ai` — cheap insurance under the EU AI transparency rules in force
since Aug 2026), AI-review verdict chip, last run.
Row actions: Activate/Deactivate, Edit, Duplicate, Delete (local only), **Save to Library**.

### Tab 2 — Add / Edit Snippet

Port `VibeSnip/admin/views/edit.php`. CodeMirror via `wp_enqueue_code_editor()`. Fields: title,
description, code, type, location, priority, tags, conditions.

**Ask AI** — port `VibeSnip_AI::review()` and its JSON schema (`PLAN-CLOUD.md` §1 has the field
list). One click: auto-save as an inactive draft, call the model, render the report, persist to
`ai_review`.

**The guardrail that must not be softened:** the AI report is *advisory and never gates anything*.
The deterministic PHP parse (`Claudpress_Snippets::lint_php()`) is the gate. Render both, labelled
differently — `Syntax check: passed` is **authoritative**; `AI assessment: caution` is an opinion.
Never let the model's claim that syntax is fine stand in for the parse.

**Save & Activate** runs, in order: parse → save inactive → activate → health check →
auto-deactivate and record `error_last` if it fatals. Nothing activates unparsed, ever. The safety
loop stays free even though nothing forces it to — it is why the product is trustworthy, and it is
what keeps dead sites out of your support inbox.

### Tab 3 — AI Compose

Port `VibeSnip/admin/class-vibesnip-compose.php` (412 lines) and the `plan()` / `generate()` pair
from `class-vibesnip-ai.php`. Flow: **describe → plan → approve → build → save inactive**.

Uses the provider settings Claudpress already has (`includes/class-providers.php`), so it works
with whatever key the owner configured, and **it does not vanish when no key is set** — with no key
it renders the full screen plus a clear "connect a provider in Settings" state.

| Entry point | Tier |
|---|---|
| AI Compose screen in wp-admin | Pro |
| MCP — `snippet_save` from a connected agent | **Free** |
| Telegram bot chat | Pro |

**Hard rule from VibeSnip:** nothing is written to the snippets table until the user clicks Save.
Planning and building are pure. VibeSnip has a browser assertion for this — port it.

### Tab 4 — Library (two sub-tabs)

**Claudpress Library** — the bundled vetted set, ported from `class-vibesnip-library.php` plus
Claudpress-specific ones (Woo tweaks, Elementor helpers, SEO snippets), grouped by category via the
existing `by_category()` pattern. **Use Snippet** imports **inactive**, then the normal
parse-and-activate path.

**Private Library** — the user's own cloud locker, grouped the same way. §8.

### 6.5 Activity page — snippets section, pagination, retention

Ships **free**, on both tiers. It currently renders **every** memory row and 100 log rows with no
paging (`class-admin.php:1351-1390`), which is why pranknologia.shop's Activity page is enormous.

**a. Add a Snippets section.** A third table: snippet created / edited / activated / deactivated /
deleted / auto-deactivated-on-fatal, with who did it (user or agent) and when. **Free on both
tiers** — a free user cannot see the Studio, but they must be able to see what the agent did to
their site. That is transparency, not a feature.

**b. Paginate every section at 10 rows**, each with its own page state (`?mem_page=`,
`?snip_page=`, `?log_page=`) so paging one table does not reset the others. Use `paginate_links()`.

**c. Retention caps, applied on write:**

| Section | Cap | Why |
|---|---|---|
| Recent tool calls | **200** | `class-logger.php` already trims at `MAX_ROWS = 500` — lower the constant. The trim logic exists. |
| Snippet activity | **200** | Same ring-buffer trim on the new table. |
| Memory | **no cap** | Memory is the agent's long-term knowledge. Silently dropping it makes the agent forget what the owner told it. Paginated, never trimmed. |

---

<a id="7"></a>
## 7. Live skills delivery

Ship with skills, refresh from a server, free endpoint and Pro endpoint, and no way to brute-force
into Pro.

**Verdict: the best monetization idea in this plan.** It is the one Pro feature whose value
arrives *continuously*, it is what Novamira already charges for, and — unlike every local
`is_pro()` check — **it is genuinely enforceable**, because the decision happens on your server.

### 7.1 Ship the 9 free skills in the zip

Shipping empty means a fresh install with no network is a dud, and the **first** experience — the
viral moment — depends on your server being up on the day the video lands. Ship the free 9 as a
bootstrap; use the endpoint for updates and Pro skills.

`Claudpress_Skills::seed_bundled_skills($force)` and `bundled_action()` already implement exactly
this hash-compare-and-decide behaviour for bundled files. **Extend them, do not replace them.**

### 7.2 Not a private GitHub repo

A private repo needs a read token, and that token would ship inside every copy of the plugin — so
it leaks on day one and the repo is effectively public. GitHub also cannot rate-limit or revoke per
customer, and its raw endpoints are not built for per-account authorization.

**Author in GitHub, serve from your own endpoint.** A CI job syncs repo → server on push and signs
the bundle at build time. Same convenience, none of the exposure.

### 7.3 The two endpoints

| Endpoint | Auth | Returns |
|---|---|---|
| `GET /v1/skills/free` | Anonymous install token | Manifest + the 9 free skills |
| `GET /v1/skills/pro` | Per-site token → licence | Manifest + every skill the plan allows |

**Identity.** On activation the plugin generates an install UUID with `random_bytes(32)` and
exchanges it on first contact for a signed **anonymous install token** — giving you rate-limiting
per install rather than per IP, which is what actually works behind shared NATs and CDNs. Pro sites
additionally hold the per-site token from device pairing (§8.3).

**The rule that makes this unbreakable:** the check happens on **your server**, never in the
plugin. A power user can edit the plugin to *ask* for the Pro endpoint. They cannot edit what your
server *answers*.

**Validation chain on `/v1/skills/pro`:**

1. Resolve the bearer token → account. Reject unknown or revoked tokens.
2. Validate the licence **server-to-server against Freemius** — never client-side. Cache ~15
   minutes so a Freemius outage does not take your endpoint down.
3. Check the requesting site URL against the account's activated sites; enforce the seat cap.
4. Return only the skills that plan allows.

**Brute force:** tokens are 256-bit random. Guessing is not a realistic attack. Rate-limit anyway
(per token and per IP), lock an account after 20 invalid attempts in an hour, and email the owner.

**The threat you should actually plan for is one paying customer republishing the markdown.** You
cannot prevent that — skills are plain text a human can read. Make it traceable and unattractive:
watermark each delivery with a per-account marker (an ordering choice or a comment line) so a
leaked pack identifies its source, enforce seat caps, revoke on abuse. Then let it go. The value is
being *current*, and a leaked pack is stale in a month.

### 7.4 The supply-chain risk — the biggest in this plan

Skills are instructions your agent follows on customer sites. **A compromise of your skill server
means you are instructing every connected agent on every customer site.** That is more dangerous
than everything in §9 combined.

Four requirements, all mandatory:

1. **Sign every bundle** (Ed25519 via `sodium_crypto_sign_*`). Embed the public key in the plugin;
   keep the private key offline, in CI only. Verify before writing anything to the skills table. A
   server breach then cannot forge a skill.
2. **Markdown only.** The payload must never contain PHP, and the plugin must never execute
   anything it downloads. Reject any payload that fails that check.
3. **Log every refresh to the Activity page**, naming which skills changed.
4. **Never overwrite user-authored skills.** Namespace by `source` (`bundled` | `remote` |
   `local`). A refresh only touches `remote` rows. This is the same class of bug as the cloud-delete
   problem in §8.1 — silently destroying the user's own work — and it must be impossible by
   construction, not by care.

### 7.5 Failure behaviour

- Cache skills locally with a **7-day TTL and a 30-day grace period**. If your server is down, the
  site keeps working.
- A failed refresh is a notice, never an error, and never blocks anything.
- The manifest carries a `version` and `sha256` per skill, so `skill_refresh` only downloads what
  changed — cheap enough for an agent to call routinely.
- Tell the agent to call `skill_refresh` periodically via the MCP `instructions` string, not by
  hammering it on every request.

### 7.6 The catalogue — a Skills screen that shows what exists

A sync that silently swaps files is untrustworthy and unsellable. The manifest must be visible.

**The manifest** describes *every* skill, including ones this site cannot have:

```
name · title · one-line description · tier (free|pro) · version · added_at · changed_at
· changelog (one line per version)
```

**A Skills screen** (free core, both tiers) renders it as two lists:

- **On this site** — every installed skill with version, source (`bundled` / `remote` / `local`),
  and when it last changed. A **New** badge on anything added since the previous refresh.
- **Available in Pro** — the 6 Pro skills by **name and description only**. Never the body.

That boundary is the whole design: **descriptions are marketing, bodies are the product.** A free
user should be able to read exactly what they would get — the most honest upsell in the plan, and
it costs nothing to give away.

**A changelog section** lists what arrived in the last few refreshes, so "new skills every month"
is something the buyer can *see* rather than something you assert.

**`skill_list` returns the same structure over MCP**, with Pro entries marked
`available: false, tier: "pro"`. A connected agent then knows a skill exists, knows what it would
do, and knows not to try.

### 7.7 The upside worth naming

Ship a new skill on Tuesday and every Pro site has it Tuesday. **Make "new skills every month" a
headline Pro benefit on the pricing page** — more persuasive than any single feature in the tier
table, and the honest answer to "what am I paying for".

---

<a id="8"></a>
## 8. Private Library + shared cloud

Adapt `VibeSnip/PLAN-CLOUD.md` Phases B and C. Supabase, **EU region** (simplest GDPR position),
shared with VibeSnip, one account.

### 8.1 The safety rule that shapes everything

**Deletion from the Private Library happens only on the website, while logged in. Never from
WordPress, never over MCP, never by an agent.**

Three sites; on site B you delete every snippet on the site. Site B's snippet page is empty — but
your Private Library is untouched. Open the Library tab, pull, restore. A local mistake, an agent
going wrong, or a hacked site can never destroy the library.

| Action | In the plugin | On the website |
|---|---|---|
| Push a snippet to the library | ✅ | — |
| Pull / refresh the library | ✅ | — |
| Use a library snippet on this site | ✅ | — |
| Import a snippet **into** the library | ✅ (MCP or Studio) | ✅ (JSON upload) |
| **Export** the library as JSON | ❌ | ✅ only |
| **Delete** from the library | ❌ | ✅ only |

Export is website-only for the same reason as delete: it moves the whole library out at once, and
that should require a deliberate login, not an agent's tool call.

**This simplifies sync considerably.** No site can originate a deletion. The one remaining rule:
**a pull never deletes or deactivates anything local.** If a snippet was removed on the website it
simply stops appearing in the Library tab; a copy already running on the site keeps running. The
site's snippets and the library are two separate things you push and pull between — never a mirror
one side can wipe.

### 8.2 Organization — tags are the headings

Private Library snippets carry a **tag**, and that tag becomes the section heading on the Private
Library tab — the same structure as the bundled library's `by_category()` grouping, so both tabs
look and behave identically. Untagged snippets fall into "Ungrouped". Tags are edited on the
website, which is where the user reorganizes their library.

### 8.3 Account linking — device pairing, not a login form

Never make a user type your password into their WordPress. Use the pattern a smart TV uses:

1. Plugin shows a 6-character code and a **Link this site** button.
2. The button opens your site in a new tab. They log in (or sign up), see the code, confirm.
3. Your server marks the pairing approved. The plugin polls, receives a **per-site token**, done.

Your account dashboard then gets a real device list — every linked site with URL, last seen, and a
**Revoke** button. That list is a good Pro feature by itself, and it is the same token the Pro
skills endpoint uses.

**Pairing security, all required:** codes expire in **10 minutes**, single-use, generated with
`random_bytes` (never `rand`/`uniqid`), both the approve endpoint and the poll endpoint
rate-limited. Tokens are short-lived with refresh; the licence decides plan and seats.

**The per-site token is a secret** — same treatment as S2: encrypted at rest, `autoload = false`,
never echoed into an admin form field.

### 8.4 Push to Library — the duplicate check

| Result | Prompt |
|---|---|
| Same `uid` exists | "This snippet is already in your library. **Update library copy** / Cancel." |
| Different `uid`, same title | "A different snippet named *X* is already in your library. **Save as new** / **Overwrite that one** / Cancel." |
| Neither | Save, confirm with a notice. |

`uid` first, title second — `uid` alone misses a copy-pasted duplicate; title alone falsely blocks
two genuinely different snippets. "Overwrite" replaces content, never deletes a row, so §8.1 holds.

### 8.5 Pull — three-way, hash-based, never auto-merge

```
wp_claudpress_cloud_map( snippet_uid PK, cloud_id, synced_at, synced_hash, synced_rev )
```

`content_hash = sha256(code + type + location + priority + conditions)`. Cloud rows carry a
monotonic `rev`. Compare local hash, `synced_hash`, and cloud `rev`:

| State | Action |
|---|---|
| local unchanged, cloud unchanged | "Your library is up to date" |
| local changed only | Offer Push |
| cloud changed only | Pull (lands **inactive** if new to this site) |
| **both changed** | **Conflict.** Side-by-side diff: Keep Mine / Take Theirs / Save as Copy. |

Compare hashes, not timestamps — `updated_at` changes when someone saves without editing, which
would light a Sync button for no reason. **Never auto-merge code**: a bad three-way merge of PHP is
a syntax error on a live site.

### 8.6 Cloud hygiene

- Every pull lands **inactive** and goes through the parse before it can activate.
- The cloud only holds code the user authored, imported or approved. **There is no path by which
  you push code to a site.** (The skills endpoint is the one exception, which is exactly why §7.4
  requires signing and markdown-only payloads.)
- **No public or shared community library at launch.** A private locker is a support problem; a
  shared repository of executable PHP is a moderation and liability problem.
- **Treat every cloud string as hostile on the way back in.** Titles, descriptions and tags are user
  text travelling between sites — escape all of them on render (`esc_html`/`esc_attr`). A title
  containing a script tag, synced from site A, is stored XSS on site B's admin, and the admin is the
  one account that can execute PHP.

### 8.7 What sharing a backend with VibeSnip costs

One breach, outage or bad migration hits **both** products and every customer of both. Accept it
deliberately: separate schemas behind one project, row-level security on `account_id` on every
table, no anon access, service-role traffic only through edge functions. Never let one product's
code path read the other's rows.

Storing customer code and site URLs makes you a **data processor**: privacy policy, ToS, a named
retention period (**90 days after account deletion**) and working deletion — live before the add-on
ships.

---

<a id="9"></a>
## 9. Security fixes — Phase 0, ship as 1.10.1

### CRITICAL

**S1 — Path traversal in `write_file` → write anywhere the PHP user can write.**
`includes/tools/class-tools-filesystem.php`, `resolve()` at `:100-122`, `write_file()` at
`:138-158`. Two defects combine:

1. `wp_normalize_path()` does **not** resolve `..` — it only fixes slashes.
2. When `realpath()` fails — every new file `write_file` creates — the guard falls back to the raw,
   unresolved string.

So `write_file` with `wp-content/../../etc/cron.d/x` passes the check, and the OS resolves the `..`
at `file_put_contents` time. Writing PHP outside the base is remote code execution and a
persistence foothold. **Higher priority than in earlier drafts, because `files` is now free.**

*Scope:* `read_file`, `edit_file` and `delete_file` require the file to exist, so `realpath()`
succeeds and their check resolves properly. **`write_file` is the exploitable one.** A smaller bug
affects all of them: `strpos($check, rtrim($base,'/')) !== 0` drops the trailing slash, so base
`/var/www/html` also matches `/var/www/html-backup`.

**Fix:** `realpath()` the *parent* directory, reject any path still containing `..` after
normalizing, compare against `$base` **with** its trailing slash.

### HIGH

**S2 — API keys stored in plaintext and printed into the admin HTML.**
`class-admin.php:452, 621, 1029`:
`<input type="password" value="<?php echo esc_attr($s['agent_api_key']); ?>">`. `type="password"`
hides it on screen; the real key is in the page source. Nothing in the codebase encrypts anything.
**Fix:** render `••••1234` plus a "Replace key" flow — never echo the key. Encrypt at rest with
`openssl_encrypt` keyed off `AUTH_KEY`/`SECURE_AUTH_KEY`. `autoload = false`. **The same treatment
covers the new per-site cloud token and install token.**

**S3 — Secrets written into the activity log in clear text.**
`class-logger.php:39-60`. `record()` truncates long fields but redacts nothing. `wc_payments` takes
live Stripe secret keys as arguments, so those keys land in `wp_claudpress_log.args_json` and render
on the Activity page.
**Fix:** one redaction pass on key names matching `key|secret|token|password|auth`, in `record()`,
covering every tool at once.

**S4 — No rate limiting, plus a plain-password fallback.**
`class-auth.php:63` falls back to `wp_authenticate($username, $password)` when the Application
Password check fails. With no throttling on `/mcp` or `/status`, that is an unlimited
password-guessing oracle **that bypasses login-page protection and every 2FA plugin** — those hook
`wp-login.php`, not your custom REST route.
**Fix:** delete the fallback (Application Passwords only). Per-IP failure counter in a transient:
10 failures → 15-minute lockout, plus a global counter (behind a CDN many requests share one IP).

### MEDIUM

- **S5** — the bearer token is a permanent, unscoped, full-admin credential in plaintext, running as
  the lowest-ID admin (`class-auth.php:103-113`). Hash at rest; add expiry, "last used", naming,
  per-token revoke.
- **S6** — public `/status` leaks the exact version (`class-rest.php:36-41`). Return only the name
  when unauthenticated.
- **S7** — `is_local()` reads the caller-controlled `HTTP_HOST` (`class-auth.php:115-129`), so
  `Host: x.local` switches off the HTTPS requirement. Compare against `home_url()`.

### Already correct — keep these

Telegram webhook signature verified with `hash_equals` (`class-rest.php:68-82`). Bearer comparison
uses `hash_equals`. The admin save handler checks both `current_user_can('manage_options')` and
`check_admin_referer` (`class-admin.php:169`). Admin output escaping is clean. The fail-soft tool
registration (`class-tool-registry.php:23-45`) is good engineering.

---

<a id="10"></a>
## 10. Settings, consent, and tier awareness

### 10.1 The consent gate on "Turn on AI abilities"

Today `abilities_enabled` is a bare checkbox (`class-admin.php:399`) with no warning. It is the
riskiest control in the plugin and it currently reads like a preference. It **is** a real gate —
enforced at `class-mcp-server.php:219`, `class-rest.php:120`, `class-agent-runtime.php:208` and
`class-sandbox.php:29` — so the consent attaches to something that genuinely blocks.

Replace it with a consent block on the Connect page, modelled on VibeSnip's but rewritten for what
Claudpress actually does — Claudpress does far more than write snippets, so the copy must be
broader and blunter:

> **Before you switch this on**
>
> Claudpress lets an AI agent make real changes to this live site. It can write code, edit pages
> and products, change settings, install plugins, and touch your store. **It can be wrong, and
> wrong changes can break pages, checkout, or the admin area.**
>
> Your instructions, the content and code involved, and a short description of this site
> (WordPress and PHP versions, theme, active well-known plugins) are sent to the AI provider you
> choose, under that provider's terms.
>
> You pay that provider directly for what you use. **Claudpress cannot see or cap that spend.**
>
> There are real safeguards. Code is saved switched off, PHP is syntax-checked before it can go
> live, a snippet that fatals is switched back off automatically, recent changes can be undone, and
> every action is written to the Activity log. **These are safeguards, not a guarantee.**
>
> You stay responsible for reviewing what the agent does, for keeping the capability switches set
> to what you actually want, and **for having a backup.**
>
> ☐ **Turn on AI abilities**
> I have read the points above and accept that AI-driven changes run on my site at my own risk.

**Rules for how this behaves:**

- The checkbox cannot be ticked without the text visible above it. Never collapse it behind a
  "learn more" link — a consent nobody read is not a consent.
- **Record it**: `consent_by`, `consent_at`, `consent_version` in settings. If someone later says
  "I never agreed to this", you have a timestamp.
- **Re-prompt when terms change** — a new provider host, or a materially wider capability set.
  Compare `consent_version` on load.
- Turning it **off** is one click, no confirmation dialog. Easy to revoke is what makes it fair to ask.
- **On its own this is not a legal shield.** It sets expectations; the liability position comes from
  the no-warranty ToS (§11.7). Both, not one.
- Pair it with the **forced backup prompt before the first destructive run**.

### 10.2 The Automation page

Two jobs on this page: the provider picker (10.2a, free core) and the scheduled-digest
destinations (10.2b, Pro add-on).

#### 10.2a Provider settings

The provider UI lives in `render_automation()` (`class-admin.php:564`, provider block at `:603-625`).
Today the model is a **free-text input** and the "Get a key" link is rendered server-side, so it only
updates *after* you save — pick a new provider and you are briefly looking at the wrong vendor's link
while typing a key into a box that expects a model name you have to remember.

`Claudpress_Provider` already has `id()`, `label()`, `default_endpoint()`, `default_model()`,
`models()`, `key_url()`, `models_url()`, `headers()` and `error_message()`. It lacks
`parse_models()`, `fetch_models()`, `test_connection()` and a models cache — all four exist in
`VibeSnip/includes/class-vibesnip-ai.php:90-155`. Port them.

| Feature | How |
|---|---|
| **Provider switch updates everything instantly** | One settings row per provider, each `class="claudpress-provider-row" data-provider="<id>"`, shown/hidden client-side on change (the pattern at `class-vibesnip-admin.php:1014`). Key field, key link, model list, endpoint and hint all swap with no reload. |
| **"Get a key" points at the right vendor** | Already `key_url()` — it just needs to live inside the per-provider row so it swaps with the rest. |
| **Model is a dropdown** | Populated from the cached list, **most recent first, capped at 10**, with `default_model()` preselected. Keep a "type a custom model" option so a brand-new model is never blocked. |
| **Refresh button** | `wp_ajax_claudpress_fetch_models` → `fetch_models()`, calling `models_url()` with the provider's headers, running `parse_models()`, cached for `WEEK_IN_SECONDS` — vendors add models occasionally, not hourly, and the button is there for anyone who cannot wait. |
| **Test connection** | `wp_ajax_claudpress_test_connection` → a minimal round trip, reporting success or the provider's own error text. |
| **Advanced: custom endpoint** | A collapsed "Advanced" section with an endpoint override, defaulting to `default_endpoint()`. |

**Three things to get right, all learned the hard way in VibeSnip:**

- **Both AJAX handlers need `check_ajax_referer` *and* `current_user_can('manage_options')`.** They
  accept an API key so they can test an unsaved one — that makes them a credential path, not a
  settings path.
- **Error messages must be human.** A 401 says "Your API key was rejected. Check it above", not
  "HTTP 401". A provider with no model list says "This provider does not publish a model list — type
  the model name your service uses", and the dropdown falls back to free text.
- **A custom endpoint is a disclosure event.** Pointing it at a gateway sends your data to that host
  under *their* terms. Say so next to the field, and again in the consent block.

#### 10.2b Digest destinations — where notifications go

Under **Scheduled tasks → Schedule a status digest**, add checkboxes so the owner chooses where the
digest lands:

> **Send this digest to**
> ☐ Email — *(disabled)* Add a notification email address in Settings first.
> ☐ Telegram — *(disabled)* Connect the Telegram bot first.

Today `Claudpress_Notifier::send()` (`class-notifier.php:18-53`) fans out to **every** configured
channel unconditionally — email, Telegram and a generic/Slack webhook. There is no way to say
"Telegram only". Give `send()` an explicit channel list argument and have the digest pass the
owner's choice.

**Four rules that make this behave correctly:**

1. **A channel is only clickable when it is connected.** Email needs a recipient address; Telegram
   needs both `notify_telegram_token` and `notify_telegram_chat`
   (`class-notifier.php:45-46`). When a channel is not ready, render the checkbox `disabled` with
   an inline reason **and a link straight to the screen that fixes it** — a greyed-out box with no
   explanation is the worst possible version of this.
2. **Preference and capability are separate values.** This is the part that is easy to get wrong: a
   disabled checkbox posts nothing, so if you derive the stored value from the form you will
   silently erase someone's choice the moment Telegram disconnects — and it will not come back when
   they reconnect. Store the preference on its own, and compute *"will actually send"* as
   `preference AND connected`. Never overwrite a preference because a channel is temporarily
   unavailable.
3. **If both are unchecked, say so.** Show "This digest will not be sent anywhere" right there.
   Silence is a bug report waiting to happen.
4. **The webhook channel gets the same treatment.** `send()` already supports a generic/Slack
   webhook, so give it a third checkbox on the same pattern rather than leaving one channel
   controlled and two not. Asymmetric code here becomes asymmetric bugs later.

**Tiering:** `comms` and `automation` are both Pro, so this whole section — digest scheduling and
its destinations — ships in the **add-on**. The free core's Automation page keeps only the provider
picker from 10.2a. That split is why 10.2a and 10.2b are separated here.

### 10.3 Two settings that build trust

**a. Hide notices about optional paid features.**

> Claudpress is fully functional without any paid add-on. Tick this and it will stop mentioning them.

One checkbox, honoured everywhere: the upgrade screen, contextual prompts, and the "Available in
Pro" list on the Skills screen. Shipping the off switch is what earns the right to show the notices
at all.

Keep it separate from the §10.4 agent-disclosure toggle. They look similar, different jobs: this one
is **what a human sees**; that one is **what an agent is told**. Someone may want the agent informed
and their own screen quiet.

**b. Delete everything on uninstall — off by default.**

> Delete all snippets, revisions, memory, skills, history and settings when the plugin is deleted.
> Off by default: deleting the plugin leaves your data in the database, so reinstalling restores it.
> There is no undo once this is on.

**This is a live bug, not a new feature.** `uninstall.php` currently drops `claudpress_memory`,
`claudpress_skills`, `claudpress_snippets`, `claudpress_tasks` and `claudpress_log`
**unconditionally**, plus every option. Someone who deletes the plugin to troubleshoot a conflict
loses every snippet, every memory and every skill, permanently, with no warning. **Flip the default
to preserve.** Ships in Phase 0 with the security fixes.

The setting must be read *before* anything is dropped, and its own option deleted **last** —
otherwise the check deletes its own answer and the branch is undefined.

### 10.4 The tier-awareness toggle

A checkbox in Settings: **"Tell connected agents about Pro features."** On, the plugin discloses
what exists, what is unavailable and why, so an agent does not waste tokens trying. Off, silence.
**Default on**; one click to stop.

The seam exists: `class-mcp-server.php:98` sends an `instructions` string in the MCP `initialize`
response, built by `server_instructions()` at `:147`.

Four surfaces, one source of truth — a `tier` field on each group in `class-capabilities.php`:

1. **`instructions`** gains a short factual paragraph, plus the reminder to call `skill_refresh`
   periodically (§7.5).
2. **`get_site_info`** (`includes/tools/class-tools-core.php`) returns `plan`,
   `available_capabilities`, and `unavailable_capabilities` as
   `{name, what_it_does, why_unavailable, how_to_enable}`. Structured beats prose — an agent can
   branch on it.
3. **A seeded memory row** on activation. `class-memory.php` has no seeding function — add an
   idempotent `seed_defaults()` so reactivation does not duplicate the row.
4. **The error path.** An agent calling a Pro tool gets `not_available_on_free`, naming the tool and
   the upgrade path — not "unknown tool", which invites a retry loop.

**Do not register Pro tools as stubs in `tools/list`.** A listed tool that always fails is worse
than an absent one; agents retry listed tools. Keep the tone **factual, not salesy** — an agent
quoting marketing copy at your user makes the plugin feel like adware. Add a
`claudpress_disclose_pro` filter so a power user can mute it in code.

### 10.5 Can a clever user talk the agent into unlocking Pro?

**Yes, for anything local. No, for anything on our server.**

The specific hole: **`snippet_save`, `execute_php` and `files` are all free.** A free-tier agent has
full code execution on the site by design. It can flip an `is_pro()` option or rewrite a plugin
file. That is not a bug; it follows from what the product is.

**Three things actually hold:**

1. **Code that is not there cannot be unlocked.** The Pro add-on zip is not on a free user's disk.
   To crack it they must first obtain it from a paying customer — turning casual bypass into
   deliberate piracy, a much smaller population. This is the whole reason for D5.
2. **Server-side features are unforgeable.** The Private Library, cross-site sync, **and the Pro
   skills endpoint** live on your infrastructure. No local edit produces a valid response from your
   API. **The most valuable Pro features are the ones that cannot be stolen at all** — which is why
   §7 sits at the top of the Pro table.
3. **Central revocation.** Short-lived signed tokens plus a per-site activation registry make a
   shared licence *visible*: same key, forty sites. Cap seats server-side; revoke centrally.

**Concretely:** treat every `is_pro()` call as **UX, not security**. Never put anything in the free
zip you would mind being switched on. Put every feature with a real marginal cost behind the server.
**Do not add obfuscation or anti-tamper tripwires** — they never work on PHP, they break legitimate
installs, and they look hostile in an audit. Price so honest people pay.

---

<a id="11"></a>
## 11. Distribution and pricing

Without wordpress.org there is no free discovery. Every install comes from something you build.
**This is the highest-risk part of the plan.**

### 11.1 Delivery

- **Self-hosted updater** — already built for VibeSnip, port it. Both zips update from your server.
- **Freemius** for accounts, trials, subscriptions and licence keys. Works fine off-directory, and
  as merchant of record it means you can take cards without a company. It is also the licence
  oracle the skills endpoint validates against (§7.3).
- **Email capture on the free download.** The hidden upside of losing wp.org: the directory gives
  you installs and zero user data. Now you own the list from install #1.

### 11.2 Channels, best first

1. **The videos.** The primary funnel. The `tubergen` studio in this workspace exists for this.
2. **MCP directories.** Claudpress *is* an MCP server — a channel WordPress competitors are not in
   and wp.org cannot take away. Cheap, high-intent, uncontested. Do it early.
3. **Public GitHub repo for the free core.** Developer discovery, stars, search traffic.
4. **WordPress communities** — r/WordPress, r/WooCommerce, Facebook groups, WP Tavern. Show, do not
   pitch: post the build video, answer questions.
5. **SEO content site** — including a real "Claudpress vs Novamira" page. That is the search term
   your buyers already use.
6. **Product Hunt at launch.**

### 11.3 Instead of AppSumo

**Skip lifetime deals; run an affiliate program instead.** (D9)

AppSumo takes roughly 70%, brings a refund-heavy and support-heavy buyer pool, and — the real
problem — sells a **lifetime licence against a product with recurring server cost**. Your library
and skills endpoint cost money every month, forever, for a customer who paid once.

**Novamira does sell a €249 lifetime Agency licence. Do not match it.** Say why on the pricing page:
*"No lifetime plan. Your library lives on our servers, and we would rather keep them running than
sell you a promise we cannot fund."* That is a differentiator, not an apology.

Alternatives, best first:

1. **Affiliate program, ~30% recurring, via Freemius.** The actual replacement for wp.org discovery,
   and it aligns perfectly with the video strategy: WordPress YouTubers and bloggers become the
   channel, and recurring commission keeps them promoting after launch week.
2. **Your own founding-member offer** — a limited number of discounted **annual** licences, price
   locked for as long as they stay subscribed. Keeps 100% of revenue, honest urgency, no permanent
   liability.
3. **Smaller LTD marketplaces** (Dealify, DealMirror, PitchGround) only if you want a cash
   injection. Cap the deal: seats and cloud storage limited, sync excluded or metered.
4. **Black Friday WordPress bundles** — annual licences, never lifetime.

### 11.4 Positioning

Claudpress is **"the AI that runs your WordPress site."** The store build is the *hook*, not the
product. One plugin, three landing pages, three video series:

| Door | Headline | Video hook |
|---|---|---|
| Store owners | Stop building your store. Start describing it. | "I built a WooCommerce store in 10 minutes without touching WordPress." |
| Site builders / bloggers | Describe your website. Watch it get built. | "I described a website. It built itself." |
| Freelancers / agencies | Run twenty client sites from one chat. | "How I manage 20 WordPress sites without opening wp-admin." |

Keep the time claim in the video, never in the website headline — someone will time it.

### 11.5 Conversion levers, strongest first

1. **New skills every month** (§7.7). The only lever that keeps working in month twelve.
2. **The second-site trigger.** The moment someone builds site #2, their library, memory and skills
   do not travel. Pro sells **portability of their own work** — nothing is taken away.
3. **The Snippets Studio.** Makes Pro make sense for a blogger, a portfolio, a restaurant — not just
   stores. Novamira has nothing like it.
4. **The "it's live now" flip.** Once the site ships, the fear changes from *can I build it* to
   *will it break*. Readiness monitoring, durable undo, auto-rollback alerts, digest.
5. **Compounding value.** The library grows every month; switching cost rises with it — honestly,
   because Export gives it all back.
6. **Autopilot.** Scheduled tasks earn while they sleep.
7. **Achievement-triggered trials.** "Your store just went live — here is 14 days of Pro." A reward
   reads as generosity; a countdown reads as a threat.

### 11.6 Pricing

Researched Aug 2026. **Re-verify the competitor rows before publishing; they move.**

| Product | Entry | Top |
|---|---|---|
| **Novamira** (direct competitor) | **€49/yr — 3 sites** | €129/yr — up to 1,000 sites · €249 lifetime Agency |
| Code Snippets Pro | $39/yr — 1 site | $79/yr — 10 · $149/yr — unlimited |
| WPCode | $49/yr — 1 site (incl. private cloud library) | $99/5 · $199/25 · $399/1,000 |

**A correction to earlier advice in this plan.** An earlier pass called $69.99 underpriced,
anchoring on WPCode and Code Snippets. Those were the wrong comparables — they are snippet plugins.
The comparable is **Novamira at €49/yr**, and against them the original instinct was closer to right.
Novamira's €129 for up to 1,000 sites also makes the earlier "$299 for 50 sites" uncompetitive.

**The final ladder:**

| Plan | Price | Sites | Cloud-synced sites |
|---|---|---|---|
| Free | $0 | unlimited installs | — |
| Pro monthly | **$8.99** | **5** | 5 |
| Pro yearly | **$69** | **5** | 5 |
| Studio yearly | **$129** | **20** | 20 |
| Agency yearly | **$199** | **unlimited** | 50 |

**Why this shape:**

- **Monthly and yearly must have the same site count.** The original proposal gave monthly 5 sites
  and yearly 10 — changing two variables at once. It makes monthly feel punitive, makes the plans
  impossible to compare at a glance, and forces a monthly customer who needs 8 sites onto annual,
  which reads as a trap. $8.99 × 12 = $107.88 against $69 is a clean 36% saving that sells itself.
- **$69 for 5 sites** sits just above Novamira's €49 for 3 — defensible, because you ship a Snippets
  Studio, a cloud library, automation and deploy that they do not.
- **Studio at $129/20** fills the 6–20-site freelancer gap, the densest paying segment in WordPress,
  which a $69 → $199 jump would leave stranded.
- **Agency caps cloud-synced sites at 50** even though plugin installs are unlimited. Site count is
  free to give away; **cloud sync is what costs you money.** Separating the two is what lets you
  match Novamira's headline without carrying unbounded cost.

**Trial (D10): 14 days of Pro, no card required, started from inside the plugin.** The original
plan was a 30-day card-required trial, but the product changed: with a free tier this capable, the
trial is about *sampling Pro*, not about using the product at all. A card wall in front of a
14-day sample kills conversion on freemium, and 30 days is longer than anyone needs to decide.

**Founding price.** Open at these numbers, say publicly that they rise after the first 100
customers, and lock early buyers in for as long as they stay subscribed. Honest urgency, and it
buys room to raise later — very hard to do otherwise.

**MEASURE — the AI cost line.** The pricing page must say what a build costs the user, because they
bring their own key. Do not publish a guess. Run three real store builds end to end, record actual
token spend from the provider dashboard, and publish the range. The working estimate is **$3–$10
per full store build**, but treat that as unverified until measured. Copy: *"Free plugin. You bring
your own AI key — about $X–$Y for a full store build."*

### 11.7 Three non-technical risks

- **Liability.** A viral plugin that runs PHP on live stores *will* delete someone's products.
  No-warranty ToS, undo prominent and free, forced backup prompt before the first destructive run.
- **Support load.** Docs and a real FAQ before launch; Pro gets email support, free gets a community
  channel. Without wp.org's forum you host that yourself — budget for it.
- **Reputation is the whole moat now.** No directory ratings means word of mouth is your only social
  proof. One data-loss story travels further than ten good reviews.

---

<a id="12"></a>
## 12. Files

**Phase 0 — security + the uninstall bug (free core, ship as 1.10.1):**
`includes/tools/class-tools-filesystem.php` (S1) · `includes/class-admin.php` (S2) ·
`includes/class-logger.php` (S3) · `includes/class-auth.php` (S4, S5, S7) ·
`includes/class-rest.php` (S6) · `includes/class-claudpress.php` (autoload) ·
**`uninstall.php` — flip to preserve-by-default**, reading the flag before dropping anything and
deleting its own option last.

**Phase 1 — settings and consent (free core):**
`includes/class-admin.php:399` — the consent block on `abilities_enabled`, plus `consent_by` /
`consent_at` / `consent_version` · `includes/class-admin.php:603-625` — per-provider rows with
`data-provider`, model dropdown, Refresh, Test connection, Advanced endpoint ·
`includes/class-providers.php` — add `parse_models()` to each provider, plus `fetch_models()`,
`test_connection()` and a week-long models cache · new `wp_ajax_claudpress_fetch_models` and
`wp_ajax_claudpress_test_connection` handlers (nonce **and** `manage_options`) ·
`hide_paid_notices` setting · `includes/class-admin.php:1351` — Activity page: three paginated
sections at 10 rows · `includes/class-logger.php` — `MAX_ROWS` 500 → 200 · new snippet-activity
table with the same trim.

**Phase 2 — tiering (free core):**
`includes/class-capabilities.php` — split `code` into `snippets` + `code_exec`, add a `tier` per
group and `is_pro()` (single source of truth) · `includes/class-tool-registry.php` — skip Pro tools
when unlicensed · `includes/class-mcp-server.php:147` — tier disclosure behind the toggle ·
`includes/tools/class-tools-core.php` — capability block in `get_site_info` ·
`includes/class-memory.php` — idempotent `seed_defaults()` · `claudpress.php` — Freemius bootstrap,
self-hosted updater, add-on detection, version → 2.0.0 · **remove** the Snippets menu entry
(`class-admin.php:28`), `render_snippets()` (`:162`) and `class-admin-snippets.php` from the free
core.

**Live skills (free core):** `includes/class-skills.php` — extend `seed_bundled_skills()` /
`bundled_action()` with a `source` column (`bundled` | `remote` | `local`), remote fetch with TTL +
grace, Ed25519 signature verification against an embedded public key, markdown-only validation, and
Activity logging · a Skills admin screen rendering "On this site" + "Available in Pro" (names and
descriptions only) and a changelog · `skill_list` returning `available` / `tier` over MCP.

**Skills content:** `skills/build-standard.md` — name `site_readiness` as the free-tier checker
alongside `pre-launch-audit` for Pro.

**Snippets engine (free core):** `includes/class-snippets.php` — additive columns + revisions table.

**New `claudpress-pro` add-on:** Snippets Studio (4 tabs, ported from `VibeSnip/admin/`, including
the moved `class-admin-snippets.php`) · cloud connector + device pairing ·
`wp_claudpress_cloud_map` · durable undo history · readiness monitoring · the digest section of the
Automation page with per-channel destination checkboxes, plus a channel-list argument on
`Claudpress_Notifier::send()` (`class-notifier.php:18`) · and, moved out of core:
`class-tools-deploy.php`, `class-tools-automation.php`, the money half of
`class-tools-woocommerce.php`, and `seo_analyze` / `seo_redirect`.

**New backend (shared with VibeSnip, Supabase EU):** schema + edge functions per
`VibeSnip/PLAN-CLOUD.md` Phase B, plus device-pairing endpoints and `GET /v1/skills/free` and
`GET /v1/skills/pro`. **No delete or export endpoint reachable from the plugin.** CI job syncing the
private skills repo → server on push, signing the bundle at build time with the offline Ed25519 key.

**New site:** Next.js — three landing pages, docs, pricing, and the account dashboard (library
manager with delete, export, tag editing, and the linked-sites list), on the existing Coolify VPS,
plus privacy policy and ToS. **Legal pages live before the add-on ships.**

---

<a id="13"></a>
## 13. Verification

Local WP rig — WP-CLI + SQLite drop-in + `php -S`. Port VibeSnip's `vibesnip-ai-mock.php` mu-plugin
idea so AI flows are testable with **no key and no spend** (`VibeSnip/PROGRESS.md` Phase 7). It
lives in the rig, never in the plugin repo.

**Phase 0**

1. **S1.** `write_file` with `path: "wp-content/../../claudpress-pwn.txt"`. If it lands outside the
   base, confirmed. After the fix it must error. Also test base `/…/html` against a sibling
   `/…/html-backup`.
2. **S3.** Run `wc_payments` with a fake Stripe key, read `wp_claudpress_log`. Key must be absent.
3. **S4.** 50 bad-password requests at `/mcp`; requests 11+ refused.
4. **Uninstall preserves by default.** Create snippets, memory and skills. Delete the plugin
   **without** ticking the box, reinstall — everything comes back. Tick the box, delete, reinstall —
   everything is gone. **Both directions must pass.**

**Phase 1**

5. **Consent gate.** With `abilities_enabled` off, confirm no tool call succeeds. Tick it and confirm
   `consent_by`, `consent_at`, `consent_version` are recorded. Bump `consent_version` in code and
   confirm the site re-prompts.
6. **Provider switch.** Change provider with the page open: key field, key link, model list, endpoint
   and hint all swap **without a reload or a save**. Refresh populates at most 10 models, newest
   first. Test connection reports a bad key as "Your API key was rejected", not "HTTP 401". A
   provider with no model list falls back to free text, not an empty dropdown.
7. **AJAX handlers are locked.** Call `claudpress_fetch_models` and `claudpress_test_connection` as a
   Subscriber, and again with no nonce. Both refused — they carry an API key.
8. **Activity page.** Seed 500 tool calls and 500 memory rows. Log holds 200, memory holds 500, every
   section pages 10 at a time, and paging one does not reset the others.
9. **Hide notices.** Tick it: the upgrade screen, contextual prompts and the "Available in Pro" list
   go quiet, while the §10.4 agent disclosure is unaffected.

**Phase 2+**

10. **The demo test.** Empty WordPress + free plugin only → ask the agent to build a WooCommerce
    store, then run `site_readiness`. Zero upgrade prompts, a passing grade, and time it. **Over 10
    minutes means the split is wrong.**
11. **Tier awareness.** Connect an agent to a free site and ask for a Pro action. It declines
    *without attempting the tool* and names the reason. Turn the toggle off; the disclosure
    disappears from `instructions` and `get_site_info`.
12. **Two-zip integrity.** Unzip the free build and grep for the Studio classes and the cloud
    connector. **Zero hits** — if Pro code ships in the free zip, D5's rationale is void.
13. **Pro skills cannot be reached without a licence.** Edit a free install to point at
    `/v1/skills/pro` and confirm the **server** refuses. This is the test that proves the §7 model —
    it must pass with the plugin actively lying about its tier.
14. **Skill signing.** Serve a tampered bundle from a local stub server. The plugin refuses to write
    it and logs the failure.
15. **Refresh never destroys user work.** Create a local skill with `skill_save`, run
    `skill_refresh`, confirm the local skill is untouched.
16. **Skills offline.** Block the network, call `skill_refresh`. Notice, not error; cached skills
    still work; the site is fine.
17. **Skills catalogue boundary.** On a free install, the Skills screen and `skill_list` show the 6
    Pro skills' names and descriptions and **expose no line of their bodies** — over the admin screen
    or over MCP.
18. **build-standard degrades.** On free, run a build. It must use `site_readiness` as its checker
    and complete, never referencing a skill it does not have.
19. **Nothing is written until Save.** Run AI Compose plan + build; the snippets table is
    byte-identical, then Save adds exactly one inactive `source=ai` row.
20. **The safety loop.** A syntax error refuses to activate. Good PHP activates with a healthy front
    end. A fatal inside a hook auto-deactivates and the next load is clean.
21. **The library can never be destroyed from a site.** Delete every snippet on rig B, open the
    Private Library — everything still there and restorable. Confirm no plugin path and no MCP tool
    can reach a cloud delete or export endpoint.
22. **Pull is non-destructive.** Delete from the library on the website while it is active on rig B.
    Pull on rig B: gone from the Library tab, still running on the site.
23. **Sync conflict.** Edit the same snippet on two rigs, push both. Conflict dialog, never
    auto-merge.
24. **Sync XSS.** Push a snippet titled `<script>alert(1)</script>` from rig A, pull on rig B, open
    every Studio tab. No dialog fires.
25. **Digest destinations.** With Telegram unconfigured, its checkbox is disabled and explains why.
    Configure Telegram, tick it, untick Email — the digest arrives on Telegram only.
    **Then disconnect Telegram, reconnect it, and confirm the tick is still there** — this is the
    preference-vs-capability trap in §10.2b and it is the one most likely to be got wrong. Untick
    both and confirm the page says the digest goes nowhere.

---

<a id="14"></a>
## 14. Decisions register

Everything that was open at some point in planning, and how it was settled.

| Question | Answer |
|---|---|
| Hold the AI key ourselves? | **No.** Customer brings their own. Keeps your cost near zero as it goes viral. |
| wordpress.org? | **No.** Category-level policy rejection; see §3. |
| Rebrand to Quasara? | **No.** Out of scope. Stays "Claudpress". |
| Donations / Buy Me a Coffee? | **No.** Two tiers only. |
| One plugin or two zips? | **Two.** Anti-piracy, not compliance. |
| `execute_php` / `files` — free or Pro? | **Free.** Novamira gives them away; gating them loses every comparison. |
| Snippet engine — free or Pro? | **Free.** Only the Studio UI is Pro. |
| `site_readiness` — free or Pro? | **Free and always-on.** Pro sells the scheduled repeat. |
| Undo depth cap on free? | **No cap change.** Free keeps its existing 5. Pro *adds* durable history. |
| Grandfathering existing users? | **Not needed.** You are the only user. |
| Shared backend with VibeSnip? | **Yes**, one account, separate schemas, RLS on `account_id`. |
| Cloud delete from the plugin? | **Never.** Website session only. |
| Cloud export from the plugin? | **Never.** Website session only. |
| Private skills repo on GitHub? | **No.** Author there, serve from your own endpoint. |
| Ship with zero skills? | **No.** The 9 free skills ship in the zip as a bootstrap. |
| Lifetime licences? | **Never.** Recurring server cost. |
| AppSumo? | **No.** Affiliate program at ~30% recurring instead. |
| Trial length and card? | **14 days, no card**, started in-plugin. |
| Supabase region? | **EU** — simplest GDPR position. |
| Data retention after account deletion? | **90 days.** |
| Skills cache TTL / grace? | **7-day TTL, 30-day grace.** |
| Pairing code lifetime? | **10 minutes, single-use.** |
| Model dropdown length? | **10, most recent first**, plus a free-text escape. |
| Model list cache? | **One week**, with a Refresh button. |
| Activity retention? | **200** tool calls, **200** snippet events, **memory uncapped**. |
| Pagination? | **10 rows** per section, independent page state. |
| Digest destinations? | **Per-channel checkboxes** (Email, Telegram, webhook), disabled until connected, with the preference stored separately from the connection state. |
| Who holds the signing key? | **You.** Generated by you, stored in CI, never in a transcript. |
| Free-core version at the split? | **2.0.0.** Add-on starts at **1.0.0**. |
| AI cost per build on the pricing page? | **MEASURE** — three real builds, then publish the range. Working estimate $3–$10. |
| Competitor prices? | **RE-VERIFY** before publishing the pricing page. |
