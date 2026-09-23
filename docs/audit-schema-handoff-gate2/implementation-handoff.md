# Connector schema evidence handoff — Gate 2

## Scope

Adds a bounded `schemaEvidence` object to each successfully parsed connector page. It records version, extraction status, presence, up to 20 normalized `@type` values, fixed provenance, a capped script count, and one allowlisted failure reason. It never uploads JSON-LD bodies, scripts, or full HTML. The v1.17.8 continuation/recovery worker path is unchanged.

Latest implementation commit: `b6c71528999fdb9f4a36e8340487c323940005a3` (exact parent `3221ca3c362b4ade86963bf4d4e874b8a8bd9e8e`).

## Data and safety contract

- `status=measured` requires a boolean `present` value.
- Legal quoted/unquoted and mixed-case JSON-LD type attributes are recognized.
- Opening tags are scanned in one bounded pass that respects single- and double-quoted attributes, skips HTML comments, and skips completed script bodies. A `>` inside an earlier attribute no longer truncates the tag.
- Script attributes are then parsed structurally. Only the actual case-insensitive `type` attribute can declare JSON-LD; MIME-like text inside `data-note`, `data-type`, ARIA, or any other quoted value is ignored. Empty, duplicate, or missing-assignment `type` attributes fail closed.
- An unfinished candidate script opening tag is `extraction_failed` with `unterminated_script_tag`; it can never become measured absence.
- Malformed, ambiguous, unterminated, oversized, or over-limit JSON-LD produces `status=extraction_failed`, `present=null`, and a bounded reason.
- Input is capped at 2 MB, each JSON-LD body at 256 KiB, total inspected JSON-LD at 512 KiB, and scripts at 20. A 21st block fails closed instead of allowing a partial measurement.
- Types are unique, at most 20, and at most 80 safe characters each.
- The existing same-host fetch, redirect, queue, claim, lease, chunk, replay, and lock rules are unchanged.
- This commit does not package, publish, install, call the Hub, or spend credits.

## Evidence

- `php -l includes/class-dhc-crawler.php`: pass.
- `node --test tests/*.test.js`: 50/50 pass.
- Functional PHP reflection fixtures verify present, absent, malformed, quoted/unquoted/mixed-case attributes, quoted `>` attributes, MIME decoys inside other attributes with both valid-JSON and ordinary-JS bodies, duplicate/empty/missing-assignment types, comment/attribute false positives, type cap, 21st-block fail-closed behavior, size bounds, ambiguous/unterminated markup, and non-persistence of script contents.

## Environment

Verified with PHP 8.5.5 and Node.js 24.12.0 on macOS 26.3 arm64. The connector suite is self-contained and required no network or external provider.

## Known limitation

The evidence measures presence and types only. It cannot claim Schema validity. A production canary and signed connector release remain Gate 3/4 work.

## Rollback

Revert the bounded implementation commit. No migration or persisted WordPress state is introduced.
