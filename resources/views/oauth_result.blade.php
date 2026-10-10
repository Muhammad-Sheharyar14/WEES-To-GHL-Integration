<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ ($success ?? false) ? 'Authorization Successful — WESS Integration' : 'Authorization Failed — WESS Integration' }}</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --success: #10b981;
            --error: #ef4444;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --bg-page: #f8fafc;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--bg-page);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .result-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 20px;
            box-shadow: 0 16px 36px -10px rgba(15, 23, 42, 0.08), 0 0 0 1px rgba(226, 232, 240, 0.8);
            max-width: 580px;
            width: 100%;
            padding: 44px 36px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .stripe-success {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #10b981, #2563eb, #3b82f6);
        }

        .stripe-error {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: #dc2626;
        }

        .icon-circle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 76px;
            height: 76px;
            border-radius: 50%;
            margin-bottom: 20px;
        }

        .icon-success {
            background: #ecfdf5;
            border: 2px solid #a7f3d0;
            color: #059669;
            box-shadow: 0 8px 20px -4px rgba(16, 185, 129, 0.25);
        }

        .icon-error {
            background: #fef2f2;
            border: 2px solid #fecaca;
            color: #dc2626;
        }

        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 30px;
            padding: 5px 16px;
            margin-bottom: 16px;
        }

        .title {
            font-size: 24px;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: -0.02em;
            margin-bottom: 10px;
        }

        .subtitle {
            font-size: 14.5px;
            color: var(--text-muted);
            line-height: 1.6;
            margin-bottom: 24px;
        }

        .details-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 24px;
            text-align: left;
        }

        .detail-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 10px 14px;
            margin-top: 8px;
            font-size: 13.5px;
        }

        .detail-row code {
            font-family: monospace;
            font-size: 13px;
            font-weight: 600;
            color: #2563eb;
            background: #eff6ff;
            padding: 2px 8px;
            border-radius: 6px;
            border: 1px solid #bfdbfe;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-primary {
            background: var(--primary);
            color: #ffffff;
            border: 1px solid var(--primary);
        }

        .btn-primary:hover {
            background: var(--primary-hover);
        }

        .btn-secondary {
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #cbd5e1;
        }

        .btn-secondary:hover {
            background: #e2e8f0;
        }
    </style>
</head>
<body>

    <div class="result-card">
        @if($success ?? false)
            <div class="stripe-success"></div>

            <div class="icon-circle icon-success">
                <svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
            </div>

            <div class="brand-badge">
                <span style="font-weight: 800; color: #2563eb; font-size: 15px;">WESS</span>
                <span style="font-size: 12px; font-weight: 600; color: #64748b;">GHL Integration</span>
            </div>

            <h1 class="title">
                {{ ($isAgency ?? false) ? 'Agency Connected Successfully!' : 'Sub-Account Connected Successfully!' }}
            </h1>

            <p class="subtitle">
                {{ $message ?? 'WESS Integration is now authorized and connected to GoHighLevel.' }}
            </p>

            <div class="details-box">
                <div style="font-size: 11.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; justify-content: space-between;">
                    <span>Authorization Details</span>
                    <span style="background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; border-radius: 20px; padding: 2px 8px; font-size: 11px; font-weight: 600;">
                        ● Active & Stored
                    </span>
                </div>

                @if(!empty($companyId))
                    <div class="detail-row">
                        <span style="color: #475569; font-weight: 600;">Agency / Company ID</span>
                        <code>{{ $companyId }}</code>
                    </div>
                @endif

                @if(!empty($locationId))
                    <div class="detail-row">
                        <span style="color: #475569; font-weight: 600;">Sub-Account / Location ID</span>
                        <code>{{ $locationId }}</code>
                    </div>
                @endif
            </div>

            <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
                <a href="{{ route('custom.page') }}" class="btn btn-primary">
                    Open Custom Settings &rarr;
                </a>
            </div>

        @else
            <div class="stripe-error"></div>

            <div class="icon-circle icon-error">
                <svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="15" y1="9" x2="9" y2="15"></line>
                    <line x1="9" y1="9" x2="15" y2="15"></line>
                </svg>
            </div>

            <h1 class="title">Authorization Could Not Complete</h1>

            <p class="subtitle">
                {{ $message ?? 'An error occurred while authorizing the application with your GoHighLevel account.' }}
            </p>

            <div style="display: flex; gap: 12px; justify-content: center;">
                <a href="{{ route('ghl.connect') }}" class="btn btn-primary">
                    Try Again
                </a>
            </div>
        @endif
    </div>

</body>
</html>
