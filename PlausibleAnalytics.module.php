<?php namespace ProcessWire;

/**
 * Plausible Analytics Dashboard for ProcessWire
 *
 * Full-featured analytics dashboard using the Plausible Stats API v2.
 * Provides a standalone admin page, a page-edit widget, and configurable
 * section visibility. All API communication uses POST JSON to /api/v2/query.
 *
 * Realtime visitor count uses the legacy /api/v1/stats/realtime/visitors
 * endpoint, which has no v2 equivalent.
 *
 * Key API constraints observed in v2:
 *  - "24h" is not a valid date_range; use "day" for the current day.
 *  - Session metrics (bounce_rate, visit_duration, views_per_visit, visits)
 *    cannot be mixed with event metrics (visitors, pageviews) in one request
 *    when an event-dimension filter (event:page) is active.
 *  - bounce_rate / visit_duration are incompatible with event:page filters;
 *    use visit:entry_page filters instead for per-page session data.
 *
 * @version 1.2.0
 * @author  Maxim Semenov
 * @link    https://github.com/mxmsmnv/PlausibleAnalytics
 * @link    https://smnv.org
 */
class PlausibleAnalytics extends Process implements ConfigurableModule {

    /** @var array  Log of API request summaries, populated in debug mode. */
    protected $debug_log = [];

    /** @var int  Unix timestamp of the last successful cache write. */
    protected $last_cache_time = 0;

    // -------------------------------------------------------------------------
    // Module info
    // -------------------------------------------------------------------------

    public static function getModuleInfo() {
        return [
            'title'      => 'Plausible Analytics',
            'version'    => '1.2.0',
            'summary'    => 'Plausible Analytics dashboard using Stats API v2 with page-edit widget, traffic trends chart, and geo/device tabs.',
            'author'     => 'Maxim Semenov',
            'href'       => 'https://github.com/mxmsmnv/PlausibleAnalytics',
            'icon'       => 'line-chart',
            'permission' => 'plausible-view',
            'autoload'   => true,
            'requires'   => ['LazyCron'],
            'page'       => [
                'name'   => 'analytics',
                'parent' => 'setup',
                'title'  => 'Analytics',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Hooks
    // -------------------------------------------------------------------------

    /** Register hooks on module init. */
    public function init() {
        if ($this->show_on_page_edit) {
            $this->addHookAfter('ProcessPageEdit::buildForm', $this, 'hookPageEditStats');
        }
    }

    /**
     * Inject a stats widget into the page-edit form.
     *
     * Uses two separate API requests to avoid mixing event and session metrics:
     *  - Request 1 (event:page filter):      visitors, pageviews
     *  - Request 2 (visit:entry_page filter): bounce_rate, visit_duration, visits, views_per_visit
     */
    public function hookPageEditStats(HookEvent $event) {
        $page = $event->object->getPage();

        // Skip admin pages and pages without a valid ID.
        if (!$page->id || $page->template == 'admin' || !$this->api_key || !$this->site_id) return;

        $form      = $event->return;
        $path      = $page->path;
        // Widget always shows the last 30 days — independent of the dashboard period selector.
        $dateRange = '30d';
        $period    = '30d';

        // Event metrics — compatible with event:page filter.
        $eventData = $this->getApiData([
            'site_id'    => $this->site_id,
            'date_range' => $dateRange,
            'metrics'    => ['visitors', 'pageviews'],
            'filters'    => [['is', 'event:page', [$path]]],
        ], "page_event_{$page->id}_{$dateRange}");

        // Session metrics — require visit:entry_page filter instead of event:page.
        $sessionData = $this->getApiData([
            'site_id'    => $this->site_id,
            'date_range' => $dateRange,
            'metrics'    => ['bounce_rate', 'visit_duration', 'visits', 'views_per_visit'],
            'filters'    => [['is', 'visit:entry_page', [$path]]],
        ], "page_session_{$page->id}_{$dateRange}");

        if (!$eventData || empty($eventData['results'])) return;

        $eRow = $eventData['results'][0]['metrics']   ?? [];
        $sRow = $sessionData['results'][0]['metrics'] ?? [];

        $visitors    = number_format($eRow[0] ?? 0);
        $views       = number_format($eRow[1] ?? 0);
        $bounce      = ($sRow[0] ?? 0) . '%';
        $duration    = $this->formatDuration($sRow[1] ?? 0);
        $visits      = number_format($sRow[2] ?? 0);
        $vpp         = number_format((float) ($sRow[3] ?? 0), 1);
        $periodLabel = ($period == 'day') ? 'Today' : strtoupper($period);
        $adminUrl    = $this->wire('config')->urls->admin;

        $f            = $this->wire('modules')->get('InputfieldMarkup');
        $f->label     = sprintf($this->_('Plausible Stats (%s)'), ($period == 'day') ? 'Today' : $period);
        $f->icon      = 'line-chart';
        $f->collapsed = Inputfield::collapsedNo;

        // Widget CSS — scoped to .plausible-card* to avoid conflicts with admin theme.
        $css  = '<style>';
        $css .= '.plausible-cards{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:4px}';
        $css .= '.plausible-card{display:flex;flex-direction:column;background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:18px 20px 16px;text-decoration:none;color:inherit;transition:border-color .15s,box-shadow .15s;min-width:140px}';
        $css .= '.plausible-card:hover{border-color:#aaa;box-shadow:0 2px 6px rgba(0,0,0,.07);text-decoration:none;color:inherit}';
        $css .= '.plausible-card-label{font-size:10px;font-weight:700;letter-spacing:.08em;color:#888;margin-bottom:8px;text-transform:uppercase}';
        $css .= '.plausible-card-count{font-size:32px;font-weight:700;color:#111;line-height:1}';
        $css .= '.plausible-card-sub{font-size:11px;color:#aaa;margin-top:4px}';
        $css .= '</style>';

        // Helper to render a single stat card.
        $card = function($label, $value, $sub) {
            return '<div class="plausible-card">'
                . '<div class="plausible-card-label">' . $label . '</div>'
                . '<div class="plausible-card-count">' . $value . '</div>'
                . '<div class="plausible-card-sub">'   . $sub   . '</div>'
                . '</div>';
        };

        $html  = $css;
        $html .= '<div class="plausible-cards">';
        $html .= $card('Visitors',       $visitors, $periodLabel);
        $html .= $card('Pageviews',      $views,    $periodLabel);
        $html .= $card('Visits',         $visits,   'entry page');
        $html .= $card('Views / Visit',  $vpp,      $periodLabel);
        $html .= $card('Bounce Rate',    $bounce,   'entry page');
        $html .= $card('Visit Duration', $duration, 'entry page');

        // Full-report link card — label has no bottom margin so the icon sits flush.
        $html .= '<a href="' . $adminUrl . 'setup/analytics/" class="plausible-card" style="justify-content:center;align-items:center;text-align:center">'
            . '<div class="plausible-card-label" style="margin-bottom:0">Full Report</div>'
            . '<div uk-icon="icon:chart;ratio:1.4"></div>'
            . '</a>';

        $html .= '</div>';

        $f->value = $html;
        $form->prepend($f);
    }

    // -------------------------------------------------------------------------
    // Main dashboard page
    // -------------------------------------------------------------------------

    /**
     * Render the Analytics admin page.
     *
     * Injects all dashboard CSS once at the top, then delegates each section
     * to its own render method. Chart.js is loaded from the jsDelivr CDN.
     */
    public function ___execute() {
        // Handle cache-clear POST before rendering anything.
        if ($this->wire('input')->post('clear_plausible_cache')) {
            $this->clearModuleCache();
            $this->wire('session')->redirect($this->wire('page')->url . '?period=30d');
        }

        // Redirect to 30d if no period is set in the URL.
        if (!$this->wire('input')->get('period')) {
            $this->wire('session')->redirect($this->wire('page')->url . '?period=30d');
        }

        if (!$this->site_id || !$this->api_key) {
            return '<div class="uk-alert uk-alert-warning">Please configure API Key and Site ID in module settings.</div>';
        }

        // Load Chart.js from local module directory.
        // To install: download https://cdn.jsdelivr.net/npm/chart.js/dist/chart.umd.min.js
        // and save as /site/modules/PlausibleAnalytics/chart.umd.min.js
        $chartJs = $this->wire('config')->urls->PlausibleAnalytics . 'chart.umd.min.js';
        $this->config->scripts->add($chartJs);

        $css  = '<style>';
        // Cards grid
        $css .= '#pla-cards{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:24px}';
        $css .= '@media(max-width:900px){#pla-cards{grid-template-columns:repeat(3,1fr)}}';
        $css .= '@media(max-width:600px){#pla-cards{grid-template-columns:repeat(2,1fr)}}';
        $css .= '.pla-card{background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:16px 18px 14px}';
        $css .= '.pla-card-label{font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#888;margin-bottom:6px}';
        $css .= '.pla-card-count{font-size:1.9rem;font-weight:700;line-height:1.1}';
        $css .= '.pla-card-sub{font-size:11px;color:#aaa;margin-top:4px}';
        // Section wrapper
        $css .= '.pla-box{background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:20px 22px;margin-bottom:20px}';
        $css .= '.pla-box h3{font-size:13px;font-weight:700;margin:0 0 16px;color:#111}';
        // Two-column layout
        $css .= '#pla-cols{display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:20px}';
        $css .= '@media(max-width:800px){#pla-cols{grid-template-columns:1fr}}';
        // Table
        $css .= '.pla-tbl{width:100%;border-collapse:collapse;font-size:13px}';
        $css .= '.pla-tbl th{font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#999;padding:0 0 8px;border-bottom:1px solid #e5e7eb;text-align:left}';
        $css .= '.pla-tbl th.r,.pla-tbl td.r{text-align:right}';
        $css .= '.pla-tbl td{padding:7px 0;border-bottom:1px solid #f3f4f6;color:#333;font-size:13px}';
        $css .= '.pla-tbl td:first-child{max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}';
        $css .= '.pla-tbl td strong{font-weight:600;color:#111}';
        // Links and pencil
        $css .= '.pla-link{color:#374151;text-decoration:none}.pla-link:hover{color:#111;text-decoration:underline}';
        $css .= '.pla-pencil{color:#d1d5db;margin-left:4px;vertical-align:middle;text-decoration:none}.pla-pencil:hover{color:#6b7280}';
        // Tabs
        $css .= '.pla-tab-panel{display:none}.pla-tab-panel.active{display:block}';
        // Footer
        $css .= '#pla-footer{display:flex;justify-content:space-between;align-items:center;margin-top:8px;padding-top:12px;border-top:1px solid #e5e7eb;font-size:11px;color:#aaa}';
        $css .= '#pla-footer button{font-size:11px;color:#ef4444;background:none;border:none;cursor:pointer;padding:0}#pla-footer button:hover{text-decoration:underline}';
        $css .= '</style>';

        $out  = $css;
        $out .= '<div id="pla">';
        $out .= $this->renderHeaderSelector();

        if ($this->show_summary)       $out .= $this->renderSummaryStats();
        if ($this->debug_mode && !empty($this->debug_log)) $out .= $this->renderDebugLog();
        if ($this->show_chart_traffic) $out .= $this->renderMainChart();

        if ($this->show_pages || $this->show_sources) {
            $out .= '<div id="pla-cols">';
            $out .= '<div>' . ($this->show_pages   ? $this->renderTopPages()   : '') . '</div>';
            $out .= '<div>' . ($this->show_sources ? $this->renderTopSources() : '') . '</div>';
            $out .= '</div>';
        }

        if ($this->show_countries || $this->show_devices) $out .= $this->renderTabsSection();

        $out .= $this->renderFooter();
        $out .= '</div>';

        return $out;
    }

    // -------------------------------------------------------------------------
    // Dashboard render methods
    // -------------------------------------------------------------------------

    /** Render the period selector dropdown. */
    protected function renderHeaderSelector() {
        $period  = $this->getPeriod();
        $periods = [
            'day'  => 'Today',
            '7d'   => 'Last 7 Days',
            '30d'  => 'Last 30 Days',
            '6mo'  => 'Last 6 Months',
            '12mo' => 'Last 12 Months',
        ];

        $out  = '<div style="display:flex;justify-content:flex-end;margin-bottom:20px">';
        $out .= '<form method="get" action="./">';
        $out .= '<select name="period" class="uk-select uk-form-width-medium" onchange="this.form.submit()">';
        foreach ($periods as $val => $lbl) {
            $sel  = ($period == $val) ? ' selected' : '';
            $out .= '<option value="' . $val . '"' . $sel . '>' . $lbl . '</option>';
        }
        $out .= '</select>';
        $out .= '</form></div>';
        return $out;
    }

    /**
     * Render summary stat cards (Live, Visitors, Pageviews, Visits, Bounce, Duration).
     *
     * Two API requests are required because v2 forbids mixing event metrics
     * (visitors, pageviews) with session metrics (bounce_rate, visit_duration)
     * in a single dimensionless request.
     */
    protected function renderSummaryStats() {
        $period    = $this->getPeriod();
        $dateRange = $period;
        $subLabel  = ($period == 'day') ? 'Today' : strtoupper($period);

        // Request 1: event metrics (visitors, visits, pageviews).
        $agg1 = $this->getApiData([
            'site_id'    => $this->site_id,
            'date_range' => $dateRange,
            'metrics'    => ['visitors', 'visits', 'pageviews'],
        ], "summary_ev_{$dateRange}");

        // Request 2: session metrics — cannot be combined with request 1 in v2.
        $agg2 = $this->getApiData([
            'site_id'    => $this->site_id,
            'date_range' => $dateRange,
            'metrics'    => ['bounce_rate', 'visit_duration'],
        ], "summary_ses_{$dateRange}");

        // Merge into [visitors, visits, pageviews, bounce_rate, visit_duration].
        $row = array_merge(
            $agg1['results'][0]['metrics'] ?? [0, 0, 0],
            $agg2['results'][0]['metrics'] ?? [0, 0]
        );
        $idx = array_flip(['visitors', 'visits', 'pageviews', 'bounce_rate', 'visit_duration']);

        // Each card has a unique accent color matching the blue/orange/green palette.
        $cards = [
            ['label' => 'Visitors',       'value' => number_format($row[$idx['visitors']]         ?? 0),       'sub' => $subLabel, 'color' => '#3b82f6'],
            ['label' => 'Pageviews',      'value' => number_format($row[$idx['pageviews']]        ?? 0),       'sub' => $subLabel, 'color' => '#8b5cf6'],
            ['label' => 'Visits',         'value' => number_format($row[$idx['visits']]           ?? 0),       'sub' => $subLabel, 'color' => '#10b981'],
            ['label' => 'Bounce Rate',    'value' => ($row[$idx['bounce_rate']]                   ?? 0) . '%', 'sub' => $subLabel, 'color' => '#f59e0b'],
            ['label' => 'Visit Duration', 'value' => $this->formatDuration($row[$idx['visit_duration']] ?? 0), 'sub' => $subLabel, 'color' => '#ef4444'],
        ];

        $out = '<div id="pla-cards">';
        foreach ($cards as $c) {
            $out .= '<div class="pla-card" style="border-top:3px solid ' . $c['color'] . '">'
                . '<div class="pla-card-label">' . $c['label'] . '</div>'
                . '<div class="pla-card-count" style="color:' . $c['color'] . '">' . $c['value'] . '</div>'
                . '<div class="pla-card-sub">' . $c['sub'] . '</div>'
                . '</div>';
        }
        $out .= '</div>';
        return $out;
    }

    /**
     * Render the Chart.js traffic trends chart.
     *
     * Uses time:hour dimension for single-day views, time:day for all others.
     */
    protected function renderMainChart() {
        $period    = $this->getPeriod();
        $dateRange = $period;
        $timeDim   = ($dateRange == 'day') ? 'time:hour' : 'time:day';

        $ts = $this->getApiData([
            'site_id'    => $this->site_id,
            'date_range' => $dateRange,
            'metrics'    => ['visitors', 'pageviews'],
            'dimensions' => [$timeDim],
        ], "ts_{$dateRange}");

        $labels = [];
        $vData  = [];
        $pData  = [];

        if (!empty($ts['results'])) {
            foreach ($ts['results'] as $entry) {
                $raw      = $entry['dimensions'][0] ?? '';
                $labels[] = ($timeDim == 'time:hour') ? date('H:i', strtotime($raw)) : date('d M', strtotime($raw));
                $vData[]  = $entry['metrics'][0] ?? 0;
                $pData[]  = $entry['metrics'][1] ?? 0;
            }
        }

        $jl = json_encode($labels);
        $jv = json_encode($vData);
        $jp = json_encode($pData);

        $out  = '<div class="pla-box">';
        $out .= '<h3>Traffic Trends</h3>';
        $out .= '<div style="height:280px"><canvas id="plaChart"></canvas></div>';
        $out .= '</div>';
        $out .= '<script>';
        $out .= '(function(){function init(){';
        $out .= 'var el=document.getElementById("plaChart");';
        $out .= 'if(!el||!window.Chart)return;';
        $out .= 'var ctx=el.getContext("2d");';
        $out .= 'var grad=ctx.createLinearGradient(0,0,0,280);';
        $out .= 'grad.addColorStop(0,"rgba(59,130,246,0.25)");';
        $out .= 'grad.addColorStop(1,"rgba(59,130,246,0.02)");';
        $out .= 'new Chart(ctx,{type:"line",data:{labels:' . $jl . ',datasets:[';
        $out .= '{label:"Visitors",data:'  . $jv . ',borderColor:"#3b82f6",backgroundColor:grad,fill:true,tension:0.4,borderWidth:2.5,pointRadius:0,pointHoverRadius:4},';
        $out .= '{label:"Pageviews",data:' . $jp . ',borderColor:"#f59e0b",backgroundColor:"transparent",fill:false,tension:0.4,borderDash:[5,4],borderWidth:2,pointRadius:0,pointHoverRadius:4}';
        $out .= ']},options:{responsive:true,maintainAspectRatio:false,';
        $out .= 'interaction:{intersect:false,mode:"index"},';
        $out .= 'plugins:{legend:{position:"bottom",labels:{font:{size:11},color:"#555",boxWidth:14,padding:20}}},';
        $out .= 'scales:{y:{beginAtZero:true,grid:{color:"#f0f0f0"},ticks:{color:"#aaa",font:{size:11}}},';
        $out .= 'x:{grid:{display:false},ticks:{color:"#aaa",font:{size:11},maxTicksLimit:10}}}}});';
        $out .= '}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",init);}else{init();}';
        $out .= '})();';
        $out .= '</script>';

        return $out;
    }

    /**
     * Render the top-pages section: horizontal bar chart + table with edit links.
     */
    protected function renderTopPages() {
        $period    = $this->getPeriod();
        $dateRange = $period;

        $data = $this->getApiData([
            'site_id'    => $this->site_id,
            'date_range' => $dateRange,
            'metrics'    => ['visitors'],
            'dimensions' => ['event:page'],
            'pagination' => ['limit' => 15],
        ], "pages_{$dateRange}");

        $adminUrl = $this->wire('config')->urls->admin;

        $chartLabels   = [];
        $chartValues   = [];
        $tableRows     = [];

        if (!empty($data['results'])) {
            foreach ($data['results'] as $row) {
                $rawPath       = $row['dimensions'][0] ?? '';
                $chartLabels[] = $rawPath;
                $chartValues[] = (int) ($row['metrics'][0] ?? 0);
                $tableRows[]   = $row;
            }
        }

        $jLabels = json_encode($chartLabels);
        $jValues = json_encode($chartValues);

        $out  = '<div class="pla-box">';
        $out .= '<h3>Top Pages</h3>';

        // Horizontal bar chart.
        if (!empty($chartLabels)) {
            $chartHeight = max(180, count($chartLabels) * 26);
            $out .= '<div style="height:' . $chartHeight . 'px;margin-bottom:20px"><canvas id="plaPageChart"></canvas></div>';
            $out .= '<script>';
            $out .= '(function(){function init(){';
            $out .= 'var el=document.getElementById("plaPageChart");';
            $out .= 'if(!el||!window.Chart)return;';
            $out .= 'new Chart(el,{type:"bar",data:{labels:' . $jLabels . ',';
            $out .= 'datasets:[{data:' . $jValues . ',backgroundColor:"rgba(59,130,246,0.75)",borderRadius:3,borderSkipped:false}]},';
            $out .= 'options:{indexAxis:"y",responsive:true,maintainAspectRatio:false,';
            $out .= 'plugins:{legend:{display:false},tooltip:{callbacks:{label:function(c){return" "+c.parsed.x+" visitors"}}}},';
            $out .= 'scales:{x:{beginAtZero:true,grid:{color:"#f0f0f0"},ticks:{color:"#aaa",font:{size:11}}},';
            $out .= 'y:{grid:{display:false},ticks:{color:"#444",font:{size:11}}}}}});';
            $out .= '}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",init);}else{init();}';
            $out .= '})();';
            $out .= '</script>';
        }

        // Table with edit links.
        $out .= '<table class="pla-tbl">';
        $out .= '<thead><tr>'
            . '<th>Path</th>'
            . '<th class="r">Visitors</th>'
            . '<th style="width:28px"></th>'
            . '</tr></thead><tbody>';

        if (!empty($tableRows)) {
            foreach ($tableRows as $row) {
                $rawPath  = $row['dimensions'][0] ?? '';
                $path     = htmlspecialchars($rawPath);
                $visitors = $row['metrics'][0] ?? 0;
                $p        = $this->wire('pages')->get('path=' . $rawPath);
                $pathCell = $p->id
                    ? '<a href="' . $p->url . '" target="_blank" class="pla-link" title="' . $path . '">' . $path . '</a>'
                    : '<span title="' . $path . '">' . $path . '</span>';
                $pencil = '<svg width="14" height="14" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14.7 2.3a1 1 0 0 1 1.4 0l1.6 1.6a1 1 0 0 1 0 1.4l-9.9 9.9-3.5.7.7-3.5 9.7-10z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                $edit = $p->id
                    ? '<a href="' . $adminUrl . 'page/edit/?id=' . $p->id . '" class="pla-pencil" title="Edit">' . $pencil . '</a>'
                    : '';
                $out .= '<tr>'
                    . '<td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . $pathCell . '</td>'
                    . '<td class="r"><strong>' . $visitors . '</strong></td>'
                    . '<td>' . $edit . '</td>'
                    . '</tr>';
            }
        } else {
            $out .= '<tr><td colspan="3" style="color:#aaa">No data.</td></tr>';
        }

        $out .= '</tbody></table></div>';
        return $out;
    }

    /** Render the traffic sources section: donut chart + table. */
    protected function renderTopSources() {
        $period    = $this->getPeriod();
        $dateRange = $period;

        $data = $this->getApiData([
            'site_id'    => $this->site_id,
            'date_range' => $dateRange,
            'metrics'    => ['visitors'],
            'dimensions' => ['visit:source'],
            'pagination' => ['limit' => 15],
        ], "sources_{$dateRange}");

        // Donut color palette.
        $palette = ['#3b82f6','#f59e0b','#10b981','#ef4444','#8b5cf6','#06b6d4','#f97316','#84cc16','#ec4899','#6366f1','#14b8a6','#a78bfa','#fb7185','#fbbf24','#34d399'];

        $chartLabels = [];
        $chartValues = [];
        $tableRows   = [];

        if (!empty($data['results'])) {
            foreach ($data['results'] as $row) {
                $chartLabels[] = $row['dimensions'][0] ?: 'Direct / None';
                $chartValues[] = (int) ($row['metrics'][0] ?? 0);
                $tableRows[]   = $row;
            }
        }

        $jLabels  = json_encode($chartLabels);
        $jValues  = json_encode($chartValues);
        $jColors  = json_encode(array_slice($palette, 0, count($chartLabels)));

        $out  = '<div class="pla-box">';
        $out .= '<h3>Sources</h3>';

        // Donut chart.
        if (!empty($chartLabels)) {
            $out .= '<div style="height:220px;display:flex;justify-content:center;margin-bottom:20px"><canvas id="plaSourceChart"></canvas></div>';
            $out .= '<script>';
            $out .= '(function(){function init(){';
            $out .= 'var el=document.getElementById("plaSourceChart");';
            $out .= 'if(!el||!window.Chart)return;';
            $out .= 'new Chart(el,{type:"doughnut",data:{labels:' . $jLabels . ',';
            $out .= 'datasets:[{data:' . $jValues . ',backgroundColor:' . $jColors . ',borderWidth:2,borderColor:"#fff",hoverOffset:6}]},';
            $out .= 'options:{responsive:true,maintainAspectRatio:false,cutout:"65%",';
            $out .= 'plugins:{legend:{position:"right",labels:{font:{size:11},color:"#555",boxWidth:12,padding:12}},';
            $out .= 'tooltip:{callbacks:{label:function(c){return" "+c.label+": "+c.parsed+" visitors"}}}}}});';
            $out .= '}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",init);}else{init();}';
            $out .= '})();';
            $out .= '</script>';
        }

        // Table.
        $out .= '<table class="pla-tbl"><thead><tr>'
            . '<th>Source</th>'
            . '<th class="r">Visitors</th>'
            . '</tr></thead><tbody>';

        if (!empty($tableRows)) {
            foreach ($tableRows as $i => $row) {
                $source   = htmlspecialchars($row['dimensions'][0] ?: 'Direct / None');
                $visitors = $row['metrics'][0] ?? 0;
                $dot      = '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' . ($palette[$i] ?? '#ccc') . ';margin-right:6px;vertical-align:middle"></span>';
                $out .= '<tr><td>' . $dot . $source . '</td><td class="r"><strong>' . $visitors . '</strong></td></tr>';
            }
        } else {
            $out .= '<tr><td colspan="2" style="color:#aaa">No data.</td></tr>';
        }

        $out .= '</tbody></table></div>';
        return $out;
    }

    /**
     * Render the Geography / Devices / Browsers tabbed section.
     * Each tab shows a horizontal bar chart followed by a data table.
     */
    protected function renderTabsSection() {
        $period    = $this->getPeriod();
        $dateRange = $period;
        $base      = ['site_id' => $this->site_id, 'date_range' => $dateRange, 'metrics' => ['visitors']];
        $limit     = ['limit' => 10];

        $geo = $this->getApiData($base + ['dimensions' => ['visit:country_name'], 'pagination' => $limit], "geo_{$dateRange}");
        $dev = $this->getApiData($base + ['dimensions' => ['visit:device'],       'pagination' => $limit], "dev_{$dateRange}");
        $brw = $this->getApiData($base + ['dimensions' => ['visit:browser'],      'pagination' => $limit], "brw_{$dateRange}");

        // Tab colors: geo=green, dev=orange, brw=blue.
        $tabColors = ['geo' => '#10b981', 'dev' => '#f59e0b', 'brw' => '#3b82f6'];

        // Build only enabled tabs.
        $tabs = [];
        if ($this->show_countries) $tabs['geo'] = ['label' => 'Geography', 'col' => 'Country', 'data' => $geo];
        if ($this->show_devices)   $tabs['dev'] = ['label' => 'Devices',   'col' => 'Device',  'data' => $dev];
        $tabs['brw'] = ['label' => 'Browsers', 'col' => 'Browser', 'data' => $brw];

        $firstKey = array_key_first($tabs);
        $tabId    = 'pla-tabs-' . substr(md5($dateRange), 0, 6);

        $out  = '<div class="pla-box">';

        // UIkit tab nav.
        $out .= '<ul class="uk-tab" uk-tab="connect:#' . $tabId . '">';
        foreach ($tabs as $key => $tab) {
            $out .= '<li><a href="#">' . $tab['label'] . '</a></li>';
        }
        $out .= '</ul>';

        $out .= '<ul id="' . $tabId . '" class="uk-switcher uk-margin-small-top">';

        foreach ($tabs as $key => $tab) {
            $color   = $tabColors[$key] ?? '#3b82f6';
            $results = $tab['data']['results'] ?? [];

            $barLabels = [];
            $barValues = [];
            foreach ($results as $r) {
                $barLabels[] = $r['dimensions'][0] ?? '—';
                $barValues[] = (int) ($r['metrics'][0] ?? 0);
            }

            $jl = json_encode($barLabels);
            $jv = json_encode($barValues);

            $out .= '<li>';

            // Horizontal bar chart.
            if (!empty($barLabels)) {
                $rgba        = 'rgba(' . implode(',', sscanf($color, '#%02x%02x%02x')) . ',0.75)';
                $chartHeight = max(150, count($barLabels) * 28);
                $out .= '<div style="height:' . $chartHeight . 'px;margin-bottom:16px"><canvas id="plaTabChart_' . $key . '"></canvas></div>';
                $out .= '<script>';
                $out .= '(function(){function init(){';
                $out .= 'var el=document.getElementById("plaTabChart_' . $key . '");';
                $out .= 'if(!el||!window.Chart)return;';
                $out .= 'new Chart(el,{type:"bar",data:{labels:' . $jl . ',';
                $out .= 'datasets:[{data:' . $jv . ',backgroundColor:"' . $rgba . '",borderRadius:3,borderSkipped:false}]},';
                $out .= 'options:{indexAxis:"y",responsive:true,maintainAspectRatio:false,';
                $out .= 'plugins:{legend:{display:false},tooltip:{callbacks:{label:function(c){return" "+c.parsed.x+" visitors"}}}},';
                $out .= 'scales:{x:{beginAtZero:true,grid:{color:"#f0f0f0"},ticks:{color:"#aaa",font:{size:11}}},';
                $out .= 'y:{grid:{display:false},ticks:{color:"#444",font:{size:11}}}}}});}';
                $out .= 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",init);}else{init();}';
                $out .= '})();';
                $out .= '</script>';
            }

            // Data table.
            $out .= '<table class="pla-tbl"><thead><tr>'
                . '<th>' . $tab['col'] . '</th>'
                . '<th class="r">Visitors</th>'
                . '</tr></thead><tbody>';

            if (!empty($results)) {
                foreach ($results as $r) {
                    $dim = htmlspecialchars($r['dimensions'][0] ?? '—');
                    $cnt = $r['metrics'][0] ?? 0;
                    $out .= '<tr><td>' . $dim . '</td><td class="r"><strong>' . $cnt . '</strong></td></tr>';
                }
            } else {
                $out .= '<tr><td colspan="2" style="color:#aaa">No data.</td></tr>';
            }

            $out .= '</tbody></table></li>';
        }

        $out .= '</ul></div>';
        return $out;
    }

    /** Render the debug log panel. Only shown when debug_mode is on and there are entries. */
    protected function renderDebugLog() {
        $out = '<div class="pla-box" style="font-family:monospace;font-size:11px"><strong>Debug API Logs</strong><br>';
        foreach ($this->debug_log as $entry) {
            $out .= '<span style="color:#aaa">[' . date('H:i:s') . ']</span> ' . htmlspecialchars($entry) . '<br>';
        }
        $out .= '</div>';
        return $out;
    }

    /** Render the footer with cache timestamp and a clear-cache submit button. */
    protected function renderFooter() {
        $timeStr  = $this->last_cache_time ? date('H:i:s, d.m.Y', $this->last_cache_time) : 'Live';
        $scheduleLabels = [
            'every30Minutes' => '30 min',
            'everyHour'      => '1 hour',
            'every2Hours'    => '2 hours',
            'every4Hours'    => '4 hours',
            'every12Hours'   => '12 hours',
            'everyDay'       => '1 day',
            'everyWeek'      => '1 week',
            'every4Weeks'    => '4 weeks',
        ];
        $schedule = $this->cache_schedule ?: 'everyHour';
        $cacheLabel = $scheduleLabels[$schedule] ?? $schedule;
        $out  = '<div id="pla-footer">';
        $out .= '<span>Last update: <strong>' . $timeStr . '</strong> &nbsp;&middot;&nbsp; Cache: ' . $cacheLabel . '</span>';
        $out .= '<form method="post" action="./">'
            . '<button type="submit" name="clear_plausible_cache" value="1">Clear Cache</button>'
            . '</form>';
        $out .= '</div>';
        return $out;
    }

    // -------------------------------------------------------------------------
    // API methods
    // -------------------------------------------------------------------------

    /**
     * Fetch the current realtime visitor count.
     *
     * Uses the v1 realtime endpoint — there is no v2 equivalent.
     * Result is cached for 60 seconds regardless of the cache_schedule setting.
     *
     * @return int
     */
    protected function getRealtimeVisitors() {
        $cacheName = 'plausible_live_' . md5($this->site_id);

        if (!$this->debug_mode) {
            $cached = $this->wire('cache')->get($cacheName);
            if ($cached !== null) return (int) $cached;
        }

        $url = rtrim($this->base_url ?: 'https://plausible.io', '/')
            . '/api/v1/stats/realtime/visitors?site_id=' . urlencode($this->site_id);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->api_key],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && is_numeric(trim($response))) {
            $count = (int) trim($response);
            $this->wire('cache')->save($cacheName, $count, 60);
            return $count;
        }

        return 0;
    }

    /**
     * POST a query to the Plausible Stats API v2 and return the decoded response.
     *
     * Results are cached in the ProcessWire WireCache for the configured LazyCron schedule interval.
     * Cache is bypassed entirely in debug mode.
     *
     * Metric compatibility notes for v2:
     *  - "24h" is not a valid date_range value; use "day" for the current day.
     *  - Event metrics (visitors, pageviews) and session metrics (bounce_rate,
     *    visit_duration, views_per_visit) cannot be requested together when an
     *    event-dimension filter (event:page) is present. Split into two calls.
     *
     * @param  array       $query          Full request body: site_id, date_range, metrics, filters, etc.
     * @param  string      $cacheKeySuffix Unique suffix appended to the MD5 cache key.
     * @return array|null  Decoded JSON response, or null on any failure.
     */
    protected function getApiData(array $query, $cacheKeySuffix) {
        $cacheName     = 'plausible_' . md5(serialize($query) . $cacheKeySuffix);
        // Map LazyCron schedule strings to seconds for WireCache TTL.
        $scheduleMap = [
            'every30Minutes' => 1800,
            'everyHour'      => 3600,
            'every2Hours'    => 7200,
            'every4Hours'    => 14400,
            'every12Hours'   => 43200,
            'everyDay'       => 86400,
            'everyWeek'      => 604800,
            'every4Weeks'    => 2419200,
        ];
        $schedule     = $this->cache_schedule ?: 'everyHour';
        $cacheSeconds = $scheduleMap[$schedule] ?? 3600;

        if (!$this->debug_mode) {
            $cached = $this->wire('cache')->get($cacheName);
            if ($cached) {
                return json_decode($cached, true);
            }
        }

        $url     = rtrim($this->base_url ?: 'https://plausible.io', '/') . '/api/v2/query';
        $payload = json_encode($query);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->api_key,
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload),
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($this->debug_mode) {
            $this->debug_log[] = 'POST /api/v2/query | Code: ' . $httpCode
                . ' | Cache: ' . $cacheSeconds . 's'
                . ' | Body: '  . json_encode($query);
            if ($curlErr) {
                $this->debug_log[] = 'cURL error: ' . $curlErr;
            }
            if ($httpCode !== 200 && $response) {
                $decoded = json_decode($response, true);
                $errMsg  = $decoded['error'] ?? ($decoded['message'] ?? substr($response, 0, 200));
                $this->debug_log[] = 'API error: ' . $errMsg;
            }
        }

        if ($httpCode === 200 && $response) {
            $this->wire('cache')->save($cacheName, $response, $cacheSeconds);
            $this->last_cache_time = time();
            return json_decode($response, true);
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Format a duration in seconds to a human-readable string.
     *
     * Examples: 45 → "45s", 125 → "2m 5s"
     *
     * @param  int    $seconds
     * @return string
     */
    protected function formatDuration($seconds) {
        $seconds = (int) $seconds;
        if ($seconds < 60) return $seconds . 's';
        return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
    }

    /**
     * Return the active reporting period.
     *
     * Reads from the GET parameter "period", falls back to the configured
     * default_period module setting, then to "30d".
     *
     * @return string  One of: day, 7d, 30d, 6mo, 12mo
     */
    protected function getPeriod() {
        return $this->wire('input')->get('period') ?: '30d';
    }

    /**
     * Delete all plausible_* entries from the ProcessWire WireCache table.
     */
    protected function clearModuleCache() {
        $this->wire('db')->query("DELETE FROM caches WHERE name LIKE 'plausible_%'");
        $this->message('Plausible Analytics cache cleared.');
    }

    // -------------------------------------------------------------------------
    // Module configuration
    // -------------------------------------------------------------------------

    /** Build the module settings inputfield form. */
    public function getModuleConfigInputfields(array $data) {
        $inputfields = new InputfieldWrapper();

        // --- API connection fieldset ---
        $api        = $this->wire('modules')->get('InputfieldFieldset');
        $api->label = 'API Connection';
        $api->icon  = 'plug';

        $f              = $this->wire('modules')->get('InputfieldText');
        $f->name        = 'api_key';
        $f->label       = 'API Key';
        $f->description = 'Stats API key from your Plausible account settings.';
        $f->value       = $data['api_key'] ?? '';
        $f->required    = true;
        $f->columnWidth = 60;
        $api->add($f);

        $f              = $this->wire('modules')->get('InputfieldText');
        $f->name        = 'site_id';
        $f->label       = 'Site ID (domain)';
        $f->description = 'The domain as registered in Plausible, e.g. example.com.';
        $f->value       = $data['site_id'] ?? '';
        $f->required    = true;
        $f->columnWidth = 40;
        $api->add($f);

        $f              = $this->wire('modules')->get('InputfieldText');
        $f->name        = 'base_url';
        $f->label       = 'API Base URL';
        $f->description = 'Override only if you self-host Plausible.';
        $f->value       = $data['base_url'] ?? 'https://plausible.io';
        $f->columnWidth = 60;
        $api->add($f);

        $f              = $this->wire('modules')->get('InputfieldSelect');
        $f->name        = 'cache_schedule';
        $f->label       = 'Cache Lifetime';
        $f->description = 'How long API responses are cached (LazyCron interval).';
        $f->columnWidth = 40;
        $scheduleOptions = [
            'every30Minutes' => 'Every 30 Minutes',
            'everyHour'      => 'Every Hour',
            'every2Hours'    => 'Every 2 Hours',
            'every4Hours'    => 'Every 4 Hours',
            'every12Hours'   => 'Every 12 Hours',
            'everyDay'       => 'Every Day',
            'everyWeek'      => 'Every Week',
            'every4Weeks'    => 'Every 4 Weeks',
        ];
        foreach ($scheduleOptions as $val => $lbl) $f->addOption($val, $lbl);
        $f->value = $data['cache_schedule'] ?? 'everyHour';
        $api->add($f);

        $inputfields->add($api);

        // --- Dashboard options fieldset ---
        $ui        = $this->wire('modules')->get('InputfieldFieldset');
        $ui->label = 'Dashboard Options';
        $ui->icon  = 'desktop';

        $f          = $this->wire('modules')->get('InputfieldCheckbox');
        $f->name    = 'show_on_page_edit';
        $f->label   = 'Show stats widget on page-edit screen';
        $f->checked = isset($data['show_on_page_edit']) ? (bool) $data['show_on_page_edit'] : true;
        $ui->add($f);

        $checkboxes = [
            'show_summary'       => 'Summary cards (Live, Visitors, Pageviews, Bounce Rate…)',
            'show_chart_traffic' => 'Traffic trends chart',
            'show_pages'         => 'Top pages table',
            'show_sources'       => 'Traffic sources table',
            'show_countries'     => 'Geography tab',
            'show_devices'       => 'Devices tab',
            'debug_mode'         => 'Debug mode (log API requests)',
        ];

        foreach ($checkboxes as $name => $label) {
            $f              = $this->wire('modules')->get('InputfieldCheckbox');
            $f->name        = $name;
            $f->label       = $label;
            $f->checked     = isset($data[$name]) ? (bool) $data[$name] : true;
            $f->columnWidth = 50;
            $ui->add($f);
        }

        $inputfields->add($ui);

        return $inputfields;
    }
}