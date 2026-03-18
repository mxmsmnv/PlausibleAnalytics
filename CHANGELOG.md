# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [1.0.0] - 2025-03-18

Initial public release.

### Added

- Standalone dashboard page under Setup → Analytics
- Summary cards: Visitors, Pageviews, Visits, Bounce Rate, Visit Duration
- Traffic trends chart powered by Chart.js with hourly resolution for Today and daily resolution for longer periods
- Top Pages table with clickable page paths and inline pencil edit links
- Traffic Sources table
- Geography / Devices / Browsers tabbed section with vanilla JS tab switching
- Page-edit widget showing per-page stats for the last 30 days (Visitors, Pageviews, Visits, Views/Visit, Bounce Rate, Visit Duration)
- Period selector: Today, Last 7 Days, Last 30 Days, Last 6 Months, Last 12 Months; defaults to Last 30 Days
- Auto-redirect to `?period=30d` when the dashboard is opened without a period parameter
- Configurable cache lifetime via module settings (default 5 minutes)
- Debug mode with per-request API logging including HTTP codes and error response bodies
- Self-hosted Plausible support via configurable base URL
- `plausible-view` permission for access control
- Dashboard CSS scoped to `.pla-*` prefix to avoid conflicts with admin theme
- Page-edit widget CSS scoped to `.plausible-card*` prefix

### API

- Migrated from Stats API v1 to Stats API v2 (`POST /api/v2/query`)
- Replaced GET query-string requests with POST JSON body
- Replaced `period` parameter with `date_range`
- Replaced `/aggregate` endpoint with dimensionless v2 query
- Replaced `/breakdown?property=` with `dimensions` array in v2 query body
- Replaced `/timeseries` with `dimensions: ["time:day"]` or `["time:hour"]`
- Replaced string filter syntax (`event:page==path`) with array filter syntax (`["is", "event:page", [path]]`)
- Split aggregate requests that mix event and session metrics into separate calls to comply with v2 constraints
- Used `visit:entry_page` filter for per-page session metrics (bounce rate, visit duration) instead of `event:page`, which is incompatible with session metrics in v2
- Realtime visitor count kept on v1 `/api/v1/stats/realtime/visitors` endpoint (no v2 equivalent)