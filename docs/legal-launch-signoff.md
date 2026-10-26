# Legal & launch sign-off pack

Status as at 26 October 2026: **documents prepared; external sign-off and credentials are still required.**

## 1. Legal documents ready for review

| Document | Location | Status |
|---|---|---|
| Terms & Conditions incl. runner terms §22 and referral terms §23 | `/terms`, `#runner-terms`, `#referral-terms` | Draft published; needs counsel sign-off |
| Runner terms standalone | `docs/runner-terms-final.md` | Final draft for counsel |
| Referral terms standalone | `/terms#referral-terms` and this pack | Draft; needs counsel sign-off |
| Privacy policy | `/privacy` | Published; review status to confirm |
| Support policy | `/legal#support` | Published |

## 2. Counsel checklist

- [ ] Confirm runner classification (independent contractor), tax and payout wording.
- [ ] Confirm referral rewards: cash vs credit, caps, forfeiture, tax treatment and anti-fraud language.
- [ ] Confirm insurance wording and claims liability.
- [ ] Confirm data-protection wording for referral attribution (cookie, click logs, IP hashes).
- [ ] Confirm consumer terms for credit expiry/non-cash redemption.
- [ ] Approve effective dates and change-notification process.
- [ ] Record sign-off reference and publish “Last updated” dates.

## 3. Social profiles — values required

The admin form is live at **Admin → Website → Social media links**. Provide the real official URLs/handles (do not use placeholders):

- Instagram: `________________________`
- Facebook: `________________________`
- LinkedIn: `________________________`
- X / Twitter: `________________________`
- TikTok (optional): `________________________`
- WhatsApp (optional business number/wa.me link): `________________________`

Until these are entered, footer icons fall back to defaults or are hidden for blank networks.

## 4. Search Console / Bing / CWV — access required

Fields already exist at **Admin → SEO**:

- Google Search Console token (`google-site-verification` content value).
- Bing Webmaster token (`msvalidate.01` content value).
- CWV API key (optional; real-user p75 dashboard already built).

Steps requiring property access:

1. Add/verify `https://mzigo.online` in Google Search Console and Bing Webmaster.
2. Paste the verification tokens into the admin SEO fields and rebuild/deploy.
3. Submit `https://mzigo.online/sitemap.xml`.
4. Configure the CWV API key if real-user monitoring is required.
5. Confirm ownership and monitoring access is held by the Mzigo team.

## 5. Blocked until external inputs arrive

- Legal sign-off reference.
- Real social URLs.
- Search Console/Bing verification tokens and property access.
- Live Daraja and SMS/WhatsApp credentials (separate launch checklist).

Once provided, these can be applied without further code changes; the admin fields and build pipeline are ready.
