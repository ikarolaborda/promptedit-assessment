# Browser acceptance checklist

Status: pending access to the browser window. HTTP tests and component tests do not substitute for this checklist.

- Desktop: sign-in page, shared demo entry, empty state and populated history; no clipped or overlapping content.
- Mobile: narrow viewport, navigation, composer, history, price details and report horizontal overflow.
- Keyboard: Tab order, focus visibility, frame selection, quality selection, reference toggle, submit and sign-out.
- Create: edit prompt and frame/quality/references; estimate updates before the generate button enables.
- In-flight: observe queued/processing and automatic transition to success without refreshing.
- Result: image renders, exact charge and usage details expand, balance and held credits update.
- Failure: [fail] and [blocked] show a clear explanation, zero charge and released reservation.
- Credits: buy simulated credits once; double clicks and retries do not duplicate the purchase.
- Account: register a separate account, sign out/in, verify private history and forbidden admin report.
- Report: daily and per-user costs, charges and profit reconcile exactly; date filtering works.
- Provider dashboard: configure the matching key's HTTPS webhook URL, copy its signing secret privately, observe successful signed deliveries and duplicate delivery with one charge.

Record observed results and screenshots before marking complete.
