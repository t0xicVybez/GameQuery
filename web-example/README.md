# Web Example: Status Checker Form

A single-file, publicly-facing form (`status-checker.php`) where a
visitor picks a protocol, enters a host:port (and a password, for
Palworld), and gets back the parsed server status.

## Why this is more than "print the result in HTML"

A public form that makes *your* server open outbound connections to
whatever IP:port a visitor types in is a real attack surface, not a
hypothetical one:

- **SSRF** — without protection, a visitor could point it at your own
  internal network (other boxes on the VPS's private network, a Redis
  instance with no auth on localhost, a cloud provider's metadata
  endpoint at `169.254.169.254`, etc.) and use your server as a probe.
  This example resolves the hostname itself, rejects private/loopback/
  reserved ranges, and connects using the *resolved IP* rather than the
  original hostname so a malicious DNS answer can't swap in a private
  address between the check and the actual query.
- **Abuse as an anonymous scanner** — without a rate limit, someone could
  script thousands of requests through your form to port-scan arbitrary
  targets using your server's IP instead of their own. There's a simple
  file-based per-IP rate limit (6 requests/minute by default) here.
- **The password field is a real credential.** It's never written to a
  log, never stored in the session, and is explicitly `unset()` after the
  request completes. It only ever exists in memory for the one request
  that needs it.

None of this is exotic — it's the standard checklist for "form that makes
outbound network calls on a visitor's behalf." Skipping it is how status
checkers like this end up abused.

## Deploying it

1. Copy `status-checker.php` (or write your own, using it as a reference)
   onto your PHP host alongside a full copy of the `GameQuery` library.
2. Fix the `require` path near the top to point at wherever you put
   `autoload.php` (or `vendor/autoload.php` if installed via Composer).
3. Make sure the directory in `RATE_LIMIT_DIR` (defaults to `../var/rate-limits`
   relative to this file) exists and is writable by PHP. It'll create it
   automatically if the parent is writable; if not, create it yourself
   and `chmod 700`.
4. **Serve it over HTTPS.** The admin password field is meaningless to
   protect server-side if it's sent in plaintext.
5. If `REMOTE_ADDR` isn't the real client IP in your setup (behind
   Cloudflare, an nginx reverse proxy, etc.), update `clientIp()` to read
   the appropriate forwarded-for header -- but only do this if you're
   certain that header can't be spoofed by the client in your specific
   setup, or the rate limiter becomes trivially bypassable.

## Worth adding if this gets real public traffic

- A CAPTCHA (hCaptcha/Turnstile) on the form if the rate limit alone
  isn't holding up against scripted abuse.
- Swap the file-based rate limiter for a DB or Redis-backed one once
  you're running behind more than one PHP-FPM worker/server, since the
  file-based version doesn't coordinate across machines.
- Logging (host, protocol, timestamp -- never the password) if you want
  visibility into who's using it or evidence if it's being abused.
