# UAT — Register and deliver a parcel end to end

**Version:** 1.0  
**Scope:** parcel registration, QR/label, drop-off, handover, arrival, customer payment, collection and tracking  
**Environment:** local preview or production  
**Owner:** QA / Operations

---

## 1. Purpose

Prove that a parcel can travel through the full Mzigo.online lifecycle:

```text
Register → QR/label → Drop off → Hand over → Arrive → Pay → Collect → Track
```

And that every step updates the correct records:

- `parcels`
- `scans`
- `mpesa_payments` (when paying by M-Pesa)
- `transactions`
- `notifications`
- customer tracking timeline

---

## 2. Preconditions

### Local

```text
App:    http://localhost:8090
Health: http://localhost:8090/api/health.php
DB:     127.0.0.1:3307 / mzigo_online
```

### Production

```text
App:    https://mzigo.online
Health: https://mzigo.online/api/health.php
```

### Required setup

- [ ] Database running and migrated
- [ ] `PLATFORM_ADMIN`, `DEMO_MODE` understood for this test
- [ ] At least one active courier partner
- [ ] At least one scan point with a copied access token
- [ ] Customer payment mode understood:
  - **demo** — no real STK
  - **live sandbox** — Daraja sandbox
  - **production** — real M-Pesa
- [ ] Admin credentials available
- [ ] Test receiver phone available
- [ ] Test phone for STK (if live)

### Demo accounts

| Role | Credential |
|---|---|
| Admin | `admin@mzigo.online` / `Admin123!` |
| Merchant | `amina@example.com` / `Demo1234!` |
| Customer | `0744333444` / code `1234` |

---

## 3. Test data

Use unique values so results are traceable.

| Field | Value |
|---|---|
| Sender name | `QA Sender` |
| Sender phone | `0700111222` |
| Receiver name | `QA Receiver` |
| Receiver phone | `0744333444` |
| Origin | `Nairobi CBD` |
| Destination | `Mombasa CBD` |
| Parcel value | `KES 2,000` |
| Size | `Medium` |
| Payment | `COD` / pay on collection |
| Description | `QA parcel — fragile` |

> Use a new receiver phone if you want to test the “new customer created from parcel” path.

---

## 4. Create the scan point

1. Sign in as admin.
2. Go to **Admin → Scan points**.
3. Create a point:
   - Carrier: the same courier used for the parcel
   - Name: `QA Multi Point`
   - Type: `multi`
   - Manual entry: **On**
4. Copy the one-time access token.
5. Open `http://localhost:8090/scan` on a phone or mobile viewport.
6. Paste the token and connect.

**Expected**

- Scanner shows the point name and carrier.
- All four actions are available: Drop off / Hand over / Arrival / Collect.

---

## 5. Happy path

### TC-01 — Register the parcel

1. Open `/send-mzigo`.
2. Choose carrier and route.
3. Enter sender details.
4. Enter receiver details.
5. Enter parcel details and value.
6. Upload product/package photos if required.
7. Choose payment method.
8. Submit.

**Expected**

- Confirmation screen appears.
- A tracking number is generated (`TRK-XXXXXXXX`).
- QR label and locker ID are available.
- Parcel status is `CREATED`.
- Payment status is `UNPAID`.
- Collection code is generated.
- Tracking page shows only the registration scan.

**DB check**

```sql
SELECT tracking, status, payment_status, collect_code
FROM parcels
WHERE tracking = 'TRK-XXXXXXXX';
```

---

### TC-02 — Verify label and public tracking

1. Download/view the label.
2. Open `/track-mzigo`, enter `TRK-XXXXXXXX`, then enter the receiver phone `0744333444`.

**Expected**

- Label shows tracking number, QR, sender/receiver and courier.
- Public tracking shows the parcel without login when the tracking number and phone match.
- Only registration scan is visible.

---

### TC-03 — Drop-off scan

1. In the scanner, select **Drop off**.
2. Scan the QR or manually enter tracking.
3. Submit.

**Expected**

- Parcel status: `AT_ORIGIN_POINT`
- Scan event: `Drop off`
- Sender notification is queued/sent.
- Public tracking shows the new scan.

**DB check**

```sql
SELECT status FROM parcels WHERE tracking='TRK-XXXXXXXX';
SELECT status, label, scanned_at FROM scans
WHERE subject_id = (SELECT id FROM parcels WHERE tracking='TRK-XXXXXXXX')
ORDER BY scanned_at;
```

---

### TC-04 — Handover scan

1. Select **Hand over**.
2. Scan the same parcel.

**Expected**

- Parcel status: `HANDED_TO_TRANSPORTER`
- Handover scan recorded.
- Tracking timeline updated.

---

### TC-05 — Arrival scan

1. Select **Arrival**.
2. Scan the parcel.

**Expected**

- Parcel status: `READY_FOR_COLLECTION`
- Arrival scan recorded.
- Customer notified that the parcel is ready for collection.

---

### TC-06 — Customer sees the parcel

1. Sign in at `/login?role=customer` with the receiver phone.
2. Open the customer portal.

**Expected**

- Parcel appears under incoming parcels.
- Status shows **Ready for collection**.
- Payment due shows the delivery fee.
- Collection code is visible after payment.

---

### TC-07 — Customer pays

#### Demo mode

1. Click **Pay delivery fee**.
2. Confirm the demo payment.

**Expected**

- Parcel `payment_status = paid`
- `paid_amount_kes` equals the fee
- `payment_ref` set
- Transaction recorded with method `DEMO_MPESA`
- Receipt available
- Collection code visible

#### Live/sandbox M-Pesa

1. Click **Pay delivery fee**.
2. Confirm STK request.
3. Enter the M-Pesa PIN on the phone.
4. Wait for callback/polling.

**Expected**

- `mpesa_payments.status = paid`
- `mpesa_receipt` is populated
- `transactions` has `method = MPESA`
- Parcel is `paid`
- Receipt available
- Collection code visible

**DB checks**

```sql
SELECT status, mpesa_receipt, amount_kes, transaction_id
FROM mpesa_payments
WHERE parcel_id = (SELECT id FROM parcels WHERE tracking='TRK-XXXXXXXX')
ORDER BY id DESC;

SELECT payment_status, payment_ref, paid_amount_kes
FROM parcels
WHERE tracking='TRK-XXXXXXXX';

SELECT reference, amount_kes, method
FROM transactions
WHERE subject_id = (SELECT id FROM parcels WHERE tracking='TRK-XXXXXXXX')
ORDER BY id DESC;
```

---

### TC-08 — Collection scan

1. Scanner → select **Collect**.
2. Enter/scan tracking.
3. Enter the customer’s 6-character collection code.
4. Submit.

**Expected**

- Parcel status: `COLLECTED_PAID`
- Collection scan recorded.
- Push notification “Rate your delivery” fires immediately
- Email backup “Rate your delivery” is sent when the customer has an email
- Status cannot proceed without payment.
- Public tracking shows collection complete.

**DB check**

```sql
SELECT status FROM parcels WHERE tracking='TRK-XXXXXXXX';
SELECT status, label FROM scans
WHERE subject_id = (SELECT id FROM parcels WHERE tracking='TRK-XXXXXXXX')
ORDER BY scanned_at;
```

---

### TC-09 — Final tracking page

Open `/track-mzigo`, enter the tracking number and the receiver phone.

**Expected timeline**

```text
Registered
Dropped off
Handed to transporter
Arrived at destination
Ready for collection
Collected
```

No login required. All timestamps and locations are correct.

---

## 6. Negative and edge cases

| ID | Test | Expected |
|---|---|---|
| NEG-01 | Handover before drop-off | `422`; status unchanged |
| NEG-02 | Collection before payment | `422 payment_required`; status unchanged |
| NEG-03 | Wrong collection code | `422 invalid_collection_code`; status unchanged |
| NEG-04 | Unknown tracking number | clear error; no scan created |
| NEG-05 | Wrong carrier point | `403` / carrier mismatch; no status change |
| NEG-06 | Duplicate drop-off scan | rejected as invalid status change; no duplicate status |
| NEG-07 | Duplicate callback | idempotent; no duplicate transaction |
| NEG-08 | Callback amount lower than expected | `failed` / `amount_mismatch`; parcel stays unpaid |
| NEG-09 | Callback cancellation (`ResultCode 1032`) | payment failed; parcel stays unpaid |
| NEG-10 | Unknown callback checkout ID | accepted safely; no DB change |
| NEG-11 | Disabled scan point token | rejected |
| NEG-12 | Manual entry disabled | scanner hides/rejects manual input |

---

## 7. Callback simulation for local QA

Use only when testing the callback locally; production callbacks come from Daraja.

1. Trigger an STK payment and get the `checkout_request_id`.
2. Post a Daraja-shaped callback:

```bash
curl -X POST http://localhost:8090/api/mpesa-callback.php \
  -H "Content-Type: application/json" \
  --data-binary @callback.json
```

`callback.json`:

```json
{
  "Body": {
    "stkCallback": {
      "MerchantRequestID": "QA-MERCHANT",
      "CheckoutRequestID": "ws_CO_...",
      "ResultCode": 0,
      "ResultDesc": "The service request is processed successfully.",
      "CallbackMetadata": {
        "Item": [
          { "Name": "Amount", "Value": 620 },
          { "Name": "MpesaReceiptNumber", "Value": "QATEST001" },
          { "Name": "TransactionDate", "Value": 20261104184500 },
          { "Name": "PhoneNumber", "Value": 254708374149 }
        ]
      }
    }
  }
}
```

Expected response:

```json
{"ResultCode":0,"ResultDesc":"Accepted"}
```

Then verify the payment and parcel as in TC-07.

---

## 8. Notifications and webhooks

Optional but recommended.

- [ ] Sender receives drop-off notification
- [ ] Customer receives ready-for-collection notification
- [ ] Customer receives payment receipt by SMS and email (collection code included)
- [ ] Customer portal shows one in-app notification per event (no duplicate rows)
- [ ] Payment SMS and email both contain the collection code
- [ ] `notifications` table has correct channel/status
- [ ] Workspace webhook `parcel.status_updated` fires
- [ ] Workspace webhook `customer.parcel_paid` fires

**DB check**

```sql
SELECT channel, template, recipient, status, error, created_at
FROM notifications
WHERE related_id = 'PARCEL_ID'
ORDER BY id DESC;
```

---

## 9. Evidence checklist

- [ ] Tracking number
- [ ] Registration confirmation screenshot
- [ ] Label/QR screenshot or PDF
- [ ] Scanner screenshots for each action
- [ ] Customer portal payment screenshot
- [ ] M-Pesa receipt / demo receipt
- [ ] Public tracking timeline screenshot (after entering tracking + phone)
- [ ] `parcels` row
- [ ] `scans` rows
- [ ] `mpesa_payments` row (if live)
- [ ] `transactions` row
- [ ] `notifications` rows

---

## 10. Sign-off

| Area | Result | Tester | Date |
|---|---|---|---|
| Registration | ☐ Pass ☐ Fail | | |
| QR/label | ☐ Pass ☐ Fail | | |
| Drop-off | ☐ Pass ☐ Fail | | |
| Handover | ☐ Pass ☐ Fail | | |
| Arrival | ☐ Pass ☐ Fail | | |
| Customer payment | ☐ Pass ☐ Fail | | |
| Collection | ☐ Pass ☐ Fail | | |
| Public tracking (order + phone) | ☐ Pass ☐ Fail | | |
| Negative cases | ☐ Pass ☐ Fail | | |
| Notifications/webhooks | ☐ Pass ☐ Fail | | |

**Overall:** ☐ Approved  ☐ Rejected

**Notes:**

```text

```

---

## 11. Cleanup

After the test:

- [ ] Delete or archive the test parcel and scans
- [ ] Remove the QA scan point or rotate its token
- [ ] Remove test payment/transaction rows if they are not needed for audit
- [ ] Revoke any temporary test device tokens
