# Connector schema evidence handoff — Gate 2

## Scope

Adds a bounded `schemaEvidence` object to each successfully parsed connector page. It records version, extraction status, presence, up to 20 normalized `@type` values, fixed provenance, and a capped script count. It never uploads JSON-LD bodies, scripts, or full HTML. The v1.17.8 continuation/recovery worker path is unchanged.

## Data and safety contract

- `status=measured` requires a boolean `present` value.
- Malformed JSON-LD produces `status=extraction_failed` and `present=null`.
- Types are unique, at most 20, and at most 80 safe characters each.
- The existing same-host fetch, redirect, queue, claim, lease, chunk, replay, and lock rules are unchanged.
- This commit does not package, publish, install, call the Hub, or spend credits.

## Evidence

- `php -l includes/class-dhc-crawler.php`: pass.
- `node --test tests/*.test.js`: 43/43 pass.
- Functional PHP reflection fixtures verify present, absent, malformed, type cap, and non-persistence of script contents.

## Known limitation

The evidence measures presence and types only. It cannot claim Schema validity. A production canary and signed connector release remain Gate 3/4 work.

## Rollback

Revert the bounded implementation commit. No migration or persisted WordPress state is introduced.
