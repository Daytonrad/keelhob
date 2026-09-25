# Features, tool by tool

**This is the developer view.** It names tools, options, endpoints and file paths, and it
assumes you are comfortable with WordPress internals.

If you are looking for *"what is this and how do I use it"*, you want the **user manual** at
[keelhob.com/docs](https://keelhob.com/docs) — the same document that ships in the zip as
[DOCUMENTATION.md](../DOCUMENTATION.md). It covers install, connecting an agent, every
capability group and troubleshooting, in plain words.

The two documents are split on purpose:

| Question | Read |
|---|---|
| What is Keelhob, how do I install it, how do I use it safely? | [DOCUMENTATION.md](../DOCUMENTATION.md) — the manual |
| Which tool does that, what does it accept, where does it store things? | this file |
| Why does this code look like this? | [architecture.md](architecture.md) |

When the two describe the same feature and disagree, the manual is the promise made to users
and this file is the detail — fix whichever one is wrong, do not let them drift apart.

[README.md](../README.md) has the pitch, the install, the safety position and the honest list
of what Keelhob cannot do yet.

## The tool surface

| Area | Tools |
|------|-------|
| **Power** | `execute_php`, `execute_sql`, `get_site_info`, `site_readiness` |
| **Filesystem** | `read_file`, `write_file`, `edit_file`, `delete_file`, `list_directory`, `write_sandbox_file` |
| **Content** | `create_post`, `update_post`, `get_post`, `list_posts`, `delete_post`, `upload_media`, `manage_taxonomy` |
| **Site admin** | `manage_option`, `manage_user`, `manage_plugin`, `manage_theme`, `manage_menu` |
| **WooCommerce** | `wc_product`, `wc_order`, `wc_coupon`, `wc_report`, `wc_settings` |
| **SEO** | `seo_meta`, `seo_analyze`, `seo_schema`, `seo_redirect`, `seo_sitemap` |
| **Code snippets** | `snippet_save`, `snippet_list`, `snippet_get`, `snippet_toggle`, `snippet_delete` |
| **Elementor** | `elementor_page`, `elementor_settings`, `elementor_kit` |
| **Ops** | `manage_updates`, `send_email`, `image_search` (Openverse, free), `image_generate` (API) |
| **Autonomy** | `get_dashboard`, `run_agent`, `agent_runs`, `task_save`, `task_list`, `task_run`, `task_delete`, `notify_owner` |
| **Checkout** | `wc_payments`, `wc_shipping`, `wc_tax` |
| **Deploy** | `export_bundle`, `import_bundle`, `deploy_push` |
| **Memory** | `memory_save`, `memory_get`, `memory_list`, `memory_delete` |
| **Skills** | `skill_list`, `skill_get`, `skill_save`, `skill_delete`, `skill_refresh` |

`execute_php` is the universal escape hatch — anything WordPress, WooCommerce or a plugin can do
in PHP, the agent can do, even where no dedicated tool exists.

Every tool belongs to a capability group the site owner can switch off, except a small read-only
set (`get_site_info`, `get_dashboard`, `memory_list`, `memory_get`, `skill_list`, `skill_get`,
`task_list`, `agent_runs`, `site_readiness`) that stays available so a restricted site can still
answer questions about itself. A test asserts that a newly registered tool cannot escape that
gating by accident.

## Code snippets engine (WPCode-style)

`snippet_save` lets the agent write **real code that runs on the live site** — PHP, CSS, JS or
HTML — with location control (everywhere, header, footer, before/after content, shortcode,
admin-only, or a full **custom page template** for pixel-perfect pages) and conditional loading.

PHP is parse-checked before a snippet can activate; code that does not parse is still stored, so
the work is not lost, but is forced inactive with the error attached. Any snippet that triggers a
fatal error at runtime is auto-deactivated by a shutdown handler, recorded in the snippet log and
announced, so the site never gets stuck. There is a master "Run code snippets" kill switch in
settings. See the `eval()` section of [README.md](../README.md) for the whole picture.

Every snippet carries a portable uid and each site a short `site_label`, so an agent can copy a
snippet from one connected site to another and a re-copy updates the existing one instead of
duplicating it.

## Website and Elementor building

Two build modes, straight from the field-tested Claude Code workflow: **(1) pixel-perfect
custom-coded pages** (a `page_template` snippet renders exact HTML/CSS), and **(2) editable
Gutenberg/Elementor pages**. The Elementor tools read and write the `_elementor_data` tree, create
pages, apply a global brand kit, and run `prepare_for_ai` (Container/flexbox ON, Atomic editor
OFF). Pick your builder in settings: Gutenberg, Elementor free + Xpro, Elementor Pro, Bricks, or
custom PHP.

## Images that actually show up

`image_search` pulls real, openly-licensed images from **Openverse** (free, no API key) and
attaches them; `image_generate` uses an OpenAI-compatible or Google key if you add one in settings.

## Default memory

On install, Keelhob seeds cross-session memory with the vital operating context — capabilities,
build modes, Elementor setup, image strategy, safety — so **any** connected agent (Claude, Codex,
Gemini) starts informed. Agents are told to call `memory_list` first.

## Autonomy — the site runs itself

Beyond the interactive MCP connection, Keelhob can operate unattended:

- **`get_dashboard`** — an on-demand snapshot (sales today/7d/30d, open orders, out-of-stock,
  pending comments, available updates), so *"how's the store doing?"* has an answer without
  logging in.
- **Autonomous agent runtime** — add an API key under **Keelhob → Automation** and `run_agent`
  runs a plain-language instruction through a real on-site agentic loop over the internal tools
  (*"fulfil paid orders, restock flagged items, publish due drafts"*). Anthropic, OpenAI or Google,
  each with a real adapter for its request format and tool-calling schema; OpenAI-compatible
  gateways work through the endpoint override.
- **Scheduled automations** (WP-Cron) — `task_save` creates **digest** tasks (emailed status
  snapshots, no key needed) or **autonomous** tasks on an hourly/daily/weekly/monthly schedule.
- **Notifications** — email, Slack/generic webhook, or Telegram (`notify_owner`).
- **Guardrails** — a policy layer caps refunds, requires per-task opt-in for destructive tools in
  unattended runs, and can hard-block tools. Enforced for interactive and autonomous calls alike.

### Running unattended, reliably

Nobody is watching a 3am cron run, so the loop defends itself:

- **Retries with backoff** — transient API failures (429, 408, 5xx, network errors) are retried
  with exponential backoff and jitter, honouring the server's `Retry-After`. Permanent failures
  (bad key, malformed request) fail fast instead of burning the window.
- **Budget caps** — per-run and per-day limits on spend, tokens and run count. A run that hits a
  cap stops cleanly between steps and reports what it finished, rather than dying mid-tool-call.
  Set them in **Keelhob → Automation**; `0` means unlimited. Cost is estimated from token usage
  at list prices (filter `keelhob_model_prices` to adjust) — your provider's invoice is the
  authoritative number.
- **Run history** — every autonomous run is recorded with its source, status, steps, tool calls,
  retries, tokens and estimated cost, shown on the Automation tab and queryable via `agent_runs`.
- **Digest cadence** — schedule a status digest with a cadence (hourly → monthly) and a preferred
  hour of day.

## Chat to your site on Telegram — no MCP client needed

Everything above assumes an MCP client on the other end. This is the path that does not: **message
a Telegram bot and the site's own agent does the work.** The plugin's API key is the brain, your
phone is the interface.

Setup, about two minutes:

1. Talk to [@BotFather](https://t.me/BotFather) → `/newbot` → copy the token.
2. Paste it into **Keelhob → Automation → Notifications → Telegram bot token**.
3. In the **Chat** card below it: tick *Accept messages from Telegram*, save, then hit **Connect
   bot to this site**.
4. Message the bot. Don't know your chat ID? Message it anyway — it replies with your ID so you
   can allowlist it.

Then talk to it: *"how did the store do this week?"*, *"publish the draft about winter tyres"*,
*"the footer on the checkout page is broken, fix it."* Same tools, same policy guardrails, same
budget caps as a scheduled run, and every Telegram run appears in the run history under the
`telegram` source.

**Commands** — answered by the plugin itself, so they cost nothing:

| | |
|---|---|
| `/clear` | Forget this conversation and start fresh |
| `/undo` | Put back the content changed last turn |
| `/status` | Store and site snapshot |
| `/usage` | Runs, tokens and estimated spend in the last 24h, against your caps |
| `/tasks` | Scheduled automations and when they last ran |
| `/model` | Which provider and model is answering |
| `/risky on\|off` | Temporarily unlock destructive/financial tools |
| `/whoami`, `/history`, `/help` | Chat ID and role, memory depth, the list above |

**Conversation memory.** It replays the last N turns (default 12, configurable 0–60) so follow-ups
like *"now do the same for the other three"* work. `/clear` wipes a conversation from your phone;
**Clear all conversations** in admin wipes every one; anything older than 30 days is pruned on the
cron tick.

**Voice notes.** Send audio and it transcribes, **shows you the transcript**, then acts — so a
mis-hearing is something you can see and correct, not something that quietly edits your site.
Transcription is a separate provider choice (OpenAI Whisper / `gpt-4o-transcribe`, or Gemini
audio) because Anthropic has no speech-to-text API; leave the key blank to reuse one you already
entered for the same vendor.

**Photos and screenshots.** The model actually sees the image — real vision input in each vendor's
own format — and the image is filed in the media library with its attachment ID handed to the
agent, so *"use this as the product image"* works with no re-upload.

**Progress in place.** One message is edited as the run works (`• list_posts`, `• update_post`)
and then becomes the answer, instead of forty seconds of silence. Throttled, so Telegram's edit
rate limit is never what slows a run down.

**One-tap approvals.** When your guardrails block something, the reply carries **Approve & run** /
**Leave it** buttons — no need to learn `/risky`. Approving re-runs the instruction once with the
guardrail lowered. It is a fresh run rather than a resumption, so the model acts on current state
instead of on what was true a minute ago. Tokens are single-use, bound to the chat they were
offered to, and expire in an hour; the instruction is stored server-side, because callback data
arrives from the client and must never be something we execute.

**`/undo`.** Puts back the content changed in the last turn: edits reverted to how they were
*before* the turn, created posts trashed, trashed posts restored. Recording hooks WordPress core
rather than the tool layer, so a change made through `execute_php` is captured just like one
through `update_post`. Its scope is content — posts, pages, products — and it says so: **not**
settings, plugins, files or live snippets. It also reports anything it could not put back rather
than claiming a clean sweep.

**Roles.** Allowlist entries can carry one:

| Written as | That chat can |
|---|---|
| `123456789` | **admin** (the default) — everything, may Approve and use `/risky` |
| `123456789:editor` | change the site, but never escalate to destructive/financial tools |
| `123456789:viewer` | **read-only** tools only — ask questions, read reports, touch nothing |

A bare ID stays admin, so an allowlist written before roles existed is unaffected. `viewer` is
derived from each tool's own `readOnlyHint`, minus `read_file`, `list_directory` and
`export_bundle` — *"read-only"* and *"can read your database password"* are not the same claim.

**Why this is safe enough to put on a public URL.** Only allowlisted chat IDs are answered, and an
unconfigured allowlist refuses *everyone* rather than defaulting open. Every delivery must carry a
generated secret, compared in constant time — rotate it from admin at any time. Destructive and
financial tools stay locked for chat unless the owner arms escalation in WP, and even then it is
either a self-expiring `/risky` window or a per-action Approve press. Deliveries are deduplicated
by `update_id` and acked before the run starts, so Telegram's retries cannot run the same
instruction twice.

Telegram only delivers to a publicly reachable HTTPS URL with a valid certificate — a localhost or
self-signed staging site cannot receive messages.

## Let the site tell you first

Everything else here is pull — you ask, the site answers. This is push. Switch it on in
**Automation → Let the site tell you first** and Keelhob messages you, on whichever channels you
configured, when:

- **a paid order lands** — with a value floor, so a busy shop does not ping you 400 times a day
- **stock runs low or sells out**
- **a live code snippet crashed and auto-disabled itself** — the site stayed up *because* it
  switched off, but whatever it was doing has stopped, and only you can judge whether that matters
- **new plugin/theme/core updates appear**

Every event is individually switchable, and **nothing is announced twice**: orders are keyed per
status transition, and the update alert only fires when the pending set actually changes — so an
update you are deliberately holding back does not nag you every hour forever.

## Staging → production migration

`export_bundle` / `import_bundle` / `deploy_push` carry more than posts and pages:

- **Products**, matched by SKU. Simple products carry their prices, stock, categories and gallery.
  A **variable** product's own page still migrates — content, images, SEO meta, Elementor data —
  but its attributes and variations do not: they live in child posts and `pa_*` term IDs that mean
  nothing on the destination, so the import reports them as skipped and you rebuild them there with
  the WooCommerce tools (`wc_product`, or `execute_php` with `WC_Product_Variation`). See
  [plans/variable-product-migration.md](plans/variable-product-migration.md).
- **Nav menus** with items and theme-location assignments. Items are relinked to the destination's
  own pages by slug, falling back to a custom link when the target does not exist there.
- **Widgets and sidebars**, skipping sidebars the destination theme does not register (writing them
  would orphan the widgets).

Secrets are never exported — the settings option holding API keys and tokens is excluded by design,
and a test asserts the option whitelist stays clean.

## Live carrier shipping rates

`wc_shipping` can set up and *verify* real carriers, not just flat rates:

- `list_available_methods` — every shipping method type installed, so the agent can see which
  carrier plugins (DHL, UPS, FedEx, Aramex…) are available.
- `configure_method` / `get_method` — set a method instance's own settings, which is how carrier
  API credentials and account numbers get entered. Credentials are redacted when read back.
- `quote` — runs WooCommerce's **real** shipping engine for a destination and cart, returning the
  rates every applicable method produces. Carrier methods hit their API exactly as they would at
  checkout, so a rate coming back proves the carrier is genuinely configured and quoting.

## Skills

Skills are Markdown playbooks stored in WordPress that the agent loads automatically when a task
matches their description. Fifteen ship with the plugin, covering the whole journey: kicking off a
build, web design, page building, SEO and GEO, blog publishing, safety, security hardening, the
definition of done, a pre-launch audit, launching and running a WooCommerce store, and a
`skill-creator` for writing your own.

Bundled skills update when the plugin does, and skills you have edited are never overwritten;
`skill_refresh` reinstalls them on demand and names anything it kept. Optional skill updates
between releases come from this project's public GitHub releases, verified against an Ed25519
signature before anything is written — an unsigned or unverifiable skill is discarded rather than
installed.

Writing one is the highest-leverage contribution to this project and needs no PHP. See
[CONTRIBUTING.md](../CONTRIBUTING.md).
