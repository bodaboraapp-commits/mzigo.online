# Email OTP login channel

**Status:** implemented 27 October 2026 for customer login. The customer can choose SMS or email when requesting a login code.

## Login flow

On `/login` → **Customer** tab:

1. Enter the delivery phone number
2. Choose **Send the login code by**
   - SMS to my phone
   - Email (address on my account)
3. Continue
4. Enter the 4-digit code

The code is still tied to the customer phone account in `users.otp_hash`. Only the delivery channel changes.

## Email requirements

The customer account must have an email address in `users.email`. If it is missing, the API returns:

```text
No email address is on this customer account. Use SMS or add an email in the customer portal first.
```

The email channel must be live. In demo mode the code remains `1234`; in production the email must be delivered through a real SMTP/SendGrid/Mailgun configuration. If the email channel is still set to `log`, the API returns a clear error instead of pretending the code was sent.

## Add email to an existing customer

Options:

1. Customer portal after signing in by SMS → Email & notification preferences → save email
2. Admin API `admin.php` action:

```json
{
  "action": "update_user_email",
  "user_id": 65,
  "email": "customer@example.com"
}
```

3. phpMyAdmin for a one-off account update:

```sql
UPDATE users SET email = 'customer@example.com', email_verified_at = NULL
WHERE id = 65;
```

## Production checklist

- Email channel configured (Admin → Communications → Email → SMTP/SendGrid/Mailgun)
- Send a test email to confirm live delivery
- Customer email populated
- Deploy the latest Git commit
- Hard-refresh `/login`

The current customer account for `254798335246` has **no email address** (`users.email = NULL`). Email OTP cannot work for that account until an email is added.
