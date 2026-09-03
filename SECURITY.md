# Security policy

## Reporting a vulnerability

**Do not open a public issue for a security problem.**

Report it privately through GitHub's
[security advisory form](https://github.com/calflint/claudpress/security/advisories/new),
which is private between you and the maintainer until a fix ships.

You will get an acknowledgement within 72 hours. If you do not, assume it was
missed and say so publicly enough to get attention — a silent maintainer is a
worse outcome than an early disclosure.

Please include what you can: the version, how to reproduce it, and what an
attacker gets out of it. A working proof of concept is welcome and never held
against you.

There is no bug bounty. This project has no revenue. What there is: your name in
the release notes and the advisory, unless you would rather stay anonymous.

## What is in scope

Anything that lets someone do more than they should:

- Reaching the MCP endpoint without valid credentials.
- Escaping the filesystem base directory.
- Privilege escalation — a subscriber reaching an admin-only tool.
- Secrets leaking into the activity log, page source, an export bundle, or an
  error message.
- Bypassing a capability group that the owner switched off.
- A skill or snippet from the update feed executing without a valid signature.
- Anything in the admin that is missing a nonce or a capability check.

## What is not a vulnerability

**A connected agent can run arbitrary PHP and SQL. That is the product, not a
bug.** `execute_php`, `execute_sql` and the filesystem tools exist so an agent
can do anything WordPress can do. A report that says "an authenticated agent
could delete the database" describes the feature.

The line is *authorisation*: the question is always whether someone can do
something **without the owner's credentials and consent**, not whether the
thing is dangerous. Almost all of it is dangerous. The plugin says so on every
admin screen.

Also out of scope: vulnerabilities in WordPress itself, in WooCommerce, or in a
third-party plugin — please report those to them.

## Where the risk actually is

Two places worth aiming at, stated openly because pretending otherwise helps
nobody:

**The skill feed.** A skill is an instruction a connected agent follows on a
live site. Someone who could get an unsigned skill installed would be
instructing every site at once — worse than any single bug in the plugin. The
Ed25519 signature check in `includes/class-skills-remote.php` is what removes
the host from the trust chain. Anything that gets past it is the most serious
report you can file.

**The token.** The bearer token is full site control. It is stored as a hash and
redacted from logs, but anywhere it is not, that is a real finding.

## Supported versions

The latest release. This is a one-maintainer project; backporting to old
versions is not realistic, and saying so is more honest than a table that
implies otherwise.

## Deployment advice

Claudpress is documented as development and staging only. If you have put it on
a production site, that is your call to make — but enable only the capability
groups you need, keep backups, and treat the endpoint token like a root
password, because it is one.
