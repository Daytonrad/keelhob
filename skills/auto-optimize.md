---
name: auto-optimize
title: Tune up this site
description: One job that measures the site, fixes what is safe to fix, and leaves a before-and-after report the owner can read — speed score, oversized images, database junk, missing image alt text, vulnerable plugins, plugin conflicts and broken links. Use for "tune up my site", "optimize my site", "speed up my site", "clean up my site", "my site is slow", "make my site faster", "run a health check", "fix whatever is wrong", or a monthly maintenance pass. Not for search rankings — that is seo-audit-and-fix.
enable_agentic: true
enable_prompt: true
role: procedure
requires: site-safety
next_skills: seo-audit-and-fix, security-standard
verified_by: build-standard
---

# Tune up this site

## When to use

The owner wants the site measured and improved and has not said what to improve.
One pass, one report, numbers at both ends.

## When NOT to use

- Rankings, traffic, meta titles, schema → `seo-audit-and-fix`.
- Hardening, logins, a site you suspect is already compromised → `security-standard`.
- One named problem ("this image is huge") → use that one tool directly. This
  skill is the whole sweep and costs far more.

## The shape of the job

Measure, ask, fix, measure again, report. The order matters: without the first
measurement "I made your site faster" is a claim with no evidence.

## 1. Measure first — nothing is changed yet

Run all five read-only reports. None of them touches the site.

- `speed_audit` — the score, the findings, and the biggest images
- `db_cleanup_preview` — counts the junk rows and does not delete any
- `plugin_security_check` — known vulnerabilities, riskiest first
- `plugin_conflicts` — overlaps, paused plugins, errors in the debug log
- `broken_links` — paginated. Keep calling it with the `next_offset` it returns
  until `done` is true. Stopping early reports a wrong number.

Write the numbers down before going on. They are the "before" column and you
cannot get them back once you start fixing.

## 2. Show the owner, then ask

Give them one short list: the score, how many images are too big, how many junk
rows, how many images have no alt text, any vulnerable plugin, any broken link.

Then ask for a yes. Say plainly which parts cannot be undone.

**Do not deviate from this gate.** `db_cleanup` deletes rows and nothing in this
plugin can put them back. Never run it on the same turn the owner said yes to
"optimize my site" in general — the yes must name the deletion.

## 3. Fix, in this order

Safest first, so that if something goes wrong the riskiest step never ran.

1. **`media_set_alt`** — alt text. Writes one meta field, reversible, affects
   nothing visual. Describe what is in the picture; never paste the filename.
2. **`image_compress`** — up to 10 attachment IDs per call. Feed it the biggest
   ones `speed_audit` listed. It banks a copy of each original first, so it is
   undoable. It does NOT rebuild thumbnails.
3. **`link_fix`** — one link per call, from the `broken_links` results. A new
   address replaces the link; an empty one removes the link and keeps the words.
4. **`db_cleanup`** — last, and only with the explicit yes from step 2. Ask the
   owner to take a host backup first and wait for them to confirm it exists.

A vulnerable or conflicting plugin is **not** yours to fix. Report it and let
the owner choose. Never deactivate a plugin on a live site: the one you switch
off may be the one taking the payments.

## 4. Measure again

Run `speed_audit` once more. Report the real number, including when it did not
move. A score that stays the same is a true result and the owner needs it.

## 5. Leave the report where they can find it

There is no report store in this plugin, so make one they can open:

- `create_post` a **private** page titled `Site tune-up — <today's date>` with a
  before/after table, what was changed, and what was left for the owner.
- `notify_owner` with one line: the score before, the score after, and the link.

## Finish check

Copy these and tick them. Do not report done with an unticked box.

- [ ] all five reports ran, and `broken_links` ran to `done: true`
- [ ] the before numbers were shown to the owner before anything changed
- [ ] the owner said yes, and said yes to deletion separately
- [ ] every fix was verified by reading it back, not by a success flag
- [ ] the front end still loads (`site-safety` verify step)
- [ ] `speed_audit` ran again and the real after-score is in the report
- [ ] the private report page exists and `notify_owner` carries its link

If the front end broke, stop fixing, undo the last write, and tell the owner.
If a fix fails twice, leave it, write it in the report as not done, and move on.
Never try the same fix a third time.

## Related skills

- **`site-safety`** — the verify-after rule every write here obeys.
- **`seo-audit-and-fix`** — the same shape for search instead of speed.
- **`security-standard`** — where a vulnerable plugin finding should go next.

## Routing examples

- fires: tune up my site
- fires: my site is slow, fix it
- fires: clean up the database and compress the images
- skips: why am I not ranking on Google
