# SEO monitoring & Search Console setup

## Search Console and Bing verification

Verification tokens are managed in **Admin → SEO → Search & Core Web Vitals**.

Paste:

- **Google Search Console token** — the `content` value Google gives for the HTML meta-tag verification method.
- **Bing Webmaster Tools token** — the `content` value for `msvalidate.01`.

These tokens are written into the static page `<head>` by the page builder:

```powershell
& 'C:\Users\user\.dsh\dsh-runtimes\dsh-primary-runtime\dependencies\python\python.exe' _raw/tools/build_pages.py
```

Deploy the rebuilt pages, then click **Verify** in Search Console / Bing. Tokens are also stored in `platform_settings` (`seo_search_console_token`, `seo_bing_token`).

## Sitemap

The generated `sitemap.xml` includes marketing pages, partner filter landing pages (`/partners/{slug}`), help and location pages. Submit:

- `https://mzigo.online/sitemap.xml` in Google Search Console
- the same sitemap in Bing Webmaster Tools

`robots.txt` allows public marketing pages and blocks authenticated/API screens.

## Partner filter landing pages

Each active partner has a dedicated, indexable route page at:

```
/partners/{partner-slug}
```

Examples: `/partners/chania`, `/partners/kasese`, `/partners/metro-trans`.

The page lists destinations served by that partner, has a unique title/meta description, breadcrumb schema and booking CTAs. Routing is handled by `partners-filter.php` and `.htaccess`.

## Real-user Core Web Vitals

Marketing pages collect **LCP, INP, CLS, FCP and TTFB** using `PerformanceObserver` and send batched, privacy-safe samples to:

```
POST /api/vitals.php
```

No IP, user ID or cookie identifier is stored. Samples contain only metric name/value/rating, page path, device class and connection type. They are stored in `web_vitals` for 7-day p75 reporting in **Admin → SEO → Search & Core Web Vitals**.

## External monitoring steps

These require account access outside the codebase:

1. Add the production property in Google Search Console and Bing Webmaster Tools.
2. Verify ownership using the admin tokens above (or DNS).
3. Submit `sitemap.xml`.
4. Enable email alerts for coverage/indexing errors.
5. Configure Core Web Vitals monitoring using CrUX/PageSpeed Insights. The optional API key field in Admin is ready for a PageSpeed/CrUX integration.
6. Review p75 LCP/INP/CLS weekly; fix regressions before they affect rankings.

## Local verification

```powershell
curl.exe -s -o NUL -w "%{http_code}" http://localhost:8090/partners/chania
curl.exe -s http://localhost:8090/sitemap.xml | Select-String "partners"
```

Post a sample vital:

```powershell
curl.exe -X POST -H "Content-Type: application/json" -d '{"page_path":"/","metrics":[{"metric":"LCP","value":2100,"rating":"good"}]}' http://localhost:8090/api/vitals.php
```
