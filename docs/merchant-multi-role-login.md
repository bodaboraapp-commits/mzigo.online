# Merchant phone: customer/freelancer access

**Status:** implemented 27 October 2026. A merchant/owner account keeps its existing registration and primary merchant role. The same phone can now also sign in as a customer or freelancer based on the login choice.

## How it works

Migration `039_multi_role_access.sql` adds `user_roles`, storing secondary access grants without changing `users.role` or the tenant membership.

Login options on `/login`:

- **Phone** tab: choose **Customer** or **Freelancer**, enter the phone number, then verify the code/PIN.
- Existing Customer and Freelancer tabs remain available.

The server:

- Adds the secondary `customer` or `freelancer` grant to the same user account
- Sets an **active role** for that session only
- Exposes the active role to the frontend and role checks
- Redirects:
  - Customer → `/customer`
  - Freelancer → `/freelancer`

The normal Business sign-in still uses the merchant email/password and returns to `/profile`/the workspace dashboard; the active role is cleared.

## Merchant registration

No change. Merchant/workspace registration, owner role, tenant membership, billing and workspace data are unchanged.

## Customer access

A merchant phone can sign in as a customer. The customer API uses the receiver phone key, so parcels sent to that phone appear in the customer portal. Claims and CSAT remain customer-scoped.

## Freelancer access

A merchant phone can sign in as a freelancer. A runner profile is created/used for the same user ID; KYC, job feed, earnings and payouts remain runner-scoped.

## Security

- Secondary roles require possession of the phone OTP/PIN.
- The active role is session-only and cleared on logout or normal business login.
- `user_roles.role` is restricted to `customer`/`freelancer`.
- Platform admin accounts are not allowed to use the phone role flows.

## Tested

- Merchant phone `0733111222` → customer: `redirect=/customer`, `role=customer`, `/customer` 200
- Same merchant phone → freelancer: `redirect=/freelancer`, `role=freelancer`, `/freelancer` 200
- Normal merchant login unchanged: `role=owner`, `redirect=/profile`, `/profile` 200
- `user_roles` contains both grants for the same user ID.

## Deploy

1. Deploy the latest Git commit in cPanel.
2. Run the web updater to apply migration `039_multi_role_access.sql`:

```text
https://mzigo.online/api/db-update.php
```

3. Hard-refresh `/login`.
