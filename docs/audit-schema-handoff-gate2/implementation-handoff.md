# Connector schema evidence handoff — Gate 2

## Scope

Adds a bounded `schemaEvidence` object to each successfully parsed connector page. It records version, extraction status, presence, up to 20 normalized `@type` values, fixed provenance, a capped script count, and one allowlisted failure reason. It never uploads JSON-LD bodies, scripts, or full HTML. The v1.17.8 continuation/recovery worker path is unchanged.

## Data and safety contract

- `status=measured` requires a boolean `present` value.
- Legal quoted/unquoted and mixed-case JSON-LD type attributes are recognized.
- Malformed, ambiguous, unterminated, oversized, or over-limit JSON-LD produces `status=extraction_failed`, `present=null`, and a bounded reason.
- Input is capped at 2 MB, each JSON-LD body at 256 KiB, total inspected JSON-LD at 512 KiB, and scripts at 20. A 21st block fails closed instead of allowing a partial measurement.
- Types are unique, at most 20, and at most 80 safe characters each.
- The existing same-host fetch, redirect, queue, claim, lease, chunk, replay, and lock rules are unchanged.
- This commit does not package, publish, install, call the Hub, or spend credits.

## Evidence

- `php -l includes/class-dhc-crawler.php`: pass.
- `node --test tests/*.test.js`: 46/46 pass.
- Functional PHP reflection fixtures verify present, absent, malformed, quoted/unquoted/mixed-case attributes, type cap, 21st-block fail-closed behavior, size bounds, ambiguous/unterminated markup, and non-persistence of script contents.

## Known limitation

The evidence measures presence and types only. It cannot claim Schema validity. A production canary and signed connector release remain Gate 3/4 work.

## Rollback

Revert the bounded implementation commit. No migration or persisted WordPress state is introduced.
