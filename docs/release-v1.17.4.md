# Dsquared Hub Connector v1.17.4

## Release purpose

v1.17.4 removes the privileged WordPress connector key from public page HTML.
Public Event Tracker and Core Web Vitals requests use a separate Hub-issued
telemetry token that cannot authorize content, schema, SEO metadata, media, or
other privileged connector writes.

## Release status

The source candidate is prepared locally only. No tag, GitHub release, ZIP,
Hub update record, installation, or production deployment is authorized by
this document. Record the final 40-character source commit and ZIP SHA-256 only
after the release checks below pass on the exact committed tree.

## Security and upgrade contract

- The private `dhc_api_key` remains server-side and never appears in public
  Event Tracker or Core Web Vitals configuration.
- Browser telemetry uses `X-DHC-API-Key`; credentials are never placed in URL
  query parameters, access logs, or CDN cache keys.
- Existing installations provision a telemetry token on upgrade.
- Failed provisioning retries with persisted exponential backoff capped at one
  day. Heartbeat and admin traffic restore a missing retry event.
- Changing the connector key clears the old local telemetry token and retry
  state before provisioning a replacement.
- Full plugin deletion removes the telemetry token, retry state, and pending
  provisioning events.

## Required release verification

1. Run the complete Node test suite and confirm zero failures.
2. Run PHP syntax validation against every PHP file.
3. Run `git diff --check` and confirm a clean worktree at the release commit.
4. Build the ZIP with the GitHub workflow exclusions and exactly one
   `dsquared-hub-connector/` root directory.
5. Verify ZIP integrity, version header, `DHC_VERSION`, and WordPress stable tag
   all report v1.17.4; record its SHA-256.
6. Deploy the coordinated Hub telemetry-token backend before distributing this
   plugin version.
7. Upgrade one internal v1.17.3 site and verify token provisioning, public page
   source, browser request headers, origin enforcement, event delivery, and Core
   Web Vitals delivery.
8. Simulate one failed provisioning response and verify a later retry succeeds
   without duplicating scheduled events.
9. Rotate the connector key on the canary, verify a replacement telemetry token
   is issued, and confirm the old token is rejected by the Hub.
10. Widen rollout only after the canary passes and the Hub reports the expected
    plugin version and telemetry activity.
