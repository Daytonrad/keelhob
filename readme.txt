=== Claudpress ===
Contributors: claudpress
Tags: mcp, ai, claude, woocommerce, seo, automation
Requires at least: 6.3
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.1.0
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
