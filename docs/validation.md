# Validation record

Executed on 16 November 2026 against the public Azure deployment.

## Automated checks

| Layer | Observed result |
| --- | --- |
| PHP unit and feature tests, SQLite | 39 tests, 187 assertions passed |
| Same PHP suite, PostgreSQL 17 | 39 tests, 187 assertions passed |
| PostgreSQL competing processes | Five checks passed: reservations, atomic queue insertion, four settlement contenders, exactly one charge, and three identical submissions |
| Vue component tests | Eight passed |
| Pint and production Vite build | Passed |
| Composer and npm audit | Zero reported advisories at validation time |
| Live HTTP acceptance | 35 checks passed, including HTTPS redirects, CSRF, sessions, pricing, idempotent credits/submissions, provider success/failure/decline, image retrieval and exact reconciliation |
| Live authentication boundaries | Nine checks passed: password confirmation, session login/logout, private history, ignored admin injection and forbidden report access |
| Live slow/overloaded provider paths | Ten checks passed: visible pending/retries, slow completion, bounded failure, released reservations and reconciliation |

[GitHub CI run 43958166796](https://github.com/ikarolaborda/promptedit-assessment/actions/runs/43958166796) passed on source `28e09af98be180c9647f75a4dc8bc3c898715671`. Subsequent mobile sign-out styling was checked with the Vue suite and production build, then exercised on Azure in Chrome.

## Real browser acceptance

Chrome rendered the deployed sign-in page and entered the shared demo. A desktop request with landscape size, high quality and one reference image automatically progressed from queued/processing to a rendered image. Its exact charge was $0.439706250000. The duplicate provider callbacks returned HTTP 204 and the report reconciled exactly, with no duplicate charge. A separate browser failure showed a clear explanation, $0 charged and a released reservation. Buying simulated credits increased the displayed balance by exactly $5.

At a 375 x 667 emulated viewport, navigation, date selection, the one-day report filter, prompt entry, portrait selection, quality changes and keyboard submission worked. A mobile request completed automatically, charged $0.025384375000 and released its hold. A separately registered account signed in through the browser and saw zero images, $5 initial credits and no admin report navigation. Desktop sign-out worked. The compact layout initially hid sign-out; this was fixed and the mobile control was then clicked successfully, returning to the sign-in page.

The provider dashboard showed successful signed processing, success, failure and duplicate deliveries (HTTP 204). The live acceptance report recorded eight unique events after the first browser and HTTP runs; duplicate deliveries did not add duplicate charges. Later mobile activity added another successful generation.

Screenshots: [desktop results](screenshots/browser-desktop-generation.png), [375-pixel report](screenshots/browser-mobile-report.png).

## Deployment and defects corrected

The initial deployment passed backend checks but failed the actual browser render because asset URLs used HTTP behind the HTTPS proxy. A replacement image then exposed a non-executable entrypoint. Both were corrected and verified through Chrome and public HTTPS asset retrieval. The final assessment image is `frame-studio:20261116-v6`, revision `frame-studio-assessment--0000004`; the image also includes the compact-layout sign-out fix. The fast rebuild Dockerfile now rebuilds frontend assets instead of inheriting stale assets.

## Scope and limitations

These are executed results, not a claim of exhaustive testing. Browser acceptance was performed through native Chrome controls, including mobile emulation; it is not a committed Playwright suite, physical-device test, Safari/Firefox certification or formal accessibility audit. Registration validation and authorization rejection were exercised by live HTTP tests; the browser login used a disposable account created through HTTP. A Chrome extension message-channel warning was observed; a fresh report load after clearing the console had no messages. Pixelforge images and purchases are simulated. Provider-generated image URLs can expire, and the shared demo is intentionally visible to other reviewers.

## Social sign-in validation (16 November 2026)

The Google/Apple integration adds 19 feature tests (124 assertions). The complete suite passes 58 tests and 311 assertions against both SQLite and PostgreSQL 17; all eight Vue tests and the production Vite build also pass. Pint passes, Composer audit reports no advisories, and the npm production dependency audit reports zero vulnerabilities.

Coverage includes configured-provider visibility, real authorization redirect construction, secure browser binding, Apple's nonce cookie and POST callback, verified identity creation, stable-subject repeat login, explicit account linking, collision rejection, shared-demo linking rejection, cancellation, expired/missing state, wrong-browser callbacks, replay prevention and logout invalidation. Provider identity results are faked in successful feature tests; these are not claims of live provider acceptance. Live provider results are recorded separately below.

The prior assessment commit `02253c8` also passed GitHub Actions run `43960374945`.

Live Google verification: Google Auth Platform reports an External app in production. On Azure image `frame-studio:20261116-v7`, ready revision `frame-studio-assessment--0000005`, `/login` and `/privacy` return HTTP 200. Chrome followed **Sign in with Google**, selected the authorized Aerolambda account, and returned to `/studio` with the user's name, $5 simulated credits, no images and no report/admin navigation. **Account** displays **Google Connected**. This verifies the real Google code exchange and application session, not only redirect construction.

OAuth source commit `1829e63` passed GitHub Actions run `43963227125`. Apple website registration is complete. The dedicated key is restricted to Sign in with Apple for Frame Studio and stored in Azure runtime secrets.

Chrome also verified Google sign-out and repeat sign-in: the same named workspace still showed $5.0000, zero images and no additional starting-credit grant.

On ready revision `frame-studio-assessment--0000006`, both providers are enabled. Chrome used **Account → Connect Apple**, completed Apple consent, and returned to Account with **Apple sign-in connected** and both providers marked **Connected**. Live HTTP checks also verified each authorization redirect and successful handling of cancelled Google and Apple callbacks, including Apple's cross-site POST route.

Final browser check: after signing out, **Sign in with Apple** completed a fresh Apple authorization and returned to the same named workspace with $5.0000, zero images and both Google and Apple still connected. No extra starting-credit grant appeared. Both real provider code exchanges, normal sign-out, repeat login and explicit cross-provider linking are verified. No Google or Apple setup steps remain pending.
