<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ ($success ?? false) ? 'Connected Successfully — WESS Integration' : 'Authorization Failed — WESS Integration' }}</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
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
            max-width: 540px;
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
            background: #f1f5f9;
            border: 1px solid var(--border-color);
            border-radius: 30px;
            padding: 5px 16px;
            margin-bottom: 18px;
        }

        .badge-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: var(--success);
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

        .guide-box {
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 20px 22px;
            margin-bottom: 22px;
            text-align: left;
        }

        .guide-header {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12.5px;
            font-weight: 700;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 14px;
        }

        .guide-steps {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .guide-step {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 13.5px;
            line-height: 1.5;
            color: var(--text-main);
        }

        .step-num {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #eff6ff;
            color: var(--primary);
            font-size: 12px;
            font-weight: 800;
            flex-shrink: 0;
            border: 1px solid #bfdbfe;
            margin-top: 1px;
        }

        .step-content {
            flex: 1;
        }

        .step-content strong {
            color: var(--text-main);
            font-weight: 700;
        }

        .close-hint {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: 13px;
            color: var(--text-muted);
            background: #f1f5f9;
            padding: 7px 16px;
            border-radius: 30px;
            border: 1px solid var(--border-color);
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
                <span class="badge-dot"></span>
                <span style="font-weight: 700; color: var(--text-main); font-size: 13px;">WESS Integration</span>
            </div>

            <h1 class="title">Connected Successfully!</h1>

            <p class="subtitle">
                Your account is now connected and ready for WESS integration.
            </p>

            <div class="guide-box">
                <div class="guide-header">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                    <span>How to Complete Setup</span>
                </div>
                <div class="guide-steps">
                    <div class="guide-step">
                        <div class="step-num">1</div>
                        <div class="step-content">
                            Return to your <strong>CRM dashboard</strong>.
                        </div>
                    </div>
                    <div class="guide-step">
                        <div class="step-num">2</div>
                        <div class="step-content">
                            In the left-side menu, click <strong>WESS Settings</strong>.
                        </div>
                    </div>
                    <div class="guide-step">
                        <div class="step-num">3</div>
                        <div class="step-content">
                            Enter your WESS API credentials, choose your branch & booking calendar, and click <strong>Save & Connect</strong>.
                        </div>
                    </div>
                </div>
            </div>

            <div class="close-hint">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18"></path><path d="M6 6l12 12"></path></svg>
                <span>You can now safely close this window.</span>
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
                {{ $message ?? 'An error occurred while authorizing your account.' }}
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
