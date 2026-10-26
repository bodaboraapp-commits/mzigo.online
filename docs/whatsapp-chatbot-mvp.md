# WhatsApp AI Chatbot — MVP Requirements & Architecture

**Status:** Phases 1 and 2 implemented 26 October 2026 — see `docs/whatsapp-phase1-implementation.md` and `docs/whatsapp-phase2-implementation.md`. This document remains the product/architecture requirements.  
**Goal:** a WhatsApp assistant that answers customer questions, tracks parcels, routes to a human, and later handles notifications/payments.  
**Recommended stack:** Meta WhatsApp Cloud API (direct) + custom webhook/orchestration in this app + an AI provider with tool calling. A BSP is optional if speed matters more than control.

---

## 1. MVP scope

### In scope
1. **Opt-in and welcome flow** — user messages the business number; consent recorded; clear AI disclosure.
2. **AI FAQ assistant** — answers using the Mzigo help centre, FAQs, service/pricing/insurance pages, with safe fallback.
3. **Track a parcel** — user sends a tracking number; bot returns current status and next step. Generic status only; no PII until verified.
4. **Human handoff** — escalate to the existing contact inbox/support queue with conversation transcript, reason and customer phone.
5. **Outbound status notifications** — optional MVP extension: approved WhatsApp templates for “parcel ready”, “payment received”, “collection code”.
6. **Admin conversation console** — list conversations, view transcript, take over, mark resolved, see AI/handoff status.
7. **AI + WhatsApp configuration** in Admin (provider keys, model, prompts, business hours, escalation).
8. **Analytics** — conversations, resolution rate, handoff rate, response time, token/message cost.

### Out of scope for MVP
- Registering a parcel entirely inside chat (wizard is complex; post-MVP).
- Real payment collection inside chat until **PAY-01** (real M-Pesa STK/callback) is live.
- Claims filing inside chat (can hand off for now).
- Voice notes/transcription, images, WhatsApp Flows/checkout, broadcast marketing, multiple brands.
- Autonomous refunds/credits; AI must never promise money.

### Important product boundary
The bot can launch as **read-only support + tracking + handoff** without real payments. Do not promise “pay in chat” or “file a claim in chat” until the underlying live payment/claims APIs are production-ready.

---

## 2. Recommended architecture

```
WhatsApp user
   ↓
Meta WhatsApp Cloud API (WABA)
   ↓ webhook (signed)
POST /api/whatsapp-webhook.php
   ↓ verify X-Hub-Signature-256, dedupe by message id
wa_messages / wa_conversations (MySQL)
   ↓ enqueue (wa_jobs)
Worker / cron → AI orchestrator
   ├─ intent + policy guard
   ├─ tools: track parcel, FAQ search, resend receipt, handoff
   └─ AI provider (OpenAI/Anthropic/Google) with tool calling
   ↓
Reply via Cloud API send endpoint
   ↓
Admin console / human takeover
```

Key rules:
- Webhook must return **200 quickly**; process asynchronously.
- Idempotency by Meta `message.id` and webhook delivery id.
- Verify `X-Hub-Signature-256` with the Meta app secret.
- Never trust the WhatsApp phone number as authentication by itself; verify identity for account-specific data.
- Store only what is necessary; redact sensitive data in logs.

---

## 3. Components

### 3.1 Meta / WhatsApp Business
- Meta Business Portfolio and Business Manager.
- Facebook Developer App with WhatsApp product.
- WhatsApp Business Account (WABA).
- Dedicated business phone number (not active on WhatsApp, or eligible for migration).
- Display name, logo, description, website, privacy/terms URLs.
- Business verification (Meta review).
- Permanent system-user access token (not a short-lived token).
- Phone Number ID, WABA ID, App ID, App Secret, webhook verify token.
- Payment method for Meta messaging.
- Approved message templates for any business-initiated message (utility/authentication/marketing as applicable).
- Test phone number(s) and a test recipient list.

### 3.2 Backend webhook/orchestration in this app
New endpoints:
- `GET /api/whatsapp-webhook.php` — Meta verification challenge.
- `POST /api/whatsapp-webhook.php` — signature check, dedupe, normalize, enqueue.
- `POST /api/whatsapp-send.php` — internal/admin send, rate-limit, template support.
- `api/_whatsapp.php` — Cloud API client, template sender, signature verifier, normalizer.
- `api/_ai.php` — provider abstraction (OpenAI/Anthropic/Google), prompts, tool schema, token logging.
- Worker: `_server/cron/whatsapp_queue.php` or extend `api/cron.php` to process queued messages, AI calls and retries.

Suggested tables:
| Table | Purpose |
|---|---|
| `wa_contacts` | phone, WhatsApp ID, linked user/customer, consent, language, last seen |
| `wa_conversations` | status (bot/human/closed), assignee, last message, context |
| `wa_messages` | direction, Meta message id, type, text, template, status, AI usage, error |
| `wa_consents` | opt-in source, timestamp, opt-out, policy version |
| `wa_jobs` | queued inbound/outbound work, retries, idempotency |
| `wa_handoffs` | reason, priority, assigned agent, resolved_at |
| `wa_templates` | template names, language, category, approval status |
| `wa_ai_settings` | provider/model/keys/prompt/limits (encrypted) |

### 3.3 AI layer
- Provider: one of OpenAI, Anthropic, Google, or a managed bot platform (Botpress/Voiceflow) if speed matters.
- Model with **tool/function calling**; keep temperature low.
- System prompt: Mzigo brand, scope, escalation rules, no promises, no PII leaks, language (English/Swahili).
- RAG over help centre + FAQs + policies; return cited/source snippets.
- Tools (read-only MVP):
  - `track_parcel(tracking)` — public status only.
  - `faq_search(query)`.
  - `handoff(reason, urgency)`.
  - `resend_receipt(tracking)` — only after identity verification.
  - `get_collection_instructions(tracking)` — generic until verified.
- Guardrails: max turns, max tokens, timeout, cost cap, blocked intents, profanity/abuse handling, no financial advice.
- AI disclosure: “I’m Mzigo’s AI assistant; I can connect you to a person.”

### 3.4 Identity and privacy
- WhatsApp phone is a channel identity, not proof of account ownership.
- For account-specific data/payment:
  - verify via one-time code (existing customer OTP or WhatsApp authentication template), or
  - use tracking number + receiver phone match, with rate limits.
- For MVP, focus on public tracking and generic FAQs; hand off sensitive cases.
- Record consent/opt-in; honor STOP/opt-out.
- Privacy notice update for WhatsApp, AI processors and retention.

### 3.5 Admin console
New **Admin → WhatsApp** section:
- Connection status: WABA, phone, token age, webhook verified.
- AI settings: provider, model, key, prompt, temperature, monthly budget, language.
- Templates: status, category, language, preview.
- Conversations: list, transcript, status, handoff, assign, reply.
- Handoff queue and SLA.
- Metrics: conversations, resolution, handoff %, AI latency, token cost, errors.

### 3.6 Human handoff
- Reuse **Admin → Messages / contact inbox** or create a WhatsApp-specific inbox.
- On handoff: assign agent, show transcript, customer phone, parcel context (after verification), SLA timer.
- Outside business hours: capture request, promise next response, optional auto-reply.
- Handoff reason taxonomy: payment, claim, lost/delayed, account, complaint, other.

### 3.7 Outbound notifications
- Use existing notification events to send approved WhatsApp templates when the user has opted in.
- Templates likely needed:
  - parcel registered
  - parcel ready / payment due
  - payment received + collection code
  - delivered/collected
  - claim update
  - OTP/authentication
- Template approval takes time; submit early.
- Respect 24-hour customer-service window and template category rules.

---

## 4. Required setup checklist (what you need to provide)

### Meta / WhatsApp
- [ ] Meta Business Portfolio (verified)
- [ ] Business registration/KRA document and business address
- [ ] Facebook Developer App + WhatsApp product
- [ ] WABA ID
- [ ] Dedicated phone number for WhatsApp
- [ ] Display name and profile assets (logo, description, website)
- [ ] Privacy Policy and Terms URLs
- [ ] Permanent system-user access token
- [ ] Phone Number ID + App Secret + App ID
- [ ] Webhook verify token (we generate/agree)
- [ ] Meta payment method and messaging budget
- [ ] Template content approval (initial 3–5 templates)
- [ ] Test recipients

### AI
- [ ] Provider choice (OpenAI/Anthropic/Google/other)
- [ ] API key + budget/limit
- [ ] Data-processing/terms review
- [ ] Language decision (English/Swahili/both)
- [ ] Knowledge sources: help centre, FAQs, policies, delivery/collection rules
- [ ] Brand voice and escalation policy

### Operations
- [ ] Support agent(s), business hours, escalation SLA
- [ ] Handoff queue owner
- [ ] Policy for refunds, claims, lost parcels, abusive users
- [ ] Opt-in copy and STOP/help handling
- [ ] WhatsApp template catalogue owner

### Existing app dependencies
- [ ] Tracking API access (ready)
- [ ] FAQ/help content (ready)
- [ ] Admin contact inbox (ready)
- [ ] Notification channel framework (ready)
- [ ] Real M-Pesa payments (**not ready — PAY-01**) if payment in scope
- [ ] Customer/runner auth delivery (**not ready — SMS/WhatsApp OTP**) if using WhatsApp OTP

---

## 5. Build options

| Option | Pros | Cons | Recommendation |
|---|---|---|---|
| **Meta Cloud API direct + custom backend** | Lowest vendor markup; full data/control; integrates with existing app | More engineering; Meta verification/templates/billing handled by us | **Recommended** |
| BSP (Twilio, 360dialog, Infobip, Wati, etc.) | Faster onboarding, dashboard, template support | Extra fees, another vendor, data/compliance review | Good if speed > control |
| Managed bot platform (Botpress, Voiceflow, etc.) | Fast visual flows, analytics | Less control over app data/tools; per-seat/usage costs; security review | Good for prototype only |
| Hybrid: BSP/inbox + custom AI tools | Fast inbox + custom data | Two systems to reconcile | Possible for pilot |

---

## 6. MVP delivery plan

### Phase 0 — approvals/setup (external; 3–10 business days)
Meta business verification, WABA, phone, templates, AI key, legal/opt-in copy.

### Phase 1 — read-only assistant (recommended MVP, ~2–3 weeks after access)
Webhook + queue + conversation store + admin console + AI FAQ/tracking + human handoff + opt-in/opt-out + metrics.

### Phase 2 — outbound WhatsApp notifications (~1–2 weeks)
Approved templates for registered/ready/payment/collection/claim; integrate with existing notifications and preferences.

### Phase 3 — transactional actions (~2–3 weeks, depends on PAY-01)
Identity verification, resend receipt, payment link/STK in chat, claim handoff/status; strict guardrails.

---

## 7. Cost model to validate

- Meta: per-message/conversation charges depending on category and Kenya rate card; authentication/utility/marketing differ. Confirm current Meta pricing before launch.
- BSP (if used): monthly/platform + per-message markup.
- AI: per-token/model cost; cap per conversation and monthly budget.
- Infrastructure: worker/cron, logs, storage; negligible at pilot volume.
- People: support agents for handoff.

Set a per-conversation budget and alert at 80% of monthly cap.

---

## 8. Risks and mitigations

| Risk | Mitigation |
|---|---|
| Meta business/template rejection delays launch | Start verification and template submission immediately; fallback to email/contact form |
| AI hallucination or wrong parcel status | Tool-only data, no free-form status invention, source citations, low temperature, escalation |
| PII leak in chat | Public tracking only until verified; redact logs; strict system prompt |
| WhatsApp policy/opt-in violations | Record consent, honor STOP, use approved templates, no unsolicited marketing |
| Payment fraud/duplicate charges | Do not enable payment until PAY-01 callbacks/idempotency/reconciliation are live |
| AI cost overrun | Token caps, model choice, budget alerts, fallback to FAQ/handoff |
| Handoff queue overload | Business hours, auto-ack, prioritisation, canned agent replies |
| Webhook downtime | Fast 200 + queue/retry, monitoring, Meta webhook retry handling |

---

## 9. Acceptance criteria for MVP

- Meta webhook verification succeeds; signatures validated; duplicate deliveries ignored.
- A new WhatsApp user gets opt-in/AI disclosure and can ask FAQs.
- User can enter a tracking number and receive the current public status.
- AI refuses/pivots when asked for account-specific data without verification.
- Handoff creates an admin conversation with transcript and alerts support.
- Admin can view/take over/close conversations; templates status visible.
- Opt-out works; no outbound messages after STOP.
- Logs/metrics show messages, AI tokens, handoffs and errors.
- No payment or claim promises in MVP copy.

---

## 10. Approval/decision checklist

1. **Scope:** Phase 1 read-only assistant + handoff as MVP, or include outbound notifications? [recommend Phase 1+2]
2. **Provider path:** Meta Cloud API direct + custom backend (recommended) or BSP? 
3. **AI provider:** OpenAI / Anthropic / Google / other; data-processing approval.
4. **Language:** English only or English + Swahili?
5. **Number:** dedicated new WhatsApp number, or migrate an existing one?
6. **Templates:** approve initial registered/ready/payment/collection/claim/OTP templates.
7. **Handoff:** which team/queue, business hours and SLA?
8. **Payment:** keep out of MVP until PAY-01 is complete? [recommend yes]
9. **Budget:** Meta + AI monthly cap.
10. **Legal/privacy:** approve WhatsApp + AI data-processing and retention notice.

---

## 11. Recommendation

Start with **Phase 1 + Phase 2**: direct Meta Cloud API, custom webhook/orchestration, AI FAQ + tracking + handoff, plus approved outbound templates for parcel updates. Keep payments and claims out until PAY-01 and identity verification are production-ready.

The fastest credible MVP is **not** a fully autonomous bot; it is a safe, well-instrumented assistant that can answer, track, and hand off — with a clear upgrade path to payments and claims.
