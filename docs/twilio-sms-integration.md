# Twilio SMS integration

**Status:** implemented 27 October 2026. Twilio (`twilio-sms`) is the active SMS channel. **28 October 2026 update:** login SMS is hidden; parcel SMS notifications are configured but the trial account hits the **50 daily messages** limit. The 21612 section below is retained as integration history.

## Historical issue: 21612 (US long code → Kenya)

> The current observed failure is the Twilio trial daily-message cap. Other successful SMS rows exist, so this section documents the original sender/route constraint rather than the present blocker.

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

## Login OTP: Twilio Verify (currently hidden in the UI)

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

Twilio Verify only covers login OTP. Parcel/job SMS notifications use the Messages API and the account's sender. Current production evidence:

- some parcel SMS rows were delivered;
- later rows fail with the trial daily-message cap (50/day);
- WhatsApp remains inactive pending sender/template approval.

Resolve the daily cap or switch to a Kenya-capable provider. Login SMS remains hidden until delivery is verified.

## Immediate alternatives

For arbitrary Kenyan numbers while Twilio Verify/alpha registration is pending:

- Infobip paid account with a Kenyan route
- A Kenyan SMS aggregator
- Email OTP after configuring SMTP and adding an email to the customer account
