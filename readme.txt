=== Keelhob ===
Contributors: calflint
Tags: mcp, ai, claude, woocommerce, seo, automation
Requires at least: 6.3
Tested up to: 6.9
Requires PHP: 8.0
Donate link: https://ko-fi.com/keelhob
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-contained MCP server that lets an AI agent (Claude Code, Claude Desktop, Cursor, Codex) fully run your WordPress and WooCommerce site.

== Description ==

Keelhob turns your WordPress site into an MCP (Model Context Protocol) server. Connect any MCP-compatible AI client and it can manage content, run your WooCommerce store, handle SEO, edit files and execute PHP — with cross-session memory and a skills engine. No external dependencies, no Node bridge for HTTP clients, and every feature unlocked: there is no paid tier, no trial and nothing held back.

This plugin is NOT in the WordPress.org plugin directory, and will not be: it executes arbitrary PHP, which the directory does not allow. It is installed from a zip and updates itself from this project's GitHub releases (https://github.com/calflint/keelhob/releases).

The full manual — install, connect, every tool, every capability group and troubleshooting — is at https://keelhob.com/docs. You do not need GitHub to read it.

Features:

* MCP Streamable-HTTP endpoint at /wp-json/keelhob/v1/mcp
* Bearer token or Application Password authentication
* execute_php / execute_sql universal power tools
* Content: posts, pages, any CPT, media, taxonomies, menus
* WooCommerce: products, orders, coupons, reports, settings
* SEO: meta (Yoast/Rank Math/AIOSEO/SEOPress compatible), analysis, schema, redirects, sitemap
* Filesystem tools confined to a configurable base, plus a crash-recovering sandbox
* Live code snippets (PHP/CSS/JS/HTML) with syntax checking and a crash guard
* Cross-session memory shared across agents
* Skills engine with twenty-six bundled expert playbooks
* Master safety switch, capability groups, and a full activity log
* Bring your own key: Anthropic, OpenAI or Google for the agent brain and image generation
* Telegram chat: message a bot and the on-site agent runs your site — no MCP client needed
* Voice notes, photos, one-tap approvals, /undo and per-chat roles for that chat
* Event alerts: paid orders, low stock, a crashed snippet, available updates

== How risky this is, honestly ==

A connected agent can write PHP, read your database and change your files. That is the product, not
a defect. So the answer is not "staging only" — it is graded, and this is the grading.

**Ten capability groups ship switched off**, and are forced off once on any site still carrying
settings from the old free/paid split, so an automatic update cannot quietly hand them over:

* abilities — let the AI write new PHP tools for this site, for you to approve
* sitecare_fix — compress images in place, delete database junk, rewrite links, install plugins
* store_orders — read and refund real orders, and read sales reports
* payments — enable and configure payment gateways and their keys
* shipping — change shipping zones, methods and rates
* tax — change tax settings and rates
* users — create, edit and delete user accounts and roles
* updates — update WordPress core, plugins and themes
* comms — send email to real addresses, including your customers
* deploy — export a bundle and push it to your production site

**What stays on** is what a build needs, and none of it is harmless: content writes posts, pages and
media · store creates products, coupons and store settings · seo and seo_optimize write meta,
schema, sitemap and redirects · design builds Elementor pages · snippets installs code that runs
on every page load · code_exec runs arbitrary PHP and SQL · files reads and writes under a
configurable base · site_admin changes options and activates plugins and themes · images searches
and generates media · automation runs unattended agent loops · memory and skills write what the
agent remembers and follows. Above all of them sits one master switch: with **AI abilities** off the
endpoint authenticates but exposes only read-only introspection. Every call is written to the
Activity log.

**Before pointing this at anything you care about:** a backup you have actually restored once, not
one you assume works; a staging copy for the first build, so you can watch what it does; only the
capability groups the job in front of you needs; and the endpoint token treated like a root
password, because it is one — stored hashed and shown once, so regenerate it the moment it leaks.

**Zero external dependencies at runtime.** No npm, no vendored libraries, no bundled SDK, and
nothing from Composer — the plugin runs from its own source, so there is no supply chain
underneath it to compromise. For software with this much reach that is worth more than any feature.
There is a composer.json, and it exists only for the test and analysis tooling: its require block is
empty on purpose and vendor/ is not in the shipped zip. SECURITY.md
(https://github.com/calflint/keelhob/blob/main/SECURITY.md) covers what is in scope and how to
report something.

== About eval() ==

Grep this repository and you will find eval() three times: twice in includes/class-snippets.php (in
run_php() and run_php_template()) and once in includes/tools/class-tools-core.php (in
execute_php()). That is the product working as designed, not an oversight — execute_php is the
escape hatch that lets an agent do anything WordPress can do without waiting for a dedicated tool,
and the snippet engine is how AI-written code becomes site code that runs for real visitors. What is
built around it: a PHP snippet is parse-checked with token_get_all(TOKEN_PARSE) before it can ever
be active, and code that fails the check is still saved — you do not lose the work — but is
forced inactive with the parse error attached. A snippet that fatals at runtime is switched off by a
shutdown handler that knows which snippet was executing, with the error written to its row, to the
snippet log and to the PHP error log, and an event fired so the site can tell you. The snippet tools
sit in the snippets capability group and execute_php/execute_sql in code_exec: switch either off and
those tools disappear from the agent's tool list and are refused if called anyway. Snippets already
saved keep running regardless, because by then they are site code — the separate **Run code
snippets** setting is the kill switch that stops every one of them at once.

== Snippet locations ==

Not every location can run every type. `everywhere`, `admin_only`, `frontend_only` and
`page_template` only execute PHP — css/js/html saved there would never be emitted, so saving one
there is refused with the locations that do work. `header`, `footer`, `before_content`,
`after_content` and `shortcode` accept php, css, js and html.

== What it cannot do yet ==

* **No onboarding wizard.** Activation drops you on an admin page; connecting an agent is a
  copy-paste step you do yourself.
* **No independent security audit.** ~2,600 unit assertions and a live rig against real WordPress,
  and nobody outside this project has read the code. Commissioning that is the top line in
  FUNDING.md (https://github.com/calflint/keelhob/blob/main/FUNDING.md).
* **Permissions are coarse.** Capability groups, on or off — no per-tool approval and no queue
  that asks before a destructive action runs.
* **No rollback.** /undo reverses one turn and only content: posts, pages, products. Not settings,
  plugins, files or live snippets. Nothing snapshots the site, so backups are yours.
* **Block themes and full site editing are not supported.** Pages are built with Gutenberg,
  Elementor, or custom-coded page templates.
* **No data importer.** Nothing reads a Shopify, Wix or Squarespace export and loads it in. The
  site-clone skills rebuild a site's design and structure by looking at it, which is a different
  job: orders, customers and stock do not come across. Going the other way, a variable product only
  half-migrates between Keelhob sites: the page moves, its attributes and variations do not, and the
  import says so.
* **One agent at a time**, no background job queue: a long interactive build lives inside the
  session that started it. **Not translated**, either — strings are wrapped, no translation ships.
* **Telegram needs a public HTTPS URL** with a valid certificate — a localhost or self-signed
  staging site cannot receive messages.
* **Not on WordPress.org**, and it will not be: the directory does not allow a plugin that
  executes arbitrary PHP. Install the zip; it updates itself from GitHub releases.

ROADMAP.md (https://github.com/calflint/keelhob/blob/main/ROADMAP.md) says which of these are being
fixed, and in what order.

== Support this project ==

Keelhob is free, GPL, and stays that way. There is no paid version, no locked feature and no
trial. It is built in evenings around a full-time job, and donations are the only thing deciding
whether it keeps being built.

https://ko-fi.com/keelhob

Other things that help just as much: report a bug you hit, write a skill and open a pull request
(skills are plain markdown, no PHP needed), star the repository, or just tell someone. Nothing here
has a marketing budget.

== External services ==

This plugin can send data to third-party APIs. Nothing is sent until you enter a
key for that service, and no service is contacted at all if you leave its key blank.

The one exception is the update check, described first below. It is the only
request Keelhob makes on its own, and you can switch it off without losing
updates.

**Updates and the public count (on by default, one setting to turn off).**
Keelhob is not in the WordPress.org directory, so it checks
https://api.keelhob.com/v1/version for new versions the way the directory
otherwise would. That same request is how the project counts how many sites are
running it, which is the only measure it has of whether any of this is worth
continuing.

What is sent, in full: a random ID generated on your site (not derived from your
address, domain or email, so it cannot be traced back to you), the Keelhob
version, your WordPress and PHP versions, your site language, whether this is a
multisite, whether WooCommerce is active, which page builder is configured,
whether AI abilities are switched on, the names of the capability groups you
have enabled, and how many unattended runs happened in the last 24 hours.

That is the complete list, and every item on it is true of a thousand other
sites. No site address, no domain, no email, no user data, no content, no API
keys, and no record of what the AI actually did. It does not send what your
automation costs you or how many tokens it used - your spend is your business.
The endpoint does not keep IP addresses, and rows are deleted after 90 days of
silence.

Why more than a version number: a free project still has to decide what to build
next, and a bare install count cannot say whether anyone comes back, which
features people actually turn on, or whether the PHP floor can move. The
alternative we did not take was putting an analytics service in your admin.

Turn it off under Keelhob > Settings and the update check goes straight to
GitHub (https://api.github.com) instead — you keep getting every update,
including security ones, and this site simply is not counted.

**The feedback report (on by default, nothing sent without your yes).**
When something goes wrong while the agent works - a tool errors, a permission
blocks real work, or it tries the same thing three times - Keelhob notes it in
a table in your own database. Nothing is sent by that. About once a week, and
only if something actually went wrong, the agent shows you a short summary and
asks whether to send it to the feedback endpoint at api.keelhob.com. You see the
whole thing before you answer, and you can change it or refuse.

What is sent, in full: the same random site ID as the update check, the plugin
version, and at most five problems of at most 200 characters each. Web
addresses, domains, email addresses, IP addresses, file paths, key-shaped
strings and your own site's name are stripped in PHP before anything is stored
or sent. No conversation, no transcript, no page content, no customer data.

If the project's inbox is already full for the day (it accepts a limited number
of reports from any one network address, because many sites share one), nothing
is lost: your notes are kept, your weekly offer is not used up, and it asks again
the next day.

The local notes are kept for 30 days or 50 entries, whichever comes first. You
can read every one of them and delete them all at Keelhob > Activity, and the
same screen can ask the server to delete every report it already holds for your
site. Untick "Help improve Keelhob" under Keelhob > Settings and nothing is
recorded at all.

**Your MCP client (Claude Code, Claude Desktop, Cursor, Codex, or any other).**
Whatever client you connect makes the requests; whatever it reads from your site
goes to that client's model provider under that provider's terms. This is the
whole point of the plugin, and it is the largest single disclosure here: an agent
you connect can read your posts, orders, customers and files.

**Autonomous runs (optional — Keelhob > Automation).** When you add an agent
API key and schedule an autonomous task, Keelhob sends the task instruction,
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
the destination Keelhob site whose URL and token you configure.

**Skill updates (optional).** Keelhob can check GitHub
(https://github.com/calflint/keelhob/releases - terms:
https://docs.github.com/site-policy/github-terms/github-terms-of-service -
privacy: https://docs.github.com/site-policy/privacy-policies/github-privacy-statement)
for newer versions of the bundled skills. It is a plain download of two public
files; no key, no account, and nothing about your site is sent. Every skill is
verified against a signature before it is written, and an unsigned or unverifiable
skill is discarded rather than installed.

== If a snippet breaks your site ==

Add this line to wp-config.php, above the "That's all, stop editing" comment:

define('KEELHOB_SNIPPETS_OFF', true);

Every snippet stops running immediately, whatever the admin page says about it.
Remove the line to switch them back on. Nothing is deleted.

Edit wp-config.php however you normally would: FTP, your host's file manager, or
a terminal. Deliberately none of that needs a working site or a database client,
because the case this exists for is a site you cannot load.

Why this is a constant and not a button: an active snippet can take a site
offline without ever erroring. The commonest way is returning a 503 from the
front end — for a coming-soon or maintenance page, say. Managed and containerised
hosts poll your home page to decide whether the site is alive, read that 503 as
"this server is dead", and stop routing traffic to it. wp-admin goes with it. At
that point every switch inside WordPress is unreachable, so the switch has to
live outside it.

The plugin already auto-deactivates a snippet that causes a FATAL error. This
covers the other case: a snippet that works exactly as written and still breaks
the site.

== Installation ==

1. Download keelhob.zip from https://github.com/calflint/keelhob/releases
2. Upload it via Plugins > Add New > Upload Plugin, then Activate.
3. Open the Keelhob admin page.
4. Enable AI abilities. Read "How risky this is, honestly" above first, and start on a staging copy
   with a backup you have actually restored.
5. Copy the "Connect Claude Code" command from the Connect page and run it in your terminal.

Ten capability groups are switched off until you turn them on: abilities, sitecare_fix,
store_orders, payments, shipping, tax, users, updates, comms and deploy. Autonomous runs are also
off until you add a provider API key
under Keelhob > Automation. Pick your provider there — the model guide next to the field lists
the exact model IDs to type for each one.

== Changelog ==

= 2.0.0 =

* **Renamed.** This plugin was previously published under a different name, one
  that contained "Claud" and read as Anthropic's Claude trademark. It is now
  Keelhob. Nothing it does has changed. See docs/why-the-name-changed.md for the
  reasoning and the upgrade order.
* **Your data comes with you.** Snippets, skills, memory, tasks, logs, settings
  and your connection token are all adopted automatically on the first load.
  Deactivate the old plugin BEFORE activating this one, check everything is
  there, then delete the old one.
* **You must reconnect your AI client.** The MCP address changed to
  /wp-json/keelhob/v1/mcp. An old configuration will fail with a 404. Copy the
  new command from the Connect screen.
* **The admin screens have a look of their own.** Every Keelhob screen now sits
  on one panel with the same type, spacing and colours, and a tab strip across
  the top. It follows your WordPress admin colour scheme, so a dark scheme gets
  a dark panel. Nothing moved and no form field was renamed.
* **New: it can look after a site, not only build one.** Five read-only
  reports — broken_links, speed_audit, db_cleanup_preview, plugin_conflicts
  and plugin_security_check — and five tools that fix what those reports find:
  image_compress, db_cleanup, link_fix, media_set_alt and plugin_install. The
  five that write are in a new sitecare_fix group and ship switched OFF,
  because they overwrite images, empty tables and install plugins.
* **New: every tool can be rated from real use.** A run log records what each
  tool did, how long it took and whether it worked, and a thumbs-down with a
  note becomes a lesson that is added to that tool's description.
* **New: an AI can write its own tools — switched OFF.** ability_create and
  ability_improve let a model author PHP that then runs on your site. This is
  the most dangerous thing in the plugin, so it is off by default and every
  new ability must be approved by you before it can run once.
* **Ten capability groups now ship switched off**, up from eight: abilities,
  sitecare_fix, store_orders, payments, shipping, tax, users, updates, comms
  and deploy.
* Everything that was written for 1.2.0 ships here: Gemini tool-list fixes, the
  hourly-digest timezone fix, per-bot Telegram replay marks, the chat-style AI
  Compose screen, and counts on every list tool.

= 1.2.0 =

* New: **the Snippets screen looks and works like a code editor.** The code now
  takes the whole left side, inside a proper editor frame with a coloured dot for
  the language, the filename and a PHP/CSS/JS/HTML badge. Everything that is a
  setting rather than the work - type, where it runs, priority, tags - moved to a
  panel down the right, always open. Nothing you have switched on is hidden
  behind a fold-away any more.
* New: **conditional logic you can actually set.** Only run a snippet for logged
  in visitors, or a role, or one page type, or on mobile, or on URLs starting
  with /shop - and match ALL or ANY of your rules. The engine existed before but
  had no screen, so only a connected AI could set a condition. Now you can, and
  the list tells you at a glance which snippets have rules.
* New: **revisions are on the edit screen**, in the panel on the right, with a
  Restore button on each one. They have been recorded on every edit for a long
  time; they were at the very bottom of the page, so nobody found them.
* Fixed: **deleting a snippet left its old versions in the database for ever** -
  including a full copy of the code. If you deleted a snippet because of what was
  in it, it was not actually gone. Deleting now removes its history with it.
* Fixed: **"delete all data on uninstall" did not delete all the data.** Four of
  the ten tables were missed, including the one holding every past version of
  every snippet. It now removes everything the plugin ever wrote, and nothing
  else.
* Fixed: **the code editor on the Snippets screen has never worked.** It was
  meant to give you line numbers and colour-coded code; on every install since it
  was added, it silently gave you a plain text box instead. It works now. This
  was found by a new test that opens the page in a real browser and looks at it -
  reading the code could not have found it, and did not.
* Fixed: PHP snippets were not colour-coded even once the editor loaded, because
  the editor was told to expect a web page with PHP inside it rather than plain
  PHP. Snippets are plain PHP.
* Changed: **the code comes first when you open a snippet.** Six fields used to
  sit above the code box, which pushed the code off the bottom of the screen on
  an ordinary laptop - every time, on every snippet. Name and type stay at the
  top; description, where it runs, priority and tags moved below the code, folded
  away unless you are using them.
* Changed: **Save is now the blue button.** "Save and switch on" was, which meant
  the loudest button on the screen was the one that changes what runs on your
  live site. It is still there, and now only appears when the snippet is off.
* New: **an on/off switch you can actually see.** Switching a snippet on or off
  was hidden until you hovered the row - the most-used control on the page, and
  the hardest one to find. It is a visible switch in the Status column now.
* New: **an "Errored" filter.** A snippet that crashed and switched itself off
  used to look exactly like one you turned off on purpose. Now you can list just
  the broken ones, and the filter only appears when something has actually
  broken.
* New: sortable columns - name, priority and when it last changed - and a
  Priority column, shown once any snippet is using a priority other than the
  default.
* New: **AI Compose is a chat.** It used to be three steps - describe, approve a
  plan, get code - and each step forgot the one before it. So if the code came
  back with a typo you could not say "fix that": your only choices were to save
  broken code or start again from an empty box. And if the AI answered in
  ordinary sentences instead of the exact shape the screen expected, you got
  "The AI replied, but not in a form this screen could read" over a perfectly
  good answer.
  Now you just talk to it. It remembers the whole conversation, so you can ask
  for changes, point out a mistake, or ask why it did something. Every piece of
  code it sends is syntax-checked on the spot and the result is shown right
  there - and if the check fails, the error is handed straight back to the AI to
  fix, automatically, once. Every block of code gets its own Save button.
  Nothing is saved until you click Save, and it still always saves switched off.
  Leaving the tab clears the conversation; an accidental page refresh does not.
* New: **the AI is told about your site.** Compose now sends your WordPress and
  PHP versions, your theme, your active plugins and whether WooCommerce is
  installed. It used to send none of that and then be judged on whether the code
  fitted.
* New: **you can hide the "AI abilities are ON" banner.** Per person, not per
  site, so hiding it does not hide it from a colleague. It comes back whenever
  what the AI is allowed to do changes. The toolbar still says "Keelhob: ON"
  on every screen, and that cannot be hidden - which is the only reason the
  banner may be.
* Changed: the Donate button is on the Connect screen and the Donate page, and
  nowhere else. It used to be beside every page title, which is not what a free
  plugin should look like.
* New: the Plugins screen now links straight to **Snippets**, and says out loud
  that the snippets engine works on its own - write PHP, CSS, JS or HTML by
  hand, with a real code editor and a syntax check, without connecting any AI
  and without switching abilities on. That was always true and the plugin never
  said it.
* Changed: the 1.0.3 entry below now says plainly that there was never a
  separate 1.0.3 download - that work shipped inside 1.1.0.
* Removed: a "Browse the library" button that had never worked, and a second,
  unreachable copy of the Snippets screen that was shipping inside the plugin
  without being used.

= 1.1.0 =

* New: **the feedback log.** When something goes wrong while the agent works - a
  tool errors, a permission blocks real work, or it tries the same thing three
  times - Keelhob notes it in your own database. About once a week, and only
  if something actually went wrong, the agent shows you a short summary and asks
  whether to send it to the project. You see the whole thing first and you can
  change it or say no. Nothing is ever sent without your yes.
* New: Keelhob > Activity now shows every note in that log, and two buttons -
  one that wipes it, one that also asks the project to delete every report it
  already holds for your site.
* New: the "Help improve Keelhob" setting, under Keelhob > Settings. Untick
  it and nothing is recorded at all - not "recorded but not sent". Nothing.
* New: `contribute-feedback` skill, which teaches the agent how to word a note
  and what must never go in one.
* New: skills now say where they can be run. A skill that needs a browser on your
  own machine is refused on Telegram instead of half-finishing.
* New: the `site-clone` skill family - copy a site built on Wix, Shopify, React,
  Next or Vite into WordPress, one page at a time, and check the result against
  the original.
* Privacy: PRIVACY.md now documents the feedback report field by field, including
  the part it is fair to be uneasy about.

= 1.0.3 - included in 1.1.0 =

There was never a separate 1.0.3 download. This work shipped as part of 1.1.0,
so if you are on 1.1.0 or later you already have all of it.

* New: three skills. A whole-site SEO audit that scores your site, fixes what it finds and shows you
  a before-and-after report page you can open on your phone. A design skill covering animation and
  scroll effects, so pages stop coming out flat. And a launch check covering cookie consent, privacy,
  accessibility and the twenty things that make a site look cheap.
* New: your AI is now told which skills exist over Telegram and on scheduled runs, not only when
  connected from a desktop app. It could previously build a page without knowing the design and SEO
  guidance was there.
* New: your AI now agrees the cost with you before starting anything slow or expensive, such as a
  whole-site audit.
* New: Keelhob now has its own icon in the WordPress admin menu, on every Keelhob screen, in
  the toolbar, and on the Updates screen, instead of a generic placeholder.
* Security: the plugin now checks that your site still loads for a logged-out visitor after any
  change that could break it — saving or switching on a snippet, running PHP or a write query,
  writing, editing or deleting a file, and activating a plugin or a theme. If the site stops
  answering, the change is undone where it can be: a plugin is deactivated again, a theme is
  switched back, a file is restored from a copy taken before the write. Running PHP or SQL cannot be
  undone automatically, so those are reported instead, in the response the AI reads before it tells
  you it finished.
* Security: file writes now keep a copy of the previous version of the file, so a change that breaks
  your site can be put back. One copy per file, most recent only, stored where the web cannot reach
  it.
* Security: a site that was already broken before your change is no longer reported as "passed". It
  now says the site is down, and that this change was not the cause.
* Fixed: a CSS, JavaScript or HTML snippet saved at a location that cannot run it — "everywhere",
  "admin only", "front end only" or "page template" — used to be stored, listed as active, and
  silently do nothing. Those four only run PHP. Saving another type there is now refused, with the
  locations that would work named in the error. In the Snippets editor it is moved somewhere it runs
  and you are told, so nothing you typed is lost.
* Fixed: building an Elementor page with a wrong widget setting name used to save and report success
  while doing nothing. Unrecognised setting names are now named in the response.
* Improved: the Elementor skill now carries the traps that cost real time on a real build, including
  the Xpro Theme Builder header and footer settings and the classic-theme requirement.
* Fixed: the launch readiness checker used to describe a coming-soon or maintenance holding page as though
  it were every page on the site, because it loads each page the way a logged-out visitor does and a gate
  answers them all identically. It now notices when several pages come back byte-for-byte the same, says so
  plainly, and marks those pages as "not checked" instead of inventing findings about them.
* Fixed: a site with no shop is no longer told off for having no refund page and no shipping page.

= 1.0.2 =
* Security: the key used to sign skill updates has been replaced. The old one was never published
  anywhere, but it was exposed somewhere it should not have been, so it was retired rather than
  trusted. Nothing you have installed is affected, and the plugin still updates normally.
* Fixed: the "External services" section above named the wrong address for the update check. It
  said keelhob.com; the real address is api.keelhob.com/v1/version, which is what PRIVACY.md
  and the code have always used. Wrong wording in the one section that tells you where your data
  goes, so it is called out here rather than fixed quietly.
* Changed: the three safety sections of this file are now generated from README.md, so the two can
  no longer disagree. They already had: one limitation was listed in one file and not the other.

= 1.0.1 =
* Fixed: the update check pointed at a route that never existed, so it always failed. It now
  calls api.keelhob.com. Updates already worked via the GitHub fallback added in 1.0.0; this
  makes the anonymous install count work too.
* Fixed: switching the Autonomy capability off did not actually refuse the agent_runs tool,
  although the dashboard said it did.
* Fixed: a class was referenced under the wrong capitalisation. Harmless in PHP, but a trap for
  the autoloader added in this release.
* Changed: the three largest source files are split, the biggest dropping from 2,440 lines to
  212. No behaviour changed - every hooked method is still on its original class, verified by
  diffing every method body against the previous release.
* Changed: 52 manual require lines replaced by an autoloader.
* Added: PHPStan in CI, and a composer.json for tooling only. The plugin still ships with zero
  runtime dependencies and no vendor directory.

= 1.0.0 - first public release =
* The first public release of Keelhob. Everything is in this one GPL plugin: content, design,
  snippets, SEO, files, images, memory, skills, code execution, WooCommerce products and orders,
  payments, shipping, tax, users, updates, email, deploy to production, the Snippets Studio, and all
  fifteen expert skills. There is no paid tier, no licence, no trial and nothing held back. The
  project is funded by donations.
* Eight capability groups ship switched off — store_orders, payments, shipping, tax, users, updates,
  comms and deploy — and are forced off once on any site carrying settings from the earlier
  free/paid split, so an update cannot silently grant a connected agent the ability to email
  customers, edit users, change payment gateways or push to production. An admin notice points you
  at the setting. Everything the build itself needs stays on.
* Snippet safety: PHP is parse-checked before a snippet can activate, code that does not parse is
  stored but forced inactive with the error attached, and a snippet that fatals at runtime is
  auto-deactivated by a shutdown handler, logged and announced.
* Filesystem safety: write_file, edit_file and write_sandbox_file refuse to write PHP that does not
  parse, and paths cannot escape the configured base directory.
* Secrets: API keys are encrypted at rest and never printed into the settings page source; the
  connection token is stored as a hash and shown once; nothing credential-shaped reaches the
  activity log or an export bundle. Failed logins are rate limited and the endpoint accepts only
  Application Passwords, never ordinary account passwords.
* Uninstalling the plugin keeps your snippets, memory, skills and tasks by default. There is a
  setting if you genuinely want everything erased.
* Skill updates come from this project's public GitHub releases, verified against an Ed25519
  signature before anything is written. No account, no key, and nothing about your site is sent.
* Telegram chat front-end with conversation memory, voice notes, photos, one-tap approvals, /undo
  and per-chat roles; event alerts for paid orders, low stock, a crashed snippet and available
  updates; scheduled digests and autonomous tasks with retries, budget caps and a run history.
* Requires WordPress 6.3+ and PHP 8.0+. Tests: ~2,600 assertions, plus a live rig against real
  WordPress and WooCommerce.

The development history before this release is in the repository's git log and on the releases page:
https://github.com/calflint/keelhob/releases

== Upgrade notice ==

= 1.0.0 =
First public release. Eight capability groups (store_orders, payments, shipping, tax, users,
updates, comms, deploy) ship switched off and are forced off on upgrade — turn back on the ones you
want under Keelhob > Capabilities.
