# Security headers for pranknologia.shop (Coolify / Traefik)

> **STATUS: APPLIED AND VERIFIED LIVE, 2026-08-18.** All five headers confirmed present
> on pages and on static assets; `Server` and `X-Powered-By` are gone.
>
> **pranknologia.shop is a Coolify _Docker Compose_ app**, so the "Standard Applications /
> Container Labels / Readonly labels" route below did **not** apply. What actually worked
> is the Docker Compose section: the labels go on the `wordpress` service in the **source
> compose**. The dashboard's "Edit Compose File" popup shows the *generated* file and is
> read-only by design — that is not a bug and there is no unlock button. See
> "What was actually used" at the bottom.

Written 2026-08-18. At the time of writing the live site sent **none** of the five headers
below and advertised `Server: Apache/2.4.68 (Debian)` and `X-Powered-By: PHP/8.3.32`.

Coolify puts Traefik in front of the app container, so headers belong there — that way
they cover every response including static files, not just PHP pages.

---

## Where these go

**Standard application** (not Docker Compose): Coolify → your application → scroll to
**Container Labels**. Per Coolify's own docs you must **uncheck "Readonly labels"** to
make that textarea editable.

> **Read this before unchecking it.** Coolify's docs are explicit: once *Readonly labels*
> is unchecked, **Coolify stops auto-generating labels on deploy** and you own them from
> then on. Break a routing label and the application becomes unreachable. So:
> **select everything already in that box and copy it into a text file first.** There is
> also a **"Reset Labels to Defaults"** button in the UI, which is the escape hatch if it
> goes wrong.

**Docker Compose application**: put the `traefik.http.middlewares.*` lines in your
service's `labels:`, and instead of step 2 add the Coolify shorthand
`- "coolify.traefik.middlewares=pk-security"`. Compose apps do not need the readonly
toggle.

---

## 1. Define the middleware — paste these lines

```
traefik.http.middlewares.pk-security.headers.contentTypeNosniff=true
traefik.http.middlewares.pk-security.headers.referrerPolicy=strict-origin-when-cross-origin
traefik.http.middlewares.pk-security.headers.stsSeconds=31536000
traefik.http.middlewares.pk-security.headers.customResponseHeaders.X-Frame-Options=SAMEORIGIN
traefik.http.middlewares.pk-security.headers.customResponseHeaders.Permissions-Policy=camera=(), microphone=(), geolocation=(), interest-cohort=()
traefik.http.middlewares.pk-security.headers.customResponseHeaders.Server=
traefik.http.middlewares.pk-security.headers.customResponseHeaders.X-Powered-By=
```

The last two lines end with `=` and nothing after it. In Traefik an empty value **removes**
the header, which is how the Apache and PHP version numbers stop being advertised.

## 2. Attach it — edit the line that already exists

Coolify has already generated a router label that looks like this, where `<uuid>` is your
application's UUID:

```
traefik.http.routers.https-0-<uuid>.middlewares=gzip
```

**Do not add a second copy of that line.** Edit the existing one and append the new
middleware, comma-separated, keeping whatever is already there:

```
traefik.http.routers.https-0-<uuid>.middlewares=gzip,pk-security
```

If your labels also show an `http-0-<uuid>` router (the plain-HTTP one that redirects to
HTTPS), leave it alone — it only redirects, so it serves no content that needs headers.

## 3. Redeploy and check

```bash
curl -sSI https://pranknologia.shop/ | grep -iE 'strict-transport|x-frame|nosniff|referrer|permissions|server|x-powered'
```

Expected afterwards: `strict-transport-security`, `x-frame-options`,
`x-content-type-options`, `referrer-policy` and `permissions-policy` all present, and
**no** `server` or `x-powered-by` lines at all.

---

## Two deliberate choices, so they don't surprise you later

**`X-Frame-Options: SAMEORIGIN`, not `DENY`.** Traefik's `frameDeny=true` shorthand emits
`DENY`, which would break the **Elementor editor** — Elementor loads your own page inside
an iframe to edit it, and `DENY` blocks that even on the same origin. `SAMEORIGIN` gives
you the clickjacking protection without breaking your page builder.

**HSTS without `includeSubDomains`.** `stsSeconds=31536000` tells browsers to use HTTPS
for `pranknologia.shop` for a year. Adding
`traefik.http.middlewares.pk-security.headers.stsIncludeSubdomains=true` extends that to
every subdomain, and browsers cache it hard — if you later put a staging or mail
subdomain on plain HTTP, it will be unreachable and you cannot quickly undo it. Add it
once you are certain no subdomain needs plain HTTP.

---

## Content-Security-Policy — start in report-only

A real CSP is the most valuable header here and also the easiest one to break a site
with. This site loads GSAP from jsdelivr, fonts from Google, emoji from `s.w.org`, and
PayPal's checkout scripts, so a strict policy applied blind **will** break checkout.

Add this **report-only** line first. It changes nothing for visitors and only logs
violations to the browser console:

```
traefik.http.middlewares.pk-security.headers.customResponseHeaders.Content-Security-Policy-Report-Only=default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://*.paypal.com https://*.paypalobjects.com https://s.w.org; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' data: https://fonts.gstatic.com; img-src 'self' data: https: ; connect-src 'self' https://*.paypal.com; frame-src https://*.paypal.com; base-uri 'self'; form-action 'self' https://*.paypal.com
```

Browse the site, add to cart, and open checkout with the console open. Fix whatever it
reports, then rename the header to `Content-Security-Policy` to start enforcing it.

`'unsafe-inline'` and `'unsafe-eval'` are in there because Elementor and WooCommerce emit
inline scripts — removing them would need a nonce-based setup, which is a bigger project.

---

## Faster alternative, if you want the headers today

Apache is the web server inside the container and WordPress already has a `.htaccess`.
The same headers can go there instead, which needs no Coolify change and no redeploy. It
does not cover responses Apache never sees, and it is easier to lose during a rebuild —
the Traefik version above is the durable one. Ask and I can apply the `.htaccess`
version immediately as a stopgap.

---

## What was actually used (Docker Compose route)

This is the version that shipped. Added to the `wordpress` service in the **source
compose** — `mariadb` gets nothing, it is not web-facing.

```yaml
    labels:
      - 'traefik.http.middlewares.pk-security.headers.contentTypeNosniff=true'
      - 'traefik.http.middlewares.pk-security.headers.referrerPolicy=strict-origin-when-cross-origin'
      - 'traefik.http.middlewares.pk-security.headers.stsSeconds=31536000'
      - 'traefik.http.middlewares.pk-security.headers.customResponseHeaders.X-Frame-Options=SAMEORIGIN'
      - 'traefik.http.middlewares.pk-security.headers.customResponseHeaders.Permissions-Policy=camera=(), microphone=(), geolocation=(), interest-cohort=()'
      - 'traefik.http.middlewares.pk-security.headers.customResponseHeaders.Server='
      - 'traefik.http.middlewares.pk-security.headers.customResponseHeaders.X-Powered-By='
      - 'coolify.traefik.middlewares=pk-security'
```

The last line is the one that applies it — the seven above only define the middleware.
Coolify consumes `coolify.traefik.middlewares` at deploy time and appends it to the
router chain alongside its own `gzip`, so no router label needs editing by hand.

### Verified after deploy

| Header | Value |
|---|---|
| `strict-transport-security` | `max-age=31536000` |
| `x-frame-options` | `SAMEORIGIN` |
| `x-content-type-options` | `nosniff` |
| `referrer-policy` | `strict-origin-when-cross-origin` |
| `permissions-policy` | `camera=(), microphone=(), geolocation=(), interest-cohort=()` |
| `server` | removed |
| `x-powered-by` | removed |

Present on `/`, `/shop/`, `/discover/`, `/terms/`, `/privacy-policy/`, the custom 404,
`wp-login.php`, the MCP endpoint, **and static assets** (`dashicons.min.css` carries
`nosniff` with no `server` header) — which is the proof it is applied at the proxy and
not just to PHP output.

Regression check after deploy: every route still 200 (404 correctly 404), GSAP 3.15.0
loads, the scroll carousel initialises with 8 cards, no horizontal overflow, and the
browser console is clean.
