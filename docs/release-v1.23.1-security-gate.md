# Dsquared Hub Connector 1.23.1 release gate

## Purpose

Version 1.23.1 preserves the complete 1.23.0 plugin currently installed in the local WordPress test site and adds the reviewed credential-containment and REST route repairs. It is intentionally a patch release. It does not replace the existing connector with the separate 2.0 alpha plugin.

## Recovered source provenance

- Source package: `/Users/dannydonovan/Documents/Codex/artifacts/dsquared-hub-connector-v1.23.0-870368cf.zip`
- Package SHA-256: `306e60278d95dfe389622bf41b761750d5899785af1ed4b967c5cf80bf31977c`
- Package comment: `870368cf5f312e0b1575240c145795d5dc373bcf`
- The extracted package was verified byte-for-byte against the installed local `dsquared-hub-connector` 1.23.0 directory before recovery.

The recovery commit imports that exact package on top of the current repository history. The security patch is a separate commit so reviewers can distinguish recovered 1.23.0 capability from the 1.23.1 changes.

## 1.23.1 changes

- Bind the browser-visible telemetry credential to the current connector key and WordPress site.
- Reject stale or malformed derived telemetry credentials.
- Revalidate privileged Hub requests against the authoritative subscription service instead of trusting the display cache.
- Clear derived telemetry state after connector key changes and plugin deactivation.
- Keep private connector keys out of public telemetry markup and URLs.
- Route Content Decay and both Leads endpoints through initialized module instances.
- Remove obsolete static route registrations that pointed at callbacks not supplied by 1.23.0.
- Remove all local private and derived credentials during uninstall without attempting remote credential revocation.

## Preserved 1.23.0 capabilities

- Thirteen-screen WordPress admin experience.
- Form inbox and form-provider capture.
- Header, body, and footer tracking scripts.
- Custom event picker and activity log.
- AI Discovery, IndexNow, schema editor, content decay, site health, and crawler workflows.
- Review-first Alt Text Generator and existing billing contracts.

## Automated verification

- Node contract suite: 84 passed, 0 failed.
- Containment PHP suite: passed.
- Uninstall PHP suite: passed.
- PHP syntax check: every plugin and test PHP file passed.
- Diff formatting: required before the final commit.

## Remaining release gates

The source is not authorized for publication or installation merely because these checks pass. Before tagging or installing 1.23.1:

1. Build the review ZIP from the exact final commit and record its SHA-256.
2. Obtain independent Gate 3 review of that exact commit and package.
3. Install the exact ZIP in a disposable or backed-up local WordPress test site.
4. Verify plugin activation and version, valid and invalid connector authentication, Content Decay, `/leads`, `/leads/stats`, telemetry containment, and preserved 1.23 admin screens.
5. Obtain the owner's exact-SHA approval before merge, tag, publication, or production installation.

