# Frame Studio

A small image-generation product built for the PromptEdit take-home assessment. Laravel 13, Vue 3, Inertia 2, Tailwind 4 and PostgreSQL. The public assessment uses Pixelforge simulations. An optional Stripe and Azure image integration is implemented but disabled on the deployed app pending credit-only billing protection.

**Deployment:** https://frame-studio-assessment.wonderfuldune-56e52a9b.northeurope.azurecontainerapps.io

Choose **Explore the demo** to use the shared reviewer workspace. Use sample prompts, because other reviewers can see this workspace. Register a separate account, or sign in with Google or Apple, to test private history and authorization. New accounts receive $5 of simulated credits. The shared demo can view the read-only usage report, where other users appear only as anonymous IDs.

## Review in five minutes

1. Explore the demo. Use the example prompt, change the frame and detail level, and observe the quote before submitting.
2. Generate an image. History updates without reloading. Open **Details** for full-precision amounts and token usage.
3. Try a prompt containing `[fail]` and another containing `[blocked]`. Both finish without a charge.
4. Try `[succeed] [duplicate]` to exercise duplicate delivery. One generation produces one charge.
5. Buy $5 of demo credits; no payment details are requested. Available balance excludes reservations for pending generations.
6. Open the usage report. Daily provider cost must equal Pixelforge's `/v1/usage`, and profit must equal customer charges minus provider costs. Dates use UTC completion time.

Other provider controls: `[slow]` takes roughly 60–90 seconds; `[overloaded]` exercises bounded retries. A maximum of three active requests per user prevents unbounded queues. Pixelforge also limits concurrency across the whole provider account, so a busy provider may delay a request.

## Local setup

Requirements: PHP 8.3+ (tested with 8.5), Composer, Node 24, SQLite or PostgreSQL. PHP extensions include PDO, mbstring, and pcntl for workers/concurrency checks.

```sh
composer install
npm ci --ignore-scripts
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan studio:demo-user
npm run build
php artisan serve
```

In separate terminals:

```sh
php artisan queue:work database --sleep=1 --tries=10 --timeout=190
php artisan schedule:work
```

Set `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` in `.env`. Configure the matching key's webhook URL to `https://YOUR_HOST/webhooks/pixelforge`. Use a separate provider key per environment: usage is reconciled per key. Localhost cannot receive public webhooks without an HTTPS tunnel; polling still recovers completion. Keep `QUEUE_CONNECTION=database`, because the accepted generation, credit reservation and queue insert must commit in the same database transaction. Set `DEMO_ENABLED=true` only for an intentionally public assessment demo.

The public demo accepts reference images only from configured HTTPS image hosts (`PIXELFORGE_REFERENCE_HOSTS`). Keep this list to trusted provider-owned hosts. `PIXELFORGE_DAILY_BUDGET_USD` defaults to $5 of quoted maximum charges across all accounts per UTC day. This is a conservative admission ceiling: failed requests still consume that day's allowance, and a provider price or usage change can exceed a quote. Rate limits also apply by account or IP and globally to registration and generation; database cache keeps them shared across workers. The application validates its public host, limits request bodies to 1 MB, and sets browser security headers.

New password registration is disabled by default (`PASSWORD_REGISTRATION_ENABLED=false`) because the assessment has no email verification service; otherwise a visitor could reserve someone else’s email address. Existing password accounts can sign in, and new visitors can use verified Google or Apple sign-in or the shared demo. Enable password registration only after adding email verification.

## Optional paid image integration

The deployed assessment keeps `FOUNDRY_IMAGES_ENABLED=false` and `STRIPE_LIVE_MODE=false`. No live credit purchases are offered. Azure reports its subscription spending limit as **Off**; eligible startup-credit usage is not a guarantee against card charges after credits expire or run out. Do not enable this integration until the owner’s credit-only condition is verified. The existing Azure model policy has not been relaxed.

A Stripe **test** product offers a one-time **$2 USD** pack. Configure server-only `STRIPE_SECRET_KEY`, `STRIPE_CREDIT_PRICE_ID` and `STRIPE_WEBHOOK_SECRET`. Checkout always uses that fixed price and quantity. Configure `/webhooks/stripe` for `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `charge.refunded` and `checkout.session.expired`. The signature, account mode, session owner, price, amount and currency are checked before crediting. The return URL also reconciles with Stripe; replayed and simultaneous callbacks cannot credit twice. Refunds remove the refunded amount from credits, including after spending; a negative balance prevents further generation.

Demo, Stripe test and live balances are separate. A new account starts with $5 demo credits and zero paid credits. Test purchases and generation are restricted to the verified `STRIPE_TEST_EMAIL`; the shared reviewer account cannot buy credits. This prevents free demo top-ups or Stripe test payments from funding the public live wallet.

The image adapter targets Azure `gpt-image-1-mini` through `/openai/v1/images/generations`, with one PNG per request. Set `FOUNDRY_ENDPOINT`, `FOUNDRY_API_KEY`, and `FOUNDRY_IMAGE_DEPLOYMENT` only after billing approval. Reference images remain demo-only. Current configured Global Standard token rates are $2/million input-text, $2.50/million input-image and $8/million output-image tokens, verified against the [Azure Retail Prices API](https://prices.azure.com/api/retail/prices) on 16 November 2026. Quotes are estimates, and successful customer charges use reported token usage plus 25%, capped at the quoted maximum. Any excess is absorbed by the operator. Unknown or cached usage needs reconciliation instead of guessing a charge.

Image reservations and successful charges update atomically. Generated PNGs are stored in PostgreSQL and served only to their owner. The configurable $5 daily application budget is a conservative admission control, **not an Azure billing or credit-card cutoff**. A request accepted by Azure may have uncertain outcome, so it is never automatically resubmitted after a timeout. Uncertain requests retain their hold and require operator reconciliation. Queue retry visibility is 240 seconds, above the 180-second image job timeout. Existing demo reports remain scoped to Pixelforge.

## Google and Apple sign-in

Google and Apple are optional. Their buttons appear only when the corresponding server credentials are configured. Set `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` for a Google web OAuth client. Register `${APP_URL}/auth/google/callback` as its redirect URI and publish the external app for reviewer access; only basic profile and email scopes are requested.

For Apple, register a Services ID linked to a primary App ID with Sign in with Apple, the deployment hostname, and `${APP_URL}/auth/apple/callback` as its return URL. Set `APPLE_CLIENT_ID` to the Services ID, plus `APPLE_TEAM_ID`, `APPLE_KEY_ID`, and `APPLE_PRIVATE_KEY_BASE64` (the base64-encoded private `.p8` file). Keep the key and Google secret in runtime secret storage. Apple client-secret JWTs are generated on the server; no manually renewed six-month token is required.

Both providers create private workspaces with the same $5 simulated starting balance. An existing email account must sign in first and use **Account → Connect**; matching email addresses never merge accounts automatically. Stable provider identifiers determine subsequent logins. The shared demo cannot link personal identities. Provider access and refresh tokens are not retained.

Callbacks require a one-time, ten-minute state record bound to the browser with an encrypted Secure/HttpOnly cookie. Apple's cross-site POST also verifies a nonce in the signed identity token using the provider library. Only that callback is exempt from the normal form-CSRF check; ordinary session cookies retain SameSite=Lax. The public privacy notice is at `/privacy`.

## Correctness decisions

- **Exact money:** integer picodollars (12 decimal places). `brick/math` performs decimal arithmetic; no floating-point value enters the ledger. Provider amounts retain their nine-decimal precision, including quarter-nanodollar results from the exact 25% markup. Display rounding never changes stored charges. Prices use the generation's own frozen rates and actual usage.
- **Quotes:** live pricing is cached for 60 seconds. The estimate uses midpoint reasoning usage; the range includes text-token variance and reasoning bounds. Unicode characters are counted server-side. Reference images contribute their documented fixed token count.
- **Balances:** lock the user before checking available balance and reserving the quoted maximum. Successful completion debits the actual charge and releases the reservation atomically. Failure releases the reservation without a charge. Simulated purchases have a unique request ID.
- **Submission:** a local UUID exists before calling the provider and is included in metadata. The same provider idempotency key is retained across retries. A timeout is an ambiguous acceptance, so the application does not invent a free failure or generate a new key. After repeated ambiguous errors it retains the hold and flags operator attention.
- **Settlement:** callbacks and polling use one service and the same lock order: user, then generation. A unique generation charge is the database backstop. Terminal states cannot regress; a late queued POST response cannot overwrite an earlier successful webhook.
- **Callbacks:** HMAC over timestamp plus the raw body, constant-time comparison, five-minute past/future window and bounded payload size. Event ID and body hash prevent duplicate processing or reuse with altered content. Receipt and settlement share a transaction, allowing failed handling to be retried safely. Unknown local IDs are acknowledged without creating or attaching records.
- **Recovery:** database-backed jobs, bounded exponential retries, `Retry-After`, polling fallback and a scheduled recovery scan. Neither UI polling nor viewing history calls the provider. The provider is contacted by workers and the report.
- **Reports:** completed UTC date, daily and per-user totals, exact provider usage comparison including generation count. An unavailable provider report is explicitly unverified, never represented as a successful reconciliation.
- **Access:** session authentication, CSRF protection, request validation, authorization, throttling and user-scoped history. Provider credentials stay on the server. The public demo's admin capability is limited to reading anonymized reports.

## Tests

```sh
php artisan test
vendor/bin/pint --test
npm test
npm run build
```

Eleven Vue component tests cover quote timing, stale responses, input choices, credit/concurrency controls, pricing failure recovery and exact-cost details. They run in jsdom and do not substitute for browser acceptance.

The feature suite uses HTTP provider fakes and prevents stray network requests. It covers exact pricing, Unicode, invalid input, authentication and user isolation, duplicate requests/events, early and out-of-order events, frozen rates, retries, ambiguous acceptance, failed generations, balance reservations, credit idempotency and report reconciliation.

Run the same suite on a disposable PostgreSQL database. Then run the process-concurrency check; it refuses any database other than `frame_studio_test` in `APP_ENV=testing`.

```sh
docker run --rm -d --name frame-studio-test-pg \
  -p 127.0.0.1:55439:5432 \
  -e POSTGRES_DB=frame_studio_test -e POSTGRES_USER=studio_test \
  -e POSTGRES_PASSWORD=local-test-only postgres:17-bookworm

export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55439
export DB_DATABASE=frame_studio_test DB_USERNAME=studio_test
export DB_PASSWORD=local-test-only DB_SSLMODE=disable
php artisan test
APP_ENV=testing php scripts/verify-concurrency.php
```

The concurrency script forks competing processes against PostgreSQL and checks overspend prevention, atomic durable jobs, simultaneous settlement and duplicate submission, including paid wallet reservations, concurrent Stripe fulfillment and the shared demo budget. CI runs SQLite, PostgreSQL, concurrency, formatting and the production frontend build.

For an authorized live deployment, `scripts/live-smoke.py URL` exercises real HTTP sessions, CSRF, quotes, credits, provider success/failure/decline, image delivery and exact usage reconciliation. Install Python `requests` first or run it with `uv run --with requests python`. It creates labelled simulated generations; it is not a visual browser test. See `docs/validation.md` for executed evidence and remaining checks.

## Deployment

The multi-stage Dockerfile builds Vue assets and installs production Composer dependencies. FrankenPHP serves HTTP on port 8080 as `www-data`. Supervisor runs the web server, two queue workers and the recovery scheduler. Worker timeout is below the database queue's retry window. Secrets are supplied at runtime, never embedded in the image or committed.

This assessment uses its own Azure Container App and PostgreSQL database/login. It shares the existing environment and database server, without modifying other applications. Azure provides HTTPS ingress. A dedicated managed identity can pull the container image. The application database login cannot create databases or act as a superuser. One replica is sufficient for the assessment; state lives in PostgreSQL, not the container filesystem.

Set production `APP_URL`, `APP_KEY`, database credentials, `DB_SSLMODE=require`, `SESSION_SECURE_COOKIE=true`, `APP_DEBUG=false` and the two provider secrets. Point the provider webhook at the deployment. Startup applies migrations and prepares the demo user. Use the database queue and database cache/session stores.

## Scope and tradeoffs

The public assessment retains simulated purchases and an intentionally shared reviewer workspace. Pixelforge image links expire after 24 hours while their history and billing records remain visible. The optional paid extension is disabled, with Stripe sandbox browser validation and provider-fixture accounting tests only; no successful Azure image request has been verified. Password-account email verification, password recovery, dispute automation, tax handling and customer-facing operator tooling remain outside the assessment. These must be reviewed before a public paid launch.

For the original Pixelforge demo, a provider price increase between quote and acceptance can exceed the reserved estimate. Actual completed usage remains authoritative; the ledger records the exact charge and further unaffordable submissions are blocked. A real paid product would require a provider-enforced price ceiling or a product policy for absorbing that difference. After repeated ambiguous submission failures, an operator must inspect provider records before releasing a reservation; silently declaring failure could lose a billable generation.
