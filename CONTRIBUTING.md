# Contributing to Keelhob

Keelhob is free, GPL, and community-funded. Contributions of every size are
wanted and every one is credited in the release notes.

You do not need to be a PHP developer to help. The single most valuable
contribution to this project is a **skill**, and skills are plain markdown.

---

## Ways to help, roughly by how much they are worth

1. **Report a bug you actually hit.** A reproducible bug report against a real
   site is worth more than most donations. Include the WordPress version, PHP
   version, which MCP client, and what you asked the agent to do.
2. **Write a skill.** A skill is a markdown playbook the agent loads when a task
   matches it. If you know how to do something well in WordPress — a niche store
   setup, a membership flow, a migration — that knowledge is a pull request. No
   PHP required.

   **Read [`docs/SKILL-STANDARD.md`](docs/SKILL-STANDARD.md) first.** It is short,
   and it is what your pull request is reviewed against. Then run
   `php bin/lint-skills.php`, which scores your skill out of 100 and names
   exactly what is wrong — the same check CI runs and the same one a reviewer
   uses, so there are no surprises at review time.

   The part people miss: a skill has to declare where it sits among the others
   (`requires`, `next_skills`, `verified_by`). A skill nothing points at is only
   ever reached by luck, and a skill that points at nothing leaves the agent
   with nowhere to go when it finishes. Both fail the linter, both used to ship.

   Start from `skills/site-safety.md` for the shape.
3. **Improve the docs.** If something took you an hour to work out, that hour is
   a documentation bug.
4. **Fix a `good first issue`.** They are genuinely that.
5. **Translate.** Every user-facing string goes through `__()` with the
   `keelhob` text domain.
6. **Tell someone.** There is no marketing budget.

---

## Running the tests — no Docker needed

```bash
git clone https://github.com/calflint/keelhob.git
cd keelhob
php tests/run.php          # the whole unit suite, ~2,500 assertions
php tests/run.php snippets # only files matching "snippets"
```

That is the entire setup. The suite runs against a hand-written WordPress stub
in `tests/bootstrap.php`, on purpose: a test suite that needs a database and a
bootstrap is a test suite nobody runs.

Build the installable zip:

```bash
bash bin/build-zip.sh      # → build/keelhob.zip
```

### If you edit README.md

Three sections are shared with `readme.txt`: **How risky this is, honestly**,
**About `eval()`**, and **What it cannot do yet**. `README.md` is the source and
`readme.txt` is generated from it, so after editing any of those three:

```bash
php bin/build-readme-txt.php
```

Commit both files. `php tests/run.php` fails if you forget, and names the line.

Everything else in `readme.txt` — the Description, External services,
Installation, the changelog — is hand-written and belongs only there. The two
changelogs are deliberately different: `CHANGELOG.md` is for developers and
names files, `readme.txt`'s is for the person clicking update.

### Static analysis, if you want it

Optional. The suite above needs nothing installed; this needs Composer.

```bash
composer install           # dev tooling only
composer analyse           # PHPStan, level 1
composer lint              # php -l over every file
```

The plugin itself has **no** runtime dependencies and that is deliberate — `require` in
`composer.json` is empty on purpose and `vendor/` never reaches the shipped zip. Composer is
here for the analyser and nothing else.

PHPStan runs at level 1 with a committed baseline, and it analyses against the **PHP 8.0**
floor rather than whatever you happen to be running. If your change adds a finding, fix it
rather than adding a baseline entry — the header of `phpstan-baseline.neon` explains what is
already in there and why. Raising the level one rule class at a time is a genuinely useful
contribution.

### The live rig (optional, for anything touching real WordPress)

Some things a stub cannot honestly answer — path traversal, WooCommerce
behaviour, whether a snippet really deactivated. Those live in `tests/rig/`,
which runs against a real WordPress on SQLite with no Docker and no MySQL.
`tests/rig/README.md` builds it from scratch.

```bash
bash tests/rig/run-all.sh /path/to/wordpress
```

---

## What "done" means here

A pull request is ready when:

- [ ] `php tests/run.php` passes.
- [ ] `php -l` is clean on every file you touched.
- [ ] Anything with decision logic has a test. Anything that touches a real site
      has a rig check.
- [ ] User-facing strings use `__()` / `esc_html__()` with the `keelhob`
      text domain.
- [ ] Output is escaped, input is sanitised, and anything that writes checks a
      capability and a nonce.
- [ ] If it changes what leaves the user's server, **`PRIVACY.md` and
      `readme.txt` are updated in the same pull request.** This one is not
      negotiable — see below.

You do not need to bump the version; that happens at release.

---

## The rules that are not style preferences

Keelhob hands an AI agent unrestricted control of someone's WordPress site.
That makes a few things different from an ordinary plugin.

**Never widen what leaves the user's server without saying so.** The update
check sends exactly four fields and `PRIVACY.md` promises that as a closed list.
`tests/unit/test-updater.php` asserts it as an exact match, so adding a field
turns the suite red. That is deliberate. If the test fails because you added
something, the fix is not the test.

**Never add analytics, error reporting, or a third-party script to the admin.**
There are none today. Somebody chose to trust this plugin with their whole site.

**A capability group is a safety fence, not a feature flag.** Eight groups ship
switched off because they can email customers, edit users, change payment
gateways or deploy to production. If you add a tool, put it in a group — the
integrity test fails if you do not — and think hard before putting it in a
default-on one.

**Fail closed.** Signature checks, licence-shaped checks, anything verifying
anything: an error means no, never a fallback. A fallback is what an attacker
aims for.

**Never break an owner's stored data.** Renaming a `wp_options` key silently
wipes somebody's configuration. If a key must change, migrate it in
`Keelhob::maybe_upgrade()` and say so.

---

## Branch names, and the one way to get no CI

Name your branch with a normal prefix and a hyphenated description:

```
feature/telegram-voice-notes
fix/snippet-revert-loses-css
docs/rig-readme
```

`feature/`, `feat/`, `fix/`, `hotfix/`, `bugfix/`, `chore/`, `docs/`,
`refactor/`, `perf/`, `test/`, `ci/`, `build/`, `release/` and `revert/` all run
the full suite on every push, as do `main` and `dev`. The list lives in
[.github/workflows/ci.yml](.github/workflows/ci.yml).

**A branch outside that list gets no CI on push** — and it does not tell you.
GitHub has nothing to run, so the branch shows no check marks rather than a
failing one, and silence looks identical to "everything passed". This is worth
knowing because it has already caught someone out on this repository.

Two things make it harmless. **Open the pull request** and CI runs regardless of
the branch name; the `pull_request` trigger has no filter. And you can always
run the whole thing locally first:

```bash
php tests/run.php     # the suite
composer analyse      # PHPStan, if you have the dev dependencies
composer lint         # php -l over every file
```

If your prefix is missing from that list and it is a normal one, adding it is a
welcome pull request in itself.

---

## Commit messages and pull requests

Explain **why**, not what — the diff already says what. A message that says
"fixed the thing" costs the next person an hour of archaeology.

If you are fixing a bug, say what the bug actually did. If you are changing a
default, say what breaks for someone who upgrades.

---

## Reviewing a pull request

Written down because a review that depends on the maintainer's mood is a review
nobody can predict, and an unpredictable review is why people stop contributing.

**Four commands decide almost everything. CI runs them on every pull request, so
the answer is usually on the page before anyone opens a terminal.**

```bash
php tests/run.php                        # every assertion
composer analyse                         # PHPStan
php bin/lint-skills.php                  # the skill standard, scored out of 100
php bin/build-skill-router.php --check   # generated files are in step
```

| Outcome | What it means |
|---|---|
| All four green | Mechanically sound. Now read it for taste. |
| Any red | Send it back with the output pasted in. Not a judgement call, and not personal — the tool said it, not you. |

For a skill, `bin/lint-skills.php` prints a score and names every finding. That
output **is** the review: paste it. A contributor who can see the score move can
fix their own work without a conversation.

### The three things the tools cannot answer

These are the only parts that need a human, and they are worth being slow about:

1. **Should this exist at all?** A correct implementation of something the
   plugin should not do is still a no. Say so early — before the review, if the
   issue was discussed first — because the expensive version of this answer is
   the one that arrives after somebody spent a weekend.

2. **Does it make a promise the project cannot keep?** `PRIVACY.md` and
   `SECURITY.md` are commitments, not documentation. A change that sends
   something new over the network, stores something new, or widens what an agent
   may do without asking, has to be weighed against them — and if it is
   accepted, those files change in the same pull request.

3. **Will the next person understand why?** This codebase explains its reasoning
   in comments on purpose. A clever change with no explanation is a change
   somebody deletes in six months because it looked redundant.

### Saying no

Say it plainly, say it once, and say what would change the answer. Thank them
for the work either way — they spent their evening on this, and the cost of a
graceless no is every contribution that person would have made afterwards.

If the answer is "not now" rather than "no", say which it is. They are very
different sentences to receive.

---

## Code of conduct

Be decent. [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) has the long version.

## Licence

By contributing you agree your work ships under GPL-2.0-or-later, the same as
everything else here.
