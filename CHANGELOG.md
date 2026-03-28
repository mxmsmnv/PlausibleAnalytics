# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [1.2.0] - 2026-03-28

### Added

- Chart.js now loaded from local module directory (`chart.umd.min.js`) instead of jsDelivr CDN — no external network dependency at runtime
- Full Report button in page-edit widget is now center-aligned

### Changed

- Cache Lifetime setting replaced from a free-form integer (minutes) with a LazyCron schedule selector (Every 30 Minutes → Every 4 Weeks)
- `LazyCron` added to `requires` in `getModuleInfo()`
- Footer cache label now shows human-readable schedule name instead of raw minutes

---

## [1.1.0] - 2026-03-28

### Added

- Summary cards now have per-metric color accents (blue, purple, green, amber, red) via `border-top` and count color
- Traffic trends chart upgraded: blue gradient fill for Visitors, orange dashed line for Pageviews, hover tooltips
- Top Pages section: horizontal bar chart (blue) above the existing table
- Sources section replaced with donut chart (15-color palette) + table with color-coded dots
- Geography / Devices / Browsers tabs now each show a horizontal bar chart (green / orange / blue) above the data table
- All charts use IIFE + `DOMContentLoaded` readyState pattern to avoid conflicts with ProcessWire admin JS

### Changed

- Dashboard layout migrated from UIkit grid classes to custom CSS grid (`#pla-cards`, `#pla-cols`, `.pla-box`, `.pla-tbl`) to prevent admin theme interference
- `uk-table` replaced with `.pla-tbl` — full control over alignment, truncation, and link colors
- Period selector uses native `uk-select` but layout wrapper uses plain flexbox
- Tabs section uses UIkit `uk-tab` / `uk-switcher` for native tab behavior (no custom JS switcher)
- Footer uses plain flexbox; Clear Cache button styled independently
- Page-edit widget Full Report card: `align-items:center` + `text-align:center`
- Edit pencil icon replaced with inline SVG (was `uk-icon="pencil"` which failed to render inside UIkit tables)
- Page path links use explicit `.pla-link` class with hover underline (was `color:inherit` which was overridden by UIkit)

---

## [1.0.0] - 2026-03-18

Initial public release.

### Added

- Standalone dashboard page under Setup → Analytics
- Summary cards: Visitors, Pageviews, Visits, Bounce Rate, Visit Duration
- Traffic trends chart powered by Chart.js with hourly resolution for Today and daily for longer periods
- Top Pages table with clickable paths and inline edit links
- Traffic Sources table
- Geography / Devices / Browsers tabbed section
- Page-edit widget showing per-page stats for the last 30 days
- Period selector: Today, Last 7 Days, Last 30 Days, Last 6 Months, Last 12 Months; defaults to Last 30 Days
- Auto-redirect to `?period=30d` on first open
- LazyCron-based cache lifetime configuration
- Debug mode with per-request API logging including HTTP codes and error bodies
- Self-hosted Plausible support via configurable base URL
- `plausible-view` permission for access control

### API

- Stats API v2 (`POST /api/v2/query`) — replaces v1 GET endpoints
- `date_range` replaces `period`; `"day"` used for current day (`"24h"` is invalid in v2)
- `dimensions` array replaces `property` parameter
- `time:hour` / `time:day` dimensions replace `/timeseries` endpoint
- Array filter syntax `["is", "event:page", [path]]` replaces string filter syntax
- Event and session metrics split into separate requests to comply with v2 constraints
- `visit:entry_page` filter used for per-page session metrics
- Realtime visitor count uses legacy v1 `/api/v1/stats/realtime/visitors` (no v2 equivalent)