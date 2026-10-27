# Twilio SMS integration

**Status:** implemented 27 October 2026. The active SMS channel is currently Twilio (`twilio-sms`); the app previously supported only Infobip/log, which caused OTP requests to fail with a 502.

## Configuration

Admin → Communications → SMS channel:

```text
Provider: Twilio (SMS)
Sender:   (display only; Twilio uses the configured From number / Messaging Service)
Account SID: AC...
Auth token: ...
From number: +1...
Messaging Service SID: MG... (optional)
Active: on
```

The app uses:

- `From` number when no Messaging Service is set
- `MessagingServiceSid` when provided

API endpoint:

```text
POST https://api.twilio.com/2010-04-01/Accounts/{AccountSid}/Messages.json
```

## OTP flow

- `customer-start` sends the 4-digit sign-in code through the active SMS channel
- `freelancer-start` sends the runner PIN
- Claim OTPs and delivery OTPs use the same SMS channel
- Parcel SMS notifications also use the channel

## Verification

- PHP lint clean
- `send_test_sms` previously returned `SMS provider "twilio-sms" is not implemented`; this provider path is now implemented
- Live Twilio delivery still needs the latest code deployed and a test send from Admin → Communications

## Twilio limitations to check

- **Trial accounts** can only send to verified recipient numbers. Verify `0798335246` in Twilio Console → Phone Numbers → Verified Caller IDs, or upgrade the account.
- Twilio may reject or filter messages to Kenya depending on the From number, Messaging Service, sender registration and account permissions.
- A US Twilio number (`+1765…`) is not a Kenyan alphanumeric sender; check Twilio’s Kenya delivery requirements.
- Use the Twilio Console **Monitor → Logs → Messaging** to see the actual error and delivery status.
