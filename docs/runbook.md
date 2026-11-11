# Tarazu runbook

## How the pieces fit
- **Website and API**: `web/` → tarazu.live (cPanel account `tarazu`, docroot `/home2/tarazu/public_html`). PHP 8.4 + SQLite in `/home2/tarazu/tarazu_data` (outside the web root).
- **AI**: n8n Cloud workflows, reached only through `api.php?op=flow` with the `X-Tarazu-Key` header.
- **Phone apps**: `mobile/` (Capacitor 8). The screens are `web/index.html`, packed by `npm run build:www`.
- **Builds and deploys**: GitHub Actions in this repo.

## GitHub secrets
| Secret | Used by | Notes |
|---|---|---|
| `CPANEL_USER`, `CPANEL_TOKEN` | deploy-web | cPanel → Security → Manage API Tokens. Name it `github-deploy`, no expiry, or 1 year and rotate. |
| `N8N_KEY` | deploy-web → server | 32+ random characters. The same value goes into the key check in each Tarazu n8n workflow. |
| `BACKUP_KEY` | deploy-web → server | 64 hex characters. **Also store offline** (password manager). Without it, backups cannot be restored. |
| `OTP_FROM` | deploy-web → server | e.g. `no-reply@tarazu.live`. Create the mailbox in cPanel and turn on DKIM/SPF (Email Deliverability). |
| `OTP_WEBHOOK` | deploy-web → server | Optional, later: n8n webhook that sends the WhatsApp sign-in code template. |
| `HOOK_BRIEF`, `HOOK_VOICE`, `HOOK_REPORT`, `HOOK_MARKET` | deploy-web → server | New, unguessable webhook paths after rotation. Until set, the old paths are used. |
| `ANDROID_KEYSTORE_BASE64`, `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD` | android | Upload key. Play App Signing holds the real signing key. Keep a copy of the keystore offline. |
| `GOOGLE_SERVICES_JSON` | android | Firebase → Project settings → Android app `live.tarazu.app`. |
| `PLAY_SERVICE_ACCOUNT_JSON` | android | Play Console → API access. Only needed for automatic uploads. |
| `IOS_DIST_CERT_P12_BASE64`, `IOS_DIST_CERT_PASSWORD`, `IOS_PROVISIONING_PROFILE_BASE64`, `APPLE_TEAM_ID` | ios | Apple Distribution certificate and an App Store profile for `live.tarazu.app` with Push enabled. |
| `ASC_KEY_ID`, `ASC_ISSUER_ID`, `ASC_KEY_P8` | ios | App Store Connect API key, for TestFlight uploads. |

**Making the Android upload key (once, on your PC with Java installed; keep the file and password offline):**
```
keytool -genkeypair -keystore tarazu-upload.keystore -storetype PKCS12 -alias tarazu-upload -keyalg RSA -keysize 4096 -validity 10950 -dname "CN=Tarazu, O=JK India eAgriTech Limited, L=Mumbai, C=IN"
```
Then add `ANDROID_KEYSTORE_BASE64` (the file, base64-encoded: `certutil -encode` on Windows, then strip the header lines), `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_PASSWORD` (the same password) and `ANDROID_KEY_ALIAS` = `tarazu-upload`.

Server-side push keys are files placed in `/home2/tarazu/tarazu_data/` by hand (never in the repo): `fcm-service-account.json` (Firebase service account) and `apns-key.p8`, plus `APNS_KEY_ID` and `APNS_TEAM_ID` defined in `config.php`.

## Cutover to the hardened version (one time)
1. Add the GitHub secrets above (at least the cPanel token, `N8N_KEY`, `BACKUP_KEY` and `OTP_FROM`).
2. Run **Deploy website to tarazu.live** (type `DEPLOY`). It snapshots the database, writes `secrets.php`, uploads `web/`, then checks hashes, headers, `config.php` and `/files/` blocking.
3. Everyone signs in again: old tokens are refused by design. PINs carry over; they are hashed on the first start.
4. Lock n8n the same hour: add the `X-Tarazu-Key` check to the Tarazu webhooks (API, Intelligence v2, Quota Sync), rotate the webhook paths, set the `HOOK_*` secrets, and redeploy. Until this is done, the old webhook addresses still work for anyone who copied them.
5. In n8n, set the voice workflow to not save successful executions, so voice audio is not retained (the privacy policy says so).
6. Move old report PDFs from `public_html/files/` to `tarazu_data/files/`. They still open from the old place meanwhile, but only through signed links.
7. Add the cron job in cPanel: `*/15 * * * * php /home2/tarazu/public_html/cron.php >/dev/null 2>&1`. This runs notifications and the nightly encrypted backup.
8. In Master admin: switch the demo off, clear demo data, change default PINs, add everyone's mobile number and email. Then turn on **Require phone sign-in** and, once emails are in, **Require a one-time code**.
9. Rotate the cPanel password (it was shared in chat earlier), delete temporary credentials, and turn on cPanel two-factor sign-in.

## Restore a backup
Backups: `/home2/tarazu/tarazu_data/backups/tarazu-YYYYMMDD-HHMMSS.sqlite.gz.enc` (30 kept). Format: `TZB1` | 12-byte IV | 16-byte GCM tag | ciphertext of a gzipped SQLite file. Key = SHA-256 of `BACKUP_KEY`.
```
php -r '$r=file_get_contents($argv[1]); $k=hash("sha256",getenv("BACKUP_KEY"),true);
  $p=openssl_decrypt(substr($r,32),"aes-256-gcm",$k,OPENSSL_RAW_DATA,substr($r,4,12),substr($r,16,16));
  file_put_contents($argv[2], gzdecode($p));' backup.sqlite.gz.enc restored.sqlite
```
Check `restored.sqlite`, stop the cron job, swap it in for `tarazu.sqlite`, then delete `tarazu.sqlite-wal` and `-shm`. Test a restore once a month. Off-server copies: download one backup weekly (or add S3 later, as in the server spec).

## Tests
- `tests/setup_local.sh` starts a throwaway local copy (test users, fake n8n, fake mail).
- `python3 tests/test_api_security.py`: 68 API security checks.
- `NODE_PATH=$(npm root -g) node tests/ui_security.js`: browser checks, phone and desktop, production CSP.
- `cd mobile && npm run build:www && cd .. && NODE_PATH=$(npm root -g) node tests/app_bridge.js`: app bundle with a simulated native layer.
