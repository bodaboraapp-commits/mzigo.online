# Mzigo.online Public API v1

Server-to-server API for creating, listing and tracking parcels inside a Mzigo.online workspace.

- **Base URL (production):** `https://mzigo.online`
- **Local/demo base URL:** `http://localhost:8090`
- **Version:** `v1`
- **Content type:** `application/json`
- **Authentication:** workspace API key in `X-API-Key`

## 1. Authentication

Create an API key in **Workspace → Settings → API keys**. The full key is shown once; store it securely. Keys are scoped per workspace and expose only that workspace's data.

```http
X-API-Key: mz_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Keys have scopes:

| Scope | Allows |
|---|---|
| `read` | `GET` endpoints |
| `write` | `POST` endpoints |

A key with only `read` receives `403 scope_required` on `POST`. Revoke a key from Settings at any time.

### API key errors

| HTTP | Code | Meaning |
|---|---|---|
| 401 | `api_key_required` | Header missing or too short |
| 401 | `api_key_invalid` | Key is unknown or revoked |
| 403 | `scope_required` | Key lacks read/write scope |
| 403 | `tenant_suspended` | Workspace is suspended/cancelled |
| 429 | `rate_limited` | Too many requests; retry later |

## 2. Rate limits

Each API key is limited to **60 requests per minute** by default. The limit is configured by the platform (`api_rate_per_minute`). Invalid API-key attempts are additionally limited per client IP.

A rate-limited response is HTTP `429` with the standard error envelope and a `Retry-After` behavior from the platform.

## 3. Response envelope

Success:

```json
{
  "ok": true,
  "source": "mysql",
  "parcel": {}
}
```

Error:

```json
{
  "ok": false,
  "error": {
    "code": "validation_failed",
    "message": "A human-readable message.",
    "fields": {
      "receiver_phone": "Receiver phone is required."
    },
    "meta": {}
  }
}
```

Check the HTTP status first, then `ok` and `error.code`.

## 4. Create a parcel

```http
POST /api/v1/parcels.php
X-API-Key: mz_live_…
Content-Type: application/json
```

### Request body

| Field | Type | Required | Notes |
|---|---|---|---|
| `carrier_slug` | string | yes | Carrier slug from `GET /api/catalog.php` |
| `from` | string | yes | Origin office name on the carrier's route |
| `to` | string | yes | Destination office name on the carrier's route |
| `sender_name` | string | yes | 3–120 characters |
| `sender_phone` | string | yes | Kenyan phone; stored normalized |
| `receiver_name` | string | yes | 3–120 characters |
| `receiver_phone` | string | yes | Kenyan phone |
| `description` | string | yes | 3–255 characters |
| `size` | string | yes | One of `Envelope`, `Small`, `Medium`, `Large`, `Extra Large`, `Extra Extra Large`, `Extra Extra Extra Large` |
| `value` | integer | yes | Declared value in KES, 1–1,000,000 |
| `payment` | string | yes | `CASH` or `M-PESA` |

Example:

```bash
curl -X POST https://mzigo.online/api/v1/parcels.php \
  -H "X-API-Key: $MZIGO_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "carrier_slug": "chania",
    "from": "Nairobi",
    "to": "Mombasa",
    "sender_name": "Amina Yusuf",
    "sender_phone": "0733111222",
    "receiver_name": "Peter Mutua",
    "receiver_phone": "0744333444",
    "description": "Documents",
    "size": "Small",
    "value": 5000,
    "payment": "M-PESA"
  }'
```

The response is HTTP `201`:

```json
{
  "ok": true,
  "source": "mysql",
  "parcel": {
    "id": "…",
    "tracking": "TRK-…",
    "locker": "LK-…",
    "carrier": "Chania",
    "carrier_slug": "chania",
    "from": "Nairobi",
    "to": "Mombasa",
    "status": "CREATED",
    "fee": {
      "base": 360,
      "holding": 30,
      "insurance": 110,
      "platform": 80,
      "total": 580
    }
  },
  "notifications": [],
  "usage": {}
}
```

Fees use the platform quote unless the workspace has a matching carrier/route/size **rate card**, in which case the tenant override is applied. See [Per-tenant rate cards](../docs/PRODUCTION.md#per-tenant-branding--carrier-rate-cards).

### Parcel creation errors

| HTTP | Code / behavior | Meaning |
|---|---|---|
| 422 | `validation` with `fields` | Missing/invalid field |
| 422 | `Unknown courier` | Carrier slug is not valid |
| 422 | `That origin is not on this courier's route` | Invalid origin |
| 422 | `That destination is not on this courier's route` | Invalid destination |
| 403 | `tenant_suspended` | Workspace cannot create parcels |
| 403 | `scope_required` | Key lacks `write` |
| 429 | `rate_limited` | API key rate limit exceeded |

`POST` is not idempotent. If a request times out, first check `GET /api/v1/parcels.php` (recent parcels) or search by tracking before retrying to avoid duplicate registrations.

## 5. List recent parcels

```http
GET /api/v1/parcels.php
X-API-Key: mz_live_…
```

Returns up to the 50 most recent parcels in the key's workspace, newest first.

```json
{
  "ok": true,
  "source": "mysql",
  "workspace": "Amina Crafts",
  "parcels": []
}
```

## 6. Get one parcel

```http
GET /api/v1/parcels.php?tracking=TRK-XXXXXXXX
X-API-Key: mz_live_…
```

Also accepts a locker ID in `tracking`:

```http
GET /api/v1/parcels.php?tracking=LK-XXXXXXXX
```

Returns `404 not_found` if the parcel does not belong to the key's workspace.

## 7. Track a parcel or errand

```http
GET /api/v1/track.php?q=TRK-XXXXXXXX
X-API-Key: mz_live_…
```

`q` can be a tracking number, Locker ID or errand reference. Results are scoped to the API key's workspace.

```json
{
  "ok": true,
  "source": "mysql",
  "found": true,
  "kind": "parcel",
  "record": {
    "tracking": "TRK-XXXXXXXX",
    "status": "IN_TRANSIT",
    "scans": []
  }
}
```

Not found is `200` with `found:false`:

```json
{ "ok": true, "source": "mysql", "found": false, "query": "TRK-XXXXXXXX" }
```

> This is an authenticated merchant endpoint. Unlike public tracking, it returns the workspace's full parcel record, including collection/payment fields. Keep API keys server-side.

## 8. Errors and retries

Use exponential backoff on:

- `429` rate limits
- `500`/`503` server/database outages
- network timeouts

Do not retry `401`, `403` or `422` without correcting the request.

Recommended retry sequence: 1s, 2s, 5s, 15s, 60s with jitter. Never log full API keys.

## 9. Outbound webhooks (event notifications)

Workspace owners can configure a signed webhook in **Settings → Webhooks**. Events include:

- `parcel.created`
- `parcel.cancelled`
- `parcel.returned`
- `parcel.status_updated`
- `customer.parcel_paid`
- `claim.created`
- `claim.updated`
- `invoice.paid`

Each delivery is a `POST application/json` with:

```http
X-Mzigo-Event: parcel.created
X-Mzigo-Event-Id: <uuid>
X-Mzigo-Idempotency-Key: <optional key>
X-Mzigo-Signature: sha256=<HMAC-SHA256 raw body, webhook signing secret>
```

Verify the signature against the **raw request body** before parsing JSON. Deduplicate on `event_id`. Retries reuse the original event ID and payload. Delivery history and a manual retry action are available in Settings → Webhook deliveries.

Example payload:

```json
{
  "event": "parcel.created",
  "event_id": "2d4c…",
  "idempotency_key": null,
  "created_at": "2026-10-26T10:00:00+03:00",
  "data": {
    "parcel": {}
  }
}
```

## 10. Versioning

API v1 paths are stable at `/api/v1/`. Breaking changes will use a new version path. Additive fields may appear in responses; clients should ignore unknown fields.

## 11. OpenAPI

Machine-readable specification: [`docs/openapi/v1.yaml`](openapi/v1.yaml).

## 12. Support

- Email: `hello@mzigo.online`
- Toll free: `0800 211 211`
- API key and workspace management: **Workspace → Settings → API keys**


## Workspace order scope

Workspace order lists and exports only include rows whose `tenant_id` matches the authenticated workspace. There is no fallback to user ID or browser session ownership. Public tracking and receiver notifications are separate flows. An account without a workspace receives an empty `workspace_required` orders response rather than another tenant's orders.
