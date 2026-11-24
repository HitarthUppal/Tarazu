# Tarazu: go-live checklist (24 Nov 2026)

Everything waiting to reach tarazu.live, in the order to apply it, plus what has to be true before and after the deploy. Main is at 00fcdba. Nothing below is on main yet.

## 1. Decide first (blocks the merge)
- **Round close time.** Patch 1 makes rounds and polls close at 8 PM IST. The 16 Nov decision was the opposite: rounds stay open 24 hours. Either drop the 8 PM default from patch 1 (keep its iPhone date-input and floating-button fixes) or confirm 8 PM now. The landing page's example card says "Today, 8 PM", which is fine either way.
- **Repo visibility.** HitarthUppal/Tarazu is public. With a public landing page pointing people at the site, make the repo private before launch.
- **Contact address.** The landing page's "Request a walkthrough" and "Contact" links use `hello@tarazu.live`. Create that mailbox (or forwarder) in cPanel, or tell us which address to use instead.

## 2. Branch order (`git am -3`, oldest first)
1. Patch 1, `fix/poll-close-8pm` (after the decision above).
2. Patch 2, `ui/iphone-legibility` (96fa3f2). Small conflicts in tests/cadence.js are likely.
3. Patch 0003, privacy.html (full IP address, Orai on Azure, processing outside India).
4. Patch 0004, AI brief on Intelligence v2.
5. `feat/dds-action-items`: 6e617a1 then c21c621 (action items, Act by, advice expiry; voice notes removed). Rebase onto 1 to 4; expect conflicts in web/index.html (brief card, round form, advisor home) and privacy.html.
6. `feat/landing-page` (this change). Touches web/index.html in two lines only (the `TARAZU_SITE` script in the head and `siteUrl`), so it should rebase cleanly on top of everything above.
7. PR #1 (cron job and webhook lock in the deploy workflow). Merges cleanly; check deploy-web.yml by eye, since both PR #1 and the landing change edit it.

**Do not launch the landing page before 3 and 5.** The landing page links to privacy.html, and on main that page still describes voice notes and an "approximate" IP address.

## 3. After merging, before deploying
Run locally (`tests/setup_local.sh`, one login call, then the suites; `tests/seed_month.py` before sales, layout, stale and cadence):

| Suite | Expected |
|---|---|
| tests/landing.js (new) | 41 |
| tests/test_api_security.py | 88 (91 with the DDS branch) |
| tests/ui_security.js | 51 |
| tests/test_sales_api.py | 16 |
| tests/ux_shell.js | 138 |
| tests/boot_stale.js | 3 |
| tests/cadence.js | 44 / 55, all passing once patch 1 is in |
| tests/test_brief_v2.py | 19 (with patch 0004) |
| dds.js | 31 (with the DDS branch) |

On the landing branch alone (on 00fcdba), every suite above passes except cadence's "advisor: asks about the next days", which fails identically on untouched main (fixed by patch 1).

## 4. GitHub secrets to check before the deploy
- Present already: `CPANEL_USER`, `CPANEL_TOKEN`, `N8N_KEY`, `BACKUP_KEY`, `OTP_FROM`, `WA_API_KEY`.
- New and optional: `HOOK_BRIEF_V2`, `HOOK_HISTORY`, `BRIEF_V2` (patch 0004), `SALES_KEY` (to switch on Nirani's sales feed).
- The `X-Tarazu-Key` value in the Intelligence v2 key-check nodes must equal `N8N_KEY`, or every brief silently falls back to v1 (the audit log shows `engine: v1`).
- The deploy workflow does not upload `HOOK_BRIEF_V2`, `HOOK_HISTORY` or `BRIEF_V2` yet unless patch 0004 added them to the secrets list in deploy-web.yml. Check the `for k in [...]` line after merging.

## 5. Deploy
Actions → **Deploy website to tarazu.live** → type `DEPLOY`. The workflow now also:
- refuses to run if home.html is missing or .htaccess does not serve it at `/`;
- uploads home.html;
- checks after upload: the landing page at `/`, the app at `/admin/` and `/nirani/`, `/admin` redirecting to `/admin/`, and the security headers on `/` and `/admin/`.

## 6. Straight after the deploy
- Open tarazu.live on a phone: landing page, then "Sign in to Nirani Group", then sign in with a WhatsApp code.
- Open tarazu.live/admin/ and sign in as master admin. **Tell anyone with the old tarazu.live bookmark for admin sign-in to use /admin/.**
- Master admin who signs in at /nirani/ should land on /admin/.
- cPanel → Cron Jobs: the 15-minute `cron.php` job exists (PR #1). Next morning: a new file in tarazu_data/backups.
- End-to-end on live: Android push, one AI brief (check `engine` in the audit log), one report link.
- Master → clear the example data and fill in mobile numbers before Nirani's people start using it.

## 7. Rollback
- Files: re-run the deploy workflow from the previous main commit (Actions → Run workflow → choose the branch or tag).
- Database: every deploy snapshots `tarazu_data/predeploy-<time>.sqlite`. Copy it over `tarazu.sqlite` in cPanel File Manager.
- Landing page only: deleting `home.html` from public_html makes `/` fall back to the old master sign-in (`DirectoryIndex home.html index.html`).

## 8. Still open, not blocking launch
- cPanel by hand: no-reply mailbox with DKIM/SPF, privacy@ mailbox, rotate the cPanel password, cPanel 2FA.
- Legal review of privacy.html; confirm the hosting location.
- Rotate BACKUP_KEY and N8N_KEY (N8N_KEY in all three workflows in the same hour); restrict the Firebase Android API key.
- n8n: head prompt still says "daily" reads; deactivate the voice workflow once the DDS branch is live.
- Signed store builds (Android upload key, Apple Developer organisation account already exists).
