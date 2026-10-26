# UX-09 — One-primary CTA audit

Date: 26 October 2026 · Method: headless Chrome at 1440px, computed visible filled `.mz-btn` (excluding `mz-btn--outline`) grouped by nearest `section`, `article`, `.mz-saas-card`, `.mz-cta-band`, `.mz-hero` or `footer`.

## Pages audited

`/`, `/send-mzigo`, `/track-mzigo`, `/pricing`, `/earn`, `/contact`, `/blog`, `/blog/how-to-send-a-parcel-in-kenya`, `/locations`, `/send-parcel-nairobi`, `/partners`, `/partners/become`, `/help`, `/faq`, `/about`, `/terms`, `/login`, `/signup`, `/refer`.

## Result

- **Zero sections contain more than one filled primary CTA.**
- Each section uses one filled primary with secondary actions as `mz-btn--outline`, `mz-tab` or text links.
- No duplicate-primary CTA groups were found after element-based grouping (an earlier class-based pass produced false positives across repeated cards).

## Guardrails for new work

- One filled CTA per section.
- Secondary action = outline button, text link or tab.
- Do not add a second filled button beside a primary just to promote another destination.
- Keep CTA labels verb-first and destination-specific.
- Re-run this audit when adding a new landing section or campaign band.
