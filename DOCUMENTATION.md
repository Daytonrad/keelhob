# Keelhob documentation

Keelhob is a WordPress plugin that lets an AI agent run your website.

You install it, connect your agent once, and then you ask for things in plain
words. The agent writes the pages, builds the layouts, adds the products, fixes
the code and checks its own work — on your hosting, in your database, under your
control.

This page is the whole manual. You do not need GitHub to read it, and you do not
need to be a developer to follow it.

**Free, GPL, no paid tier.** Everything described here is in the one download.

Two companion pages, for when this one is not the right level:

- [How risky this is, honestly](/docs/risk) — the graded risk statement, in more
  detail than the summary further down.
- [Features, tool by tool](https://github.com/calflint/keelhob/blob/main/docs/features.md)
  — the developer view: argument shapes, option names and file paths.

---

## What Keelhob actually is

Most "AI website builders" run on somebody else's servers. You describe a site,
they hand you a site, and you rent it from them for as long as you keep paying.

Keelhob is the opposite arrangement. It is a plugin on **your** WordPress, and
it gives your AI agent real controls on that site: a set of tools it can call to
create a post, upload an image, edit a product, run a database query, write a
line of PHP. The agent is yours. The site is yours. The hosting is yours. If you
uninstall Keelhob tomorrow, everything it built stays exactly where it is,
because it built real WordPress.

The technical name for the connection is **MCP** — Model Context Protocol. It is
the standard way an AI agent talks to an outside system. You do not need to
understand it. You copy one line into your agent, and it works.

### What it is not

- It is not a page builder you click around in. You talk to it.
- It is not a hosted service. There is no Keelhob server holding your site.
- It is not a chatbot bolted onto your admin. It is a set of real controls.
- It is not a theme. It works with the theme you already have.

### Who it is for

- Someone who wants a real website without learning WordPress.
- A developer who would rather describe a change than click through six screens.
- An agency running many sites that all need the same job done.

---

## Before you start

You need three things.

| You need | Details |
|---|---|
| **WordPress 6.3 or newer** | Almost every live site already qualifies. |
| **PHP 8.0 or newer** | Your host will tell you; most are on 8.1 or 8.2. |
| **An AI agent that speaks MCP** | Claude Code, Claude Desktop, Cursor and Codex all do. |

Nothing else is required. WooCommerce and SEO plugins (Yoast, Rank Math,
AIOSEO, SEOPress) are **detected automatically if you have them**, and ignored
if you do not. Keelhob has no runtime dependencies of its own — nothing to
install with Composer, no build step, no external service.

### Do I need a staging site?

Not necessarily, but read the control section below first.

A connected agent can write PHP and change your database. That is the product,
not a fault. Keelhob's answer is not "only use it on a copy" — it is a set of
graded controls you decide for yourself, and they start cautious.

If the site earns money today, work on a copy until you trust it.
If the site does not exist yet, go straight in.

---

## Install it

1. Download `keelhob.zip` from the [download page](/download), or straight from
   the [latest release](https://github.com/calflint/keelhob/releases).
2. In WordPress: **Plugins → Add New → Upload Plugin**.
3. Choose the zip, then **Install Now**, then **Activate**.
4. A **Keelhob** item appears in the admin menu.

That is the whole installation. No configuration file, no account, no key.

---

## Connect your agent

Open **Keelhob → Connect**. Pick your app. Copy what it shows you.

Each app wants a different thing, so pick the right one. The screen fills in
your real address and token for you.

| Your app | What you do | Needs Node.js? |
|---|---|---|
| **Claude Code** | Run one command in a terminal | No |
| **Claude Desktop** | Paste JSON into a config file | **Yes** |
| **Cursor** | Paste JSON into `~/.cursor/mcp.json` | No |
| **VS Code** | Paste JSON into `.vscode/mcp.json` | No |

### Claude Code

One command, then restart the app:

```bash
claude mcp add keelhob-yoursite --transport http https://yoursite.com/wp-json/keelhob/v1/mcp --header "Authorization: Bearer kh_xxxxxxxx" -s user
```

The terminal and the Claude Code desktop app share one settings file, so this
covers both. **Keep `-s user`** — without it the site is saved only for the
folder you were standing in, and disappears everywhere else with no error.

### Claude Desktop

**This one is different.** Claude Desktop cannot open a web address by itself.
A small bridge does it, and that bridge needs **Node.js** installed first.

The Connect screen gives you the JSON. Paste it into:

- **Windows, Microsoft Store:** `%LOCALAPPDATA%\Packages\Claude_*\LocalCache\Roaming\Claude\claude_desktop_config.json`
- **Windows, normal install:** `%APPDATA%\Claude\claude_desktop_config.json`
- **macOS:** `~/Library/Application Support/Claude/claude_desktop_config.json`

On a Microsoft Store install the obvious path exists **and is ignored**. Try the
Store path first.

Then **fully quit** Claude Desktop and reopen it. Closing the window is not enough.

### Cursor and VS Code

Both take JSON from the Connect screen. They use different key names, so copy
the block for the one you use. Cursor reads `~/.cursor/mcp.json` for every
project, or `.cursor/mcp.json` for one. VS Code reads `.vscode/mcp.json` in
your project.

### A site on your own computer

Local tools like LocalWP work fine. Two things to know:

- **Leave SSL switched off.** A local certificate is self-signed and the
  connection is rejected before it starts. Stay on `http://`.
- **Telegram, email alerts and scheduled tasks will not work.** They need a
  public address.

The Connect screen detects a local site and says this on the page.

### Check that it worked

Ask your agent:

> list the plugins on this site

If it answers with your real plugin list, you are connected.

### About the token

The token is the password to your site's controls. Treat it like one.

- It is shown on the Connect page and nowhere else.
- You can regenerate it at any time; the old one stops working immediately.
- After ten failed attempts from one address in fifteen minutes, that address is
  locked out. Regenerating the token clears the lock.

You can also connect with a WordPress **Application Password** instead of the
token, if that suits your setup better.

---

## Your first five minutes

Try these, in this order. They are safe, and each one proves a different part
works.

1. **"What is on this site?"**
   The agent reads your pages, plugins, theme and settings, and summarises them.
   Nothing is changed.

2. **"Write a blog post about my topic and publish it."**
   It drafts the post, structures it with headings, sets the meta description
   and publishes it. Then ask it for the link.

3. **"Change the site's accent colour to a deep green."**
   It finds where your colours are defined and changes them in the right place.

4. **"Build me a contact page."**
   It builds the layout, writes the copy and wires up the form.

If any of those goes somewhere you did not want, say so. The agent can undo most
things, and it keeps a record of what it did.

---

## What it can do

Keelhob gives the agent **85 tools**, grouped by job. You never call these
yourself; you ask for an outcome and the agent picks the tools. Two of the 85
only exist once you switch on agent-written abilities, and eight more need
WooCommerce installed, so a plain site shows the agent fewer than this.

This section is the honest inventory. If a feature is not listed here, Keelhob
does not have it.

### Content and media

Write, edit, publish and delete posts, pages and any custom post type. Upload
images and files into the media library. Manage categories and tags.

`create_post` · `update_post` · `delete_post` · `get_post` · `list_posts` ·
`upload_media` · `manage_taxonomy`

### Design and page building

Build and edit **Elementor** pages, headers and footers, and set the site-wide
design kit — colours, fonts, spacing — in one place so every page inherits it.
Pages built this way stay fully editable by you in the Elementor editor
afterwards. It is not a locked export.

`elementor_page` · `elementor_settings` · `elementor_kit`

### WooCommerce

Products, variations, coupons and store settings for building a shop. Orders,
refunds and sales reports for running one.

`wc_product` · `wc_coupon` · `wc_settings` · `wc_order` · `wc_report` ·
`wc_payments` · `wc_shipping` · `wc_tax`

`wc_shipping` can also fetch **live carrier rates**, so a customer sees the real
posted price for their address instead of a flat fee you guessed.

The money-touching parts — orders, payments, shipping and tax — are **switched
off when you install**.

### SEO

Meta titles and descriptions, Open Graph and Twitter cards, JSON-LD schema, the
sitemap, `robots.txt` and `llms.txt`. It can also score a page against a target
keyword and manage redirects so you never leave a dead link behind.

`seo_meta` · `seo_schema` · `seo_sitemap` · `seo_analyze` · `seo_redirect`

If you already run Yoast, Rank Math, AIOSEO or SEOPress, Keelhob writes through
that plugin rather than fighting it.

### Code snippets

Add real code to your site — PHP, CSS, JavaScript or HTML — without touching
theme files. Same idea as WPCode or Code Snippets, with one difference: the
agent writes them, and the plugin checks them.

- PHP is **parse-checked before it is saved**. Code that does not parse is saved
  but forced inactive, with the error attached.
- A snippet that causes a **fatal error is switched off automatically**, so the
  site stays up.
- Every snippet carries a plain-English purpose and impact that you read on the
  Snippets page. That is how you judge what was installed without reading code.
  **A new snippet cannot be saved without them**, so there is no such thing as
  a snippet on your site that nothing explains.
- **Full revision history.** Every save that changes the code keeps what was
  there before — whoever made the change. Nothing an agent overwrites is lost,
  and restoring an old version is itself undoable.

`snippet_save` · `snippet_list` · `snippet_get` · `snippet_revisions` ·
`snippet_toggle` · `snippet_delete`

### Files and raw code

Read, write and delete files under your site. Run PHP directly against the full
WordPress runtime. Run SQL against the database.

`read_file` · `write_file` · `edit_file` · `delete_file` · `list_directory` ·
`write_sandbox_file` · `execute_php` · `execute_sql`

This is the most powerful group and the one to think hardest about. It is what
makes "just fix it" possible, and it is on by default because almost nothing
else works without it. You can switch it off.

### Images

Search Openverse for free, properly licensed photographs, or generate an image.
Either way it lands in your media library with alt text.

`image_search` · `image_generate`

### Site administration

Options, plugins, themes, menus, user accounts and updates.

`manage_option` · `manage_plugin` · `manage_theme` · `manage_menu` ·
`manage_user` · `manage_updates`

### Site care

Looking after a site that already exists. Every one of these is a **report**:
it reads, counts and ranks, and changes nothing at all.

`speed_audit` scores the site out of 100 — homepage load time and size,
script and stylesheet counts, compression, caching plugin, object cache, the
weight of autoloaded options (naming the eight heaviest), database junk,
oversized images, plugin count and PHP version — and every finding names the
tool that fixes it. `db_cleanup_preview` counts revisions, auto-drafts,
trashed posts, spam and trashed comments, expired transients and orphaned post
meta, and deletes nothing. `plugin_security_check` flags plugins removed from
WordPress.org, plugins untouched for over two years, updates waiting, and —
with a free WPScan token — known vulnerabilities in the installed version.
`plugin_conflicts` finds two plugins doing the same job, PHP errors in the
debug log counted per plugin, plugins WordPress paused after a crash, and
plugins needing a newer PHP or WordPress; it never deactivates anything.
`broken_links` scans published pages and posts in batches of 15, Elementor
layouts included, and reports what no longer answers.

`speed_audit` · `db_cleanup_preview` · `plugin_security_check` ·
`plugin_conflicts` · `broken_links`

### Site care — fixing what the reports found

These five **change the site**, so they are a separate permission and they are
**switched off until you turn them on** (Keelhob → Settings → "Site care (fix &
clean up)"). You can always ask what is wrong; acting on it is your decision.

`image_compress` shrinks oversized images in place: it resizes anything wider
than 2048px (800–4000 if you ask for something else) and re-saves it at quality
82, up to ten images per call. **Your original file is copied into Keelhob's
backup folder before anything is written**, the new version is written to a
temporary file first, and if that file is not actually smaller the original is
left exactly as it was. Thumbnails are not regenerated — only the full-size
file changes, and its recorded width, height and file size are updated to
match. If a copy of the original cannot be taken, that image is refused rather
than overwritten.

`db_cleanup` deletes database junk: revisions (keeping the newest three per
post, or however many you say), auto-drafts and trashed posts older than a
week, spam and trashed comments, expired transients and orphaned post meta.
**This cannot be undone — not by Keelhob and not by WordPress.** It refuses to
run until `db_cleanup_preview` has been taken, so you always see the numbers
first; take a host backup before you say yes. It is capped per call (2,000
revisions, 1,000 auto-drafts, 1,000 trashed posts, 2,000 comments) and tells
you both what it removed and what is left.

`link_fix` replaces one broken link on one page with a new address, or removes
the link and keeps its words. It works in normal content and in Elementor
layouts (both the plain and the escaped form of the address, which is how
Elementor stores them), backs the page up first, and errors rather than
guessing if the old link is not there.

`media_set_alt` sets alt text on up to 50 images at once.

`plugin_install` installs a plugin from the official WordPress.org directory by
its slug — a slug only, never a URL or a ZIP file. It is installed switched off
unless you ask for it to be activated, and activating loads your homepage
straight afterwards: if the site broke, the plugin is switched off again and the
result says `rolled_back`. Keelhob never switches itself on or off.

`image_compress` · `db_cleanup` · `link_fix` · `media_set_alt` ·
`plugin_install`

### Agent-written abilities

This is the one feature where the AI writes PHP that later runs on your site as
part of Keelhob itself. It is **switched off when you install** (Keelhob →
Settings → "Let the AI write new code tools"), and while it is off the two tools
below do not exist: the agent is not told about them and cannot call them.

When an agent hits a job Keelhob has no tool for, it can write one. `ability_create`
submits a new ability — a name, a plain-English description, and a body of PHP.
`ability_improve` submits a new version of one that already exists, with a note
saying what changed.

**Nothing submitted this way runs until you approve it.** A new ability is saved
as *pending*. Pending code is not registered as a tool, so the agent that wrote
it cannot call it, and neither can any other agent. You approve it on **Keelhob →
Connect**, where the card shows you the full PHP, badges saying whether it changes
the site and whether it is risky, and a tick-box you have to tick before the
button works. Approving is a form in your own admin, not something an agent can
do on your behalf.

**Versions and rolling back.** Each approved version is kept. A new version
arrives pending in the same way, and the version already live keeps working while
the new one waits — so an improvement that is never approved breaks nothing. Each
version earns its own rating from its own runs, so you can see that version 2 is
doing worse than version 1 did. The Versions panel offers **Roll back to this**
on any retired version; rolling back makes it live again and retires the current
one. Nothing is deleted by rolling back, so you can roll forward again.

**The code-hash guard.** When you approve an ability, Keelhob records a hash of
the exact bytes you read. Before every run it hashes the stored code again. If
the two do not match, the ability is blocked and the agent is told to ask you to
review it again. So code edited in the database after approval — by a plugin, by
another agent, by anything with database access — does not run.

**What the gate does not protect against.** The hash proves the code is the code
you approved. It does not prove the code is safe. If you approve an ability that
deletes posts, it will delete posts, every time it runs, without asking again —
approval is once, and the running is forever after. An ability's own description
of itself is written by the AI that wrote the code, and the description is not
checked against what the code does: read the PHP, not the summary. The code runs
with the full powers of your site, the same as a snippet does, and it is not
sandboxed. Keelhob parse-checks the PHP and will not register a body that does
not parse, but parsing is not safety — broken code is caught, harmful code is
not. If you cannot read PHP, leave this capability off, or ask someone who can
to read the body before you tick the box.

**Switching the capability back off does not stop abilities you already
approved.** It stops the AI writing new ones and submitting new versions —
nothing more. An ability you approved stays live and keeps running, on purpose:
it went live because you read it and said yes, and un-registering it behind your
back would mean "Approve & go live" did not mean what it said. The review screen
gives you **Approve**, **Reject** and **Roll back**; there is no button that
retires a live ability. To stop one immediately, use the kill switch below —
`KEELHOB_SNIPPETS_OFF` stops agent-written abilities as well as snippets. To
remove one permanently today takes a database edit.

One thing Keelhob does by itself: if an ability causes a fatal error, it is
retired automatically and the error is recorded against it, so a crashing
ability takes your site down once rather than on every request.

`ability_create` · `ability_improve`

### Memory

Keelhob remembers things between sessions. Not chat history — decisions. "This
site's brand colour is #D08A55." "The client wants British spelling." "Do not
touch the pricing page." Every agent that connects reads the same memory, so you
explain a preference once.

`memory_save` · `memory_list` · `memory_get` · `memory_delete`

### Lessons

A lesson is a sharper form of the same idea. Memory is a store the agent has to
remember to read; a lesson is written straight into the description of the tool
it names, so the next agent reads it whether it went looking or not. "Rank Math
is the SEO plugin here, not Yoast", filed against `seo_meta`, is read on every
single connection.

That makes a lesson a standing instruction to something that can change your
site, so it is fenced: 300 characters of plain text each, six per tool at most,
and every one of them visible, editable and deletable by you on **Keelhob >
Activity**, with a note saying whether you or an agent wrote it. A lesson saved
by an agent is always recorded as the agent's — it cannot claim you said it.

`lesson_save` · `lesson_list`

### Ratings, and the run log behind them

Every tool call is recorded: which tool, what it was asked for, whether it
worked, how long it took, and where the request came from. That is the **run
log**, and it is what **Keelhob → Activity** shows you.

From that log each tool earns a **score out of 100** on this site specifically —
how often it has actually worked here, and what you said about it. A tool that
keeps failing on your host scores badly even if it works everywhere else, which
is the point: the score is about your site, not about Keelhob.

Next to each run you get **thumbs up and thumbs down**. Use them. A thumbs down
on a result that was technically a success but not what you wanted is the only
way the plugin can tell the difference. The buttons do not lie to you — the
control changes only after the server has confirmed the vote, and if the vote
fails it goes back to what it was and says so, rather than showing a vote that
was never recorded.

The score and the lessons are both read by the agent, in the tool's own
description, on every connection. So a tool you have rated badly arrives already
carrying that fact.

The run log is a **ring buffer**: old rows are trimmed as new ones arrive. It is
a record of what happened recently, not an audit trail you can rely on months
later. If you need to keep a run, copy it out.

### Automation

Run the agent unattended, on a schedule. A weekly blog post. A nightly check
that the site is up. A monthly SEO report.

`run_agent` · `task_save` · `task_list` · `task_run` · `task_delete` ·
`agent_runs`

There is also a **Telegram front end**: message your site from your phone, with
voice notes, photos, and approve-or-reject buttons for anything risky.

### Deploy

Export everything you built on a staging site as a bundle, and import it into
production.

`export_bundle` · `import_bundle` · `deploy_push`

Off by default.

### Checking the work

`site_readiness` scores the site against a build standard and tells you what is
not finished. That includes an **automatic security sweep** — the file editor,
guessable admin usernames, all three user-enumeration routes, HTTPS, the
authentication salts, debug exposure, public directory listings, version
disclosure and XML-RPC — reported as findings the agent has to clear before it
may call the site done. The parts no tool can judge, like whether your backups
actually restore, are listed separately as yours to check rather than quietly
omitted. `get_dashboard` gives a full status snapshot. `get_site_info` is the
smaller version an agent reads on connecting: WordPress and PHP versions, the
active theme and plugins, whether WooCommerce and a known SEO plugin are there,
post and page counts, and whether abilities are switched on. `seo_analyze` grades
a page. These are read-only — they report, they do not change anything.

`site_readiness` · `get_dashboard` · `get_site_info` · `seo_analyze`

### Telling you about it

`send_email` sends an email from your site, and `notify_owner` sends the same
message across every channel you have set up — email, Telegram, a webhook. They
are for the agent to reach you when something needs a person: an approval, a
summary, a warning. This is **off when you install**, because a tool that can
email real addresses is worth turning on deliberately.

`send_email` · `notify_owner`

### Reporting problems back to the project

When an agent gets properly stuck — a tool that will not do the job, a
capability that blocks the obvious route — it can report that to the Keelhob
project. `feedback_status` asks whether a report is due and produces the draft;
`feedback_send` sends it, or records that you said no.

**Nothing is sent without you seeing it first.** The agent has to show you the
whole draft, word for word, and get an explicit yes. Saying no is recorded so
you are not asked the same thing again.

`feedback_status` · `feedback_send`

---

## Staying in control

This is the part worth reading properly.

A connected agent can write PHP, read your database and change your files. So
the question is not whether to trust it completely — it is how much you hand
over, and how you get it back.

### Capability groups

Every tool belongs to a group, and every group has a switch in
**Keelhob → Settings**. Eight groups are **off when you install**, and stay off
until you personally turn them on.

| Group | Default | What it covers |
|---|---|---|
| Content | **On** | Posts, pages, media, taxonomies |
| Store (products & catalogue) | **On** | Products, coupons, store settings |
| Store (orders & reports) | *Off* | Reading and refunding real orders |
| Payments (money) | *Off* | Payment gateways and keys |
| Shipping | *Off* | Zones, methods, rates |
| Tax | *Off* | Tax settings and rates |
| SEO setup | **On** | Titles, descriptions, schema, sitemap |
| SEO optimization | **On** | Page scoring, redirects |
| Site care (read-only reports) | **On** | Speed, database junk, plugin security and conflicts, broken links |
| Site care (fix & clean up) | *Off* | Compressing images, deleting database junk, fixing links, alt text, installing plugins |
| Let the AI write new code tools | *Off* | The AI writing new PHP tools for this site, for you to approve |
| Design / Elementor | **On** | Pages, kit, settings |
| Snippets | **On** | Live code snippets |
| Raw code execution | **On** | Arbitrary PHP and SQL |
| Filesystem | **On** | Read, write and delete files |
| Users | *Off* | Creating, editing and deleting accounts |
| Site admin (plugins/themes/options) | **On** | Options, plugins, themes, menus |
| Updates | *Off* | Updating core, plugins and themes |
| Images | **On** | Search and generation |
| Email & notifications | *Off* | Sending email to real addresses |
| Autonomy (agent & scheduled tasks) | **On** | Unattended and scheduled runs |
| Memory & lessons (write) | **On** | Saving and deleting memory, and saving lessons the agent reads |
| Skills (write) | **On** | Creating and deleting skills |
| Deploy to production | *Off* | Bundles and pushing to production |

**Switching a group off genuinely removes its tools.** The agent does not see
them in its list, and calling one by name anyway is refused. It is not a hint.

The eight that start off are the ones where a mistake costs real money, reaches
real people, or is hard to undo: orders, payments, shipping, tax, user accounts,
updates, email, and deploying to production.

### The kill switch

If a snippet breaks your site badly enough that you cannot load the admin, put
this line in `wp-config.php`, above the *"That's all, stop editing"* comment:

```php
define('KEELHOB_SNIPPETS_OFF', true);
```

Every snippet stops immediately, and so does every agent-written ability — it is
one switch for all code an agent put on your site. Nothing is deleted. Remove
the line to switch them back on.

Edit that file however you normally would — FTP, your host's file manager, a
shell. **None of that needs a working site or a database.** That is the point:
this exists for a site you cannot load.

**Why a constant and not a button.** A snippet can take a site down without ever
erroring — the commonest way is returning a `503` from the front page, for a
coming-soon screen. Managed hosts poll your home page to decide whether the site
is alive, read that `503` as *this server is dead*, and stop routing to it.
`wp-admin` goes with it, and so does the connection an agent would need to undo
its own change. Every switch inside WordPress is unreachable at that point, so
the switch has to live outside it.

The plugin already switches off a snippet that causes a **fatal error**. The
constant covers the other case: one that runs exactly as written and breaks the
site anyway.

### About `eval()`

Keelhob runs AI-written PHP with `eval()`. That is not an accident and it is not
hidden, so here is the plain version.

**Where it is used.** Two places only: a PHP snippet you have saved, and the
`execute_php` tool. Nowhere else.

**Why not something safer.** There is no safer option that still does the job. A
snippet plugin that cannot run PHP is a text editor. Every competitor in this
category — WPCode, Code Snippets, all of them — does exactly the same thing.

**What guards it.** The code is parse-checked before it is stored, so code that
does not compile never runs. A snippet that throws a fatal error switches itself
off. After a risky write the plugin loads your front page to confirm the site
still works. And it only ever runs for a request that already authenticated as
an administrator.

**How to switch it off completely.** Turn off the capability group that allows
raw code, and use the kill switch constant below. Keelhob still builds pages,
products and SEO without it — you lose snippets and `execute_php`, nothing else.

### My site broke — do this, in this order

1. **Keelhob → Snippets.** Switch off the newest snippet. Most breakages are the
   last thing that was added, and this is one click.
2. **Keelhob → Activity.** It lists every change with a timestamp. Find the last
   thing that ran before the site went wrong.
3. **Cannot load `wp-admin` at all?** Use the kill switch constant above. It
   needs no database and no working site.
4. **Still broken?** The kill switch proves whether a snippet is to blame. If the
   site is still down with every snippet off, the cause is somewhere else —
   another plugin, the theme, or the host.

Nothing in that list deletes anything. A switched-off snippet keeps its code and
its revision history, so you can read it, fix it and switch it back on.

### It checks its own work

After every risky write, Keelhob fetches your front page and confirms it still
loads. If the change broke it, the plugin undoes what it can and tells the
agent, rather than reporting a cheerful success over a dead site.

### Activity: what it did, and when

**Keelhob → Activity** is the log. Every tool call, what it changed, and what
came back. If a site looks different and you do not know why, that is where you
look.

---

## Skills

A **skill** is a written procedure the agent loads when the job calls for it.
Not a prompt you retype — a document that lives on the site.

Twenty-six ship with the plugin, covering things like building a site from
scratch, Elementor page building, SEO, WooCommerce operations, security
hardening, a pre-launch audit, and cloning an existing site into WordPress.

One of them, `scroll-craft`, is for the kind of page where scrolling is the
experience: layers that travel at different rates, a section that holds still
while you read it, a clip that plays frame by frame as you move down. It builds
this out of three snippets and a custom page template, so what you get is real
code on your own site.

Two things worth knowing before you ask for one. **It does not work inside
Elementor** — a scroll-craft page is a page template, not an Elementor layout,
and the skill says so before it builds anything. And it is checked three times
over: on a desktop, on a 390×844 phone, and with reduced motion switched on, so
a visitor whose system asks for less movement still gets a page that reads.

They matter because they are the difference between an agent that *can* build a
page and one that builds a page the way a professional would — with the alt text
filled in, the heading order correct, the contrast measured and the meta
description written.

Some skills are **gates**: a skill can declare that work is not finished until a
named checker has run and passed. `pre-launch-audit` is the main one. That is
why Keelhob will sometimes tell you the site is not done when it looks done.

You can write your own, edit the shipped ones, and copy skills between sites.

`skill_save` · `skill_list` · `skill_get` · `skill_delete` · `skill_refresh` ·
`skill_lint`

---

## Snippets Studio

The **Keelhob → Snippets** screen is a full code editor in your admin: syntax
highlighting, conditional loading rules (only on this page, only for logged-in
users, only on mobile), revision history with one-click restore, and a
plain-English description of every snippet written for a non-programmer.

You can edit anything the agent wrote. It is your code.

---

## Run it from your phone, with no MCP client

You do not have to run Claude Code, or any desktop app, to use Keelhob. Add your
own API key in **Keelhob → Settings**, connect a Telegram bot, and you can just
message your website.

**What you can send.** Plain messages ("add a post about the new opening
hours"). Voice notes — it transcribes them. Photos — it uploads them to the
media library and can use them in a page.

**What comes back.** The agent does the work and replies with what it did.
Anything risky arrives as a question with **approve** and **reject** buttons, so
a change does not happen because you were vague.

**`/undo`** reverses the last thing it did, where reversing it is possible.

**Alerts, unprompted.** The site can message you first: a paid order, stock
running low, a snippet that crashed, an update waiting. You find out from your
phone rather than from a customer.

**Roles per chat.** A chat can be read-only, or allowed to publish, or allowed
to do everything. So a team chat and your own chat do not have the same powers.

**Setting it up** takes two things: an API key from Anthropic, OpenAI or Google,
and a bot token from Telegram's own [@BotFather](https://t.me/BotFather). The
Settings screen walks through both.

This whole feature belongs to the **Comms** capability group and is **off when
you install**.

---

## Moving a site from staging to live

Build on a staging site, then move the finished thing across in one step.

`export_bundle` packs up what the agent built — pages, Elementor layouts, the
design kit, menus, snippets, SEO settings, products — into a single bundle.
`import_bundle` unpacks it on the live site.

**What it is for:** doing the risky building somewhere nobody can see it, and
moving only the finished result.

**What it is not:** a backup tool, and not a full site clone. It carries what
Keelhob built. It does not carry your uploads folder, your users, or your
orders. Keep taking real backups.

This belongs to the **Deploy** capability group and is **off when you install**.

---

## Removing Keelhob

Deactivate and delete it from **Plugins**, the same as any other plugin.

**Everything it built stays.** Your posts, pages, Elementor layouts, products,
media and SEO settings are ordinary WordPress data. They were never stored in
some Keelhob format, and deleting the plugin does not touch them.

**What is removed** is Keelhob's own data: its tables for snippets, memory,
skills, scheduled tasks and the activity log, plus its settings and your access
token.

**Snippets stop working**, because snippets are run by the plugin. If a snippet
is doing something you want to keep, copy its code into your theme's
`functions.php` or another snippet plugin **before** you delete Keelhob.

If you only want to stop the agent and keep everything, do not delete anything —
switch off the master safety switch in **Keelhob → Settings**.

---

## Requirements and compatibility

| Item | Minimum |
|---|---|
| WordPress | 6.3 |
| PHP | 8.0 |
| Database | Whatever WordPress is already using |
| Runtime dependencies | None |

**Works with:** WooCommerce · Elementor (free or Pro) · Xpro Addons · Yoast ·
Rank Math · AIOSEO · SEOPress. All detected automatically, none required.

**Multisite.** Keelhob is built and tested for a single site, and multisite is
**not a supported configuration**. It is not on the roadmap either, so do not
wait for it.

If you run it on a network anyway: activate it **per site, not network-wide**.
Its tables are created with the per-site prefix, so each site gets its own
token, its own snippets and its own memory, and nothing crosses between sites.
That part works. It is simply not something being tested, so problems there will
not be found before you are.

**Scheduled tasks need WP-Cron.** Anything that runs on a schedule — a weekly
post, a nightly check, Telegram alerts — depends on WordPress's own scheduler.
Some hosts switch it off with `DISABLE_WP_CRON`, and some very quiet sites
simply never get a visitor to trigger it. Either way, tasks do not fail loudly:
**they just never run.** If a scheduled task never seems to fire, check that
first. The fix is a real server cron job hitting `wp-cron.php`, which your host
can set up in a minute.

---

## Troubleshooting

**The agent says it cannot connect.**
Check the address in the command matches your real site, including `https`. If
your site is behind a firewall or a security plugin, allow `/wp-json/`.

**The agent connects but refuses everything.**
Abilities are switched off. Go to **Keelhob → Settings** and turn them on.

**A tool it used to have has vanished.**
Its capability group was switched off. Check the table above.

**Something broke and I do not know what changed.**
**Keelhob → Activity**.

**My site is down after a snippet.**
Use the kill switch above.

**I am locked out of the connection.**
Ten failed attempts in fifteen minutes locks an address. Regenerate the token on
the Connect page; that clears it. The usual cause is two copies of the same
connection saved in your agent, both trying an old token.

**It worked, then stopped halfway through a big job.**
PHP gave up before the work finished. A long build — a whole store, a bulk
import — can outrun your host's `max_execution_time`. Nothing is corrupted; the
job stopped partway. Ask the agent to carry on, or ask your host to raise the
limit. Smaller requests also avoid it.

**A security plugin or firewall is blocking it.**
Wordfence, a host-level firewall or ModSecurity can block `/wp-json/` or strip
the `Authorization` header, and the symptom is a connection that fails with no
useful message. Allow `/wp-json/keelhob/` and make sure `Authorization` headers
are passed through. If your agent authenticates but every call comes back
unauthorised, a stripped header is the most likely cause.

**A scheduled task never runs.**
See WP-Cron under Requirements above. This is the usual answer.

**Updates never arrive.**
Keelhob updates itself from GitHub releases, not from WordPress.org. If your
host blocks outgoing HTTP requests, the check silently finds nothing. You can
always install the newest zip by hand over the top of the old one — your
settings, snippets and memory are kept.

---

## Why it is free

Keelhob is GPL, free forever, with no paid tier and nothing held back. There is
no "pro" version of this page.

It is funded by donations. If it saved you money,
[Ko-fi](https://ko-fi.com/keelhob) is how you say thanks.

The reason is simple: the thing being defended here is that you own your
website. A version of that idea you have to pay to unlock is not the idea.

---

## Privacy

Keelhob does not collect your content, your visitors' data, or anything you
build. The plugin checks for updates, and that check sends a random site
identifier and your WordPress version — nothing that identifies you or your
site. Full detail is on the [privacy page](/privacy).

---

## Getting help

- [Report a problem or ask a question](https://github.com/calflint/keelhob/issues)
- [Download](/download) — the plugin zip and the install steps
- [How risky this is, honestly](/docs/risk) — the full risk statement
- [Roadmap](/roadmap) — what is being built next
- [Why this exists](/story) — the reason someone built this for free
- [Privacy](/privacy) · [Terms of use](/terms)
- [Contact](/contact) — one person reads these
