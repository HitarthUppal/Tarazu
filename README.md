# Tarazu

Sugar Sale Intelligence System, powered by AgriMandi. Built for Nirani Group by AgriMandi.live, a venture of JK India eAgriTech Limited.

| Folder | What |
|---|---|
| `web/` | The website and API as deployed to tarazu.live (PHP + SQLite). `config.php` and the database stay on the server. |
| `mobile/` | iOS and Android apps (Capacitor 8). `npm run build:www` packs `web/index.html` into the app. |
| `tests/` | Local test server, 71 API security checks, browser checks, app-bundle checks. |
| `tools/` | Repeatable patch scripts. |
| `docs/` | Security audit, runbook (secrets, cutover, restore), store submission kit. |
| `.github/workflows/` | Android build, iOS build (macOS), website deploy, web snapshot. |

Start with `docs/runbook.md`.
