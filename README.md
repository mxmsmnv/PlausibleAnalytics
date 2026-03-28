# Plausible Analytics for ProcessWire

A full-featured [Plausible Analytics](https://plausible.io) dashboard module for [ProcessWire](https://processwire.com), built on the Stats API v2.

**Author:** [Maxim Semenov](https://smnv.org)  
**Repository:** [github.com/mxmsmnv/PlausibleAnalytics](https://github.com/mxmsmnv/PlausibleAnalytics)

---

## Features

- Standalone dashboard page under **Setup → Analytics**
- Summary cards with color accents: Visitors, Pageviews, Visits, Bounce Rate, Visit Duration
- Traffic trends chart (Chart.js) — blue gradient fill, orange dashed Pageviews line
- Top Pages — horizontal bar chart + table with clickable paths and inline edit links
- Traffic Sources — donut chart + table with color-coded dots
- Tabbed section: Geography (green bars), Devices (orange bars), Browsers (blue bars)
- Page-edit widget showing per-page stats for the last 30 days
- Period selector: Today, Last 7 Days, Last 30 Days, Last 6 Months, Last 12 Months (defaults to 30 Days)
- Configurable cache lifetime via LazyCron schedule
- Debug mode with per-request API logging
- Self-hosted Plausible support via custom base URL
- Chart.js loaded from local module directory (no external CDN dependency)

---

## Requirements

- ProcessWire 3.0+
- PHP 7.3+
- cURL extension
- LazyCron module (included with ProcessWire)
- A [Plausible](https://plausible.io) account (cloud or self-hosted)

---

## Installation

1. Download or clone this repository into `/site/modules/PlausibleAnalytics/`
2. Download Chart.js and save it to the module directory:
   ```bash
   cd /site/modules/PlausibleAnalytics/
   curl -o chart.umd.min.js https://cdn.jsdelivr.net/npm/chart.js/dist/chart.umd.min.js
   ```
3. Go to **Modules → Refresh** in the ProcessWire admin
4. Find **Plausible Analytics** and click **Install**
5. Open **Setup → Analytics** — you will be prompted to configure the module

---

## Configuration

Go to **Modules → Configure → PlausibleAnalytics**.

| Setting | Description |
|---|---|
| API Key | Stats API key from your Plausible account (Account → API Keys → Stats API) |
| Site ID | Your domain as registered in Plausible, e.g. `example.com` |
| API Base URL | Override only if self-hosting Plausible. Default: `https://plausible.io` |
| Cache Lifetime | LazyCron interval for API response caching. Default: Every Hour |
| Show widget on page edit | Enables the stats widget on the page-edit screen |
| Summary cards | Toggle the summary stat cards |
| Traffic trends chart | Toggle the Chart.js traffic trends chart |
| Top pages | Toggle the top pages bar chart and table |
| Traffic sources | Toggle the sources donut chart and table |
| Geography tab | Toggle the country breakdown tab |
| Devices tab | Toggle the device breakdown tab |
| Debug mode | Logs all API requests with HTTP codes and error responses |

### Cache Lifetime options

| Option | Interval |
|---|---|
| Every 30 Minutes | 30 min |
| Every Hour | 1 hour |
| Every 2 Hours | 2 hours |
| Every 4 Hours | 4 hours |
| Every 12 Hours | 12 hours |
| Every Day | 24 hours |
| Every Week | 7 days |
| Every 4 Weeks | 28 days |

### Creating an API key

1. Log in to your Plausible account
2. Go to **Account Settings → API Keys**
3. Click **New API Key**, select **Stats API**, save the key

---

## Permissions

The module registers the `plausible-view` permission. Assign it to any role that should have access to the Analytics dashboard.

---

## Page-edit widget

When **Show widget on page edit** is enabled, a stats panel is prepended to the page-edit form for every non-admin page. It always shows data for the **last 30 days**, regardless of the period selected in the dashboard.

Metrics shown:
- **Visitors** and **Pageviews** — filtered by `event:page`
- **Visits**, **Views/Visit**, **Bounce Rate**, **Visit Duration** — filtered by `visit:entry_page`

The widget includes a **Full Report** button linking to the main dashboard.

---

## Chart.js

Chart.js is loaded from the local module directory (`chart.umd.min.js`) to avoid external CDN dependencies. The file is not bundled with the module — download it once during installation:

```bash
curl -o /site/modules/PlausibleAnalytics/chart.umd.min.js \
  https://cdn.jsdelivr.net/npm/chart.js/dist/chart.umd.min.js
```

Tested with Chart.js 4.x.

---

## API notes

This module uses the Plausible **Stats API v2** (`POST /api/v2/query`).

Key differences from v1 that are handled internally:

- `"24h"` is not a valid `date_range` in v2; `"day"` is used for the current day
- Event metrics (`visitors`, `pageviews`) and session metrics (`bounce_rate`, `visit_duration`) cannot be mixed in a single request — the module splits them into separate calls where needed
- `visit:entry_page` filter is used for per-page session metrics instead of `event:page`, which is incompatible with session metrics in v2

---

## Cache

All API responses are cached in the ProcessWire WireCache table under keys prefixed `plausible_`. The cache lifetime is controlled by the LazyCron schedule setting. Cache can be cleared at any time using the **Clear Cache** button at the bottom of the dashboard.

---

## License

MIT