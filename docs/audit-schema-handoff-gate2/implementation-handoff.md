# Connector schema evidence handoff — Gate 2

## Scope

Adds a bounded `schemaEvidence` object to each successfully parsed connector page. It records version, extraction status, presence, up to 20 normalized `@type` values, fixed provenance, a capped script count, and one allowlisted failure reason. It never uploads JSON-LD bodies, scripts, or full HTML. The v1.17.8 continuation/recovery worker path is unchanged.

Implementation commit: `7dc6134b6fa6a4e823d40937597f14aa7bae93db` (parent repair head `9232b062e5e0e57f611bcb03a26ceef20662c5e3`).

## Data and safety contract

- `status=measured` requires a boolean `present` value.
- Legal quoted/unquoted and mixed-case JSON-LD type attributes are recognized.
- Opening tags are scanned in one bounded pass that respects single- and double-quoted attributes, skips HTML comments, and skips completed script bodies. A `>` inside an earlier attribute no longer truncates the tag.
- An unfinished candidate script opening tag is `extraction_failed` with `unterminated_script_tag`; it can never become measured absence.
- Malformed, ambiguous, unterminated, oversized, or over-limit JSON-LD produces `status=extraction_failed`, `present=null`, and a bounded reason.
- Input is capped at 2 MB, each JSON-LD body at 256 KiB, total inspected JSON-LD at 512 KiB, and scripts at 20. A 21st block fails closed instead of allowing a partial measurement.
- Types are unique, at most 20, and at most 80 safe characters each.
- The existing same-host fetch, redirect, queue, claim, lease, chunk, replay, and lock rules are unchanged.
- This commit does not package, publish, install, call the Hub, or spend credits.

## Evidence

- `php -l includes/class-dhc-crawler.php`: pass.
- `node --test tests/*.test.js`: 49/49 pass.
- Functional PHP reflection fixtures verify present, absent, malformed, quoted/unquoted/mixed-case attributes, quoted `>` attributes, comment/attribute false positives, type cap, 21st-block fail-closed behavior, size bounds, ambiguous/unterminated markup, and non-persistence of script contents.

## Known limitation

The evidence measures presence and types only. It cannot claim Schema validity. A production canary and signed connector release remain Gate 3/4 work.

## Rollback

Revert the bounded implementation commit. No migration or persisted WordPress state is introduced.
