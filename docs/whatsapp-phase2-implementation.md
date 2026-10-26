# WhatsApp Phase 2 — Outbound Parcel Templates & QC

**Status: implemented 26 October 2026.** Phase 1 (FAQ + tracking + handoff) remains in place. Phase 2 adds approved Meta templates, consent-aware delivery and parcel lifecycle hooks.

## What Phase 2 adds

- Template configuration in **Admin → WhatsApp** for:
  - parcel registered
  - ready for collection
  - payment received + collection code
  - collected
  - other status update
- Meta Cloud API **template** sender (`type=template`, body parameters).
- Consent enforcement: templates only send to contacts with recorded WhatsApp opt-in and no STOP.
- Customer **WhatsApp parcel updates** opt-in in the customer portal.
- Parcel lifecycle hooks:
  - `notify_parcel_created()` → registered template
  - `notify_customer_status()` → ready/collected/status template
  - `notify_customer_payment()` → payment received + collection code template
- Outbound audit log: `wa_outbound_events`.
- Template delivery is stored in the admin conversation thread as a template message.
- Demo-safe test send from Admin → WhatsApp; live parcel sends require live mode + approved active templates.

## Files

| File | Purpose |
|---|---|
| `_server/db/migrations/036_whatsapp_templates.sql` | `wa_templates`, `wa_outbound_events` |
| `api/_whatsapp_outbound.php` | template lookup, Meta template send, consent checks, lifecycle hooks |
| `api/_notifications.php` | calls WhatsApp templates after existing parcel notifications |
| `api/customer.php` | customer WhatsApp opt-in/out endpoint |
| `api/admin-whatsapp.php` | template save/test + outbound log in admin payload |
| `assets/js/site.js` | Admin template editor, demo test, customer opt-in UI |
| `assets/css/site.css` | WhatsApp admin/customer styles |

## Template parameter order (must match Meta approval)

| Event | Body parameters |
|---|---|
| `registered` | receiver name, tracking, carrier, from → to |
| `ready` | receiver name, tracking, delivery fee |
| `payment_received` | receiver name, tracking, delivery fee, collection code |
| `collected` | receiver name, tracking |
| `status` | receiver name, tracking, status, note (optional) |

Templates are inactive by default. Create and approve them in Meta, then enter the exact template name/language in Admin → WhatsApp and enable them. The fixed parameter order above is what the app sends.

Suggested utility template bodies:

- `mzigo_parcel_registered`: “Hi {{1}}, parcel {{2}} with {{3}} is registered. Route: {{4}}. Track it at mzigo.online/track-mzigo.”
- `mzigo_parcel_ready`: “Hi {{1}}, parcel {{2}} is ready for collection. Delivery fee: KES {{3}}. Pay in the Mzigo customer portal to receive your collection code.”
- `mzigo_payment_received`: “Hi {{1}}, payment for {{2}} was received. Amount: KES {{3}}. Collection code: {{4}}. Show it at the office.”
- `mzigo_parcel_collected`: “Hi {{1}}, parcel {{2}} has been collected. Thank you for using Mzigo.online.”
- `mzigo_parcel_status`: “Hi {{1}}, parcel {{2}} update: {{3}} {{4}}”

## Consent rules

- Outbound templates **never** go to a cold phone number.
- A `wa_contacts` row must have `consent_at` set and `opted_out = 0`.
- Inbound WhatsApp messages create consent automatically.
- Customer portal → Email & notification preferences → **Receive parcel updates on WhatsApp** creates consent for the account phone.
- STOP/UNSUBSCRIBE/OPT OUT sets `opted_out=1`; no template or auto reply is sent afterwards.
- START/OPT IN re-enables.

## Live setup

1. Meta: create/approve the five utility templates with the exact parameter counts above.
2. Admin → WhatsApp:
   - Mode: Live
   - Enabled: Enabled
   - Meta phone number ID, access token, app secret
   - Paste verify token into Meta webhook
   - Enter template names/languages and enable each
3. Ensure the queue worker runs every minute:
   ```
   C:\xampp\php\php.exe C:\xampp\htdocs\www\mzigo\_server\cron\whatsapp_queue.php
   ```
4. Customers must opt in (inbound message or customer preference).
5. Test each template from Admin → WhatsApp before enabling parcel traffic.

## QC performed

- Migration 036 applied.
- PHP lint clean for outbound, admin API, notifications, customer API.
- JS syntax clean.
- Saved/activated all five template mappings through the admin API.
- Demo-sent all five events successfully.
- Verified outbound events table and template messages in the conversation thread.
- Verified template skipped with `live mode is not enabled`.
- Verified consent enforcement: opted-out contact → `no_consent`; START → active again.
- Verified customer opt-in via customer login: `whatsapp_optin` false → true, `wa_contacts.consent_at` recorded.
- Verified Admin API returns templates and recent outbound logs.
- Phase 1 regression checks remain valid (webhook verification, FAQ, tracking, handoff, queue worker).

## Known limitations

- Templates must be created and approved manually in Meta; no Meta template-approval API sync yet.
- No scheduled retry/fallback to SMS if a template fails.
- No WhatsApp template message history in the main notification log (it has its own outbound log + conversation message).
- Only parcel lifecycle events are wired; claim/invoice/subscription events remain out of scope for Phase 2.
- Outbound sending requires the Meta contact to have opted in; most existing customers must complete the customer-portal opt-in or message the business first.
- Meta per-template category/pricing and 24-hour service-window rules still apply.
- No template media headers or buttons; body text only.
- Production rollout still depends on real Meta credentials, live mode, and approved templates.
