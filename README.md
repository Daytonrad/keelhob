# Claudpress

**A self-contained MCP server for WordPress.** Upload one plugin, flip a switch, and connect Claude Code (or Claude Desktop, Cursor, Codex, any MCP client) to run your whole site — content, WooCommerce store, SEO, files, and PHP — with cross-session memory and a skills engine.

Inspired by [Novamira](https://novamira.ai), rebuilt from scratch with **no external dependencies**, **no WordPress 6.9 requirement**, and **every "pro" feature unlocked**: bundled expert skills, persistent memory, WooCommerce, and SEO all included and free.

> ⚠️ **Development / staging only.** Claudpress gives a connected AI agent unrestricted control of this WordPress install (arbitrary PHP, database, filesystem). Only enable AI abilities on sites you can afford to break, with backups. Deactivating the plugin turns abilities off.

---

## Install

1. Download `claudpress.zip`.
2. WordPress admin → **Plugins → Add New → Upload Plugin** → choose the zip → **Install Now** → **Activate**.
3. Go to **Claudpress** in the admin menu.

Requires WordPress 6.3+ and PHP 8.0+. WooCommerce and any SEO plugin (Yoast, Rank Math, AIOSEO, SEOPress) are auto-detected if present — none are required.

## Connect Claude Code

On the **Claudpress → Connect** page you'll find a ready-to-paste command with your real URL and token filled in. It looks like:

```bash
claude mcp add claudpress-yoursite --transport http https://yoursite.com/wp-json/claudpress/v1/mcp --header "Authorization: Bearer cp_xxxxxxxx"
```

Run it in your terminal, start a new Claude Code session, and you'll see the Claudpress tools. Try: *"list the plugins on this site"* or *"show me the last 10 WooCommerce orders."*

### Other clients
The Connect page also outputs a JSON `mcpServers` block for Claude Desktop, Cursor, Codex, etc. You can authenticate with either the **bearer token** (simplest) or a WordPress **Application Password** (`Authorization: Basic base64(user:app_password)`) against the same endpoint.

## The safety switch

- **AI abilities** is a master toggle. When **off**, the endpoint authenticates but only exposes read-only introspection (`get_site_info`, `memory_list`, `skill_list`). When **on**, the full tool surface is live.
- Sandbox PHP files that fatal are auto-quarantined so the site never boot-loops.
- Every tool call is logged (Activity tab).

## What it can do

| Area | Tools |
|------|-------|
| **Power** | `execute_php`, `execute_sql`, `get_site_info` |
| **Filesystem** | `read_file`, `write_file`, `edit_file`, `delete_file`, `list_directory`, `write_sandbox_file` |
| **Content** | `create_post`, `update_post`, `get_post`, `list_posts`, `delete_post`, `upload_media`, `manage_taxonomy` |
| **Site admin** | `manage_option`, `manage_user`, `manage_plugin`, `manage_theme`, `manage_menu` |
| **WooCommerce** | `wc_product`, `wc_order`, `wc_coupon`, `wc_report`, `wc_settings` |
| **SEO** | `seo_meta`, `seo_analyze`, `seo_schema`, `seo_redirect`, `seo_sitemap` |
| **Code snippets** | `snippet_save`, `snippet_list`, `snippet_get`, `snippet_toggle`, `snippet_delete` |
| **Elementor** | `elementor_page`, `elementor_settings`, `elementor_kit` |
| **Ops** | `manage_updates`, `send_email`, `image_search` (Openverse, free), `image_generate` (API) |
| **Autonomy** | `get_dashboard`, `run_agent`, `task_save`, `task_list`, `task_run`, `task_delete`, `notify_owner` |
| **Checkout** | `wc_payments`, `wc_shipping`, `wc_tax` |
| **Deploy** | `export_bundle`, `import_bundle`, `deploy_push` |
| **Memory** | `memory_save`, `memory_get`, `memory_list`, `memory_delete` |
| **Skills** | `skill_list`, `skill_get`, `skill_save`, `skill_delete` |

`execute_php` is the universal escape hatch — anything WordPress, WooCommerce, or a plugin can do in PHP, the agent can do, even where no dedicated tool exists.

### Code snippets engine (WPCode-style)
`snippet_save` lets the agent write **real code that runs on the live site** — PHP, CSS, JS, or HTML — with location control (everywhere, header, footer, before/after content, shortcode, admin-only, or a full **custom page template** for pixel-perfect pages) and conditional loading. Any PHP snippet that triggers a fatal error is **auto-deactivated** by a shutdown handler, so the site never gets stuck. There's a master "Run code snippets" kill-switch in settings.

### Website & Elementor building
Two build modes, straight from the field-tested Claude-Code workflow: **(1) pixel-perfect custom-coded pages** (a `page_template` snippet renders exact HTML/CSS), and **(2) editable Gutenberg/Elementor pages**. The Elementor tools read/write the `_elementor_data` tree, create pages, apply a global brand kit, and run `prepare_for_ai` (Container/flexbox ON, Atomic editor OFF). Pick your builder in settings (Gutenberg / Elementor free+Xpro / Elementor Pro / Bricks / custom PHP).

### Images that actually show up
`image_search` pulls real, openly-licensed images from **Openverse (free, no API key)** and attaches them; `image_generate` uses an OpenAI-compatible key if you add one in settings.

### Default memory
On install, Claudpress seeds cross-session memory with the vital operating context (capabilities, build modes, Elementor setup, image strategy, safety) so **any** connected agent — Claude, Codex, Gemini — starts informed. Agents are told to call `memory_list` first.

### Autonomy (v1.2) — the site runs itself
Beyond the interactive MCP connection, Claudpress can operate unattended:
- **`get_dashboard`** — an on-demand status snapshot (sales today/7d/30d, open orders, out-of-stock, pending comments, available updates) so you can ask *"how's the store doing?"* and get an answer without logging in.
- **Autonomous agent runtime** — add an Anthropic API key in **Claudpress → Automation**, and `run_agent` / autonomous tasks run a plain-language instruction through a real on-site agentic loop over the internal tools (e.g. *"fulfil paid orders, restock flagged items, publish due drafts"*).
- **Scheduled automations** (WP-Cron) — `task_save` creates **digest** tasks (emailed status snapshots, no key needed) or **autonomous** tasks on an hourly/daily/weekly schedule.
- **Notifications** — email, Slack/generic webhook, or Telegram (`notify_owner`).
- **Guardrails** — a policy layer caps refunds, requires per-task opt-in for destructive tools in unattended runs, and can hard-block tools. Enforced for both interactive and autonomous calls.

## Skills & memory (the "pro" features, free)

- **Skills** are Markdown playbooks stored in WordPress that the agent loads automatically when a task matches their description. Claudpress ships with expert skills for WooCommerce operations, SEO, blog publishing, safety, and a `skill-creator`. Write your own from the agent ("create a skill that…") or edit them in the DB.
- **Memory** is per-site key/value knowledge the agent persists across sessions ("remember: primary color is #f8ca50"). Shared by every connected agent.

## Architecture

```
claudpress.php              bootstrap, constants, activation
includes/
  class-claudpress.php      orchestrator + settings
  class-auth.php            bearer token + application password auth
  class-mcp-server.php      JSON-RPC 2.0 MCP protocol (initialize/tools/prompts/resources)
  class-rest.php            /wp-json/claudpress/v1/mcp transport
  class-tool-registry.php   tool registration + dispatch
  class-memory.php          cross-session memory store
  class-skills.php          skills store + markdown parser + bundled seeding
  class-sandbox.php         persistent AI PHP with crash recovery
  class-logger.php          tool-call audit log
  class-admin.php           settings/connect/activity UI
  tools/                    core, filesystem, content, site, woocommerce, seo, memory, skills
skills/                     bundled expert skill playbooks (markdown)
```

The MCP transport answers a single `application/json` JSON-RPC response per request (the simplest valid Streamable-HTTP mode), which every MCP client accepts. No Node bridge required for HTTP-capable clients.

## License

GPL-2.0-or-later. Built with Claude Code.
