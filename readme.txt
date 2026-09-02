=== Claudpress ===
Contributors: calflint
Tags: mcp, ai, claude, woocommerce, seo, automation
Requires at least: 6.3
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 2.0.0
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
* Bring your own key: Anthropic, OpenAI or Google for the agent brain and image generation
* Telegram chat: message a bot and the on-site agent runs your site — no MCP client needed
* Voice notes, photos, one-tap approvals, /undo and per-chat roles for that chat
* Event alerts: paid orders, low stock, a crashed snippet, available updates

== External services ==

This plugin can send data to third-party APIs. Nothing is sent until you enter a
key for that service, and no service is contacted at all if you leave its key blank.
Claudpress itself has no servers and phones nothing home.

**Your MCP client (Claude Code, Claude Desktop, Cursor, Codex, or any other).**
Whatever client you connect makes the requests; whatever it reads from your site
goes to that client's model provider under that provider's terms. This is the
whole point of the plugin, and it is the largest single disclosure here: an agent
you connect can read your posts, orders, customers and files.

**Autonomous runs (optional — Claudpress > Automation).** When you add an agent
API key and schedule an autonomous task, Claudpress sends the task instruction,
your site name and URL, the stored site memory, the tool list, and each tool's
results to the provider you selected, in order to decide the next step:

* Anthropic — https://api.anthropic.com — terms: https://www.anthropic.com/legal/commercial-terms — privacy: https://www.anthropic.com/legal/privacy
* OpenAI — https://api.openai.com — terms: https://openai.com/policies/terms-of-use — privacy: https://openai.com/policies/privacy-policy
* Google (Gemini) — https://generativelanguage.googleapis.com — terms: https://ai.google.dev/gemini-api/terms — privacy: https://policies.google.com/privacy

An endpoint override sends the same data to whichever gateway you point it at instead.

**Image generation (optional).** When you add an image API key, the prompt text
you or the agent writes is sent to OpenAI or Google (same endpoints and terms as
above) and the returned image is stored in your media library.

**Image search (optional, no key).** image_search queries Openverse
(https://api.openverse.org) for openly licensed stock images. Only your search
term is sent. Terms: https://wordpress.org/openverse/terms

**Notifications (optional).** If you configure them, digests and alerts are sent
to your own email, your own webhook URL, and/or the Telegram Bot API
(https://api.telegram.org — https://telegram.org/privacy) using your own token.

**Telegram chat (optional, off by default).** If you switch it on and connect the
bot, Telegram delivers the messages you send it to this site, and the replies —
including whatever the agent reports about your site or store — go back through
the Telegram Bot API (https://api.telegram.org — https://telegram.org/privacy).
Your messages are also stored in this site's own database as conversation history
until you clear them, and each one is sent to your chosen AI provider as the
instruction for that run. Only chat IDs you allowlist are answered.

If you enable photos, an image you send is downloaded from Telegram, stored in
this site's media library, and sent to your chosen AI provider as part of the
message so the model can see it.

**Voice transcription (optional, off by default).** If you enable voice notes, the
audio you send the bot is downloaded from Telegram and sent to the transcription
provider you select — OpenAI (https://api.openai.com) or Google
(https://generativelanguage.googleapis.com), same terms and privacy links as
above — using your own key. Only the audio is sent; the transcript comes back and
is shown to you before anything acts on it.

**Deploy (optional).** export_bundle/deploy_push send your content bundle only to
the destination Claudpress site whose URL and token you configure.

**Skill updates (optional).** Claudpress can check GitHub
(https://github.com/calflint/claudpress/releases - terms:
https://docs.github.com/site-policy/github-terms/github-terms-of-service -
privacy: https://docs.github.com/site-policy/privacy-policies/github-privacy-statement)
for newer versions of the bundled skills. It is a plain download of two public
files; no key, no account, and nothing about your site is sent. Every skill is
verified against a signature before it is written, and an unsigned or unverifiable
skill is discarded rather than installed. Turn it off and the skills that shipped
in the zip keep working.

== Installation ==

1. Upload the plugin zip via Plugins > Add New > Upload Plugin, then Activate.
2. Open the Claudpress admin page.
3. Enable AI abilities (development/staging only).
4. Copy the "Connect Claude Code" command and run it in your terminal.

Autonomous runs are optional and off until you add a provider API key under
Claudpress > Automation. Pick your provider there — the model guide next to the
field lists the exact model IDs to type for each one.

== Changelog ==

= 2.0.0 - free forever, one plugin =
* Claudpress is now free, in full, permanently. There is no paid add-on, no licence, no tier and nothing held back. Every feature that was going to be sold - payments, shipping, tax, orders and reports, user management, updates, email and notifications, deploy to production, the Snippets Studio, and all fifteen expert skills - is in this one plugin. The project is funded by donations from people who find it useful.
* PLEASE READ IF YOU ARE UPGRADING: eight capability groups are switched OFF by this update, and you turn back on the ones you want. Payments, Shipping, Tax, Store orders & reports, Users, Updates, Email & notifications, and Deploy. Until now the paywall was the only thing standing between a connected AI agent and those tools. Removing the paywall without replacing that guard would have silently handed every site the ability to email its customers, edit its users, change its payment gateways and push to production. An admin notice will point you at the setting.
* Everything the ten-minute build needs stays on: content, design, snippets, SEO, files, images, memory, skills and code execution.
* Skill updates now come from this project's public GitHub releases instead of a server we ran. No account, no key, and nothing about your site is sent. Every skill is still verified against a signature before it is written, and an unsigned one is discarded rather than installed.
* The private snippet library and site pairing are gone. They only ever worked by talking to that server. Snippets stored on your own site are untouched, and still listed in the Studio.
* Nothing you have made is affected by any of this. Snippets, skills, memory, tasks and settings all carry over.

= 1.10.1 - security and a data-loss fix =
* IMPORTANT: deleting the plugin no longer deletes your data. Until now, uninstalling dropped every snippet, memory, skill and scheduled task, permanently and without warning - which is a disaster if you were only removing the plugin to test for a conflict. Uninstall now keeps everything by default, so reinstalling puts you back where you were. There is a new setting if you genuinely do want everything erased.
* Fixed a critical file-writing flaw. The write_file tool could be pointed outside its allowed directory using "..", which meant it could write a PHP file anywhere on the server the site can write to. Reading, editing and deleting files were not affected. If you have ever exposed your Claudpress endpoint to anyone you do not trust, update now.
* API keys are now encrypted in the database instead of stored as plain text, and are no longer printed into the settings page source. The page shows the last four characters so you can still tell which key is installed. Your existing keys are re-encrypted the first time you save.
* Secrets no longer appear in the Activity log. Payment gateway keys passed to the store tools were being written to the log table in clear text and shown on screen.
* Failed logins are now rate limited, and the connection endpoint no longer accepts ordinary account passwords - only Application Passwords. Together those closed a way to guess passwords that security plugins and two-factor plugins could not see, because they only watch the normal login page.
* The connection token is now stored as a hash and shown once, when you create it. Your current token keeps working; it is converted automatically the first time it is used. If you lose it, regenerate it.
* The public status endpoint no longer reveals the exact plugin version to unauthenticated callers.
* Fixed a way to bypass the "require HTTPS" setting by sending a forged Host header.

= 1.10.0 — "Done" now means done =
* The definition of done is no longer optional. Three rules were added to the operating protocol that every connected agent receives — run pre-launch-audit against your own work before reporting anything done, never invent a statistic/review/testimonial/customer/staff member the site cannot substantiate, and load the build-standard skill before building. The protocol is PHP, injected into every interactive session and every unattended run, so a site cannot switch it off.
* New skill build-standard: the Definition of Done itself — page-level and site-level checklists with real numbers (no image over 300 KB, hero under 200 KB, body contrast 4.5:1), a router to the right skill for each part of a job, the fix loop with a 3-iteration cap and anti-gaming rules, and the mandatory three-section completion report (score and fixes / needs you / not verified).
* New skill security-standard: a ranked WordPress threat model, twenty automatic hardening checks (A1–A20) each with how to detect it from Claudpress and what the fix costs, a seven-question owner intake with a stated default for every answer, a WooCommerce card-skimming addendum, and a compromise-detection sweep to run before building on a site you did not build.
* New tool site_readiness: the same standard as an exit code instead of a judgement call — read-only, checks heading order, alt text, meta/OG, JSON-LD validity, dead href="#" links, image weight, security headers, sitemap/robots/llms.txt, snippet errors and legal-page wiring, plus tax rates, stock policy and registration defaults when WooCommerce is active. Registered always-on, so it still runs when the owner has restricted other capabilities. Returns pass and complete as separate results — pass means nothing that ran actually failed, complete means nothing was skipped — because a skipped check (WooCommerce off, no HTTPS, a disabled capability) is reported, never counted as a pass.
* Seven skills became gates instead of advice: site-build-kickoff loads the standard at step 0 and closes with the fix loop; seo-optimization is a required build phase verified in the rendered HTML, not from a tool's return value; web-design-master's quality audit is now blocking numbers; website-building runs the page checklist before handing any page over; store-launch-and-deploy blocks on tax rates, stock policy, review policy, legal wiring and one real test order; site-safety adds snapshot-before-fix and the rules for adopting a site somebody else built; pre-launch-audit gained the checks two real audits found ad hoc, the full security section, and a machine-readable JSON findings block so the fix loop can consume it.
* UPGRADING: the two new skills install themselves on update. The seven edited skills only refresh if you have not edited them yourself — run skill_refresh to pull them in, and skill_refresh force=true if you have local edits you are willing to lose.
* Tests: 836 assertions.

= 1.9.1 — SEO head injection fixes =
* Fixed: og:description was never emitted. The head injector looked up `_claudpress_seo_og_description` while seo_meta stores `_claudpress_seo_og_desc`, so the tag silently never rendered no matter what you set.
* Fixed: /llms.txt was served with a 404 status. The bytes were right but WordPress had already set up a 404 for the unrouted path, so AI crawlers — the only readers this file has — discarded it.
* Fixed: the SEO title never reached <title>. seo_meta stored `_claudpress_seo_title` and nothing ever read it back. A `pre_get_document_title` filter now applies it.
* Fixed: pages that are not `is_singular()` got no meta at all. The shop archive, the blog index and a static front page now resolve to the post that carries their SEO meta, so /shop/ no longer ships with no description and no Open Graph.
* Added: og:url, og:type, og:site_name and twitter:card. Without them a shared link renders as a bare card on most platforms.
* All of the above stand down when Yoast/RankMath/AIOSEO/SEOPress is active, as before.
* Tests: 672 assertions.

= 1.9.0 — Snippets control page =
* New Claudpress > Snippets admin page: every snippet the AI has installed, listed with Name | Description | Tags | Type | Status | ID, per-row activate/deactivate, and an expandable panel explaining why the snippet exists, what it changes, where and when it runs, where it came from, and any error that switched it off. Code can be edited and snippets deleted from the browser.
* snippet_save now MERGES on update. Previously it rebuilt the whole row, so saving one field (for example adding tags) silently wiped the snippet's code.
* snippet_save gained tags, purpose, impact, uid, origin_site and origin_uid; snippet_list gained search/tag/snippet_type/active filters and returns the new fields plus the site label.
* PHP snippets are parse-checked before they are stored. Code that does not parse is still saved — you do not lose your work — but is forced inactive with the error attached, instead of fataling the site and relying on the crash guard.
* Cross-site copying: every snippet now has a portable uid, and each site has a short site_label (settable on the Connect page, exposed by get_site_info). Ask your agent to copy a snippet from one connected site to another; re-copying updates the existing copy instead of duplicating it.
* Bundle export/import is now idempotent for snippets — importing the same bundle twice no longer duplicates every snippet. Bundle version 2 → 3; version 2 bundles still import.
* New bundled skill pre-launch-audit: a read-only, scored audit of a site before it goes live — design, responsiveness, performance, functionality, accessibility, security, SEO and conversion — with weighted category scores, severity levels, and a fixed report format. It inspects and reports; it never fixes.
* Bundled skills now update when the plugin does. Previously a shipped playbook was only ever written once, so improvements never reached a site that already had it. Skills you edited here are still never overwritten. New tool skill_refresh reinstalls them on demand and names anything it kept.
* Expanded playbooks: website-building gained a cinematic scroll-scrub hero mode (the video-scrubs-with-the-scroll look, built as a page_template snippet, including the verified fact that upload_media cannot take a video and what to use instead); web-design-master gained a design package to approve before any code plus concrete anti-slop checks; site-build-kickoff gained asset-first intake and a batched revision loop; woocommerce-store-operations gained the four product types, global attributes and coupon ceilings; store-launch-and-deploy gained tax inclusive/exclusive, four-decimal rates, priority fallbacks (with the execute_php workaround for wc_tax's hard-coded priority), per-item shipping, zone layering, order emails, site visibility and legal pages.
* Tests: 648 assertions.

= 1.8.0 — SEO/GEO power-up, site-build kickoff =
* seo_analyze now returns a second score, geo_score, alongside the classical on-page score: answer-first opening paragraph, FAQ-style Q&A headings, list/table structuring, JSON-LD presence, author bio (E-E-A-T), content freshness, and outbound citations — the signals AI answer engines (ChatGPT, Perplexity, Google AI Overviews, Claude) weight when deciding what to cite.
* seo_schema can now generate valid JSON-LD for you (generate=article|faq|howto|local_business|person + data=…) instead of requiring hand-written schema, and accepts an array of schema objects layered into one @graph so a page can carry Article + FAQPage + BreadcrumbList together. Article generation pulls the post author into a Person sub-schema automatically.
* seo_sitemap gained llms.txt management (get_llms_txt / set_llms_txt, served at /llms.txt) and per-AI-crawler access control (get_ai_crawlers / set_ai_crawlers for GPTBot, ClaudeBot, Google-Extended, PerplexityBot, CCBot, Bytespider, Applebot-Extended). Sitemap ping now targets Bing only (Google retired sitemap ping in 2023).
* seo-optimization skill rewritten as a combined SEO + GEO workflow with concrete GEO fix steps and a "never disallow an AI crawler without being asked" guardrail.
* New skill site-build-kickoff: a lighter intake flow for "build me a website" requests — a stack menu and one intake batch instead of a long upfront brief.
* New skill solace-header-footer: guidance distilled from the Solace Site Builder/Customizer reference (header systems, doubled-prefix and global-background traps, execute_php vs elementor_page).
* Tests: 583 assertions (unchanged from 1.7.1 — this release adds tool surface, not test count).

= 1.7.1 — Filesystem-tool safety hotfix =
* write_file, edit_file and write_sandbox_file now refuse to write broken PHP to disk instead of saving it. A missing/mangled opening `<?php` tag, an unbalanced brace, a stray token or an unterminated string are all caught and rejected before they touch a file — with the specific reason why. Previously nothing checked a .php write for a live plugin file (only the sandbox had crash recovery), so a bad write there fatal-errored the whole site on the next request with no way back except direct server access.
* Tests: 583 assertions, up from 573.

= 1.7.0 — Voice, photos, approvals, undo and roles =
* Voice notes: send the bot a voice message and it transcribes it, shows you the transcript, then acts on it. Transcription is its own provider choice (OpenAI Whisper / gpt-4o-transcribe, or Gemini audio) because Anthropic has no speech-to-text API; a blank key reuses any key you already entered for the same vendor.
* Photos and screenshots: send an image (with a caption if you like) and the model actually sees it — proper vision input in each vendor's own format. The image is also filed in the media library and its attachment ID handed to the agent, so "use this as the product image" works without a re-upload.
* One-tap approvals: when your guardrails block something, the reply carries Approve & run / Leave it buttons instead of making you learn /risky. Approving re-runs the instruction once with the guardrail lowered — a fresh run, so the model acts on current state rather than on what was true a minute ago. Tokens are single-use, chat-bound, expire in an hour, and the instruction is stored server-side because callback data comes back from the client.
* Progress in place: one message is edited as the run works ("• list_posts", "• update_post") and then becomes the answer, instead of 40 seconds of silence. Throttled so Telegram's edit rate limit is never the thing slowing a run down.
* /undo: puts back the content changed in the last turn — posts, pages and products, whether the agent changed them through update_post or through execute_php, because recording hooks WordPress core rather than the tool layer. Edits are reverted to how they were *before* the turn, created posts are trashed, trashed posts are restored. It says plainly what it could not undo, and that its scope is content only — not settings, plugins, files or live snippets.
* Chat roles: allowlist entries can carry a role — 123456789:viewer or :editor. A viewer gets read-only tools only (and not read_file, list_directory or export_bundle: "read-only" and "can read your database password" are not the same claim). An editor can change the site but can never escalate to destructive or financial tools. A bare chat ID stays admin, so an existing allowlist is unaffected.
* Event notifications — the site tells you first: a paid order (with a value floor), low stock or a sell-out, a live code snippet that crashed and auto-disabled itself, and new available updates. Every event is individually switchable, and nothing is announced twice — orders are keyed per status change, and the update alert only fires when the pending set actually changes, so holding an update back deliberately does not nag you forever.
* Tests: 573 assertions, up from 468.

= 1.6.0 — Chat to your site on Telegram =
* Telegram chat front-end: message a Telegram bot and the on-site agent does the work — no MCP client, no terminal, no laptop. The plugin's own API key is the brain, your phone is the interface. Connect it in Claudpress > Automation > Chat with one button.
* Multi-turn conversation memory, so follow-ups like "now do the same for the other three" work. Configurable depth (0–60 turns), and /clear wipes a conversation from Telegram. "Clear all conversations" in WP admin wipes every one; transcripts older than 30 days are pruned automatically.
* Built-in commands answered by the plugin without spending a cent on the API: /status (store snapshot), /usage (runs, tokens and estimated spend against your caps), /tasks (scheduled automations), /model, /history, /whoami, /help. They register into Telegram's own command menu on connect.
* Access control: only allowlisted chat IDs are answered (an unconfigured allowlist refuses everyone, including you), every delivery is verified against a generated secret compared in constant time, and the secret can be rotated from admin. An unrecognised chat is told nothing but its own chat ID, which is what the owner needs to allowlist themselves.
* Destructive and financial tools stay locked for chat by default. The owner arms them in admin, the chat opts in with /risky on, and the opt-in lapses by itself after 30 minutes.
* A live chat gets its own operating prompt — it may ask one clarifying question, and it writes for a phone screen — rather than the "never ask, you are unattended" rules a 3am cron run needs. Everything else (protocol, policy, budget caps, run history) is identical, and Telegram runs appear in the run log under the "telegram" source.
* Replies longer than Telegram's 4,096-character limit are split on paragraph boundaries instead of being truncated, and each reply carries a footer with tools used and estimated cost.
* Delivery is deduplicated by update_id and acked before the agent runs (via fastcgi_finish_request where available), so Telegram's retries cannot run the same instruction twice; a per-chat lock stops two runs overlapping.
* Tests: 468 assertions, up from 370.

= 1.5.0 — Bring your own model, and guardrails =
* Multi-provider agent brain: choose Anthropic, OpenAI or Google in Claudpress > Automation and paste that vendor's key. Each provider has a real adapter for its request format, tool-calling schema and response shape — this is not just a URL swap. OpenAI-compatible gateways (Azure, OpenRouter, Groq, Ollama) work via the endpoint override.
* Multi-provider image generation: OpenAI (GPT Image / DALL·E) or Google Gemini image, a.k.a. "Nano Banana".
* Model guide: an expandable cheat-sheet next to the Model field lists the exact model IDs to type for every provider, what each one is for, and links to the vendor's own model list and key page — so a wrong model name is no longer a guessing game.
* Blank model/endpoint now means "use the provider's recommended default", and switching provider can no longer strand the previous vendor's URL in the endpoint field.
* Operating protocol: connected agents — interactive and autonomous alike — now receive explicit working rules covering read-before-write, minimal changes, preferring specific tools over execute_php/execute_sql, verifying changes by reading them back, treating live snippets as the highest-risk action, and reporting honestly. Extend it with the `claudpress_operating_protocol` filter.
* Security: the unauthenticated /status health check no longer advertises whether AI abilities are on or how many tools are exposed; that detail now requires authentication.
* The Connect page no longer links to the MCP endpoint (a browser GET could only ever return 401). It shows the URL to copy, and links the health check instead.
* Internationalization: text domain loaded and every admin string across all four screens wrapped for translation, with translator comments and positional placeholders where values are interpolated.
* Budget caps now state plainly that costs are estimates from built-in list prices — they do not know your discounts, cached-token rates, or a gateway's pricing. Your provider's invoice is the authoritative number.
* Tests: 370 assertions, up from 206. Adds full provider adapter coverage (parsing, tool-call round-trips, malformed responses, Google schema sanitising), protocol guardrail checks, and a new admin render suite that renders all four screens and asserts balanced markup, that every form carries a nonce, that API keys and tokens only ever appear inside password inputs, and that no untranslated strings creep back in. Filters now execute for real in the test harness instead of being no-op stubs.

= 1.4.0 — Reliability, budgets & migration depth =
* Autonomy reliability: the agent loop now retries transient API failures (429/408/5xx and network errors) with exponential backoff and jitter, honouring the server's Retry-After. Permanent failures (bad key, bad request) still fail fast.
* Budget caps: per-run and per-day spend/token/run limits stop a runaway autonomous loop from spending real money. A capped run halts cleanly and reports what it finished. Configurable under Claudpress > Automation; 0 means unlimited.
* Agent run history: every autonomous run is recorded (source, status, steps, tool calls, retries, tokens, estimated USD) and shown on the Automation tab, plus a new read-only `agent_runs` tool so your AI can answer "what has the site been doing on its own, and what is it costing?".
* Digest cadence UI: schedule a status digest straight from the Automation tab with a cadence and a time of day. Tasks gained a `run_hour` and a `monthly` schedule.
* Migration: bundles now carry nav menus with their items and theme locations (relinked to the destination's pages by slug), and widgets/sidebars. Variable products migrate as content only — their attributes and variations are reported as skipped, to be rebuilt on the destination with the WooCommerce tools.
* Shipping: `wc_shipping` gained `list_available_methods`, `get_method`, `configure_method` (enter a carrier's API credentials on an existing instance) and `quote` — which runs WooCommerce's real shipping engine for a destination and returns the live rates, including rates fetched from carrier APIs.
* Tests & CI: a dependency-free PHP test suite (`php tests/run.php`, 200+ assertions covering policy, capabilities, budget caps, retry/backoff, scheduling and whole-plugin integrity), a live MCP smoke test (`node tests/live-smoke.mjs`), and GitHub Actions running lint on PHP 8.0–8.4 plus the suite on every push.

= 1.3.3 — Autonomy hotfix =
* Fixed autonomous agent runs failing when the model called a tool with no arguments (tool_use.input serialized as [] instead of {}, rejected by the Anthropic API). Found via a full live feature test.

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
