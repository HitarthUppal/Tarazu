# Tarazu UX overhaul: design (approved 12 Nov 2026)

## Intent
Tarazu should feel like a finished product, not a demo: one typeface (Overpass), no demo machinery, and a phone experience that feels like a real app (app bar, icon tab bar, primary action button) rather than the website with its header cut off. The example data (sample mills, rounds, sales) stays until go-live.

## Decisions
1. **Type.** Overpass (400/600/700/800), self-hosted in `web/fonts/` so it loads instantly and works offline in the apps. The तराज़ू wordmark keeps Rozha One (also self-hosted), because Overpass has no Devanagari letters. Google Fonts links removed, CSP tightened to `font-src 'self'`.
2. **Removed.**
   - The Phone/Desktop toggle: the layout now follows screen width (1000 px and over is desktop).
   - The V1/V2/V3 switch and its stored setting: V3 only.
   - The server demo engine (`demo_tick`, `demo_story`), which wrote fake activity and filled in decisions.
   - All demo wording.
   - "Clear example data" stays, and moves to Master admin only.
3. **Phone shell** (the apps, and the website under 1000 px).
   - **App bar:** sticky and safe-area aware. Screen title on the left, a small client logo, and a profile button on the right. The profile button opens a sheet with name, role, client, notifications, security, theme, "Powered by AgriMandi" and Sign out.
   - **Bottom tab bar:** inline SVG icons with labels and badges, 64 px plus the home-bar inset.
   - **Floating + button** for the main action of the current screen, where one exists.
   - **Inputs** at 16 px (no iOS zoom), tap targets at least 44 px.
   - **Skeleton loading screen.**
4. **Desktop shell** (1000 px and over): left sidebar with the brand at the top, navigation with icons, and the profile at the bottom. Content to the right.
5. **Screens.** Action-first ordering on each role's home: the thing waiting for that person comes first. Copy tightened. Nothing that changes data rules or the API.
6. **App extras.** Light haptics on submit and decision. The status bar follows the app bar colour.

## Out of scope
New features, API changes, data model changes, store submission.

## Testing
- **Existing suites must pass:** API, browser, app bridge.
- **New checks:**
  - No toggle, no version switch and no demo strings in the page.
  - Overpass is the computed font.
  - Phone shows the app bar and icon tabs; desktop shows the sidebar.
  - Every role's home renders without script errors.
- **Screenshots** of every role on phone and desktop go to Hitarth before deploy.
