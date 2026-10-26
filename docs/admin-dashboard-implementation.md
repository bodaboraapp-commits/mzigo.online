# Admin Customer Overview — P0 / P1 / P2 Implementation

Implemented 26 October 2026. Proposal: `docs/admin-dashboard-audit-proposal.md`.

## What shipped

A customer-operations overview sits in the **Analytics** section. The new **Overview** sidebar section is the default admin landing and holds the legacy performance charts, Platform detail (workspace stats) and Platform API usage. The customer journey chart remains in Analytics as the second row, directly above Needs attention now. Analytics contains the customer-promise overview with the customer journey chart as the second row, above Needs attention now; Experience/runner-quality, Growth, Platform-detail and Sender-cohort cards were removed on request; the remaining cards are a top-5 Carrier scorecard and a top-5 City/route table.

### P0 — read-only overview
- Five customer-promise KPIs: on-time delivery, fee collection, claims resolved, customer rating/CSAT, repeat senders.
- **Needs attention now** queue with SLA age, amount at risk, severity, owner, SLA hours and Review links into existing sections.
- Today pulse: registered, collected, active errands, runners online, new receivers, runner jobs done.
- Money snapshot: fees collected, outstanding at collection, pending runner payouts, discounts, MRR.
- Quick actions: create runner job, review KYC, contact inbox, insurance retry.
- Customer journey: registered → dropped off → in transit → ready → paid → collected; drop-off/collection/unpaid-ready rates and collection p50/p90 hours.
- Experience, growth and platform-detail cards (later removed from Analytics on request; underlying metrics remain in the API).
- 30d / 90d / 12m range toggle.
- Read-only endpoint: `api/admin-overview.php`.
- New **Overview** sidebar item holding the legacy performance charts plus Platform detail and Platform API usage; Analytics contains only the customer promise overview.

### P1 — instrumentation and SLAs
- **Promise windows:** `parcels.promised_delivery_at`, set from admin-configurable `delivery_promise_hours` (default 48h); existing parcels backfilled in migration.
- **Stage timing:** derived from existing `scans` (dropped off, transit, ready, collected), used for on-time and collection p50/p90.
- **Parcel CSAT:** new customer dashboard “Rate your delivery” card and `customer.php action=rate_parcel`; `csat_rating`, `csat_comment`, `csat_at` stored on parcels.
- **Contact/claims SLA:** contact first-reply median and unanswered count; claims resolved % and median resolution days.
- **Runner quality:** completion %, average rating, KYC pending, payouts pending.
- **Growth KPIs:** new senders/receivers, referrals, K-factor, rewards, coupon redemptions.
- Promise window editable in the overview header via `admin.php action=save_promise_config`.

### P2 — optimisation
- **Sender cohorts:** monthly new senders, average parcels, average platform fees (LTV proxy) and repeat senders.
- **Carrier scorecards:** volume, paid %, average delivery hours, claims.
- **City / route heatmap:** top corridors by parcels and GMV.
- **Alert routing/escalation:** severity, owner and SLA on every attention row; **Ack** and **Escalate** actions stored in `admin_alert_actions` via `api/admin-alert-action.php`.

## Data / files
- Migration `033_promise_csat.sql`: promise window, CSAT fields.
- Migration `034_alert_actions.sql`: alert acknowledgements and escalations.
- `api/admin-overview.php` — additive read endpoint.
- `api/admin-alert-action.php` — ack/escalate.
- `api/admin.php` — additive `save_promise_config` action only.
- `assets/js/site.js` — overview renderer, CSAT card, alert action handlers.
- `assets/css/site.css` — overview layout/responsive styles.
- `api/register-parcel.php` — sets promised delivery time.
- `api/customer.php` — CSAT action.

## Compatibility verification
- **20 existing admin nav keys unchanged** (analytics, workspaces, invoices, transactions, unassigned, claims, runnerjobs, kyc, insurance, messages, sessions, users, communications, points, activities, growth, faqs, cms, seo, settings).
- Existing hash/deep-link switching still works (verified KYC section switch).
- Existing legacy analytics KPI cards and charts still render below the overview.
- No existing API action keys renamed; no table/column renames or drops. New columns are additive.
- No workflow IDs changed; attention rows link to the existing workflows.

## Verification
- Overview rendered: 5 promise KPIs, 4 attended alert groups, 20 nav keys, legacy analytics present.
- Alert **Ack** returned “Alert acknowledged.” and wrote `admin_alert_actions`.
- **Escalate** API returned “Escalated to the owner team.”
- Promise setting saved to 48h via the overview action.
- Customer CSAT 5★ saved to parcel `TRK-DEMO0C0BB5` and appeared in overview as “Parcel CSAT 5.0 / 5”.
- Mobile: no horizontal overflow; layout switches to 2-column KPIs/stacked cards.
- PHP lint, JS syntax and SEO validator clean; no page errors.

## Known limitations / next iteration
- On-time % depends on the configured promise window; demo data has sparse promise samples and old parcels, so early percentages are not representative.
- Manual promise override per route/customer is not built; P1 currently uses one platform-wide window.
- Stage timestamps are derived from scans; canonical columns would make p50/p90 more robust.
- Alert Ack/Escalate records an audit event but does not yet notify an owner group or change the alert state after reload.
- LTV is a platform-fee proxy, not a full customer-acquisition-cost model.
- CSAT card appears for paid parcels; customers with no paid parcel see no card.
