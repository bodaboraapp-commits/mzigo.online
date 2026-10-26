# WhatsApp Phase 1 — Implementation

**Status: implemented 26 October 2026.** Demo-testable without Meta credentials; live mode requires Meta WhatsApp Cloud API credentials and AI provider key.

**Phase 2:** outbound parcel templates are implemented — see `docs/whatsapp-phase2-implementation.md`.

## What is included

- Meta WhatsApp Cloud API webhook:
  - `GET /api/whatsapp-webhook.php` verification challenge.
  - `POST` signed inbound messages; fast enqueue and idempotent message storage.
  - Live mode refuses unsigned webhooks.
- Demo/live configuration in **Admin → WhatsApp**.
- **FAQ answers** from the existing `faqs` table.
- **Parcel tracking** by `TRK-…` number — public status only.
- **AI** via OpenAI or Anthropic (configurable base URL/model/key); deterministic FAQ/tracking first, AI fallback, then human handoff.
- **Human handoff** queue and conversation takeover.
- **Admin console**:
  - metrics: conversations, open handoffs, messages today, queued jobs
  - live/demo config form
  - AI provider/model/key/system prompt
  - conversation list, full transcript, human reply
  - take over / close / reopen
  - handoff queue + Resolve
  - demo-safe message simulator
  - process queue button
  - webhook URL and verify token
- Queue worker: `_server/cron/whatsapp_queue.php`.
- Opt-in/opt-out keywords: STOP/UNSUBSCRIBE/OPT OUT and START/OPT IN/UNSTOP.
- Tables: `wa_contacts`, `wa_conversations`, `wa_messages`, `wa_jobs`, `wa_handoffs`.

## Demo flow (no Meta needed)

1. Admin → WhatsApp.
2. Keep mode **Demo**.
3. Simulate:
   - `How do I track my parcel?`
   - `track TRK-…`
   - `I need a human about a lost parcel`
4. The simulator always uses demo sending, even when live mode is enabled.
5. Open the conversation, inspect transcript, take over, reply, resolve handoff.

## Live setup

1. Meta Business verification, WABA, dedicated phone number, permanent token, phone number ID, App ID/secret.
2. In Admin → WhatsApp:
   - Mode: **Live Meta Cloud API**
   - Enabled: **Enabled**
   - Phone number ID, WABA ID, Graph version
   - Meta access token + app secret
   - Verify token (already generated; copy to Meta webhook configuration)
   - AI provider, model, base URL, API key
   - Handoff message and system prompt
   - Save.
3. In Meta app webhook:
   - URL: `https://<domain>/api/whatsapp-webhook.php`
   - Verify token: copy from Admin → WhatsApp.
   - Subscribe to `messages` (and statuses).
4. Schedule the worker every minute:
   ```
   C:\xampp\php\php.exe C:\xampp\htdocs\www\mzigo\_server\cron\whatsapp_queue.php
   ```
5. Send a test WhatsApp message from a permitted/test number.

## Configuration fields

| Field | Purpose |
|---|---|
| Mode | `demo` (no Meta calls) or `live` |
| Enabled | Pauses auto-processing. Inbound messages are still stored for manual handling. |
| Phone number ID / WABA ID / Graph version | Meta Cloud API target |
| Access token | Permanent system-user token |
| App secret | Required in live mode to validate `X-Hub-Signature-256` |
| Verify token | Meta webhook verification |
| AI provider/model/base/key | OpenAI or Anthropic; `demo` disables AI |
| Auto reply | When off, inbound messages create a handoff instead of an automated reply |
| Handoff message | Reply used when handing to support |

## Behaviour order

1. STOP/START keywords.
2. Opted-out contacts get no auto reply.
3. Tracking number regex → public parcel status.
4. Explicit human/claim/refund/lost/damaged/payment-issue keywords → handoff.
5. FAQ match (≥2 keyword matches).
6. AI provider answer (if configured).
7. Fallback handoff.

AI guardrails: no personal data, no invented status, no payment/refund promises; the system prompt explicitly requires handoff for account-specific/payment/claim cases.

## Security

- Live webhook requires app secret + `X-Hub-Signature-256`; unsigned live posts are rejected.
- Duplicate Meta message IDs are ignored via a unique key.
- Admin APIs require platform admin + CSRF.
- Demo simulation forces demo sending, so it cannot message real WhatsApp users.
- Tokens/secrets are masked in the UI; blank fields preserve stored secrets.
- Public tracking response excludes receiver name, phone, collection code and account data.
- Opt-out is recorded; STOP stops automated replies.

## Verification performed

- Webhook GET verification: both dotted Meta params and underscore fallback returned the challenge.
- Webhook POST accepted a sample Cloud API payload and enqueued it.
- Queue worker processed the job and sent a demo reply.
- FAQ: “How much does delivery cost?” returned the cost FAQ.
- Tracking: `track TRK-DEMO0C0BB5` returned courier, route, status and tracking link.
- Handoff: “I need a human about a lost parcel” created a handoff and returned the handoff message.
- Admin API: simulator processed FAQ/tracking/handoff; conversation and handoff counts updated.
- PHP lint and JS syntax clean.

## Known limitations (post-MVP)

- Outbound parcel templates are now implemented in Phase 2 (`docs/whatsapp-phase2-implementation.md`).
- No payment link/STK or claims actions inside chat (Phase 3; depends on PAY-01).
- Text messages only; images/voice/media are stored as unsupported and not interpreted.
- AI keys use platform settings (same pattern as other integrations); move to a secret manager for production.
- No per-agent admin roles; any platform admin can take over.
- Inbound rate limiting is not yet implemented beyond Meta-side controls.
- Conversation assignment is single-admin; no team routing.
- No WhatsApp template management UI yet.
- Admin console uses polling/reload, not live websockets.
- AI fallback is intentionally conservative; more FAQ content will improve resolution rate.
