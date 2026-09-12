# Privacy

Claudpress hands an AI agent full control of a WordPress site. That is a lot of
trust to ask for, so this document says exactly what leaves your server, and
every claim in it can be checked against the code — the file is named beside
each one.

## The short version

Claudpress makes **one** network request of its own accord: an update check.
Everything else happens only because you entered a key or connected a client,
and goes to a service you chose.

There is one more thing that can leave your server, and it never leaves without
you reading it first and saying yes: a **feedback report**. It is described in
full below.

Claudpress has no analytics, no session recording, no error reporting service,
no advertising, and no third-party scripts anywhere in the admin. The feedback
report is none of those, and the section on it says exactly why — including the
parts that are fair to be uneasy about.

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

## The feedback report

**Code:** [`includes/class-feedback.php`](includes/class-feedback.php)
**Endpoint:** `https://api.claudpress.com/v1/feedback`
**Frequency:** at most once a week, and only when you say yes.

### Why this exists

You ask the agent to build a hero section. It gets it wrong. You correct it. It
gets it wrong differently. Three rounds later it lands and you are tired.

That happens on every install and none of it reaches the project. One person
shrugs. Twenty people is the most useful thing this project could know, and
today it evaporates when you close the window.

### What is recorded on your own site

When **Claudpress → Settings → "Help improve Claudpress"** is ticked, the plugin
notes friction in a table in your own database. Nothing is sent by this.

It records only these things, and each is one row:

| Recorded | Detected by |
|---|---|
| A tool returned an error | the tool's own failure result |
| A permission blocked work that looked legitimate | the same result, read for refusal wording |
| The same tool called with near-identical arguments three or more times | a hash of the tool name and its arguments, within one burst of work |
| A short note the agent wrote about something that looked wrong | the agent, rarely — this is the only category no machine can see |

**It does not record your conversation.** Not a transcript, not a summary, not
your words. A row is a friction type, a tool name, a skill name, a count, and at
most 200 characters of note.

**Retention:** 30 days, or 50 rows, whichever comes first. Older rows are
deleted automatically.

**You can read every row and delete them all** at Claudpress → Activity →
Feedback log.

**Untick the box and nothing is recorded at all.** Not "recorded but not sent" —
not recorded. A promise with a "but" in it is not one anybody should believe.

### What is sent, and only after you approve it

About once a week — and only if something actually went wrong — the agent will
show you a draft and ask. The rule that requires it to show you the whole thing,
and to send nothing without an explicit yes, is rule 15 of the operating
protocol in [`includes/class-mcp-server.php`](includes/class-mcp-server.php). It
lives there rather than in a skill because a skill can be switched off, and
consent must not be something that can be edited away.

The report carries at most **five** problems, each one line of at most **200**
characters, plus exactly these fields:

| Field | Value |
|---|---|
| `site_id` | The same random UUID as the update check — not derived from your address |
| `plugin_version` | The Claudpress version running |
| `events` | Up to five entries of: friction type, tool name, skill name, attempt count, note |
| `omitted` | How many problems did not fit, as a number |

**That is the complete list**, and `tests/unit/test-feedback-offer.php` asserts
it as an exact match, so adding a field turns the test suite red.

### What is stripped before anything is stored or sent

Redaction runs twice — once before a row is written to your own database, and
again before anything leaves. It is PHP, not guidance, for the same reason as
rule 15.

Removed: web addresses and bare domains, email addresses, IPv4 and IPv6
addresses, absolute file paths, key-shaped strings, anything following the words
`key`, `token`, `password` or `secret`, and **your site's own name and
hostname**.

[`tests/unit/test-feedback-redaction.php`](tests/unit/test-feedback-redaction.php)
plants each of those as a deliberate leak and fails if any survives. That file
is what makes this section checkable rather than merely stated.

### The part it is fair to be uneasy about

If you type a client's name into a note during the approval step, that name is
sent. Redaction catches addresses and keys; it cannot know that "Acme Dental" is
a customer. It is your own text, it is on screen in front of you when you are
asked, and it goes as written. That is the honest limit of this design.

### What the endpoint does with it

Stores the fields above, keyed by `site_id`. **No IP address is logged.** Rows
older than 90 days are deleted. The public page on claudpress.com shows only
grouped counts — how many distinct sites hit a given problem — and never a note.

### Deleting what was already sent

**Claudpress → Activity → Feedback log → "Clear, and delete what was sent."**

This sends `DELETE /v1/feedback?site_id=…` and removes every report the server
holds for your site, then clears the local log. Anonymous data normally cannot
honour a deletion request because nothing can find it; your site knows its own
UUID, so it can ask.

### Turning it off

**Claudpress → Settings → untick "Help improve Claudpress."** Recording stops
immediately and you are never asked again. Nothing else about the plugin
changes.

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
