# Infobip WhatsApp integration

**Status:** implemented 27 October 2026. Supports Infobip as an alternate WhatsApp provider alongside Meta Cloud API for text replies, outbound templates and inbound webhooks. A live outbound test still requires an Infobip WhatsApp-enabled sender and approved templates.

## What is wired

- Admin → WhatsApp provider selector:
  - **Meta WhatsApp Cloud API**
  - **Infobip WhatsApp**
- Infobip configuration fields:
  - Base URL (same account base URL used for SMS, e.g. `https://d845wr.api.infobip.com`)
  - API key
  - WhatsApp sender
  - Optional webhook authorization secret
- Outbound **text** messages:
  - `POST {base_url}/whatsapp/1/message/text`
- Outbound **template** messages:
  - `POST {base_url}/whatsapp/1/message/template`
  - Uses `messages[].content.templateName`, `templateData.body.placeholders`, `language`
- Inbound webhook parsing:
  - Accepts Infobip `results[]` payloads
  - Stores sender name, phone, messageId and message text in the same `wa_contacts` / `wa_conversations` / `wa_messages` tables
  - Enqueues the same AI/FAQ/tracking/handoff worker
- Webhook URL: `https://mzigo.online/api/whatsapp-webhook.php`

## Configure in Admin → WhatsApp

1. Select **Provider: Infobip WhatsApp**
2. Fill:
   ```text
   Infobip base URL:       https://d845wr.api.infobip.com
   Infobip WhatsApp sender: <Infobip WhatsApp sender / number>
   Infobip API key:        <API key>
   Infobip webhook secret: optional
   ```
3. Set mode **Live** and enable the assistant.
4. Save.
5. In Infobip, configure the inbound WhatsApp webhook:
   - URL: `https://mzigo.online/api/whatsapp-webhook.php`
   - If a webhook secret is set, add header:
     ```text
     Authorization: App <secret>
     ```
6. In Admin → WhatsApp → templates, enter the exact approved Infobip WhatsApp template names and language codes for each parcel event.

The Meta fields remain available but are ignored when the Infobip provider is selected.

## Important differences from Meta

- Infobip WhatsApp templates must be approved in the Infobip/WhatsApp account; template names are often different from Meta template names.
- Text replies outside a customer-initiated 24-hour window may be rejected; use approved templates for business-initiated messages.
- Infobip inbound webhook authorization is optional in this implementation. Set a secret for production.
- Delivery reports/status webhooks are not parsed yet; outbound success means Infobip accepted the message.
- Media/buttons are not rendered; text and body-placeholder templates only.
- The Infobip **SMS** channel is separate: configure it under Admin → Communications. This page configures the WhatsApp assistant provider.

## Verification performed

- Infobip WhatsApp inbound sample:
  ```json
  {"results":[{"messageId":"infobip.uat.001","from":"254799123456","message":{"type":"TEXT","text":"..."}}]}
  ```
  returned HTTP 200 and stored the inbound message.
- PHP lint clean for `_whatsapp.php`, `_whatsapp_outbound.php` and `whatsapp-webhook.php`.
- Meta WhatsApp flow remains unchanged when provider is `meta`.
- Live outbound Infobip WhatsApp is **not yet tested** because an Infobip WhatsApp sender/template was not provided.

## Files

- `api/_whatsapp.php` — provider config, Infobip text sender, inbound parser
- `api/_whatsapp_outbound.php` — Infobip template sender
- `api/whatsapp-webhook.php` — provider routing/authorization
- `assets/js/site.js` — Admin provider fields
- `docs/whatsapp-phase1-implementation.md` / `whatsapp-phase2-implementation.md` — prior Meta/Infobip-agnostic engine
