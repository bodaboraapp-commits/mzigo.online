# Real Insurer Integration

Implemented 26 October 2026. Insurance fee controls added 28 October 2026.

## Decision

A platform-generated `INS-…` hash is **not** a real policy. The platform now issues a real policy number through a configured insurer API in **live** mode, and clearly-labelled demo policies in **demo** mode.

- **Demo mode** (default): `DEMO-POL-…`, provider shown as the configured demo provider. Email labels it as an insurance number, but the admin UI explicitly says “Demo policies”.
- **Live mode**: the insurer API is called at parcel registration; the returned policy number is stored on the parcel and shown to the sender/receiver and in the email.
- **Strict mode** (admin checkbox): if live issuance fails, the parcel registration is blocked rather than implying cover. When off, the parcel is created with policy status `failed` and the email says “Pending — insurer will issue”.

## Admin configuration

**Admin → Insurance** form:

- Mode: demo / live
- Provider name
- API base URL
- Issue-policy path (default `/policies`)
- Auth type: bearer token, basic auth, or API-key headers
- API key / client ID and API secret (blank secret fields keep the saved value)
- Insurance product code
- JSON path for the policy number in the response (e.g. `policy_number` or `data.policy_number`)
- Timeout seconds
- **Charge type:** percentage of declared value or fixed amount per order
- Insurance percentage (%)
- Fixed charge per order (KES)
- Minimum percentage charge (KES)
- Block registration if policy cannot be issued
- Insurance terms path
- **Test connection**, **Save insurance config**, **Retry pending policies**
- Policy status table: tracking, declared value, policy number, status, provider, issue date

## Fee calculation

The insurance fee exposed in quotes and passed to the insurer is controlled in **Admin → Insurance**:

- **Percentage mode:** declared value × configured percentage, with the configured minimum applied.
- **Fixed mode:** the configured fixed amount on every order.

Defaults: percentage mode, 1.5%, minimum KES 30. Workspace rate cards with an explicit `insurance_pct` still override the platform fee for that workspace.

## API contract used

`POST {api_base_url}{issue_path}` with JSON:

```json
{
  "product_code": "MZIGO_PARCEL_KE",
  "reference": "TRK-XXXXXXXX",
  "currency": "KES",
  "declared_value_kes": 5000,
  "insurance_fee_kes": 80,
  "sender": {"name": "...", "phone": "..."},
  "receiver": {"name": "...", "phone": "..."},
  "description": "...",
  "route": {"from": "Nairobi", "to": "Mombasa"},
  "carrier": "Chania"
}
```

Expected response: HTTP 2xx JSON containing a policy number at the configured path (fallbacks: `policy_number`, `policyNumber`, `data.policy_number`). Non-2xx or missing policy number is treated as a failure.

## Data stored on `parcels`

- `insurance_policy_no`
- `insurance_status` (`pending`, `issued`, `failed`)
- `insurance_provider`
- `insurance_issued_at`
- `insurance_response` (provider response JSON, truncated by UI display)

Migration: `_server/db/migrations/032_insurance_policies.sql`.

## Flow

1. Parcel registration computes the insurance fee and inserts the parcel.
2. `insurance_issue_for_parcel()` calls the provider (or demo generator).
3. Policy fields are stored; the API response and emails use `insurance_policy_no`.
4. If live issuance fails:
   - `require_policy = true`: delete the just-created parcel/scans and return HTTP 502.
   - `require_policy = false`: keep parcel with `failed` status; email shows policy pending; admin **Retry pending policies** or the cron/admin retry can reissue.
5. Admin → Insurance shows issued/pending/failed counts and recent policies.

## Email/notification behaviour

- Confirmation email shows the insurer-issued policy number when issued.
- If policy failed/pending, it shows “Pending — insurer will issue” instead of a fake reference.
- SMS/in-app body uses the real policy number when present and “policy pending” otherwise.

## Verification performed

- Admin form loaded and saved demo config; mode badge and policy table rendered.
- Demo issuance on an existing parcel produced `DEMO-POL-…`, status `issued`, and the email HTML contained that policy number.
- Full parcel registration through the public API returned `insurance_ref = DEMO-POL-343A5F420C`, `insurance_status = issued`, `insurance_provider = Mzigo Demo Cover`.
- Live failure path with an unreachable insurer endpoint returned `connection failed`, marked the parcel `failed`; retry after restoring demo config issued a policy.
- PHP lint and JS syntax clean; no page errors.

## Remaining for real production cover

- Choose/contract a licensed insurer and obtain their API documentation, credentials, product code and sandbox/prod URLs.
- If the insurer contract differs from the generic REST payload above, add a provider adapter/mapping in `api/_insurance.php` (the current integration assumes JSON REST).
- Enter credentials in **Admin → Insurance**, switch to live mode, turn on **block registration if policy cannot be issued**, run **Test connection**, then run a sandbox parcel and verify the policy number in the email and admin table.
- Confirm regulatory, data-protection and customer-disclosure wording with counsel; add the insurer’s policy wording/terms where required.
- Keep demo mode until the insurer contract and credentials are live.
