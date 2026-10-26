# Mzigo.online — SEO Rollout Log (Phase A + B)

**Status:** implemented and verified on 25 October 2026. Source proposal: [seo-content-proposal.md](seo-content-proposal.md).
**Phase 1 (on-page copy + technical SEO) and Phase 2 (About, Help Centre, location pages, partner programme) are live.**

## Approved decisions

| Decision | Resolution |
|---|---|
| Home H1 | **Option B kept** — "Parcels delivered fast, affordably with care" (CMS-editable). Keywords live in the title, meta, intro and section headings. |
| Coverage claim | **"6 trusted courier partners · 70+ destinations"** approved after data cleanup (now 71 real destinations). |
| Pricing page | Plans/prices are shown **only in paid mode**. In free mode the page now renders a clear "free to use right now" state; the old redirect to `/signup` and the promo banner are removed. |
| Courier partner programme | Exists — Q12 FAQ + partner recruitment block shipped; a full `/partners/become` page is queued for Phase 2. |
| Domain & contact | `https://mzigo.online`; `hello@mzigo.online`; toll free **0800 211 211**; social placeholder **@mzigo.online** (Instagram/Facebook/LinkedIn/X). |
| OG image | Created: `assets/img/og-image.png` (1200×630). |
| Phase 2 order | About page, Help Centre and location pages (Nairobi, Mombasa, Nakuru first); partner programme page after. |

## What shipped

### Metadata & technical SEO (all pages)
- Unique, length-validated `<title>` and meta description for every page (SEO source of truth: `SEO` dict in `_raw/tools/build_pages.py`).
- Self-referencing canonicals on `https://mzigo.online{path}`.
- `robots` policy: public pages `index, follow, max-image-preview:large`; auth/app screens `noindex, nofollow`.
- Open Graph + Twitter (`summary_large_image`) with the new 1200×630 image.
- JSON-LD: Organization (site-wide), WebSite + Service + FAQPage (home), BreadcrumbList (inner pages), ContactPage (contact).
- `sitemap.xml` (22 indexable URLs, including `/faq`, `/help`, guides, locations and `/partners/become`) and `robots.txt` generated on every build.
- New title/meta: home now "Send & Track Parcels Across Kenya | Mzigo.online".

### Copy
- **Home:** new intro/trust band, "How Mzigo works" (3 steps), "Where we deliver", "Built for businesses and everyday senders", final CTA; "Why send with Mzigo" expanded to 4 proof cards.
- **Send:** parcel sizes, pay-on-collection, what you need, where parcels can go, track cross-link.
- **Errand:** what a runner can do, paying for an errand, how booking works.
- **Track:** where to find a tracking number, what each scan means, "no scans yet" support block.
- **Partners:** how to choose a partner, new routes note, courier recruitment block ("Become a partner").
- **Pricing:** plan explanations, inclusions, pricing Q&A; paid plans rendered only in paid mode.
- **Contact:** support intro, toll-free number and social handle.
- **Signup:** trust-oriented heading and intro.
- **Parcel insurance positioning:** Mzigo.online is the first Kenyan company to offer parcel insurance — the fact and the peace-of-mind benefit are now woven through the home page, Send, Errand, Track, Partners, Pricing, Contact, Signup, FAQ, About, Help Centre and its guides, all three location pages, the partner programme and the Terms.
- **FAQ:** replaced with the keyword bank (DB + static). The landing page now shows the first **5** FAQs with a **View all FAQs** button that opens the new standalone **[/faq](/faq)** page, which lists all 12 and carries the FAQPage schema.
- **Footer:** toll free + @mzigo.online placeholder added.

### Data
- Migration `015_cleanup_destinations.sql`: removed test rows, merged spelling duplicates → **71 destinations**.
- Migration `016_seo_faqs.sql`: seeded the 12 approved FAQs (still editable in Admin → Website → FAQs).

## Verification

| Check | Result |
|---|---|
| Titles ≤60 / metas ≤160 / unique | ✅ (validated by script) |
| Canonical + OG + JSON-LD on indexable pages | ✅ |
| `noindex` on app/auth screens | ✅ (12 screens) |
| FAQPage entries | ✅ 12 (matches visible FAQs) |
| Sitemap / robots / OG image | ✅ 200 |
| Pricing free mode | ✅ no redirect, plans hidden, clear free copy, paid mode still serves plans |
| CLS (home) | ✅ 0.0016 |
| Mobile overflow | ✅ none |
| Screenshots | `_raw/saas-proof/seo/` |
| Console errors | ✅ none |

## Phase 2 shipped (25 October 2026)
1. **About** — `/about` expanded with the Kenyan-first insurance story, mission/what/who cards, company story, why-senders-choose, promise, network, standards and CTA (AboutPage + Breadcrumb schema).
2. **Help Centre** — `/help` hub plus 4 guides: `/help/how-to-send-a-parcel-in-kenya` (Article + HowTo), `/help/mpesa-pay-on-collection`, `/help/how-to-track-your-parcel`, `/help/parcel-sizes-and-pricing`.
3. **Location pages** — `/send-parcel-nairobi`, `/send-parcel-mombasa`, `/send-parcel-nakuru` with local area lists and internal links.
4. **Partner programme** — `/partners/become` with benefits, onboarding steps, requirements, partner FAQs and apply CTA. Partners page links to it.
5. Footer updated (About Mzigo, Become a partner, Help centre) and home coverage cities now link to the location pages.

## Still to do (Phase 3 ideas)
- Blog hub + monthly delivery content.
- More corridor pages (Nyeri, Thika, Kisumu, Machakos).
- Review the placeholder social profile URLs.
- Consider partner directory filters landing pages.

## Maintenance notes
- CMS-editable: home hero title/subtitle, FAQs, brand logo/favicon/hero photo (Admin → Website).
- Everything else is generated: edit `_raw/tools/build_pages.py`, then run the build (it also refreshes sitemap/robots and bakes CMS hero copy).
- When paid mode is enabled, the pricing page automatically shows plan cards again — no code change needed.

## Runner flow fixes shipped (26 October 2026)
1. **Runner landing page** — `/earn` with how it works, requirements, runner pay explanation, runner FAQ and join CTAs; added to sitemap (23 URLs), header nav (“Earn”), home band, footer and login helper links.
2. **Broken dashboard CTAs fixed** — `/profile` redirect loops removed; “Update profile / Update availability” now target the on-page runner profile section. Misleading “Find errand jobs” replaced with an honest jobs panel (refresh + empty state).
3. **Onboarding** — first-login checklist, runner profile form (name, areas, vehicle, availability days/window, M-Pesa payout number, optional ID, terms consent), availability toggle and Active/Profile-incomplete status. New `freelancer_profiles` table + `api/freelancer.php` (`GET` snapshot, `save-profile`, `set-availability`).
4. **Join flow** — earnings explanation, required Terms/Privacy consent, and Resend PIN in the freelancer login panel.
5. **Role-aware header CTA** — runners see “My jobs”, customers “My parcels”; guest nav now includes “Earn”.

Still pending: real job feed/accept/status flow, notifications, earnings ledger/payout history, runner-specific terms page, KYC verification.

## Runner job console shipped (Phase 2, 26 October 2026)
- Jobs created automatically from errand bookings (`api/_runner_jobs.php` + `api/errands.php`).
- Runner dashboard: available errands, accept/decline, confirm pickup, mark delivered with proof note, completed history, real earnings and in-app notifications.
- Accept guarded by completed profile + availability; atomic assignment prevents double-accept.
- Migration `022_runner_jobs.sql`; job/notification APIs in `api/freelancer.php`.
- Remaining: payout ledger/disbursement, SMS/WhatsApp notifications, area matching, ops dispatch console.

## CTA system + P1/P2 pass (26 October 2026)
- Applied the CTA hierarchy rules (one filled primary per section, verb labels, 44px mobile touch targets).
- Added send-page guest workspace CTA, pricing audience switch, login secondary helpers.
- Added role-aware mobile bottom nav (guest/merchant/freelancer/customer).
- Added merchant dashboard workspace actions (invite team, upgrade, API, help) and richer empty states across orders, claims, dashboard lists and customer parcels.
- Receiver polish: post-track support/claim actions, customer sign-in prompts, collection-code copy + “keep this code” guidance.
