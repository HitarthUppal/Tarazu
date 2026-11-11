# Tarazu security audit (11 Nov 2026)

Scope: live tarazu.live (api.php, .htaccess, index.html, sw.js, config.php layout). Stack: PHP 8 + SQLite doc store on cPanel, n8n Cloud for AI.

## Already sound
- Data directory and SQLite outside the web root; config.php blocked.
- Session token is an HMAC-signed header (X-Token), not a cookie, so no CSRF exposure.
- Server-side role checks on every write (can_write) and read (can_read); advisors can only touch their own views.
- Lockout on wrong PINs (5 per user / 20 per IP per 15 min), constant-time PIN compare, artificial delay.
- Strict path validation, JSON-only API, size caps on imports.
- Master "log in as" sessions are marked and limited to 12 hours.

## Findings, ranked

| # | Severity | Finding | Fix |
|---|---|---|---|
| 1 | High | PINs stored in plain text. Owners and master can read every PIN through sync. Anyone with the DB file or a master session sees all PINs. | Hash with Argon2id/bcrypt. Never send PINs to any client. Owner can reset a PIN, not read it. |
| 2 | High | PIN-only sign-in (4 digits). A shared or shoulder-surfed PIN is a full account. | One-time code (OTP) by WhatsApp (email fallback) for first sign-in on a device; PIN or biometrics after that. |
| 3 | High | Tokens last 30 days and cannot be revoked. A lost phone stays signed in for a month; signing out does not kill the token. | Server-side sessions table: random token, hashed at rest, per device, 30-day idle / 90-day absolute expiry, revocable (sign out, sign out everywhere, owner/master revoke). |
| 4 | High | Signed-out sync returns the user roster (names, roles, client ids) and client records to anyone on the internet. | Signed-out sync returns nothing but the public login config. Sign-in by phone number, not by picking a name. |
| 5 | High | Uploaded report PDFs (paid AgriMandi content) sit in /files/ under the web root, readable by anyone who guesses the name. | Store outside web root; serve through api.php after a session and role check. |
| 6 | High | The web page calls n8n Cloud directly, so every webhook address (AI brief, voice notes, quota) is visible in the page source. Anyone can run Gemini briefs at our cost (about $0.55 each) or feed data in, with no sign-in. | Route all AI calls through api.php (signed-in users only, per-user rate limits, allow-listed hooks); n8n checks a shared-secret header and rejects everything else; rotate the webhook paths. |
| 7 | Medium | No audit log. Master "log in as", PIN changes, role changes, deletions and decisions leave no record. | Append-only audit table plus a viewer in Master. |
| 8 | Medium | Security headers incomplete (no CSP). The web app keeps its token in localStorage, so any script injection is account takeover. | Strict CSP, HSTS, frame-ancestors none, Permissions-Policy (mic self only), Referrer-Policy. |
| 9 | Medium | Backups are manual snapshots only. | Nightly encrypted DB backup, 30-day rotation, off-server copy, monthly restore test. |
| 10 | Medium | Demo engine and demo data still live in the production database. | Switch off, archive demo data, start clean. |
| 11 | Low | Notification inbox endpoint is unauthenticated (protected by the push endpoint URL being secret). | Acceptable; tie to session for the app. |
| 12 | Low | No CORS policy; the phone apps need one. | Allow-list only the app origins (capacitor://localhost, https://localhost). |
| 13 | Ops | cPanel password was shared in chat earlier; temporary credentials exist. | Rotate before go-live; enable cPanel 2FA. |

## App-specific (phones)
- Session token in Keychain / Keystore, never in web storage.
- Face ID / fingerprint lock after 60 s in the background; content hidden in the app switcher.
- Android: screenshots blocked (FLAG_SECURE), no cloud backup of app data, HTTPS only.
- iOS: HTTPS only (ATS default), no encryption export paperwork needed (standard HTTPS).
