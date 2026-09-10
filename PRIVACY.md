# Privacy

Claudpress hands an AI agent full control of a WordPress site. That is a lot of
trust to ask for, so this document says exactly what leaves your server, and
every claim in it can be checked against the code — the file is named beside
each one.

## The short version

Claudpress makes **one** network request of its own accord: an update check.
Everything else happens only because you entered a key or connected a client,
and goes to a service you chose.

Claudpress has no analytics, no session recording, no error reporting service,
no advertising, and no third-party scripts anywhere in the admin.

---

## The update check, and the public count

**Code:** [`includes/class-updater.php`](includes/class-updater.php)
**Endpoint:** `https://api.claudpress.com/v1/version`
**Frequency:** at most twice a day, cached in between.

Claudpress is not in the WordPress.org plugin directory, so nothing tells you
when a new version exists unless the plugin asks. The same request is how the
project counts how many sites are running it.

That count matters more than it sounds. It is the only signal the project has
about whether to keep going, and it is what a contributor or a sponsor looks at
before deciding you are worth their time. WordPress.org derives its own "active
installs" number from exactly this mechanism.

### What is sent

| Field | Value | Why |
|---|---|---|
| `site_id` | A random UUID generated once on your site | So two check-ins from the same site are not counted twice |
| `version` | The Claudpress version running | To know which release to offer, and what is still in the wild |
| `wp` | Your WordPress version | To know which versions still need supporting |
| `php` | Your PHP version | The same |
| `locale` | Your site language, e.g. `en_US` | Whether translating is worth doing, and into what |
| `multisite` | `1` or `0` | Multisite breaks assumptions all over a plugin. Knowing whether anyone runs it decides whether to keep testing for it |
| `woo` | `1` or `0` — is WooCommerce active | Half this plugin is store features. This says whether that half is used |
| `builder` | `auto`, `elementor`, `solace`, … — the configured page builder | Which builder skills are worth the effort |
| `ai` | `1` or `0` — whether AI abilities are switched on | Installs that never switch it on are a different problem from installs that do |
| `caps` | The **names** of the capability groups you have switched on, e.g. `content,seo` | Eight groups ship OFF because they are risky. This says whether people find the switch, or whether it is buried |
| `runs` | How many unattended runs happened in the last 24 hours, as a number | Whether anyone actually uses automation, or only ever drives it by hand |

**That is the complete list.** In particular it does **not** send: your site
address, your domain, your site name, any email address, any username, any post
or product or order, any API key, any file, any prompt, or any record of what a
connected agent did.

It also does **not** send what your automation costs you or how many tokens it
used. Your spend is your business. `runs` is a count of how many times something
ran, and nothing about what it did or what it was worth.

Every field above obeys one rule: it has to be true of a thousand other sites.
None of them, alone or together, says whose install this is.

**On `caps` specifically.** It is fair to ask whether "this install has code
execution switched on" is a thing you want leaving your server. It is worth
saying plainly why it is safe here: the endpoint stores no site address, no
domain and no IP, so the most anyone could ever learn — including us, and
including anyone who broke into the endpoint — is that *some* anonymous UUID
somewhere has a capability enabled, with no way to find the site it belongs to.
The reason that holds is the same reason `site_id` is random rather than derived
from your URL. If that ever stopped being true, this field would have to go.

**Why these and not an analytics service.** A free project still has to decide
what to build next, and until now this endpoint answered one question — how
many — and no others. It could not say whether anyone comes back after week one,
which risky capabilities people deliberately enable, or whether the PHP floor
can move. The alternative to a dozen anonymous integers was guessing.

The alternative we did **not** take was adding PostHog or anything like it to
the plugin. That would have meant a third-party script in your admin, on a
plugin that already has more access to your site than almost anything else
installed on it. This request already existed, was already documented, and
already had an off switch.

`site_id` is a `wp_generate_uuid4()` value stored in your own database. It is
**not** derived from your URL, domain, or anything else about you, so it cannot
be reversed into a site. Delete and reinstall the plugin and you get a new one —
which undercounts, and that is the right way for the error to go.

### What the endpoint does with it

Stores one row per `site_id`: the id, the versions, and when it was last seen.
The row is updated, never appended to. **IP addresses are not logged.** A row not
seen for 90 days is deleted.

The published figure is *"sites running now"* — distinct IDs seen in the last
seven days. Not total installs, which can only ever go up and is therefore a
number that flatters.

### Turning it off

**Claudpress → Settings → "Do not include this site in the public count."**

The update check then goes straight to `https://api.github.com` instead. You
still get every update, including security ones. An opt-out that quietly stops
your security updates is not an opt-out, so this one does not.

---

## Skill updates

**Code:** [`includes/class-skills-remote.php`](includes/class-skills-remote.php)
**Endpoint:** `https://github.com/calflint/claudpress/releases/latest/download/`

Downloads two public files. No key, no account, and **nothing about your site is
sent** — it is a plain file download, the same as clicking the link yourself.

Every skill is verified against an Ed25519 signature before it is written. An
unsigned or unverifiable skill is discarded, not installed. This is not
politeness: a skill is an instruction a connected agent follows on a live site,
so an attacker who took over the host could otherwise instruct every site at
once. The signature is what removes the host from the trust chain.

Skills you wrote yourself are never overwritten.

---

## Everything else needs a key or a connection from you

None of the following happens until you set it up, and each goes to a provider
you picked. `readme.txt` carries the terms and privacy links for each.

- **Your MCP client** (Claude Code, Claude Desktop, Cursor, Codex…). This is the
  largest disclosure here and the whole point of the plugin: an agent you
  connect can read your posts, orders, customers and files, and what it reads
  goes to that client's model provider under their terms.
- **Autonomous runs** — your instruction, site name and URL, stored memory, tool
  list and tool results go to the AI provider you chose, so it can decide the
  next step.
- **Image generation** — the prompt text goes to OpenAI or Google.
- **Image search** — your search term goes to Openverse. No key needed.
- **Notifications** — to your own email, your own webhook, your own Telegram bot.
- **Telegram chat** — your messages, and the agent's replies about your site, go
  through the Telegram Bot API. Messages are also stored in your own database as
  history until you clear them.
- **Voice notes** — the audio goes to the transcription provider you chose.
- **Deploy** — your content bundle goes only to the Claudpress site whose URL and
  token you configured.

## Your data stays yours

API keys are encrypted in your database and never exported in a migration
bundle. Secrets are redacted from the activity log
([`includes/class-logger.php`](includes/class-logger.php)). Deleting the plugin
keeps your snippets, memory, skills and history by default, so reinstalling
puts you back where you were.

## Something wrong here?

If this document and the code ever disagree, the code is the bug and this is the
promise. Open an issue.
