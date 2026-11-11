# Tarazu: App Store and Google Play submission kit

## Identity
- **App name:** Tarazu: Sugar Sale Intelligence
- **Bundle / package id:** `live.tarazu.app` (permanent once published)
- **Publisher:** JK India eAgriTech Limited (both stores, as an **organisation** account)
- **Category:** Business. **Price:** free download; access needs an account from your company.
- **Privacy policy:** https://tarazu.live/privacy.html · **Account deletion:** https://tarazu.live/delete-account.html
- **Support:** https://tarazu.live, plus a support email (create `support@tarazu.live` in cPanel)

## Accounts to open (slowest first)
1. **D-U-N-S number** for JK India eAgriTech Limited, needed for Apple's organisation account. Look it up first on Apple's D-U-N-S lookup page; if there is none, request it (can take 1 to 2 weeks).
2. **Apple Developer Program**, organisation, USD 99 a year. Enrol with the company's legal name exactly as on D-U-N-S, and an Apple ID on a company email.
3. **Google Play Console**, organisation, USD 25 one-time. Needs D-U-N-S too, plus identity verification of the account owner.
4. **Firebase** project `tarazu` under the company Google account: add the Android app `live.tarazu.app` (gives `google-services.json` and the server key) and the iOS app (upload the APNs key).

## Store listing text
**Short description (Play, 80 characters):**
Sugar sale decisions for mills: advisor views, market data and an AI brief.

**Promotional text (Apple, 170 characters):**
Post a sale offer, hear from your advisory panel and decide with live sugar prices, ChiniMandi news and an AI brief, all in one place.

**Full description:**
Tarazu is a private decision tool for sugar mills, built by AgriMandi.

When a mill has sugar to sell, the manager posts a round: price band, quantity, grade and quota. Your industry advisors each give their view independently: sell now, sell some or hold, with their price expectations and reasons, typed or spoken in Hindi, Marathi or English. The owner sees every view side by side, with live ex-mill prices, ChiniMandi news and AgriMandi market reports, plus an AI brief that weighs the evidence. The owner makes the final call.

- Rounds, advisor views, polls and comments, with notifications
- Live sugar prices and 14-day trends by state
- AI brief: pricing gap, unoffered quota and evidence for each side
- Sales ledger and reports by mill, month and buyer
- Voice notes, transcribed automatically
- Secure: phone sign-in with PIN and one-time code, Face ID / fingerprint lock, encrypted connections, full audit trail

Tarazu is for invited members of client organisations. Accounts are created by your company's administrator.

**Keywords (Apple, 100 characters):** sugar,mill,ChiniMandi,AgriMandi,commodity,price,advisory,decision,ethanol,trading

## Screenshots
Required: iPhone 6.9" (1320 × 2868), iPad 13" if iPad is supported (it is off for now), Play phone screenshots (1080 × 1920 or more), and a Play feature graphic (1024 × 500).
Take them from the **Demo Mills** reviewer workspace, never from Nirani's real data. Screenshots are blocked inside the Android app (it hides prices from screen capture), so take them in a phone-sized browser on the demo workspace, or in a debug build.
Suggested 5: home with market mood; a round with advisor views; the AI brief; sales report; sign-in with the security points.

## Google Play: Data safety answers
| Question | Answer |
|---|---|
| Collects or shares user data? | Collects: yes. Shares with third parties: **no** (service providers processing on our behalf are not "sharing"). |
| Encrypted in transit? | Yes |
| Can users request deletion? | Yes, https://tarazu.live/delete-account.html |
| Personal info: name, email address, phone number | Collected · required · app functionality, account management |
| Audio: voice recordings | Collected · optional · app functionality · **processed ephemerally** |
| App activity: other user-generated content | Collected · required · app functionality |
| App info and performance | Not collected |
| Device or other IDs (push token) | Collected · app functionality |
| Location, contacts, photos, financial info, browsing | Not collected |
Also: **Ads:** none. **Target audience:** 18+. **Content rating:** questionnaire, business app, no objectionable content. **Government app:** no. **News app:** no.

## Apple: App Privacy ("nutrition label")
- **Data linked to the user:** Contact Info (Name, Email Address, Phone Number); User Content (Audio Data, Other User Content); Identifiers (User ID); Usage Data: none.
- **Purpose** for all of these: App Functionality only.
- **Tracking:** No.
- **Export compliance:** uses only standard HTTPS. `ITSAppUsesNonExemptEncryption = NO` is already set in the app.

## Notes for the reviewers (both stores)
Before submitting, create the reviewer workspace on production. In Master admin: add client **Demo Mills** with 3 mills and sample rounds; add a manager account with a dedicated test number and PIN; and tick that account's **no code needed** flag (master-only; it skips the one-time code so reviewers can sign in).

Text to paste:
> Tarazu is a private business tool for sugar mills; accounts are created by each client's administrator, so there is no public sign-up. Please use this reviewer account on the Demo Mills workspace (sample data only): mobile 9XXXXXXXXX, PIN XXXX. No one-time code is needed for this account.
> Native features: push notifications for new rounds and decisions; Face ID / Touch ID / fingerprint app lock (Settings is not needed; it asks on return after 60 seconds); microphone for voice notes, transcribed into the advisor's view; session stored in the iOS Keychain / Android Keystore; screens are bundled in the app, and reports open in the system browser.

**Apple guideline 4.2 risk:** the screens are the same as the website. The native features above are the answer to "minimum functionality". If Apple still objects, the next step is native sign-in and home screens.

## Release steps
1. Fill the signing secrets (see runbook) → run **Android build** with track `internal` and **iOS build** with `testflight` ticked.
2. Test on real phones: sign-in, one-time code, push (send a test round), voice note, PDF report, lock on return, sign out.
3. Play: internal testing → closed testing (Nirani's people) → production. Apple: TestFlight internal → external (one review) → App Store review.
4. Expect about 1 to 3 days for Apple review, and up to a week for a new Play organisation account's first review.
