# Dsquared Hub Connector v1.15.2

## Release source

- Plugin code commit: `4bc740a` (includes the heartbeat fallback and diagnostic-preservation fix)
- Artifact: `dsquared-hub-connector-v1.15.2-4bc740a-final.zip`
- SHA-256: `ba9ddcce0afff62657e09ce5b3a7dbab70e4dc9cf430d08ca27bf45d8dc7a401`

## What changed

- The pull crawler runs from its dedicated five-minute event and the existing, proven heartbeat event.
- A 270-second cadence lock prevents both events from processing two crawl batches in the same window.
- An expected locked fallback records `last_locked_at` without overwriting the meaningful scan result.
- The plugin accepts at most 100 pages, keeping the crawl and completion retries inside the Hub's one-hour claim lease.

## Verification completed

- All crawler contract tests pass.
- Every PHP file passes syntax validation.
- The release ZIP has one `dsquared-hub-connector/` root and passes archive integrity checks.

## Required live canary after publication

1. Install v1.15.2 on the Dsquared Media WordPress site.
2. Confirm a fresh heartbeat and that Hub reports the scanner as capable.
3. Queue a 1–5 page scan from Website Health.
4. Confirm `queued → offered → claimed → crawling → finalization_pending → completed`.
5. Confirm the finished audit has the same website ID and a positive page count.
6. Switch to a second client and confirm no Dsquared results render there.

Do not widen rollout until this canary passes.
