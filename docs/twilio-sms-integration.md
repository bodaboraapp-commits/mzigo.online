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
- Generic alpha IDs such as `InfoSMS` should be avoided

So a US Twilio long code cannot deliver SMS to Kenya. No application-side setting can change that.

## What must be done in Twilio

1. Upgrade/confirm the Twilio account is eligible for Kenya alphanumeric sender registration.
2. Twilio Console → Messaging → Senders → **Alphanumeric Sender ID**
3. Register a branded sender for Kenya, e.g.:

```text
MZIGO
```

4. Wait for approval/provisioning (about 4 weeks).
5. Attach the approved sender to the Messaging Service `MGb4ea...`.
6. Ensure Kenya geo permissions are enabled.
7. Retest Admin → Communications → SMS.

Until registration is approved, OTP via Twilio SMS to `+254…` cannot work.

## App support for alpha senders

`api/_sms.php` now accepts either:

- an E.164 number (`+1765…`)
- or a registered 1–11 character alphanumeric sender ID (`MZIGO`)

If a Messaging Service SID is configured, the app sends it and Twilio chooses a sender from the service's sender pool. In that case, the sender pool must contain the approved Kenya alpha sender.

If you want to send directly from an alpha sender, clear the Messaging Service SID and set the From field to:

```text
MZIGO
```

The app no longer blindly prefixes `+` to alphanumeric senders.

## Immediate alternatives

For OTP delivery to arbitrary Kenyan numbers while Twilio registration is pending:

- Infobip paid account with a Kenyan route
- A Kenyan SMS aggregator
- Email OTP after configuring SMTP and adding an email to the customer account
