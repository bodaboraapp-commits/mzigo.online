# Twilio SMS integration

**Status:** implemented 27 October 2026. The active SMS channel is currently Twilio (`twilio-sms`).

## Current production issue: 21612

Current sender:

```text
From:              +17655387167 (US long code)
Messaging Service: MGb4ea...
To:                +254798335246 (Kenya)
```

Twilio error:

```text
21612: Message cannot be sent with the current combination of "To" and/or "From"
```

Twilio's Kenya SMS guidelines state:

- **Long code domestic:** Not supported
- **Long code international:** Not supported
- **Short code:** Not supported
- **Alphanumeric sender ID:** Supported only after **pre-registration**
- **Provisioning time:** about **4 weeks**

So the Twilio Messages API cannot send ordinary SMS to Kenya from the US long code.

## Recommended fix for login OTP: Twilio Verify

Twilio Verify uses Twilio's own generic sender and is designed for OTP delivery. It avoids using the account's long code.

### 1. Create a Verify Service

Twilio Console → Verify → Services → **Create Service**

Set:

```text
Code length: 4
```

Copy the **Service SID** — it starts with `VA...`.

### 2. Enable Kenya in Verify geo permissions

Twilio Console → Verify → Settings → **Geo permissions** → allow Kenya.

### 3. Add it to Mzigo

Admin → Communications → SMS channel (Twilio) → **Verify Service SID for login OTP**

Example:

```text
VA1a2b3c4d...
```

Save the channel.

### 4. Verify the trial recipient

If the Twilio account is in trial:

- Twilio Console → Phone Numbers → Verified Caller IDs
- Verify `+254798335246`

### 5. Test

Request a new customer login code. The app will call:

```text
POST https://verify.twilio.com/v2/Services/{ServiceSid}/Verifications
Channel=sms
To=+254...
```

Then the login code is checked with:

```text
POST https://verify.twilio.com/v2/Services/{ServiceSid}/VerificationCheck
```

This is now supported in `api/_sms.php` and `api/auth.php` for:

- Customer login OTP
- Freelancer phone PIN

If the Verify Service SID is not configured, the app falls back to the previous Messages API flow.

## Ordinary SMS notifications

Twilio Verify only covers login OTP. Parcel/job SMS notifications still use the Messages API and the account's sender. Those will continue to fail for Kenya until:

- a Kenya alphanumeric sender is registered and attached to the Messaging Service, or
- the app is switched to a Kenya-capable SMS provider.

## Immediate alternatives

For arbitrary Kenyan numbers while Twilio Verify/alpha registration is pending:

- Infobip paid account with a Kenyan route
- A Kenyan SMS aggregator
- Email OTP after configuring SMTP and adding an email to the customer account
