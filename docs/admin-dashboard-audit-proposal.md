# Admin Dashboard — Post-Implementation Audit & Wave 2 Proposal

**Status: Wave 2 proposal only. No implementation in this pass.**
Updated 26 October 2026 after P0/P1/P2 customer overview shipped.

Implemented baseline: `docs/admin-dashboard-implementation.md`

---

## 1. What is already implemented (excluded from this proposal)

The following are shipped and are **not** being re-proposed:

- Customer-promise KPI band (on-time, fee collection, claims resolved, rating/CSAT, repeat senders).
- SLA “Needs attention now” queue with severity, owner, SLA hours and Review links.
- Ack / Escalate actions and audit logging.
- Today pulse, money snapshot, quick actions.
- Customer journey funnel with drop-off/collection/unpaid-ready rates and p50/p90 collection hours.
- Experience/runner-quality, Growth and Platform-detail cards (removed from Analytics on request; metrics remain available in the API).
- Promise windows (platform-wide default 48h) and customer parcel CSAT.
- Contact first-response and claims resolution/SLA metrics.
- Runner completion/rating/KYC/payout signals.
- Growth KPIs (new senders/receivers, K-factor, rewards, coupon redemptions).
- P2 cohorts/LTV proxy, carrier scorecards, city/route heatmap.
- 30d/90d/12m range, promise-window editor, existing legacy analytics preserved.
- Compatibility: 20 nav keys, hashes, API actions and workflows unchanged.
- **Overview sidebar** holds the legacy performance charts, Platform detail stats and Platform API usage; Analytics keeps the customer-promise overview with the customer journey chart as its second row, above Needs attention now.

Those items are out of scope below.

---

## 2. Focused audit of the shipped dashboard

Method: logged-in admin session at `/admin#analytics`; DOM/computed layout at 1440px and 390px; DB-backed overview endpoint; no mutations during the audit.

### Evidence

- The legacy performance charts, Platform detail stats and Platform API usage now render under the new **Overview** section; Analytics contains the customer overview plus a top-5 Carrier scorecard and top-5 City/route table. The split resolved the previous single 3,725px page.
- Promise KPI targets displayed: **0 of 5**; no definition or target badge on any KPI.
- Queue: **4 rows** with **4 generic “Review” links**; the two highest-volume groups both go to `/admin#unassigned`.
- Filter/search controls inside the overview: **0**.
- Export/download controls: **0**.
- Ack/Escalate writes an audit event, but the alert count and row do not change state, snooze, assign or notify anyone.
- 20 admin sections and legacy analytics still work; no page errors.

### Remaining findings

| # | Finding | Customer/admin impact | Severity |
|---|---|---|---|
| R2 | **KPIs lack targets, definitions, freshness and sample size.** 0/5 show target/SLA; on-time sample was only 10. | Numbers are not actionable; risk of over-trusting low samples. | **P0** |
| R3 | **Alert queue is shallow.** Aggregate counts only; generic review links; no item-level list, filters, owner assignment, snooze or state. | Agent still hunts; Ack/Escalate does not reduce the queue or notify an owner. | **P0** |
| R4 | **No segmentation/filters.** No city, carrier, workspace, sender-type or payment filter. | Cannot answer “which city/carrier/account is breaking?” | **P1** |
| R5 | **No drilldowns from KPIs/funnel.** Clicking values is not supported; export/board pack absent. | Investigation stops at the number. | **P1** |
| R6 | **Promise model is one global window.** No route/carrier/city/business-hours override; on-time uses sparse scans. | On-time % is not credible across different routes/customers. | **P1** |
| R7 | **Stage timing is scan-derived.** No canonical dropped/ready/collected timestamps or exception reasons. | p50/p90 can be noisy; cannot explain delays. | **P1** |
| R8 | **Support/claims SLA is partial.** Median first reply and claims resolved exist, but no SLA breach %, aging buckets, channel split, reopened/refund cases. | Hard to manage queues against a promise. | **P1** |
| R9 | **No finance reconciliation.** Fees collected/outstanding are shown, but no refunds, credits, COD vs M-Pesa reconciliation, payout overdue or invoice-to-payment mismatch. | Finance/customer disputes surface late. | **P1** |
| R10 | **Growth metrics stop at activity.** No retention 30/60/90, CAC, coupon lift vs control, referral cost per activation; LTV is an all-time fee proxy. | Can’t judge acquisition quality. | **P2** |
| R11 | **Alert routing is presentation-only.** Owner/SLA labels exist; no assignment, notification, escalation rules or acknowledgement state. | Same alerts recur; no accountability loop. | **P1** |
| R12 | **Single view for all roles.** Support, ops, finance and leadership see the same page; no saved views or defaults. | Each role scans irrelevant data. | **P2** |
| R13 | **Mobile is functional but long.** No compact mobile cockpit or sticky section switcher. | Fast phone triage is slower. | **P2** |
| R14 | **Data quality gaps remain.** No exception reasons, delivery attempts, business calendar, CSAT response rate or notification channel split. | Root-cause analysis is limited. | **P2** |

---

## 3. Wave 2 proposal — prioritised for impact

### P0 — make the overview trustworthy and usable (next)

1. **Alert detail + stateful triage**
   - Clicking an attention row opens a drawer with the affected parcels/claims/messages, not just a count.
   - Persistent states: open / acknowledged / snoozed / assigned / resolved; queue count respects state.
   - Owner assignment and Resolve action; optional note.
   - Escalate sends a notification to the owner team/channel (email/SMS/in-app) instead of only logging.
   - Filter chips by severity/owner/SLA breach.
   - Deep links point to a filtered list where the workflow supports it.

2. **KPI trust layer**
   - Every KPI: target (e.g. 95% on-time), definition tooltip, sample size, freshness timestamp and prior-period trend.
   - “Low sample” badge when sample <30; “No data yet” with next action.
   - Click KPI → filtered list.
   - Reconcile values to SQL in QA for 3 date windows.

3. **Platform detail collapse**
   - Optional: collapse the platform stats/API usage block in Analytics so the customer overview is the focus.
   - No existing chart API/keys removed; visibility only.

### P1 — operational depth

5. **Filters and saved views** — city, carrier, workspace, sender segment, payment status, date range; save view per admin/role.
6. **Route/carrier promise model** — configurable windows by route/carrier/business calendar; on-time by carrier/city; promise override per workspace/customer; show target vs actual.
7. **Support/claims SLA cockpit** — first-response SLA %, aging buckets (0–4h, 4–24h, 1–3d, 3d+), channel split, reopened claims, refund/credit reconciliation.
8. **Stage timestamps & exception reasons** — canonical dropped/delivered/ready/collected timestamps, exception reason, delivery attempts; use for p50/p90 and delay reasons.
9. **Finance reconciliation** — fees paid vs collected, refunds/credits, payouts overdue, invoice/payment mismatch, COD vs M-Pesa.
10. **Alert escalation rules** — severity thresholds, owner routing, notifications, SLA breach reminders, on-call recipient.

### P2 — optimisation and scale

11. **Retention/LTV/CAC** — cohort retention 30/60/90, CAC, referral cost per activation, coupon lift vs control.
12. **Scheduled board pack** — CSV/PDF export and scheduled email of Overview + Revenue summaries.
13. **Role-based dashboards** — Support, Ops, Finance, Exec default tabs; permissions preserved.
14. **Mobile cockpit** — compact top 5 KPIs, queue-only view, sticky tabs.
15. **Accessibility polish** — tooltips keyboard-accessible, status not colour-only, table captions, focus states.

---

## 4. Execution guardrails (unchanged)

- No changes to the 20 nav keys, URL hashes, `data-admin-*` mount IDs, API action keys or workflow IDs.
- Wave 2 is additive/visibility-only; all existing analytics and sections remain.
- Hidden/collapsed legacy charts must still load correctly when their tab opens.
- Alert state tables are additive; old audit events remain.
- P1 data capture uses nullable columns/new tables only.
- Feature flag `admin_overview_wave2`; fallback to the current dashboard.
- QA: nav/hash golden tests, legacy chart render, mobile 390px, empty/low-sample states, data reconciliation, no page errors, rollback.

---

## 5. Approval checklist — Wave 2

Reply **approve** or adjust:

1. **P0:** split Customer ops / Revenue tabs and add stateful alert detail/triage? [approve]
2. **P0:** add KPI targets, definitions, freshness, sample size and KPI drilldowns? [approve]
3. **P1:** route/carrier promise model, support/claims SLA cockpit and finance reconciliation? [approve]
4. **P1:** filtered lists, saved views and alert escalation notifications? [approve]
5. **P2:** retention/LTV/CAC, scheduled exports, role views and mobile cockpit? [approve]
6. **Guardrails:** visibility-only legacy move, additive state tables, no nav/hash/API/workflow ID changes? [approve]
7. **Priority:** P0 first, P1 second, P2 third? [approve]

After approval I will implement Wave 2 behind a flag and provide before/after screenshots and compatibility evidence.

---

## 6. Evidence

- Implemented dashboard: `_raw/saas-proof/admin-overview/admin-overview-section.png`
- Mobile: `_raw/saas-proof/admin-overview/admin-overview-mobile.png`
- Post-implementation audit screenshot: `_raw/saas-proof/admin-dashboard-audit/admin-audit-post-implementation.png`
- Metrics/layout evidence in section 2: 3,725px height; 0 targets; 0 filters; 0 exports; generic review links.
