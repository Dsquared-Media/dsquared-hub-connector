# Controlled connector release

Packaging, a manually installed canary, and a stable fleet publication are separate steps requiring their respective approval. Do not push a new stable release merely to make a ZIP available for testing. Older installed versions can force auto-updates once a newer Latest release appears.

## Prepare an immutable artifact

1. Combine the controlled auto-update, upload-filter and scanner repairs in one reviewed source commit. Keep the private alpha connector out of this public package.
2. Confirm the intended next version is unused in both tags and releases. Align the plugin header, DHC_VERSION, readme Stable tag and release notes.
3. Run the connector security workflow at the exact candidate head. Its review package job and the manual package workflow use the same standard-library Python packager. The manual workflow requires a full commit SHA and has read-only repository permissions. Neither workflow publishes a release, creates a tag, or replaces release assets.
4. To build locally, run `python3 scripts/package-plugin.py --commit FULL_40_CHARACTER_SHA --output-dir NEW_EMPTY_OUTPUT_DIRECTORY`. It reads the committed tree, ignores working/untracked files, rejects symbolic links, verifies version agreement and includes only the explicit runtime file list. It produces the ZIP, its SHA-256 file and a manifest recording source SHA, version and every file hash. Fixed ZIP metadata and stored entries avoid platform-specific compressor differences.
5. Download and retain the actual CI artifact. Confirm its source SHA, version, checksum, file manifest and canonical `dsquared-hub-connector/dsquared-hub-connector.php` path. Do not substitute GitHub's generated source archive.

## Canary before Latest

Obtain explicit approval naming the candidate SHA, ZIP checksum and one chosen site. Preserve the site's recoverable prior plugin package and settings. Manually install the retained ZIP only on that site; leave GitHub Latest and the Hub fallback channel unchanged. Verify frontend availability during Hub outage, admin/connection/heartbeat, preserved upload filters, scanner behavior and WordPress/hosting auto-update preferences. Record errors and stop on a regression. Do not reactivate a retired preview. Existing local tests do not establish fleet acceptance.

## Publish the tested bytes

After canary acceptance and separate fleet approval, verify the candidate source/tag, create a draft release, and upload the retained canary ZIP with its checksum and manifest. Inspect the draft asset's bytes and updater-selected ZIP before explicitly publishing. Never use `--clobber`, regenerate the approved ZIP or replace an asset after publication. A changed candidate or package needs a new version and review.

The manual packaging workflow does not replace the need for source/CI approval. When changing the release workflow itself, that change must be merged before creating any release tag; an older workflow on the default branch or tagged commit may still auto-publish. Inspect the effective workflow before any tag or release operation.

Confirm the actual GitHub Latest and Hub fallback metadata after approved publication. Observe client heartbeats and errors in controlled batches, with an agreed stop and recovery plan. Rolling back installed code is a separate deliberate site operation; publishing an older version does not automatically downgrade existing installations.
