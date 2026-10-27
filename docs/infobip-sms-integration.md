# Infobip SMS integration

**Status:** implemented 27 October 2026. Production credentials and one live test message still need to be configured/validated.

## What is wired

- Infobip provider option in **Admin → Communications → SMS channel**
- Infobip endpoint: `POST {base_url}/sms/2/text/advanced`
- API authentication: `Authorization: App <API_KEY>`
- E.164 phone normalization for Kenyan numbers (`0712345678` → `254712345678`)
- Customer sign-in codes, runner PINs, claim OTPs, delivery OTPs and active parcel notifications now use the SMS channel
- Admin **Send test SMS** action in Communications
- SMS audit rows in `notifications`; provider log in `_server/logs/sms.log`
- Channel credentials remain encrypted at rest by the existing channel-config layer

## Configure in Admin

1. Create an Infobip account and an SMS sender/sender ID approved for Kenya.
2. In **Admin → Communications → SMS channel → Edit**:
   - Provider: **Infobip (SMS)**
   - Sender: approved sender ID, e.g. `MZIGO`
   - Infobip base URL: `https://xxxxx.api.infobip.com`
   - API key: your Infobip API key
   - Active: **on**
3. Save.
4. Use **Send test SMS** to a real phone.
5. Only then set `DEMO_MODE=0` in production `.env`.

Demo mode keeps returning `1234` codes and does not call Infobip. In production, a failed SMS delivery now returns a clear `sms_delivery_failed` / `otp_delivery_failed` error instead of silently pretending the code was sent.

## Verification performed

- Admin saved the SMS channel as Infobip with encrypted config.
- `send_test_sms` reached Infobip and returned Infobip's `Invalid login details` response using a placeholder API key — confirming URL, auth header and payload are accepted by the endpoint.
- Channel restored to `log` / inactive after the test; no real SMS was sent.
- `sms_e164` normalization tested for `0712345678`, `+254712345678`, `254712345678` and `712345678`.
- PHP lint and JS syntax clean.

A green end-to-end test still requires a real Infobip API key and sender ID.

## Operational notes

- Infobip credentials are entered per SMS channel, not committed to the repository.
- The API key is masked in admin API responses; leave the field blank when editing to preserve it.
- Keep `DEMO_MODE=0` after the channel is verified; otherwise codes are always `1234`.
- If SMS delivery fails in production, retry/contact support; the code hash is regenerated on the next request.
- No Infobip delivery-report webhook is implemented yet; outbound status in `notifications` means Infobip accepted the message, not that the handset received it.
- Use an approved sender ID; unapproved alphanumeric senders will be rejected by Infobip.
- Monitor `_server/logs/sms.log`, failed `sms` rows in `notifications`, and auth failure rates.

## Test flow

1. Configure and activate Infobip.
2. Admin → Communications → Send test SMS to your phone.
3. Sign out and request a customer code or runner PIN.
4. Confirm the SMS arrives and login succeeds.
5. Trigger a claim OTP and a delivery OTP.

## Files

- `api/_sms.php` — Infobip client, normalization, logging
- `api/_notifications.php` — SMS delivery branch and provider catalogue
- `api/auth.php` — customer code / runner PIN SMS delivery
- `api/customer.php` — claim OTP delivery status
- `api/_runner_jobs.php` — delivery OTP SMS-first with email fallback
- `api/admin.php` + `assets/js/site.js` — Send test SMS action
