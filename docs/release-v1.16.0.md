# Dsquared Hub Connector v1.16.0

## Release purpose

v1.16.0 adds renewable, claim-bound large-site crawling. One Website Health or
Marketing Audit run can crawl up to 500 pages without splitting the customer
experience into separate audits.

## Security and lifecycle contract

- Every accepted or idempotently repeated chunk rotates the claim token.
- Renewal remains bound to the exact Hub job, website, connector key, and claim
  nonce.
- A claim expires after one hour without accepted progress and after four hours
  in all cases.
- Failed uploads restore the pre-tick URL frontier so pages are not silently
  skipped on retry.
- The plugin advances only when the Hub returns an explicit success plus a new
  claim token.
- The Hub enforces the cumulative 500-page maximum atomically.

## Required release verification

1. Record the clean plugin source commit used to build the ZIP.
2. Build `dsquared-hub-connector.zip` with one `dsquared-hub-connector/` root.
3. Record and independently verify the ZIP SHA-256.
4. Publish the immutable v1.16.0 asset and verify the Hub update-check returns
   v1.16.0, the exact asset URL, and the same digest.
5. Deploy the coordinated Hub release whose minimum crawler version is v1.16.0.
6. Install v1.16.0 on one internal site and run both a small canary and a crawl
   above 100 pages.
7. Confirm `queued → offered → claimed → crawling → finalization_pending →
   completed`, a positive authoritative page count, and the same selected
   website ID throughout.
8. Switch clients while the scan is active and confirm no stale result renders.

Do not enable 500-page scans broadly until both canaries pass.
