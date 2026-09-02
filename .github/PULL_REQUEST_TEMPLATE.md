## What this changes, and why

<!-- The diff says what. Say why. What was broken, or what was missing? -->

## How I checked it

<!-- "php tests/run.php passes" is fine. If it touches a real site, say what you
     ran it against. -->

- [ ] `php tests/run.php` passes
- [ ] `php -l` clean on every file touched

## If this changes behaviour

- [ ] Anything with decision logic has a test
- [ ] User-facing strings go through `__()` with the `claudpress` text domain
- [ ] Output escaped, input sanitised, writes check a capability and a nonce
- [ ] No stored `wp_options` key was renamed without a migration

## If this changes what leaves the user's server

- [ ] `PRIVACY.md` updated in this same PR
- [ ] `readme.txt` "External services" updated in this same PR

<!-- This one is not a formality. People trust this plugin with their whole
     site. If neither box applies, delete this section. -->
