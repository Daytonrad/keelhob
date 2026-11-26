#!/usr/bin/env bash
# Start WordPress, force Keelhob's token to the one Glama passed in, then hand
# stdin/stdout to the bridge. Everything lives in this container; no outside
# site is touched.
set -euo pipefail
WP=/var/www/html
TOKEN="${KEELHOB_TOKEN:-}"
if [ -z "$TOKEN" ]; then
  echo "KEELHOB_TOKEN is not set" >&2
  exit 2
fi

# Keelhob stores only a sha256 of the token, so the token Glama supplies is
# written as that hash rather than minted here. set_token() would mint its own
# and Glama would then be holding the wrong one.
wp --path="$WP" --allow-root eval '
  $plain = getenv("KEELHOB_TOKEN");
  $kh = Keelhob::instance();
  $kh->set("token_hash", hash("sha256", $plain));
  if (class_exists("Keelhob_Auth") && method_exists("Keelhob_Auth", "unlock")) {
      Keelhob_Auth::unlock();
  }
' > /dev/null

php -S 127.0.0.1:8899 -t "$WP" > /dev/null 2>&1 &

# Wait for it rather than sleeping a guess: a fixed sleep is either slow or
# flaky, and on a cold container it is usually both.
for _ in $(seq 1 60); do
  if curl -fsS -o /dev/null "http://127.0.0.1:8899/"; then break; fi
  sleep 0.5
done

exec php /usr/local/bin/kh-bridge.php \
  "http://127.0.0.1:8899/wp-json/keelhob/v1/mcp" "$TOKEN"
