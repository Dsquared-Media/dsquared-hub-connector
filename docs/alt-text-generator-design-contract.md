# WordPress Alt Text Generator design contract

Status: **Implemented, awaiting independent QA**

## Source and environment

- Authoritative product behavior: Hub Alt Text Generator at the Hub commit paired with this plugin release.
- WordPress shell: Dsquared Hub Connector v1.19.0 at `3f426947adb8e79faf9f86f82ef3a87051af44f4`.
- No external mockup was supplied. The existing D2 Hub Connector admin system is the visual source of truth.
- Acceptance render targets: Chrome, macOS, 100% zoom, DPR 1, reduced motion off, at 1440×900, 1024×768, 768×1024, and 390×844.

## Page composition

- Add **Alt Text Generator** beneath D2 Hub in WordPress admin.
- Reuse the plugin header, connected badge, buttons, cards, borders, radii, type scale, colors, and Plus Jakarta Sans font.
- Header copy explains that images are detected locally, generation uses Hub credits, and nothing is saved before review.
- Summary row shows missing alt text, selected images, and expected credit cost.
- Filter controls: Missing alt text (default), All images, search, and Select visible.
- Responsive media grid: four columns on wide desktop, three at 1024, two at tablet, one at mobile. No page-level horizontal overflow.
- Sticky action bar within the content column contains selected count, exact credit quote, Generate, and Save reviewed changes.

## Image card and states

- Each card contains a checkbox, thumbnail, filename, current alt text, editable draft, character count, and status.
- Existing alt text is never overwritten merely by loading or switching a filter.
- Loading uses a visible progress state and disables duplicate submissions.
- Empty states distinguish no media, no missing alt text, and no search results.
- Connection, permission, insufficient-credit, provider, skipped-person, partial-success, and save errors remain visible and actionable.
- People and headshots show **Manual review needed** and do not receive generated descriptions or consume a credit after refund.
- Decorative images may be explicitly marked with an empty alt value; an unclassified blank draft cannot be saved as if complete.

## Interaction and mutation contract

- Selecting images is free. Immediately before confirmation, WordPress requests a short-lived signed quote bound to the connector's website wallet, current managed price, exact Media Library IDs, and one stable request ID.
- Generation begins only after an administrator clicks the priced Generate button.
- The browser never receives the Connector API key. WordPress proxies generation server-side over HTTPS using the existing `X-DHC-API-Key` contract.
- The Hub resolves the API key to exactly one active website and charges that website owner’s wallet. Cross-site assertions are rejected.
- Each successfully generated image costs the quoted unit price. Skipped people/headshots and provider/output failures are refunded using the existing reservation recovery system. The result separates charged, refunded, refund-pending, and net credits.
- Retrying after a lost or interrupted response reuses the same request ID and durable Hub receipt, even after cache eviction or a delayed return; WordPress retains the selection-bound ID in user metadata until it receives a terminal receipt.
- AI output is a draft. Saving requires a separate click and writes `_wp_attachment_image_alt` only for reviewed rows.
- Save validates capability, attachment identity, classification, and maximum alt length; it returns per-image receipts and logs the event.
- Repeated Generate clicks are disabled while a request is pending. Only the current request may update the page.

## Accessibility and responsive behavior

- Every input has a programmatic label; cards use fieldsets/legends where grouping matters.
- Status changes use an `aria-live` region. Focus moves to the result summary on completion or error.
- Buttons retain at least 44px touch targets on narrow screens.
- Thumbnail images use empty alt because the editable text and filename label the asset in the admin UI.
- Keyboard users can select, edit, generate, save, filter, and paginate without pointer input.

## Truthful limitations

- The WordPress surface uses the same Hub model and rules; it does not run an AI model inside WordPress.
- It operates on Media Library image attachments. Theme assets that are not registered as attachments remain in the Hub crawl workflow.
- Browser visual QA requires a WordPress admin fixture or test installation. Until equal-size renders pass, status remains **Implemented, awaiting QA**.

## Mismatch ledger

| ID | Severity | Screen/state | Source evidence | Current evidence | Exact mismatch | Required correction | Owner | Status | Verification |
|---|---|---|---|---|---|---|---|---|---|
| ALT-WP-01 | P0 | Navigation | User request | Dedicated plugin page and submenu | Generator exists in WordPress and Hub | Add WordPress admin screen | Implementation | Implemented | Menu contract + fixture render |
| ALT-WP-02 | P0 | Billing/auth | Existing Hub generator | Key-bound quote/generation routes with reserve/refund | Paid drafts are requested server-to-server | Add site-bound plugin API route with reserve/refund | Implementation | Implemented | 57 focused Hub/security tests |
| ALT-WP-03 | P0 | Save | Existing media write module | Nonce/capability guarded reviewed save | Local review writes attachment alt meta | Add nonce/capability guarded local save | Implementation | Implemented | Plugin contract + full suite |
| ALT-WP-04 | P1 | Desktop/mobile layout | Existing D2 admin shell | Responsive 4/3/2/1 grid and contained action bar | Fixture matches the plugin shell without overflow | Build adaptive grid and sticky action bar | Implementation | Implemented, QA pending | 1440 fixture + 768/390 wrappers reviewed |
| ALT-WP-05 | P1 | Loading/error/empty/skipped | Hub states | States implemented in PHP/JS | Loading, empty, error, partial, and manual-person states are present | Implement all defined states | Implementation | Implemented, QA pending | Contract tests; live WordPress interaction pending |
