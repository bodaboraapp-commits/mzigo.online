# UAT — Parcel scan (partner scan points)

## Purpose
Verify the partner scan-point flow end to end before go-live: admin point/token management, scanner authentication, the four scan actions, status transitions, idempotency, validation, notifications/webhooks and audit.

## Environment
| Item | Value |
|---|---|
| App | `https://mzigo.online` (or a dedicated UAT/staging origin) |
| Scanner | `/scan` |
| Admin | `/admin#points` |
| Roles | Platform admin, scanner operator (token), customer/owner |

## Preconditions
1. Assets, `api/` and `_server/` are deployed and reachable (homepage returns 200, `/assets/js/site.js` and `/api/health.php` return 200).
2. `.env` has valid DB credentials and `APP_CONFIG_KEY`.
3. At least one active carrier and one workspace exist.
4. A test parcel exists in `CREATED` status belonging to the test carrier (and test workspace if the point is workspace-scoped).
5. The test parcel has:
   - `payment_status = paid` before the collection test
   - a known `collect_code` for the collection test
6. Scanner operator has the point access token.

## Data setup (repeat for each UAT run)
1. **Admin → Scan points → Create point** for each type:
   - `dropoff` (origin)
   - `handover` (transporter)
   - `arrival` (destination)
   - `collection` (destination, or use `multi`)
2. Copy each one-time `mzp_…` token immediately.
3. Create/identify a test parcel `TRK-UAT-0001` with matching `carrier_slug` (and `tenant_id` when the point is workspace-scoped).

---

## A. Admin scan-point management

| ID | Test | Steps | Expected |
|---|---|---|---|
| A01 | Create point | Admin → Points → New: name/code/carrier/type/location → save | Point created, active, one-time token shown once |
| A02 | Token never re-shown | Reload the points table | Token value is not recoverable from the UI |
| A03 | Edit point | Change name/location/type → save | Updated in list without invalidating the token |
| A04 | Disable point | Toggle Disable | Scanner with that token gets `401 point_token_invalid` (or point disabled) |
| A05 | Re-enable point | Toggle Enable | Existing token works again |
| A06 | Regenerate token | New token → save | New token works; old token immediately fails |
| A07 | Delete point | Delete | Point removed; old token fails |

## B. Scanner authentication

| ID | Test | Steps | Expected |
|---|---|---|---|
| B01 | Connect valid token | `/scan` → paste token | Point name/location/type shown; allowed actions shown |
| B02 | Missing token | Open `/scan`, submit empty | Error `point_token_required` (401) |
| B03 | Malformed token | Token shorter than 24 chars | Error `point_token_required` |
| B04 | Wrong token | Valid-looking but unknown token | Error `point_token_invalid` (401) |
| B05 | Disabled point token | Use disabled point token | Rejected (see A04) |
| B06 | Rate limit auth failures | Repeat bad token | Rate-limited; UI shows error |

## C. Drop-off scan (origin)

| ID | Test | Steps | Expected |
|---|---|---|---|
| C01 | Happy path | Tracking `TRK-UAT-0001` → Drop off → Record scan | Status `CREATED → AT_ORIGIN_POINT`; success result |
| C02 | Wrong carrier | Parcel with different `carrier_slug` → drop off | `wrong_carrier` (403) |
| C03 | Wrong workspace | Workspace-scoped point + other tenant parcel | `wrong_workspace` (403) |
| C04 | Already dropped off | Repeat drop-off on same parcel | `invalid_status_change` (422) |
| C05 | Unknown tracking | `TRK-NOPE-0000` | `parcel_not_found` (404) |

## D. Handover scan (origin → transporter)

| ID | Test | Steps | Expected |
|---|---|---|---|
| D01 | Happy path | After drop-off, `handover` point scans | `AT_ORIGIN_POINT → HANDED_TO_TRANSPORTER` |
| D02 | Skipped drop-off | New parcel → handover first | `invalid_status_change` (422) |

## E. Arrival scan (destination)

| ID | Test | Steps | Expected |
|---|---|---|---|
| E01 | Happy path | After handover, `arrival` point scans | `HANDED_TO_TRANSPORTER → READY_FOR_COLLECTION` |
| E02 | Note recorded | Add note “Bay 4” | Note saved on scan trail |
| E03 | Not yet handed over | New parcel → arrival | `invalid_status_change` (422) |

## F. Collection scan (destination, paid)

| ID | Test | Steps | Expected |
|---|---|---|---|
| F01 | Happy path | Paid parcel + correct 6-char code → Collect | `READY_FOR_COLLECTION → COLLECTED_PAID` |
| F02 | Wrong code | Correct parcel, wrong code | `invalid_collection_code` (422) |
| F03 | Unpaid parcel | `payment_status != paid` | `payment_required` (422) |
| F04 | Already collected | Repeat collect | `invalid_status_change` (422) |

## G. Idempotency

| ID | Test | Steps | Expected |
|---|---|---|---|
| G01 | Retry same key | Submit the same scan twice with the same idempotency key | Second call returns same success with `idempotent: true`; no duplicate scan |
| G02 | Key reused for different scan | Same key, different tracking/action | `idempotency_conflict` (409) |
| G03 | Missing key | Submit without idempotency key | `idempotency_required` (422) |

## H. QR / manual entry

| ID | Test | Steps | Expected |
|---|---|---|---|
| H01 | Manual entry normalises | Enter lowercase tracking | API accepts and normalises to uppercase |
| H02 | Camera QR | Scan a valid parcel QR | Tracking prefilled; scan can be recorded |
| H03 | Camera denied | Deny camera permission | Manual entry remains available; no crash |

## I. Scanner UX

| ID | Test | Steps | Expected |
|---|---|---|---|
| I01 | Recent scans | Perform several scans | Recent list updates on that device |
| I02 | Clear recent | Clear | List empties |
| I03 | Offline indicator | Disconnect network | UI shows Offline; scan attempts fail gracefully (no silent success) |
| I04 | Install PWA | Use install prompt | App installs and opens to `/scan` |
| I05 | Change point | Change token | Disconnects and returns to token screen |

## J. Notifications, webhooks and audit

| ID | Test | Steps | Expected |
|---|---|---|---|
| J01 | Customer notification | Each valid scan | Customer/owner receives status email for the transition |
| J02 | Webhook | Workspace has webhook for `parcel.status_updated` | Signed webhook delivered with status/point/action |
| J03 | Audit trail | Admin → Activities | `point.scan` entry with point, action, from/to |

## K. Rate limits

| ID | Test | Steps | Expected |
|---|---|---|---|
| K01 | Scan rate limit | Exceed 90 scans/minute from one point/IP | Rate-limited; error returned |
| K02 | Auth-fail limit | Repeat bad token | Rate-limited |

---

## Acceptance criteria
- All critical cases `C01`, `D01`, `E01`, `F01` pass.
- Statuses follow the exact sequence with no skipped or duplicate transitions.
- Idempotency (`G01`–`G03`) passes.
- Wrong-carrier / wrong-workspace isolation (`C02`, `C03`) passes.
- Collection never succeeds without payment and correct code (`F02`, `F03`).
- Notifications/webhooks/audit recorded (J).
- No unhandled 500s; failures return structured JSON.

## Sign-off
| Role | Name | Date | Result |
|---|---|---|---|
| Platform admin | | | |
| Scanner operator | | | |
| QA / owner | | | |
