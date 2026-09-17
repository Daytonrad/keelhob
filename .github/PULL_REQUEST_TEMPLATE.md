## What this changes, and why

<!-- The diff says what. Say why. What was broken, or what was missing? -->

## How I checked it

<!-- "php tests/run.php passes" is fine. If it touches a real site, say what you
     ran it against. -->

- [ ] `php tests/run.php` passes
- [ ] `php -l` clean on every file touched

## If this adds or changes a skill

<!-- The linter is the review rubric. It scores out of 100 and says what is
     wrong, so this is not a formality anyone has to interpret. -->

- [ ] `php bin/lint-skills.php` passes
- [ ] `php bin/build-skill-router.php` was run and the generated files are committed
      (`includes/class-skill-catalog.php`, `docs/SKILL-STANDARD.md`, the table in
      `skills/build-standard.md`) — you do not edit any of those by hand
- [ ] It declares `next_skills` and/or `verified_by`, and at least one other skill
      points at it. A skill nothing reaches is a skill that does not exist.

## If this changes behaviour

- [ ] Anything with decision logic has a test
- [ ] User-facing strings go through `__()` with the `keelhob` text domain
- [ ] Output escaped, input sanitised, writes check a capability and a nonce
- [ ] No stored `wp_options` key was renamed without a migration
- [ ] If you edited a shared README section, `php bin/build-readme-txt.php` was
      run and `readme.txt` is committed with it

## If this changes what leaves the user's server

- [ ] `PRIVACY.md` updated in this same PR
- [ ] `readme.txt` "External services" updated in this same PR

<!-- This one is not a formality. People trust this plugin with their whole
     site. If neither box applies, delete this section. -->
