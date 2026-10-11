# Connector 1.21.1 security release gate

Preparation only. No tag, GitHub release, auto-update publication, live install, or credential rotation is authorized by this record.

## Lineage and compatibility

- Deployment requires a compatible Hub that enforces narrow telemetry scope, exact-site credential validation, and remote revocation. Confirm that prerequisite separately before installation.
- Preserve the 1.20 Alt Text Generator and 1.21 AI Discovery capabilities, based on published 1.20 source `dfe335495bad8137f147b3a410332a72483f3c78`.
- Preserve the reviewed authentication implementation when preparing the public source and package.
- Use patch version 1.21.1, newer than 1.20.0 and 1.21.0. No 1.22 feature work is included; later feature releases must incorporate this security fix.
- AI Discovery/admin and Alt Text Generator runtime files are byte-identical to the 1.21 source. Updated tests retain 1.21 behavior coverage.
- Compatibility evidence is source preservation and isolated PHP/Node behavior, not proof of installed WordPress file identity or real-site upgrade acceptance.

## Gates

1. Run `node --test tests/*.test.js`.
2. Run `php tests/connector-containment.php` and `php tests/connector-uninstall.php`.
3. Lint every PHP file, including on the supported 7.4 and 8.3 CI matrix.
4. Build from the final exact commit:

```sh
git archive --format=zip --prefix=dsquared-hub-connector/ --output=dsquared-hub-connector.zip HEAD dsquared-hub-connector.php uninstall.php readme.txt README.md CHANGELOG.md LICENSE includes admin assets
shasum -a 256 dsquared-hub-connector.zip
```

5. Independently inspect the ZIP, verify file hashes against the source, execute the security fixtures against its extracted PHP, and record the exact SHA/checksum.
6. Online source-only CI uploads a review artifact only; never dispatch the existing `release.yml` workflow, push a `v*` tag, or publish a release before separate approval.

## Lifecycle contract / remaining limits

- Browser-visible configuration may contain only a validated narrow token bound to the current private-key fingerprint and site.
- Privileged incoming requests always revalidate with Hub and fail closed on revocation/outage. Subscription display caching is not authorization.
- Direct key add/update/delete clears stale derived telemetry and auth-cache state; late responses for a replaced key cannot restore it.
- Deactivation clears local telemetry/cache/retries but retains the private key for intentional reactivation.
- Uninstall removes local credential/options/cache/retry state. It does not revoke Hub keys. Permanent offboarding needs explicit Hub-side revocation; never describe local cleanup as complete remote revocation.
- Live credential rotation, plugin installation, and release publication are separate approval gates.
