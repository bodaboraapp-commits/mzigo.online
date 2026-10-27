# Email OTP login and admin email management

**Status:** customer and freelancer email OTP implemented 27 October 2026. Admin add/verify email added 27 October 2026.

## Login channels

On `/login`:

- **Freelancer** tab — choose **SMS** or **Email**
- **Customer** tab — choose **SMS** or **Email**
- **Phone** tab — choose Customer/Freelancer and the delivery channel

The login identifier remains the phone number. The selected channel controls where the 4+ digit code is sent.

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

If the channel is still `log`, email OTP returns a clear delivery error instead of pretending to send. Production currently needs SMTP/SendGrid/Mailgun credentials.

## Twilio Verify

If Twilio Verify is configured for the SMS channel, SMS login OTP uses Twilio Verify. Selecting **Email** continues to use SMTP.

## Tested locally

```text
freelancer-start channel=email → channel=email, demo PIN issued
admin update_user_email → email saved
admin verify_user_email → email_verified_at set
```
