---
name: launch-readiness-and-compliance
title: Launch readiness and compliance
description: The gate a site passes before anyone sees it — the twenty things that make a site read as cheap, and the legal duties a website owes its visitors. Covers cookie consent and GDPR/ePrivacy done correctly (no analytics before Accept), privacy policy, CCPA/CPRA, accessibility to WCAG 2.2 AA and the European Accessibility Act, and e-commerce duties like returns, delivery terms and business identity. Use for "is my site ready", "am I allowed to", "check my cookie banner", "GDPR", "privacy policy", "do I need terms", "is this legal", "review before launch", or automatically before any site or store goes live. Ask what the site is for first — the rules that apply differ.
enable_agentic: true
enable_prompt: true
role: gate
surface: any
next_skills: store-launch-and-deploy, security-standard
verified_by: pre-launch-audit
---

# Launch readiness and compliance

## When to use

Anything to do with cookie consent, GDPR or ePrivacy, a privacy policy, accessibility duties, or the legal obligations a shop owes a buyer. Also the last gate before a site is shown to the public.

## When NOT to use

Not a substitute for a lawyer, and not the place for design judgement. How a page looks is `web-design-master`; whether it may lawfully load a tracker is here.

## Read this first

1. **This skill has two halves, and only one of them is conditional.** The twenty in §1
   are about quality, and they apply to **every** site — a shop, a one-page business card,
   a personal blog, a charity, a site that sells nothing and collects nothing. The legal
   duties in §2 and §3 depend on what the site does and where its visitors are. Never let
   "this site does not sell anything" or "we are not in the EU" skip §1. A site with no
   shopping cart can still have a headline nobody understands, a dead button and a wall of
   text, and those are the things that make it look amateur.
2. **Ask what the site is for before you check the legal half.** A shop, a service
   business, a blog and a SaaS owe different things. Raising all of it on a personal blog
   is how a checklist gets ignored.
3. **The twenty** in §1 are the difference between a site that looks paid-for and one that
   looks generated. Detect them, do not just warn about them.
4. **The commonest legal failure is not a missing banner — it is a banner that does nothing.** Analytics firing before Accept is pressed is the single most widespread violation on vibe-coded sites.
5. **The owner may decline any of it.** Record the decision and move on. What is never acceptable is silence.
6. **This is what the rules require, not legal advice.** Say so. A real lawyer is the answer for anything contested.
7. **Write what you found to memory** so the next session resumes instead of re-asking.

---

## 0. Ask first

These questions scope **§2 and §3 only**. §1 runs regardless of every answer here,
including "it sells nothing" and "there are no European visitors" — the twenty are
about whether the site looks professional, and that is not a matter of jurisdiction.

- **What is this site for?** Shop · service business · blog or publication · SaaS or app · portfolio · charity.
- **Where are the visitors?** EU/UK visitors bring GDPR and ePrivacy. California brings CCPA/CPRA. Most sites get both, because the web is not regional.
- **Does it take money?** That turns on the whole e-commerce section.
- **Does it collect anything at all** — a contact form, a newsletter, analytics? If genuinely nothing, parts of §3 drop away and you should say so rather than manufacturing work. §1 does not drop away.

---

## 1. The twenty — what makes a site read as cheap

Each has a way to check it. A finding you cannot demonstrate is an opinion.

| # | Problem | How to detect it |
|---|---|---|
| 1 | **Too many pop-ups** | Load the home page and count anything that covers content unasked — newsletter modal, chat bubble, cookie wall, exit-intent. More than one is one too many. |
| 2 | **Confusing headline** | Read the `<h1>` alone. If a stranger cannot say what is sold and to whom, it fails. "Empowering your digital journey" fails. |
| 3 | **No clear call to action** | Is there one obvious action above the fold, styled as the primary thing on screen? |
| 4 | **AI-slop imagery** | Impossible hands, melted text, uncanny faces, that plastic teal-and-purple gradient look. Check every generated image. |
| 5 | **Competing calls to action** | Count primary buttons per screen. Two equally weighted choices means the visitor picks neither. |
| 6 | **Slow pages** | Run the performance check from `seo-audit-and-fix`. Mobile below 90 is a finding here as well as there. |
| 7 | **Walls of text** | Any paragraph over ~4 lines on mobile, or a section with no heading, list or image to break it. |
| 8 | **Generic AI copy** | Grep for the tells: "unlock", "leverage", "seamless", "elevate", "in today's fast-paced world", "we understand that". Any hit is a rewrite. |
| 9 | **Vague value proposition** | Can you name one concrete thing the visitor gets, with a number or a specific? "Better results" is not one. |
| 10 | **No obvious next step** | At the bottom of every page: is there one? A page that dead-ends wastes the visit that got there. |
| 11 | **Poor mobile design** | Render at 375px. Look for horizontal scroll, tap targets under 44px, text under 16px, an overlapping header. |
| 12 | **Stock images** | The handshake, the diverse team laughing at a laptop. Real photos beat perfect stock every time; if there are none, abstract or typographic beats fake. |
| 13 | **Too many animations** | More than one signature moment per page, or anything moving that the visitor did not cause. See `motion-and-interaction`. |
| 14 | **Video backgrounds** | Almost always a mistake: heavy, unreadable behind text, and a Core Web Vitals cost. Needs a real reason. |
| 15 | **Unnecessary form fields** | Count them. Every field costs completions. Ask why each one exists; "nice to have" means delete it. |
| 16 | **Hidden pricing** | If it is deliberately hidden to force a call, say plainly that this loses more visitors than it captures. Some businesses genuinely cannot list prices — a range or a "from" still beats nothing. |
| 17 | **No social proof** | Reviews, testimonials, logos, case studies, a real count. **Never invent any of it** — operating protocol rule 10. If there is none, say so and suggest how to get some. |
| 18 | **Broken links or buttons** | Crawl every link and `wp_remote_head()` it. Click every button and confirm it goes somewhere. A dead "Buy" button has been live on real sites for months. |
| 19 | **Distracting navigation** | More than about seven top-level items, or a mega-menu on a six-page site. |
| 20 | **Unnecessary cookie banner** | The one people never think about: **if the site sets no non-essential cookies, it needs no banner.** Removing an unused analytics script removes the banner along with it. Check what is actually set before assuming a banner is required. |

Score it out of twenty and report which failed, with the evidence. Anything
the owner deliberately keeps is recorded as their decision, not as a pass.

---

## 2. Cookies, consent and GDPR — the part most sites fail

The rule, stated plainly: **in the EU and UK you may not set non-essential
cookies, or run analytics, advertising or tracking scripts, until the visitor
has actively agreed.** Not "until they reject". Before consent, nothing but
what the site strictly needs to work.

Most vibe-coded sites get this exactly backwards: the analytics tag fires in
`<head>` on every page load, and the banner is decoration on top of tracking
that already happened.

### Check it, do not assume it

A banner existing proves nothing. Verify:

1. Open the site with **no cookies at all** — a clean browser profile, or `wp_remote_get()` with no cookie jar.
2. **List every cookie set** and every third-party request made **before touching the banner**. Look for `_ga`, `_gid`, `_fbp`, `_gcl_au`, anything to `google-analytics.com`, `googletagmanager.com`, `facebook.net`, `hotjar`, `clarity.ms`.
3. Anything non-essential in that list is a **violation**, and it is a finding regardless of what the banner says.
4. Press **Reject** and check again. Nothing should start.
5. Press **Accept** and check that tracking now starts — a banner that does nothing in either direction is just as broken.

### What a compliant setup requires

- **A banner that gates, not announces.** Scripts must be blocked until consent, not merely reported.
- **Reject as easy as Accept.** Equal prominence, same number of clicks. A grey "Manage preferences" against a big green "Accept all" is a known enforcement target.
- **No pre-ticked boxes.** Silence is not consent, and neither is scrolling.
- **Granular categories** — necessary, analytics, marketing, preferences — each independently refusable.
- **Withdrawable** as easily as it was given: a persistent link to reopen the settings.
- **Logged.** Record what was consented to and when.
- **A privacy policy that is actually true**: what is collected, why, the legal basis, who it is shared with, how long it is kept, and how to request deletion. A generic template naming services the site does not use is worse than none — it is a false statement about data handling.
- **Google Consent Mode v2** where Google tags are used; it is how those tags are told to hold off.

**The cheapest compliant answer is often to collect less.** A brochure site
that drops Google Analytics for a privacy-respecting alternative — or for
nothing — needs no banner, no consent log and a much shorter policy. Offer
this. It is frequently the right call and nobody suggests it.

Keelhob's own `site_readiness` tool checks whether privacy and terms pages
exist. It cannot check whether the banner works. That part is this skill's job.

---

## 3. Beyond cookies

Raise only what applies to the site in front of you.

### Every site

- **Privacy policy** — reachable from every page, usually the footer.
- **Contact route that works.** Send a real test through the form and confirm it arrives. A contact form that silently fails is the most common broken thing on a small site.
- **Accessibility to WCAG 2.2 AA.** In the EU the European Accessibility Act extends this to most consumer-facing commercial sites; several other jurisdictions have their own. The floor: text contrast 4.5:1, every image with meaningful `alt`, every form field with a real `<label>`, keyboard reachable in a sensible order, visible focus, no information carried by colour alone. Most of it is also cheap Lighthouse points.
- **Say who you are.** Many countries require a business name and contact address on a commercial site. Germany's *Impressum* is the strictest, but it is far from the only one.

### If it collects personal data

- **A lawful basis** for each purpose, and only what is needed for it.
- **Access and deletion requests** — a route to ask, and someone who will act on it. WordPress has export and erase tools built in under Tools; point the owner at them.
- **Email marketing needs its own consent.** A checkout box is not permission to send a newsletter. Every message needs a working unsubscribe.
- **California (CCPA/CPRA)** — a "Do Not Sell or Share My Personal Information" link where behavioural advertising is used, and a policy section covering it. It applies by visitor location, not by where the business sits.
- **Children** — anything aimed at or likely to attract under-13s (US) or under-16s (parts of the EU) carries much heavier duties. Flag it rather than trying to solve it.

### If it takes money

- **Business identity**: legal name, address, contact, and company or VAT number where required.
- **Prices that are honest** — including tax where the law says so, with delivery costs shown before the final step, not revealed at checkout.
- **Terms of sale** covering what is bought, when it arrives, and what happens when it does not.
- **A returns and refunds policy.** In the EU/UK most distance sales carry a **14-day right of withdrawal** with no reason needed; some categories are exempt. Say which applies.
- **Delivery terms** — where you ship, how long, what it costs.
- **Payment security.** Never handle raw card data. Use the gateway's hosted fields or redirect. If a form on the site is capturing card numbers, that is a blocker, not a finding.
- **Subscriptions** need the renewal price, the interval and the cancellation route stated before purchase.
- **Cancellation must be as easy as signing up.** Increasingly enforced.

Cross-check against `store-launch-and-deploy` for the mechanics of turning
payments on, and `security-standard` for the hardening around them.

---

## 4. How to report it

Group findings into three, and be honest about which is which:

- **Blocker** — do not launch. A dead checkout, tracking without consent, card data on the site, no privacy policy on a site that collects data.
- **Should fix** — launch is possible but the site is worse for it. Most of the twenty live here.
- **Owner's call** — a real choice with a real trade-off. Hidden pricing, no social proof yet, an animation they love.

**The owner may decline anything.** Record it: what they were told, what they
chose, and when. That record is the point — it turns "nobody mentioned it"
into "we discussed it and decided". Never quietly drop a finding because it was
awkward, and never re-raise a settled one every session.

---

## 5. Memory

After every pass, `memory_save`:

- the date and what was checked
- the score out of twenty, and which failed
- compliance findings by severity
- **what the owner declined, and why** — so the next session does not re-ask
- what is genuinely still outstanding

A checklist nobody can resume is a checklist that gets run from scratch, badly,
every time.

---

## Related skills

- **`pre-launch-audit`** — the scored design, performance and functionality review. Run both; this one covers what that one does not, and they must agree.
- **`build-standard`** — the definition of done. Nothing here overrides it; this is the gate at the end.
- **`seo-audit-and-fix`** — page speed and broken links appear in both. Measure there, report here.
- **`store-launch-and-deploy`** — payments, shipping and tax, which is where most of the e-commerce duties are actually implemented.
- **`security-standard`** — hardening. Compliance and security overlap but are not the same thing: a site can be secure and unlawful.
- **`web-design-master`**, **`motion-and-interaction`** — the fixes for most of the twenty.

## Routing examples

Phrases that must reach this skill, and one that must not. `bin/lint-skills.php`
checks them against every other skill's description.

- fires: do i need a cookie banner
- fires: is my site legal to launch in europe
- fires: check my gdpr consent setup
- skips: rebuild the homepage in elementor
