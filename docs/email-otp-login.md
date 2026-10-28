# Email OTP login and admin email management

**Status:** customer and freelancer email OTP implemented 27 October 2026. SMS login is temporarily hidden; email OTP is the active login channel (28 October 2026).

## Login channels

On `/login`:

- **Business** — email/phone + password
- **Freelancer** — phone number + email OTP, with SMS available only when re-enabled
- **Customer** — phone number + email OTP, with SMS available only when re-enabled

There is no separate Phone tab. The login identifier remains the phone number; the channel controls where the one-time code is sent. The SMS option is currently hidden from the login UI until live SMS delivery is verified.

### Freelancer email

`freelancer-start` accepts:

```json
{
  "action": "freelancer-start",
  "phone": "0712345678",
  "channel": "email"
}
```

The runner PIN is generated, stored as a hash, and emailed. If the runner account has no email, the API returns:

```text
No email address is on this runner account. Use SMS or ask an admin to add an email.
```

### Customer email

`customer-start` accepts:

```json
{
  "action": "customer-start",
  "phone": "0712345678",
  "channel": "email"
}
```

## Admin add/verify email

Admin → Users rows now show:

- Email address
- `verified` / `unverified` badge
- **Add email** or **Change email**
- **Verify email**

Admin actions:

```json
{ "action": "update_user_email", "user_id": 65, "email": "user@example.com" }
{ "action": "verify_user_email", "user_id": 65 }
```

Adding/changing an email clears the verified flag. Verify marks `users.email_verified_at` as now.

Email OTP itself does not require the verified badge: receiving the code at the address proves access. The badge is for admin records and communication trust.

## Email delivery requirement

A real email provider must be configured in **Admin → Communications → Email**.

If the channel is still `log`, email OTP returns a clear delivery error instead of pretending to send. Production currently needs a verified SMTP/SendGrid/Mailgun delivery path.

**Known issue (28 Oct 2026):** OTP email reportedly reaches the inbox, but order/payment notification emails accepted by SMTP have not reliably reached Gmail. Verify the cPanel queue and provider deliverability separately from the OTP path; both use `mzigo_mail_send` but different HTML content and recipients.

## Twilio Verify

If Twilio Verify is configured for the SMS channel, SMS login OTP uses Twilio Verify. Selecting **Email** continues to use SMTP.

## Tested locally

```text
freelancer-start channel=email → channel=email, demo PIN issued
admin update_user_email → email saved
admin verify_user_email → email_verified_at set
```
