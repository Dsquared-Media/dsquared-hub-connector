# AI Discovery v1.21.0 design contract

## Immutable source and baseline

- Product scope: AI Discovery editing, crawler directives, and IndexNow submission.
- Baseline plugin tag: `v1.20.0` (`dfe335495bad8137f147b3a410332a72483f3c78`)
- Baseline surfaces: `/robots.txt`, `/llms.txt`, and `/wp-json/dsquared-hub/v1/status` on a representative WordPress installation.
- Acceptance browsers: current Chromium, 100% zoom, DPR 1, reduced motion off, normal scrollbars.
- Acceptance viewports: 1440x900, 1024x768, 768x1024, and 390x844.

## Scope and preservation contract

Only the AI Discovery module, its admin panel, version metadata, documentation, and focused tests may change. Existing subscription checks remain authoritative. Schema, forms, site health, event tracking, content publishing, and unrelated connector routes must retain their existing behavior.

The last generated static `llms.txt` and `llms-full.txt` remain available if the subscription lapses. New generation, Hub writes, and IndexNow submissions remain unavailable while AI Discovery is not entitled.

## Page and component contract

The existing AI Discovery tab keeps the Business Profile form and adds one editor card below it:

- mode selector with `Auto-generate`, `Auto-generate + append my text`, and `Use my file exactly as written`;
- curated Links repeater with title, absolute URL, description, section, sort order, enabled toggle, and remove action;
- conditional custom-content textarea;
- separate full-file manual toggle and textarea;
- actions for `Add link`, `Preview & validate`, `Save & Regenerate`, and `Reset to auto`;
- side-by-side final-file preview and validation panel on desktop, stacked below 900px;
- validation rows for Markdown structure, live link status, noindex exclusion, and 100KB size ceiling;
- generated-files card retains current URLs and adds `Submit all public URLs now`.

Controls use the plugin's existing Plus Jakarta Sans typography, white cards, neutral borders, indigo focus state, 8px control radius, and existing primary/outline button tokens. Every control has a visible label and keyboard-operable native input.

## Responsive and state contract

- No page-level horizontal overflow at any acceptance viewport.
- Repeater rows use a seven-column grid at 1440px, a two-column grid below 1100px, and one column below 600px.
- Preview and validation each preserve long URLs and Markdown with wrapping; no horizontal scrollbar is required to read content.
- Empty links show one editable row and an explicit empty validation state.
- Loading disables the initiating action and keeps the rest of the form readable.
- Success and error messages use the existing inline status region and do not rely on color alone.
- Preview does not save. Reset and save are nonce-protected `manage_options` mutations.

## Data, security, and mutation contract

- One versioned option stores mode, curated links, custom content, full-file mode/content, and `updated_at`.
- Links are sanitized and validated; saved output excludes thank-you/noindex targets and targets that fail the cached 200 check.
- Hub REST reads and writes use the existing `DHC_API_Key::authenticate_request` path and selected-site connector key. Missing/invalid credentials return 401.
- IndexNow queues at most 100 URLs per request and sends no more than one request per 60 seconds.
- Admin and Hub writes log their source to the existing activity log.
- Upgrade regeneration runs after WordPress initialization and migrates legacy raw content without silently deleting it.

## Mismatch ledger

| ID | Severity | Screen/state | Baseline evidence | Exact mismatch | Required correction | Status |
|---|---|---|---|---|---|---|
| AD-01 | P0 | IndexNow key URL | Correct body with 404 | WordPress 404 status survives the handler | Clear 404, send 200 and UTF-8 text headers, exit | Resolved; regression covered by `ai-discovery-v121.test.js` |
| AD-02 | P0 | robots.txt | Three `Allow` directives precede any `User-agent` | Invalid robots syntax | Append valid grouped directives after existing Yoast output without duplicates | Resolved; behavior test verifies complete groups |
| AD-03 | P0 | llms.txt | No Markdown links; static response has no charset | File is not a useful discovery index and may mojibake | Add verified sectioned links and UTF-8-safe delivery/regeneration | Resolved; generated links filter noindex and thank-you pages |
| AD-04 | P1 | AI Discovery admin | Profile form only | Missing editor, modes, repeater, preview, validation, and submit action | Implement full editor and responsive states | Resolved; editor remains inside the AI Discovery tab |
| AD-05 | P0 | Hub API | Only legacy profile/raw/ping routes | Missing settings/read/regenerate/submit contracts | Add four authenticated routes and 401 behavior | Resolved; existing API-key/subscription gate retained |
| AD-06 | P0 | Upgrade/cache | Existing static file can outlive new code | Upgrade can appear unchanged | Migrate, regenerate on version upgrade, overwrite stale physical files, and surface validation | Resolved; first load after upgrade migrates and regenerates |

## Truthful limitations

The plugin can enforce `Content-Type: text/plain; charset=utf-8` for WordPress-served files and writes Apache charset/header rules for physical files. A host that bypasses both PHP and Apache for static `.txt` responses controls the final HTTP header; generated content therefore also normalizes common smart punctuation to ASCII-safe equivalents so it cannot render as mojibake when such a host omits a charset.
