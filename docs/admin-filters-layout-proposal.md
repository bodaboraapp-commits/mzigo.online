# Admin Dashboard — Segmentation & Filter Layout Proposal

**Status: layout proposal only. Nothing is wired yet.**
For approval before implementation · 26 October 2026

Wireframe:
- Desktop: `_raw/saas-proof/admin-dashboard-audit/filters-layout-main.png`
- Mobile: `_raw/saas-proof/admin-dashboard-audit/filters-layout-mobile.png`

---

## 1. Goal

Let admins answer these questions without leaving the dashboard:

- “Which **city/route** is behind the unpaid-ready or stuck-parcel count?”
- “Which **carrier** has the delays or claims?”
- “Which **workspace** or **public sender** is generating the problem?”
- “Are **business or individual senders** driving volume?”
- “Is the issue with **paid or unpaid** parcels?”

The same filter set should slice Overview and Analytics so both views agree.

---

## 2. Recommended layout (Option A)

**A shared, sticky filter bar immediately below the page title.**

```
Overview
Platform performance with filters applied to the visible data.     Updated 2 min ago

[City: All ▾] [Carrier: All ▾] [Workspace: All ▾] [Sender type: All ▾]
[Payment: All ▾] [Past 30 days ▾]                  [Clear all] [Save view]

Applied:  City Nairobi ×   Payment Paid ×

Showing 31 parcels · 2 workspaces · 4 carriers · KES 18,420 collected
Filters apply to Overview charts, Platform detail and Analytics customer metrics.
```

- The bar becomes sticky under the page title while scrolling.
- Active filters are summarised as removable chips.
- `Clear all` resets to the unfiltered equivalent.
- `Save view` is a P1 add-on (named presets, e.g. “Nairobi unpaid”).
- Filters persist when moving between **Overview** and **Analytics**.
- Filters do **not** change the sidebar, URL hash or workflow IDs; they are optional query parameters (`?city=…&carrier=…&workspace=…`).

### Mobile
- A compact `Filters (2)` button replaces the row; it opens a bottom sheet with the same five selects and `Apply filters`.
- Active chips stay visible under the button.
- Apply closes the sheet and refreshes the dashboard.
- Wireframe: `filters-layout-mobile.png`.

### Option B (not recommended initially)
A “Filters” drawer opened from a single button. Better for very many filters, but adds a click for every use and hides applied state. The recommended Option A keeps common filters one click away.

---

## 3. Filter definitions

| Filter | Options | Semantics | Notes |
|---|---|---|---|
| **City** | All cities · populated from live parcel origins/destinations | Matches **origin OR destination**. Add a small “Origin / Destination / Either” segmented option inside the City popover if needed. | Recommended default: “Either end”. Needs approval. |
| **Carrier** | All carriers · from active partner list | Filters parcels by `carrier`/`carrier_slug`. | Shows volume/performance for that carrier. |
| **Workspace** | All workspaces · Public / no workspace · each tenant | `tenant_id`; “Public” means no workspace (individual sender). | API usage only makes sense with this filter; it is not affected by city/payment. |
| **Sender type** | All senders · Business (workspace) · Individual (public/no workspace) | Mutually exclusive: business vs individual sender. | Advanced “First-time vs repeat” can be a P1 second filter if needed. |
| **Payment** | All · Paid · Unpaid | Uses parcel payment status. | “Refunded/failed” can be added if those statuses become available. |
| **Date range** | Past 30 days · 90 days · 12 months | Applies to period metrics. | Custom range is P1; current 3 ranges stay. |

**Important scope notes**
- Overview: filters apply to legacy performance charts, KPI cards, Platform detail counts and (for workspace only) API usage.
- Analytics: filters apply to promise KPIs, attention queue, journey, experience and growth metrics.
- “Today” tiles remain today’s numbers but are scoped by city/carrier/workspace/payment where applicable.
- Filters do not rewrite historical records or alter workflow tables.

---

## 4. Behaviour

- **Desktop:** selecting a value applies immediately; chips update; don’t require an Apply click.
- **Mobile:** sheet selections apply on `Apply filters`; `Reset` clears the sheet.
- **State:** kept in memory while moving between Overview and Analytics; encoded in the URL query string so reload/back works. Hash (e.g. `#analytics`) remains untouched.
- **Empty state:** “No data matches these filters” with `Clear all`; never show 0/NaN as if it were a real metric.
- **Loading:** subtle inline “Updating…” state; previous numbers can remain dimmed.
- **Counts:** “Showing X parcels · Y workspaces · Z carriers · KES collected” updates with filters.
- **Performance:** one request per filter change (debounced), cached filter options, additive indexes only if needed.

---

## 5. Implementation plan after approval

### P0 — usable filter bar
1. Add a filter-options endpoint (cities, carriers, workspaces) or include options in the existing overview payload.
2. Extend `api/admin-overview.php` and `api/admin-analytics.php` with optional query params:
   `city`, `city_mode`, `carrier`, `workspace_id`, `sender_type`, `payment`, `from`, `to`, `days`.
3. Add the sticky filter bar + chips + clear + result summary to the admin UI.
4. Apply the same filter state to Overview and Analytics.
5. Mobile bottom sheet.
6. URL query-string persistence (`history.replaceState`) while preserving hash.
7. Empty/loading/low-sample states.

### P1 — efficiency
- Saved filter views per admin (`localStorage` first, server later).
- “First-time vs repeat sender” filter.
- Custom date range.
- Export filtered CSV.
- Filter-aware deep links from alerts and KPIs.

### P2 — scale
- Server-persisted saved views shared by role.
- Workspace/carrier comparison mode.
- Filter presets in a board pack.

---

## 6. Compatibility guardrails

- No changes to the 21 admin nav keys, URL hashes, `data-admin-*` mounts, API action keys or workflow IDs.
- Filters are optional query parameters; **no filters = current dashboard behaviour**.
- Additive response fields only; no renamed/removed admin analytics keys.
- Existing charts/stat cards remain; filters only narrow the data.
- No DB renames/drops; indexes are additive if required.
- Feature flag `admin_filters`; fallback to current unfiltered dashboard.
- QA: unfiltered vs filtered reconciliation, empty states, back/forward URL state, mobile 390px, no page errors, legacy sections untouched.

---

## 7. Approval checklist

Please reply **approve** or adjust:

1. **Layout** — Option A: shared sticky filter bar on Overview + Analytics, mobile bottom sheet? [approve]
2. **City semantics** — city matches either origin or destination by default? [approve / change]
3. **Sender type** — Business (workspace) vs Individual (public) only for now; advanced first-time/repeat later? [approve]
4. **Payment** — Paid / Unpaid only for now? [approve]
5. **Apply behaviour** — auto-apply on desktop, Apply button in mobile sheet? [approve]
6. **Scope** — filters affect Overview charts/platform detail and Analytics customer metrics; API usage only reacts to workspace? [approve]
7. **Compatibility** — query-string filters only, no nav/hash/API/workflow changes? [approve]
8. **Saved views** — P1 after launch? [approve]

After approval I’ll implement P0 behind the feature flag and share before/after screenshots for sign-off before enabling it by default.
