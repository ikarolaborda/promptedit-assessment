# Browser acceptance checklist

Executed in Chrome on the Azure deployment, 16 November 2026. See [the validation record](validation.md) for evidence and limits.

- [x] Desktop sign-in renders and shared demo opens.
- [x] Private account signs in with an empty history; shared demo history is not exposed.
- [x] Desktop prompt, landscape/high settings and reference input update the quote before submission.
- [x] Queued/processing automatically becomes success; generated image renders.
- [x] Details expose full-precision charge and token usage.
- [x] Browser failure is terminal, free and releases the hold; blocked result was also visible in history and verified through HTTP.
- [x] Simulated $5 purchase updates the balance; duplicate purchase protection is covered by live HTTP and concurrent tests.
- [x] Daily/per-user report matches provider cost and counts; the date picker filters to one UTC day.
- [x] Mobile widths 440 and 375 render without page-level clipping; report tables are contained in their own overflow region.
- [x] At 375 pixels, navigation, prompt entry, portrait selection, quality change, Tab focus to Generate, Return submission and automatic completion work.
- [x] Desktop and mobile sign-out return to the rendered login page.
- [x] Signed provider processing/success/failure callbacks and duplicate callbacks return HTTP 204; exact reconciliation proves no duplicate settlement.

Registration validation, incorrect-password rejection and direct report authorization were tested over real HTTP. No claim is made for a complete screen-reader audit or physical iPhone/Safari validation.
