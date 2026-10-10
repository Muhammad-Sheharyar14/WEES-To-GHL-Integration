<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Integration Event Logs | WESS - GoHighLevel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --bg-page: #f8fafc;
            --card-bg: #ffffff;
            --border-color: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --radius-md: 8px;
            --radius-lg: 12px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--bg-page);
            color: var(--text-main);
            padding: 24px;
        }

        .container {
            max-width: 1140px;
            margin: 0 auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            gap: 16px;
            flex-wrap: wrap;
        }

        .title {
            font-size: 1.375rem;
            font-weight: 700;
        }

        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 18px;
        }

        .filters {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .filter-btn {
            padding: 6px 14px;
            border-radius: 9999px;
            font-size: 0.8125rem;
            text-decoration: none;
            color: var(--text-muted);
            background: #ffffff;
            border: 1px solid var(--border-color);
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
        }

        .filter-btn.active, .filter-btn:hover {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
        }

        .search-form {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .search-input {
            padding: 6px 12px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
            font-size: 0.8125rem;
            font-family: inherit;
            color: var(--text-main);
            background: #ffffff;
            width: 250px;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.15);
        }

        .search-btn {
            padding: 6px 14px;
            border-radius: var(--radius-md);
            background: var(--primary);
            color: #ffffff;
            border: none;
            font-size: 0.8125rem;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.15s;
        }

        .search-btn:hover {
            background: var(--primary-hover);
        }

        .search-clear {
            font-size: 0.8125rem;
            color: var(--text-muted);
            text-decoration: none;
            padding: 6px 4px;
        }

        .search-clear:hover {
            color: #dc2626;
        }

        .active-filter-banner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1e40af;
            padding: 8px 14px;
            border-radius: var(--radius-md);
            font-size: 0.8125rem;
            margin-bottom: 16px;
        }

        .card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .log-item {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            transition: background 0.1s;
        }

        .log-item:last-child {
            border-bottom: none;
        }

        .log-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            gap: 12px;
            flex-wrap: wrap;
        }

        .log-header-left {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .log-source {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            padding: 3px 8px;
            border-radius: 4px;
            letter-spacing: 0.5px;
        }

        .source-ghl_webhook { background: #dbeafe; color: #1e40af; }
        .source-wess_sync { background: #fef3c7; color: #92400e; }
        .source-ghl_subscription { background: #dbeafe; color: #1e40af; }

        .log-email-tag {
            display: inline-flex;
            align-items: center;
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
            padding: 2px 9px;
            border-radius: 9999px;
            font-size: 0.775rem;
            font-weight: 600;
        }

        .log-email-tag a {
            color: inherit;
            text-decoration: none;
        }

        .log-email-tag a:hover {
            text-decoration: underline;
        }

        .log-time {
            font-size: 0.775rem;
            color: var(--text-muted);
        }

        .log-event {
            font-weight: 600;
            font-size: 0.9375rem;
            margin-bottom: 6px;
        }

        .json-toggle {
            font-size: 0.8125rem;
            color: var(--primary);
            cursor: pointer;
            text-decoration: underline;
            margin-top: 6px;
            display: inline-block;
        }

        .json-content {
            display: none;
            background: #0f172a;
            color: #e2e8f0;
            padding: 12px;
            border-radius: var(--radius-md);
            margin-top: 8px;
            font-family: monospace;
            font-size: 0.775rem;
            overflow-x: auto;
            white-space: pre-wrap;
            word-break: break-all;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--text-muted);
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <div>
            <h1 class="title">Integration Event Logs</h1>
            <p style="font-size: 0.875rem; color: var(--text-muted); margin-top: 4px;">
                Inspect incoming GoHighLevel webhooks, WESS synchronization events, and appointment activity.
            </p>
        </div>
        <div>
            <a href="javascript:location.reload()" class="filter-btn" style="background:#f1f5f9; color: var(--text-main);">
                Refresh
            </a>
        </div>
    </div>

    <!-- Toolbar: Filters and Search -->
    <div class="toolbar">
        <div class="filters">
            <a href="{{ route('logs.index', array_filter(['email' => $email, 'location_id' => $locationId])) }}" class="filter-btn {{ empty($source) ? 'active' : '' }}">All Logs</a>
            <a href="{{ route('logs.index', array_filter(['source' => 'ghl_webhook', 'email' => $email, 'location_id' => $locationId])) }}" class="filter-btn {{ $source === 'ghl_webhook' ? 'active' : '' }}">GHL Webhooks</a>
            <a href="{{ route('logs.index', array_filter(['source' => 'wess_sync', 'email' => $email, 'location_id' => $locationId])) }}" class="filter-btn {{ $source === 'wess_sync' ? 'active' : '' }}">WESS Sync</a>
        </div>

        <!-- Search Form for Customer/Order Email & Keywords -->
        <form method="GET" action="{{ route('logs.index') }}" class="search-form">
            @if($source)
                <input type="hidden" name="source" value="{{ $source }}">
            @endif
            @if($locationId)
                <input type="hidden" name="location_id" value="{{ $locationId }}">
            @endif
            <input type="text" name="email" value="{{ $email ?? $search ?? '' }}" placeholder="Search customer/order email..." class="search-input">
            <button type="submit" class="search-btn">Search</button>
            @if(!empty($email) || !empty($search))
                <a href="{{ route('logs.index', array_filter(['source' => $source, 'location_id' => $locationId])) }}" class="search-clear" title="Clear search">Clear</a>
            @endif
        </form>
    </div>

    @if(!empty($email))
        <div class="active-filter-banner">
            <span>Filtering logs for email: <strong>{{ $email }}</strong></span>
            <a href="{{ route('logs.index', array_filter(['source' => $source, 'location_id' => $locationId])) }}" style="color:#1e40af; text-decoration:underline;">Remove filter</a>
        </div>
    @endif

    <div class="card">
        @if($logs->count() > 0)
            @foreach($logs as $log)
                <div class="log-item">
                    <div class="log-header">
                        <div class="log-header-left">
                            <span class="log-source source-{{ $log->source }}">{{ str_replace('_', ' ', $log->source) }}</span>
                            @if($log->email)
                                <span class="log-email-tag" title="Customer / Order Email">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block; vertical-align:-1px; margin-right:4px;"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                                    <a href="{{ route('logs.index', array_filter(['email' => $log->email, 'source' => $source])) }}">{{ $log->email }}</a>
                                </span>
                            @endif
                        </div>
                        <span class="log-time">{{ $log->created_at->format('Y-m-d H:i:s') }} ({{ $log->created_at->diffForHumans() }})</span>
                    </div>
                    <div class="log-event">
                        Event: {{ $log->event_type ?: 'Unknown' }}
                        @if($log->location_id)
                            <span style="font-size: 0.775rem; color: var(--text-muted); font-weight: normal; margin-left: 8px;">Location: <code>{{ $log->location_id }}</code></span>
                        @endif
                    </div>
                    @if($log->error_message)
                        <div style="color: #dc2626; font-size: 0.8125rem; font-weight: 500;">
                            Error: {{ $log->error_message }}
                        </div>
                    @endif
                    <div>
                        <span class="json-toggle" onclick="toggleJson('json-{{ $log->id }}')">View Raw Payload & Details</span>
                        <div id="json-{{ $log->id }}" class="json-content">{{ json_encode(['payload' => $log->payload, 'headers' => $log->headers, 'response' => $log->response_body], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</div>
                    </div>
                </div>
            @endforeach
        @else
            <div class="empty-state">
                @if(!empty($email))
                    No logs found matching email <strong>{{ $email }}</strong>.
                @else
                    No logs recorded yet. Once GoHighLevel or WESS events trigger, they will be logged here in real-time.
                @endif
            </div>
        @endif
    </div>

    <div style="margin-top: 18px;">
        {{ $logs->links() }}
    </div>
</div>

<script>
    function toggleJson(id) {
        const el = document.getElementById(id);
        if (el.style.display === 'block') {
            el.style.display = 'none';
        } else {
            el.style.display = 'block';
        }
    }
</script>

</body>
</html>
