# Dsquared Hub Connector v1.15.2

## Release source

- Plugin code commit: `4bc740a` (includes the heartbeat fallback and diagnostic-preservation fix)
- Artifact: `dsquared-hub-connector-v1.15.2-4bc740a-final.zip`
- SHA-256: `6db2236484e9ef58ba7db7d00b00c3192a144f33cbdd7bb7f2e99a097614f036`

## What changed

- The pull crawler runs from its dedicated five-minute event and the existing, proven heartbeat event.
- A 270-second cadence lock prevents both events from processing two crawl batches in the same window.
- An expected locked fallback records `last_locked_at` without overwriting the meaningful scan result.
- The plugin accepts at most 100 pages, keeping the crawl and completion retries inside the Hub's one-hour claim lease.

## Verification completed

- All crawler contract tests pass.
- Every PHP file passes syntax validation.
- The release ZIP has one `dsquared-hub-connector/` root and passes archive integrity checks.
- The archive was built deterministically from commit `4bc740a` with the
  release-workflow exclusions and a fixed `2026-08-15 00:00` entry timestamp.

## Required live canary after publication

1. Install v1.15.2 on the Dsquared Media WordPress site.
2. Confirm a fresh heartbeat and that Hub reports the scanner as capable.
3. Queue a 1–5 page scan from Website Health.
4. Confirm `queued → offered → claimed → crawling → finalization_pending → completed`.
5. Confirm the finished audit has the same website ID and a positive page count.
6. Switch to a second client and confirm no Dsquared results render there.

Do not widen rollout until this canary passes.
