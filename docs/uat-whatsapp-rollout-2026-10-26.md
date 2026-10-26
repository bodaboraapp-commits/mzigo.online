# UAT Summary — WhatsApp Phase 1 + 2 Production Rollout

**Date:** 26 October 2026  
**Build:** WhatsApp Phase 1 (FAQ, tracking, handoff, admin inbox) + Phase 2 (consent-aware outbound parcel templates)  
**Environment:** Local UAT at `http://localhost:8090`, demo WhatsApp mode, MariaDB on port 3307, no Meta/AI credentials.  
**Assets:** `site.js?v=20261026d`, migrations `035_whatsapp_phase1.sql` and `036_whatsapp_templates.sql`.  
**Scope assumption:** “p roll out” is treated as production rollout of the WhatsApp feature. Broader V1 app blockers are listed separately at the end.

---

## 1. Verdict

| Question | Result |
|---|---|
| Functional UAT in demo environment | **PASS** |
| Production WhatsApp rollout | **CONDITIONAL NO-GO** until live Meta/template/worker prerequisites are completed |
| Defects found | **0 functional failures** in the demo test matrix |
| Tests passed | **16** |
| Tests blocked | **3** (external/live dependencies) |
| Tests failed | **0** |

The feature is functionally ready for a controlled Meta test/pilot. It is not ready for unrestricted customer traffic until live template approval and production delivery are verified.

---

## 2. Test matrix

| ID | Test | Expected | Result | Evidence |
|---|---|---|---|---|
| WA-UAT-01 | Admin WhatsApp console data | Config, metrics and five template mappings load | **PASS** | mode=demo, templates=5 |
| WA-UAT-02 | Meta webhook verification | Challenge returned with configured token | **PASS** | HTTP 200, challenge returned |
| WA-UAT-03 | Inbound FAQ + worker | FAQ answered end-to-end via queued job | **PASS** | 255-char reply from cost FAQ |
| WA-UAT-04 | Valid parcel tracking | Tracking, courier, route and status returned | **PASS** | `TRK-DEMO0C0BB5` matched |
| WA-UAT-05 | Invalid tracking | Safe “couldn’t find” response, no false status | **PASS** | no false parcel result |
| WA-UAT-06 | Human handoff | Handoff created, conversation moves to human, agent can take over/reply/resolve | **PASS** | conversation=12, handoff=1 |
| WA-UAT-07 | STOP / START | STOP suppresses auto replies; START restores consent | **PASS** | opted_out=1 then 0; no reply while stopped |
| WA-UAT-08 | Duplicate webhook delivery | Same Meta message ID stored once | **PASS** | one stored message for two posts |
| WA-UAT-09 | Customer WhatsApp opt-in | Customer preference persists and records consent | **PASS** | false → true; `consent_at` present |
| WA-UAT-10 | Template configuration | Five event mappings saved and active | **PASS** | 5 events configured |
| WA-UAT-11 | All template events | registered / ready / payment_received / collected / status send in demo | **PASS** | 5 events sent, 5 template messages stored |
| WA-UAT-12 | Consent enforcement | Template blocked for a non-opted-in phone | **PASS** | skipped=`no_consent` |
| WA-UAT-13 | Admin security | Anonymous access denied; invalid CSRF rejected | **PASS** | anon=401, bad CSRF=403 |
| WA-UAT-14 | Admin UI wiring | Admin page references cache-busted JS and WhatsApp module | **PASS** | `site.js?v=20261026d`, module present |
| WA-UAT-15 | Live Meta webhook signature + template delivery | Signed live webhook accepted, template delivered | **BLOCKED** | Requires Meta app secret, WABA, access token and approved templates |
| WA-UAT-16 | Live AI response | AI provider answers with configured key | **BLOCKED** | Requires OpenAI/Anthropic key; deterministic fallback tested |
| WA-UAT-17 | Production worker schedule | Queue worker runs every minute on production host | **BLOCKED** | Worker runs manually in UAT; scheduler not configured |
| WA-UAT-18 | Pause/rollback control (extra check) | Paused assistant stores inbound but creates no auto-reply job | **PASS** | 1 message stored, 0 jobs |
| WA-UAT-19 | Signature helper (extra check) | Valid HMAC accepted, invalid HMAC rejected | **PASS** | good-ok / bad-rejected |

UAT harness: [`_raw/tools/uat_whatsapp.ps1`](../_raw/tools/uat_whatsapp.ps1)  
Machine-readable result: [`_raw/tools/uat_whatsapp_result.json`](../_raw/tools/uat_whatsapp_result.json)

---

## 3. Evidence from the run

- Admin API returned five template mappings and conversation/metrics data.
- Webhook GET returned the verification challenge.
- Webhook POST queued inbound FAQ/tracking messages; worker produced outbound replies.
- Duplicate POST with the same `wamid` produced one stored message.
- Human handoff, takeover, agent reply and handoff resolution all succeeded.
- STOP set `opted_out=1`; a normal message while stopped produced no auto reply; START re-opted in.
- Customer portal opt-in persisted and created a WhatsApp consent contact.
- All five template events were demo-sent; outbound events and template messages were stored.
- A non-consented phone was blocked from receiving a template.
- Anonymous admin API access and bad-CSRF writes were rejected.
- Paused assistant stored an inbound message without enqueueing a job.
- Test contacts/conversations/customer account were removed after the run.

---

## 4. Findings and residual risks

| # | Finding | Severity | Action |
|---|---|---|---|
| 1 | No functional defects in demo UAT | — | Proceed to Meta staging test |
| 2 | Live Meta signature/template path untested | **Blocker** | Configure Meta test number/app secret/token; run WA-UAT-15 against Meta |
| 3 | Templates are configured but not verified against Meta approval | **Blocker** | Create/approve the five utility templates with exact parameter order |
| 4 | Worker is not scheduled in production | **Blocker** | Add the cron/Task Scheduler entry every minute |
| 5 | AI provider key absent | Risk (AI optional) | Add key/model and run live AI test; deterministic fallback works without it |
| 6 | Opt-in coverage will be low until customers message or use the portal | Risk | Add WhatsApp opt-in prompt to parcel/customer messaging; monitor opt-in rate |
| 7 | No browser-level visual UAT was executed in this environment | Test gap | Run one admin/customer UI walkthrough on staging before go-live |
| 8 | No SMS fallback if a WhatsApp template fails | Risk | Monitor failures; keep email/SMS notification path |
| 9 | Test/template names are active in demo | Housekeeping | Keep assistant paused; activate templates only after Meta approval |

### Production template prerequisites

1. `mzigo_parcel_registered`
2. `mzigo_parcel_ready`
3. `mzigo_payment_received`
4. `mzigo_parcel_collected`
5. `mzigo_parcel_status`

Each must be approved in Meta with the fixed parameter order documented in [`whatsapp-phase2-implementation.md`](whatsapp-phase2-implementation.md). If the approved body differs, update the mapping/parameter order before activating.

---

## 5. Rollout recommendation

### Stage 0 — pre-flight (must pass)
- [ ] Meta Business/WABA verified; production phone number attached.
- [ ] Five templates approved with matching parameter counts.
- [ ] WhatsApp access token + app secret stored; live mode configured.
- [ ] Webhook verified with Meta against the production URL and signature.
- [ ] Worker scheduled every minute and monitored.
- [ ] AI key/model configured (optional; fallback available).
- [ ] Customer opt-in copy/privacy notice approved.
- [ ] Staging browser walkthrough completed.

### Stage 1 — controlled Meta test
- Use Meta test numbers or 3–5 internal staff phones.
- Verify inbound FAQ/tracking/handoff and **one real template** end-to-end.
- Confirm Meta delivery status callback updates the message to sent/failed.
- Run for 24–48 hours; review outbound log and worker failures.

### Stage 2 — limited pilot
- 20–50 opted-in customers/partners.
- Monitor:
  - inbound volume
  - FAQ resolution vs handoff rate
  - template delivery failures
  - worker lag/queue depth
  - duplicate webhook deliveries
  - opt-out rate
  - support response time
- Rollback trigger: template failure rate >5%, worker backlog >5 minutes, or repeated wrong parcel status.

### Stage 3 — full enable
- Enable live parcel templates only after pilot metrics are acceptable.
- Keep AI fallback and human handoff active.
- Review daily for the first week.

### Rollback
- Admin → WhatsApp → Assistant **Paused** (stops auto replies and webhook enqueueing; inbound messages remain visible).
- Set mode **Demo** to prevent live Meta sends.
- Disable template mappings if needed.
- Keep the worker running to drain queued jobs, or stop it after the queue is empty.

---

## 6. Broader V1 rollout status

This UAT covers WhatsApp only. The full app **still has the V1 production blockers** from [`ENGINEERING_V1_READINESS.md`](ENGINEERING_V1_READINESS.md), notably:

- real M-Pesa customer/invoice payments (`PAY-01`)
- SMS/WhatsApp delivery for customer codes and runner PINs (`MSG-01`)
- live payout callback/reconciliation (`PAY-02`)
- production environment, demo-account cleanup, 2FA/access restriction, TLS/WAF, backups/monitoring, pen test (`PRD-01…09`)
- real insurer contract or gated insurance (`INS-01`)
- legal/privacy/DSR sign-off (`LEG-01`)

Do not treat the WhatsApp UAT as approval for the full V1 app rollout.

---

## 7. Sign-off

| Role | Name | Decision | Date |
|---|---|---|---|
| Engineering |  |  |  |
| Product/Owner |  |  |  |
| Support lead |  |  |  |
| Security/Ops |  |  |  |

**Engineering recommendation:** proceed to **Stage 1 controlled Meta test**, not full production. Current demo UAT is green; live Meta verification and worker scheduling are the remaining release gates.
