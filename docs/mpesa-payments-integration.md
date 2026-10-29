# M-Pesa customer payments (Daraja STK)

**Implemented:** 29 October 2026

## What it does

Customers can pay the delivery fee from the customer portal with a real M-Pesa
STK prompt. The collection code is released only after a successful STK result is
validated and recorded.

## Admin setup

**Admin → Settings → M-Pesa payments**

- Mode: **Demo** or **Live STK**
- Environment: **Sandbox** or **Production**
- Shortcode / BusinessShortCode
- Passkey
- Consumer key
- Consumer secret
- Callback URL, for example:
  ```text
  https://mzigo.online/api/mpesa-callback.php
  ```
- Transaction type, normally `CustomerPayBillOnline`

Use **Test Daraja auth** to verify the consumer key/secret against the selected
environment. Blank masked secret fields keep the existing saved value.

### Sandbox credentials supplied 29 Oct 2026

The credentials are configured through the admin form, not committed to the
repository. The supplied sandbox shortcode `174379` uses the standard Daraja
sandbox passkey. Switch to **Production** only after production shortcode,
passkey and consumer credentials have been issued.

## Storage

Migration:

```text
_server/db/migrations/042_mpesa_payments.sql
```

Table `mpesa_payments` records:

- checkout/merchant request IDs
- parcel, customer, phone and amount
- pending/paid/failed/cancelled status
- M-Pesa receipt and callback raw result
- coupon/credit adjustments and a linked transaction ID

## Flow

1. Customer chooses **Pay with M-Pesa**.
2. `POST /api/customer.php` with `action=pay` validates the parcel, coupon and
   credit, then requests an STK push if live mode is ready.
3. The API returns `pending` with the checkout request ID.
4. Customer enters the M-Pesa PIN.
5. Safaricom POSTs to `/api/mpesa-callback.php`.
6. The callback validates the checkout ID and amount, marks the parcel paid,
   writes the transaction ledger row and sends the payment receipt.
7. The customer portal polls `action=payment_status`; if the callback is delayed,
   it queries Daraja's STK status endpoint.
8. Only a paid parcel exposes its collection code.

If live mode is enabled but credentials are incomplete, payment fails with
`mpesa_not_configured` rather than silently falling back to demo.

## Deployment

1. Deploy the latest code.
2. Apply migration `042_mpesa_payments.sql` through:
   ```text
   https://mzigo.online/api/db-update.php
   ```
3. Enter the admin M-Pesa configuration and run **Test Daraja auth**.
4. Set the callback URL to the public HTTPS endpoint.
5. Make a sandbox payment and verify:
   - `mpesa_payments` row changes `pending → paid`
   - parcel `payment_status=paid`
   - `transactions` row with method `MPESA`
   - collection code appears in the customer portal
   - receipt notification is recorded

## Notes

- Safaricom may block requests from some local/development IPs; production must
  be tested from the cPanel host.
- Do not commit Daraja credentials. They live in `platform_settings` and are
  editable from the admin form.
- The callback endpoint intentionally has no session/CSRF requirement because
  Safaricom calls it server-to-server; it validates the checkout ID and amount.
