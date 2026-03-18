# Plausible Analytics for ProcessWire

A full-featured [Plausible Analytics](https://plausible.io) dashboard module for [ProcessWire](https://processwire.com), built on the Stats API v2.

**Author:** [Maxim Semenov](https://smnv.org)
**Repository:** [github.com/mxmsmnv/PlausibleAnalytics](https://github.com/mxmsmnv/PlausibleAnalytics)

---

## Features

- Standalone dashboard page under **Setup → Analytics**
- Summary cards: Visitors, Pageviews, Visits, Bounce Rate, Visit Duration
- Traffic trends chart (Chart.js) with hourly or daily resolution
- Top Pages table with clickable paths and inline page-edit links
- Traffic Sources table
- Tabbed section: Geography, Devices, Browsers
- Page-edit widget showing per-page stats (Visitors, Pageviews, Visits, Views/Visit, Bounce Rate, Visit Duration)
- Period selector: Today, Last 7 Days, Last 30 Days, Last 6 Months, Last 12 Months
- Configurable cache lifetime
- Debug mode with API request logging
- Self-hosted Plausible support via custom base URL

---

## Requirements

- ProcessWire 3.0+
- PHP 7.3+
- cURL extension
- A [Plausible](https://plausible.io) account (cloud or self-hosted)

---

## Installation

1. Download or clone this repository into `/site/modules/PlausibleAnalytics/`
2. Go to **Modules → Refresh** in the ProcessWire admin
3. Find **Plausible Analytics** and click **Install**
4. Open **Setup → Analytics** — you will be prompted to configure the module

---

## Configuration

Go to **Modules → Configure → PlausibleAnalytics**.

| Setting | Description |
|---|---|
| API Key | Stats API key from your Plausible account settings (Account → API Keys) |
| Site ID | Your domain as registered in Plausible, e.g. `example.com` |
| API Base URL | Override only if self-hosting Plausible. Default: `https://plausible.io` |
| Cache Lifetime | How long API responses are cached, in minutes. Default: `5` |
| Show widget on page edit | Enables the stats widget on the page-edit screen |
| Summary cards | Toggle the summary stat cards |
| Traffic trends chart | Toggle the Chart.js traffic trends chart |
| Top pages table | Toggle the top pages breakdown |
| Traffic sources table | Toggle the traffic sources breakdown |
| Geography tab | Toggle the country breakdown tab |
| Devices tab | Toggle the device breakdown tab |
| Debug mode | Logs all API requests with HTTP codes and error responses |

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
- **Visits**, **Views/Visit**, **Bounce Rate**, **Visit Duration** — filtered by `visit:entry_page` (sessions that started on this page)

---

## API notes

This module uses the Plausible **Stats API v2** (`POST /api/v2/query`).

Key differences from v1 that are handled internally:

- `"24h"` is not a valid `date_range` in v2; `"day"` is used for the current day
- Event metrics (`visitors`, `pageviews`) and session metrics (`bounce_rate`, `visit_duration`) cannot be mixed in a single request — the module splits them into separate calls where needed
- Realtime visitor count still uses the v1 `/api/v1/stats/realtime/visitors` endpoint, as v2 has no equivalent

---

## Cache

All API responses are cached in the ProcessWire WireCache table under keys prefixed `plausible_`. Cache can be cleared at any time using the **Clear Cache** button at the bottom of the dashboard.

---

## License

MIT