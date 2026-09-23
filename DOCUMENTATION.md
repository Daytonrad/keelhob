# Keelhob documentation

Keelhob is a WordPress plugin that lets an AI agent run your website.

You install it, connect your agent once, and then you ask for things in plain
words. The agent writes the pages, builds the layouts, adds the products, fixes
the code and checks its own work — on your hosting, in your database, under your
control.

This page is the whole manual. You do not need GitHub to read it, and you do not
need to be a developer to follow it.

**Free, GPL, no paid tier.** Everything described here is in the one download.

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

1. Download `keelhob.zip` from the [latest release](https://github.com/calflint/keelhob/releases).
2. In WordPress: **Plugins → Add New → Upload Plugin**.
3. Choose the zip, then **Install Now**, then **Activate**.
4. A **Keelhob** item appears in the admin menu.

That is the whole installation. No configuration file, no account, no key.

---

## Connect your agent

Open **Keelhob → Connect**. The page prints a command with your real address and
your real token already filled in. It looks like this:

```bash
claude mcp add keelhob-yoursite --transport http https://yoursite.com/wp-json/keelhob/v1/mcp --header "Authorization: Bearer kh_xxxxxxxx"
```

Copy it, run it in your terminal, and start a new session with your agent.

For **Claude Desktop, Cursor or Codex**, the same page gives you a JSON block to
paste into their config instead.

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

Keelhob gives the agent **68 tools**, grouped by job. You never call these
yourself; you ask for an outcome and the agent picks the tools.

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
- Full revision history, so you can restore any earlier version.

`snippet_save` · `snippet_list` · `snippet_get` · `snippet_toggle` ·
`snippet_delete`

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

### Memory

Keelhob remembers things between sessions. Not chat history — decisions. "This
site's brand colour is #D08A55." "The client wants British spelling." "Do not
touch the pricing page." Every agent that connects reads the same memory, so you
explain a preference once.

`memory_save` · `memory_list` · `memory_get` · `memory_delete`

### Automation

Run the agent unattended, on a schedule. A weekly blog post. A nightly check
that the site is up. A monthly SEO report.

`run_agent` · `task_save` · `task_run` · `task_delete` · `agent_runs`

There is also a **Telegram front end**: message your site from your phone, with
voice notes, photos, and approve-or-reject buttons for anything risky.

### Deploy

Export everything you built on a staging site as a bundle, and import it into
production.

`export_bundle` · `import_bundle` · `deploy_push`

Off by default.

### Checking the work

`site_readiness` scores the site against a build standard and tells you what is
not finished. `get_dashboard` gives a full status snapshot. `seo_analyze` grades
a page. These are read-only — they report, they do not change anything.

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
| Memory (write) | **On** | Saving and deleting memory |
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

Every snippet stops immediately. Nothing is deleted. Remove the line to switch
them back on.

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

Twenty-four ship with the plugin, covering things like building a site from
scratch, Elementor page building, SEO, WooCommerce operations, security
hardening, a pre-launch audit, and cloning an existing site into WordPress.

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

## Requirements and compatibility

| Item | Minimum |
|---|---|
| WordPress | 6.3 |
| PHP | 8.0 |
| Database | Whatever WordPress is already using |
| Runtime dependencies | None |

**Works with:** WooCommerce · Elementor (free or Pro) · Xpro Addons · Yoast ·
Rank Math · AIOSEO · SEOPress. All detected automatically, none required.

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
the Connect page; that clears it.

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
- [Roadmap](/roadmap) — what is being built next
- [Contact](/contact)
