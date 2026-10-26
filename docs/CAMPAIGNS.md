# Marketing campaigns

Admin → Campaigns sends **non-transactional marketing messages** to confirmed newsletter and alert subscribers. Order, delivery, claim and onboarding communications remain in the transactional Communications templates and must not be sent through this menu.

## Audience rules

- Only `newsletter_subscribers` with `status = confirmed` receive campaigns.
- Audience filters:
  - Newsletter interest
  - Product-alert interest
  - All confirmed subscribers
- The subscriber's chosen channel is used: email, SMS or WhatsApp.
- Inactive channels are skipped and counted as failed/skipped.
- Every message includes a signed unsubscribe link.
- Unsubscribed subscribers are excluded from all future campaigns.
- Linked users with marketing preferences switched off are skipped.
- No contact is added from order, merchant or runner records implicitly.

## Campaign fields

- Name
- Audience
- Email subject
- Message body
- Optional CTA label and URL
- Optional artwork URL (defaults to the current newsletter artwork)
- Optional schedule time

## Trigger rules

Admins build a trigger plan in the campaign editor:

- Enable one or more of **Time**, **Event** or **Condition**.
- Add multiple **event rules**, each with its own delay in days.
- Add multiple **condition rules**, each with its own days/count value.
- Choose **ALL selected rules** or **ANY selected rule** logic.
- Time is always a hard gate: even with ANY logic, a campaign with Time enabled cannot send before its date.

Supported events:

- Subscriber confirmed after campaign creation, with optional N-day delay.

Supported conditions:

- Subscriber age at least N days
- No marketing campaign sent in the last N days
- Audience has at least N confirmed subscribers

The rule builder shows a live plain-language summary. Event and condition campaigns are activated without a schedule and are evaluated by `_server/cron/marketing_campaigns.php` (recommended every 15 minutes). The central `api/cron.php` also evaluates them. A campaign is sent once per recipient; the recipient ledger prevents duplicate sends.

## Delivery

- **Save draft** stores the campaign without sending.
- **Send now** requires explicit admin confirmation and cannot be undone.
- **Save & schedule** sets the campaign to `scheduled`.
- The central `api/cron.php` processes due scheduled campaigns.
- Each recipient is recorded in `marketing_campaign_recipients` to prevent duplicate sends.
- Campaign counters show sent and failed/skipped totals.

## Consent and compliance

Campaign recipients come only from the double opt-in newsletter subscription flow. Marketing campaigns must not be used for transactional notices, delivery updates, invoice notices or onboarding messages. Include accurate marketing copy; do not use false urgency or misleading claims.

## QC

- Confirm campaign appears under Admin → Campaigns.
- Check audience counts.
- Save a draft.
- Schedule a due campaign and run `php api/cron.php`.
- Send a test campaign in log mode and confirm recipient delivery records.
- Verify unsubscribe links and inactive-channel skipping.
- Verify merchant accounts cannot access `/api/admin-campaigns.php`.
