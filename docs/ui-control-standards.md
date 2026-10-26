# Mzigo.online — Form & CTA Padding Standards (UI/UX + QC)

Audit date: 26 October 2026 · Scope: public pages, auth, send/track/contact forms, merchant dashboard, admin console, runner and customer dashboards.

## Standard control scale

| Control | Height | Horizontal padding | Radius | Notes |
|---|---|---|---|---|
| Text input / select / custom select | **44px** | **14px** | 12px | Single-line controls; icon-leading fields use 36px left padding and 16px right padding |
| Textarea | auto, **min 110px** | **14px** | 12px | Vertical padding 12px; `resize: vertical` |
| Primary / secondary CTA (`mz-btn`) | **44px** | **24px** | Pill | One filled primary per section; outline secondary |
| Compact CTA (`mz-btn--sm`) | **36px** | **16px** | Pill | Sign-out and dense admin actions only |
| Segmented tabs (`mz-tab`) | min **40px** | **12px** | 12px | Do not use for primary CTAs |
| Header search / header CTA | **44px** | 24px CTA / 16px+36px icon input | Pill | Matches control scale on desktop |

Design tokens live in `:root` (`--mz-control-h`, `--mz-control-px`, `--mz-control-radius`, `--mz-cta-h`, `--mz-cta-px`, `--mz-cta-sm-*`).

## Audit findings (before)

- Primary CTAs were **40px** on desktop while inputs were **44px**; mobile CTAs were 44px only via a mobile media override.
- Header CTA/search were **40px**, out of step with form controls.
- Sign-out and admin compact buttons used ad-hoc inline styles: `height:36px;padding:0 14px` and `height:32px;padding:0 14px`.
- Textareas defaulted to **92px** minimum with 12/14 padding (padding was correct, height inconsistent).
- Custom send-wizard selectors were **41px** with `padding:9px 12px`, unlike standard inputs.
- Tabs varied 39–42px due to missing min-height.

## Changes applied

- Tokenised control/CTA sizes and paddings in `assets/css/site.css`.
- `.mz-btn` → 44px / 0 24px; new `.mz-btn--sm` → 36px / 0 16px.
- `.mz-input`, `select`, custom `.mz-select__control` → 44px / 0 14px, 12px radius.
- `textarea.mz-input` → min-height 110px, padding 12px 14px.
- `.mz-tab` → min-height 40px with unchanged 10px 12px padding.
- Header search input and header CTAs → 44px.
- Replaced inline compact-button sizing (sign-out, restore defaults, add FAQ, error-page sign-in) with `.mz-btn--sm`.
- Phone touch targets remain at least 44px.

## Verification (after)

- CTA group: **44px / 0 24px** everywhere; compact actions **36px / 0 16px**.
- Inputs/selects: **44px / 0 14px**; custom wizard selectors now **44px / 0 14px**.
- Textareas: **12px 14px**, min 110px.
- Header row stays 88px; search/CTA 44px; no overflow at 1440/1280/1024/768.
- Mobile forms (`/login`, `/signup`, `/contact`, `/send-mzigo`, `/track-mzigo`): no horizontal overflow, controls 44px.

## Intentional exceptions

- **Text-action links** inside tables (Label · Receipt · Cancel · Revoke) are links, not buttons — zero padding by design.
- **FAQ accordion rows** use 18px vertical padding as full-width list rows.
- **Admin sidebar nav items** use 9px/10px padding for dense navigation, not CTAs.
- **Admin range filters** use a 32px compact tab variant in the analytics toolbar.
- **Icon-only circular buttons** (search submit, profile button) are 40px with zero padding by design.

## Review checklist for new work

- [ ] New form field uses `.mz-input` / `.mz-select` (never inline height/padding).
- [ ] New CTA uses `.mz-btn` (44px) or explicitly `.mz-btn--sm` (36px) for dense contexts.
- [ ] One filled primary per section; secondary outline; tertiary text link.
- [ ] Labels verb-first; touch target ≥44px on phones.
- [ ] Textareas use `.mz-input` and rely on the 110px minimum.
- [ ] No inline `height`/`padding` on buttons or inputs.
