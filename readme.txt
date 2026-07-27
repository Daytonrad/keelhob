=== Claudpress ===
Contributors: claudpress
Tags: mcp, ai, claude, woocommerce, seo, automation
Requires at least: 6.3
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.3.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-contained MCP server that lets an AI agent (Claude Code, Claude Desktop, Cursor, Codex) fully run your WordPress and WooCommerce site.

== Description ==

Claudpress turns your WordPress site into an MCP (Model Context Protocol) server. Connect any MCP-compatible AI client and it can manage content, run your WooCommerce store, handle SEO, edit files, and execute PHP — with cross-session memory and a skills engine. No external dependencies, no Node bridge for HTTP clients, every feature unlocked.

WARNING: This gives connected AI agents unrestricted control of the site. Use development or staging sites only, with backups. Deactivating the plugin turns AI abilities off.

Features:

* MCP Streamable-HTTP endpoint at /wp-json/claudpress/v1/mcp
* Bearer token or Application Password authentication
* execute_php / execute_sql universal power tools
* Content: posts, pages, any CPT, media, taxonomies, menus
* WooCommerce: products, orders, coupons, reports, settings
* SEO: meta (Yoast/Rank Math/AIOSEO/SEOPress compatible), analysis, schema, redirects, sitemap
* Filesystem tools confined to a configurable base, plus a crash-recovering sandbox
* Cross-session memory shared across agents
* Skills engine with bundled expert playbooks (WooCommerce, SEO, blogging)
* Master safety switch + full activity log

== Installation ==

1. Upload the plugin zip via Plugins > Add New > Upload Plugin, then Activate.
2. Open the Claudpress admin page.
3. Enable AI abilities (development/staging only).
4. Copy the "Connect Claude Code" command and run it in your terminal.

== Changelog ==

= 1.3.2 — Newbie guidance =
* New "Guide" admin page: an auto-generated, plain-English master guide — what the plugin is, 3-step start, the two safety controls, a recommended newbie on/off config, every capability with its tools and descriptions, autonomy, deploy, skills/memory, starter prompts, and an FAQ.
* Banner on the plugin page linking to the guide.
* Hover info (ℹ️) on every capability toggle explaining what it does when ON, which tools it covers, and the risk if it's sensitive.

= 1.3.1 — Capability toggles =
* New Capabilities panel on the dashboard: switch any area (Payments/money, Store & orders, Shipping, Tax, Code execution, Filesystem, Users, Site admin, Updates, Comms, Autonomy, Deploy, Content, SEO, Design, Images, Memory, Skills) on or off.
* Disabled capabilities are hidden from the agent's tool list, refused if called, and named as off-limits in the session instructions — enforced for both interactive and autonomous runs. Read-only status tools stay available.

= 1.3.0 — Commerce & Deploy =
* Checkout setup tools: wc_payments (enable/configure gateways — Stripe, PayPal, COD, BACS, cheque; test mode), wc_shipping (zones, flat/free/local-pickup methods, shipping classes), wc_tax (enable + rates).
* Staging→production deploy: export_bundle / import_bundle (portable, idempotent JSON of pages, posts, products, snippets, skills, memory, options incl. Elementor & SEO meta; secrets never exported), and deploy_push to send straight to a configured production site.
* New authenticated /wp-json/claudpress/v1/import endpoint so a staging site can push a bundle to production in one call.
* New bundled skill: store-launch-and-deploy. Deploy-target settings on the Connect page.

= 1.2.0 — Autonomy =
* Autonomous agent runtime: runs a plain-language instruction through an on-site AI agentic loop (Anthropic Messages API) over the internal tools, with policy guardrails. Tools: run_agent, task_save (autonomous mode).
* Scheduled automations via WP-Cron: digest tasks (email status snapshots, no API key) and autonomous tasks. Tools: task_save/list/run/delete.
* get_dashboard: on-demand status snapshot (sales today/7d/30d, open orders, out-of-stock, updates, pending comments) — answer "what's going on" without logging in.
* Multi-channel notifications: email, webhook/Slack, Telegram. Tool: notify_owner.
* Policy/guardrails: financial caps (refunds), destructive-tool opt-in for unattended runs, always-block list; enforced for both interactive and autonomous calls.
* New admin "Automation" tab: engine status, API key + notification + policy settings, live dashboard, task management.

= 1.1.1 =
* New bundled "web-design-master" skill: a full modern-responsive web-design reference (design tokens, mobile-first layout, component patterns, WordPress theme-safe CSS rules, anti-slop quality audit) synthesized from leading design skills + WPWriter-style patterns.
* Memory keys now keep dots/colons (e.g. brand.accent) instead of stripping them.

= 1.1.0 =
* Code Snippets engine (PHP/CSS/JS/HTML) with locations, conditions, custom page templates, and crash auto-deactivation.
* Elementor tools: read/write page data, create pages, global kit, prepare_for_ai (Container on, Atomic off).
* Ops tools: manage_updates (core/plugins/themes), send_email, image_search (Openverse, free), image_generate (API).
* Builder toggle setting, optional image API key, and seeded default memory so any agent starts informed.
* Auto-upgrade routine: updating the plugin creates new tables without reactivation.

= 1.0.0 =
* Initial release.
