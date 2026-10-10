<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WESS Integration Settings</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --primary-light: #eff6ff;
            --primary-border: #bfdbfe;
            --success: #059669;
            --success-bg: #ecfdf5;
            --success-border: #a7f3d0;
            --warning: #d97706;
            --warning-bg: #fffbeb;
            --warning-border: #fde68a;
            --danger: #dc2626;
            --danger-bg: #fef2f2;
            --danger-border: #fecaca;
            --bg-page: #f8fafc;
            --card-bg: #ffffff;
            --border-color: #e2e8f0;
            --border-focus: #3b82f6;
            --text-main: #0f172a;
            --text-muted: #475569;
            --text-subtle: #64748b;
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;
            --shadow-sm: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.06), 0 2px 4px -2px rgba(0, 0, 0, 0.04);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.08), 0 4px 6px -4px rgba(0, 0, 0, 0.04);
            --shadow-toast: 0 16px 28px -4px rgba(0, 0, 0, 0.12), 0 6px 12px -4px rgba(0, 0, 0, 0.06);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        /* Prevent ugly scrollbars */
        html, body {
            height: 100%;
            background-color: var(--bg-page);
            color: var(--text-main);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            overflow-y: auto;
            scrollbar-width: none; /* Firefox */
            -ms-overflow-style: none; /* IE/Edge */
        }

        html::-webkit-scrollbar, body::-webkit-scrollbar {
            display: none;
            width: 0;
            height: 0;
        }

        body {
            padding: 16px 22px;
        }

        .container {
            max-width: 1120px;
            margin: 0 auto;
        }

        /* Floating Toast Alert */
        .toast-container {
            position: fixed;
            top: 16px;
            right: 22px;
            z-index: 9999;
            max-width: 440px;
            width: calc(100% - 44px);
            pointer-events: none;
        }

        .toast-alert {
            pointer-events: auto;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 13px 16px;
            border-radius: var(--radius-md);
            background: #ffffff;
            box-shadow: var(--shadow-toast);
            border: 1px solid var(--border-color);
            margin-bottom: 10px;
            transform: translateY(-20px);
            opacity: 0;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .toast-alert.show {
            transform: translateY(0);
            opacity: 1;
        }

        .toast-alert.success {
            border-left: 4px solid var(--success);
        }

        .toast-alert.error {
            border-left: 4px solid var(--danger);
        }

        .toast-alert.warning {
            border-left: 4px solid var(--warning);
        }

        .toast-icon {
            font-size: 18px;
            line-height: 1;
            margin-top: 1px;
        }

        .toast-content {
            flex: 1;
        }

        .toast-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 2px;
        }

        .toast-desc {
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.4;
        }

        .toast-close {
            background: transparent;
            border: none;
            color: var(--text-subtle);
            font-size: 18px;
            cursor: pointer;
            padding: 0 4px;
            line-height: 1;
        }

        /* Top Header */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--border-color);
            flex-wrap: wrap;
            gap: 12px;
        }

        .brand-area {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-logo-badge {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-weight: 800;
            font-size: 18px;
            box-shadow: 0 2px 5px rgba(37, 99, 235, 0.3);
        }

        .header-titles h1 {
            font-size: 19px;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: -0.01em;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .header-titles p {
            font-size: 13px;
            color: var(--text-subtle);
            margin-top: 2px;
        }

        /* Status Pills */
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 5px 12px;
            border-radius: 30px;
            font-size: 12.5px;
            font-weight: 600;
            border: 1px solid transparent;
        }

        .status-pill.connected {
            background-color: var(--success-bg);
            color: var(--success);
            border-color: var(--success-border);
        }

        .status-pill.disconnected {
            background-color: #f1f5f9;
            color: var(--text-subtle);
            border-color: var(--border-color);
        }

        .status-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background-color: currentColor;
        }

        /* Tab Navigation Bar */
        .tabs-nav-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 18px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 2px;
        }

        .tab-nav-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            border: 1px solid transparent;
            border-bottom: 2px solid transparent;
            background: transparent;
            color: var(--text-muted);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border-radius: var(--radius-md) var(--radius-md) 0 0;
            transition: all 0.15s ease;
        }

        .tab-nav-btn:hover {
            color: var(--primary);
            background-color: #f1f5f9;
        }

        .tab-nav-btn.active {
            color: var(--primary);
            background-color: #ffffff;
            border-color: var(--border-color) var(--border-color) transparent var(--border-color);
            border-bottom: 2px solid var(--primary);
            box-shadow: 0 -2px 6px rgba(0, 0, 0, 0.02);
        }

        .tab-badge {
            background-color: #e2e8f0;
            color: var(--text-main);
            font-size: 11.5px;
            font-weight: 700;
            padding: 1px 7px;
            border-radius: 12px;
        }

        .tab-nav-btn.active .tab-badge {
            background-color: var(--primary-light);
            color: var(--primary);
        }

        /* Master Sync Switch Banner */
        .master-switch-banner {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 16px 20px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: var(--shadow-sm);
            gap: 16px;
        }

        .switch-info {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .switch-icon {
            width: 40px;
            height: 40px;
            background: var(--primary-light);
            border: 1px solid var(--primary-border);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: var(--primary);
            flex-shrink: 0;
        }

        .switch-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-main);
        }

        .switch-desc {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 1px;
        }

        .toggle-wrapper {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .toggle-status-text {
            font-size: 13.5px;
            font-weight: 600;
            color: var(--success);
        }

        .toggle-status-text.paused {
            color: var(--warning);
        }

        .switch-label {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 26px;
            cursor: pointer;
        }

        .switch-label input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .switch-slider {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #cbd5e1;
            transition: .25s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 26px;
        }

        .switch-slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .25s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 50%;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
        }

        input:checked + .switch-slider {
            background-color: var(--primary);
        }

        input:checked + .switch-slider:before {
            transform: translateX(24px);
        }

        /* 2-Column Main Layout */
        .main-layout {
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            gap: 20px;
        }

        @media (max-width: 860px) {
            .main-layout {
                grid-template-columns: 1fr;
            }
        }

        /* Card System */
        .card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 22px 24px;
            box-shadow: var(--shadow-sm);
        }

        .card-header {
            margin-bottom: 18px;
            padding-bottom: 12px;
            border-bottom: 1px solid #f1f5f9;
        }

        .card-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-main);
        }

        .card-subtitle {
            font-size: 13px;
            color: var(--text-subtle);
            margin-top: 2px;
        }

        /* Form Controls */
        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 6px;
        }

        .label-badge {
            font-size: 11px;
            font-weight: 500;
            color: var(--text-subtle);
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 4px;
        }

        .input-group {
            position: relative;
            display: flex;
            align-items: center;
        }

        .form-control {
            width: 100%;
            height: 42px;
            padding: 9px 13px;
            font-size: 14px;
            font-family: inherit;
            color: var(--text-main);
            background-color: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            outline: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .form-control:focus {
            border-color: var(--border-focus);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .btn-toggle-pwd {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: #f1f5f9;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 4px 9px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
        }

        .form-hint {
            font-size: 12px;
            color: var(--text-subtle);
            margin-top: 5px;
            line-height: 1.4;
        }

        .form-hint code {
            font-family: 'JetBrains Mono', monospace;
            background: #f1f5f9;
            padding: 1px 5px;
            border-radius: 3px;
            color: #334155;
            font-size: 11.5px;
        }

        /* Buttons */
        .btn-actions {
            display: flex;
            gap: 12px;
            margin-top: 22px;
            padding-top: 14px;
            border-top: 1px solid #f1f5f9;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            height: 42px;
            padding: 0 20px;
            border-radius: var(--radius-md);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.15s ease;
            text-decoration: none;
        }

        .btn-primary {
            background-color: var(--primary);
            color: #ffffff;
            box-shadow: 0 1px 3px rgba(37, 99, 235, 0.2);
            flex: 1;
        }

        .btn-primary:hover:not(:disabled) {
            background-color: var(--primary-hover);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background-color: #ffffff;
            border-color: var(--border-color);
            color: var(--text-main);
            flex: 1;
        }

        .btn-secondary:hover:not(:disabled) {
            background-color: #f8fafc;
            border-color: #cbd5e1;
        }

        .btn-sm {
            height: 32px;
            padding: 0 12px;
            font-size: 13px;
            border-radius: var(--radius-sm);
        }

        /* Status Metrics List */
        .metric-list {
            display: flex;
            flex-direction: column;
        }

        .metric-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #f1f5f9;
            font-size: 14px;
        }

        .metric-item:first-child {
            padding-top: 0;
        }

        .metric-item:last-child {
            border-bottom: none;
        }

        .metric-key {
            color: var(--text-muted);
            font-weight: 500;
        }

        .metric-val {
            font-weight: 600;
            color: var(--text-main);
            text-align: right;
        }

        .mono-val {
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
            background: #f1f5f9;
            padding: 2px 8px;
            border-radius: 4px;
            border: 1px solid #e2e8f0;
            color: #1e293b;
        }

        .info-card {
            margin-top: 16px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: var(--radius-md);
            padding: 12px 14px;
            font-size: 12.5px;
            color: var(--text-muted);
            line-height: 1.5;
            display: flex;
            gap: 10px;
        }

        /* ========================================================
           Activity & Webhook Logs Tab Styles
        ======================================================== */
        .logs-controls {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .logs-search-wrapper {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
            min-width: 240px;
        }

        .logs-search-input {
            width: 100%;
            height: 38px;
            padding: 7px 12px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 13.5px;
            background-color: #ffffff;
            outline: none;
        }

        .logs-search-input:focus {
            border-color: var(--border-focus);
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.15);
        }

        .logs-filter-select {
            height: 38px;
            padding: 7px 10px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 13px;
            background-color: #ffffff;
            color: var(--text-main);
            outline: none;
            cursor: pointer;
        }

        /* Table */
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            scrollbar-width: none;
        }

        .table-responsive::-webkit-scrollbar {
            display: none;
        }

        .logs-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
            text-align: left;
            background-color: #ffffff;
        }

        .logs-table th {
            background-color: #f8fafc;
            color: var(--text-subtle);
            font-weight: 700;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 12px 14px;
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
        }

        .logs-table td {
            padding: 12px 14px;
            border-bottom: 1px solid #f1f5f9;
            color: var(--text-main);
            vertical-align: middle;
        }

        .logs-table tr:hover td {
            background-color: #f8fafc;
        }

        .logs-table tr:last-child td {
            border-bottom: none;
        }

        /* Table Badges */
        .badge-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .badge-status.success {
            background-color: var(--success-bg);
            color: var(--success);
            border: 1px solid var(--success-border);
        }

        .badge-status.error {
            background-color: var(--danger-bg);
            color: var(--danger);
            border: 1px solid var(--danger-border);
        }

        .badge-status.neutral {
            background-color: #f1f5f9;
            color: var(--text-subtle);
            border: 1px solid var(--border-color);
        }

        .badge-method {
            display: inline-block;
            font-family: 'JetBrains Mono', monospace;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 4px;
            text-transform: uppercase;
        }

        .badge-method.post { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
        .badge-method.get { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
        .badge-method.patch { background: #fefce8; color: #ca8a04; border: 1px solid #fef08a; }
        .badge-method.delete { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }

        .endpoint-code {
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            color: #334155;
            max-width: 320px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            display: inline-block;
            vertical-align: middle;
        }

        .btn-view-detail {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            color: var(--primary);
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
            white-space: nowrap;
        }

        .btn-view-detail:hover {
            background: var(--primary-light);
            border-color: var(--primary-border);
        }

        /* Pagination Bar */
        .pagination-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 16px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .pagination-info {
            font-size: 13px;
            color: var(--text-subtle);
        }

        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .page-btn {
            min-width: 34px;
            height: 34px;
            padding: 0 10px;
            border: 1px solid var(--border-color);
            background: #ffffff;
            color: var(--text-main);
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s ease;
        }

        .page-btn:hover:not(:disabled) {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-light);
        }

        .page-btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: #ffffff;
        }

        .page-btn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }

        /* Empty State */
        .logs-empty-state {
            padding: 42px 20px;
            text-align: center;
            color: var(--text-subtle);
        }

        .logs-empty-state svg {
            width: 48px;
            height: 48px;
            color: #94a3b8;
            margin-bottom: 12px;
        }

        .logs-empty-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 4px;
        }

        .logs-empty-desc {
            font-size: 13px;
            max-width: 440px;
            margin: 0 auto;
            line-height: 1.5;
        }

        /* ========================================================
           Detail Modal Popup Styles
        ======================================================== */
        .modal-backdrop {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(2px);
            z-index: 10000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-backdrop.show {
            display: flex;
        }

        .modal-card {
            background: #ffffff;
            border-radius: var(--radius-lg);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            border: 1px solid var(--border-color);
            width: 100%;
            max-width: 780px;
            max-height: 88vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: modalFadeIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.96) translateY(10px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }

        .modal-header {
            padding: 18px 22px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background-color: #ffffff;
        }

        .modal-title-area {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .modal-title {
            font-size: 17px;
            font-weight: 800;
            color: var(--text-main);
        }

        .modal-close-btn {
            background: transparent;
            border: none;
            font-size: 24px;
            color: var(--text-subtle);
            cursor: pointer;
            line-height: 1;
            padding: 4px 8px;
            border-radius: var(--radius-sm);
        }

        .modal-close-btn:hover {
            color: var(--text-main);
            background: #f1f5f9;
        }

        .modal-body {
            padding: 20px 22px;
            overflow-y: auto;
            scrollbar-width: none;
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .modal-body::-webkit-scrollbar {
            display: none;
        }

        .modal-meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 14px 16px;
        }

        .meta-field-label {
            font-size: 11.5px;
            font-weight: 700;
            color: var(--text-subtle);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
        }

        .meta-field-value {
            font-size: 13.5px;
            font-weight: 600;
            color: var(--text-main);
            word-break: break-all;
        }

        .code-box-wrapper {
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            overflow: hidden;
            background: #ffffff;
        }

        .code-box-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 14px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border-color);
            font-size: 12.5px;
            font-weight: 700;
            color: var(--text-muted);
        }

        .code-box-header button {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 3px 9px;
            font-size: 11.5px;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
        }

        .code-box-header button:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .code-block {
            margin: 0;
            padding: 14px;
            background: #fdfdfd;
            font-family: 'JetBrains Mono', monospace;
            font-size: 12.5px;
            line-height: 1.5;
            color: #0f172a;
            max-height: 240px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-break: break-all;
            scrollbar-width: none;
        }

        .code-block::-webkit-scrollbar {
            display: none;
        }

        .modal-footer {
            padding: 14px 22px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            background: #f8fafc;
        }

        /* Spinner */
        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: #ffffff;
            animation: spin 0.8s linear infinite;
        }

        .spinner.dark {
            border: 2px solid rgba(15, 23, 42, 0.2);
            border-top-color: var(--text-main);
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>

<!-- Floating Toast Container -->
<div class="toast-container" id="toastContainer"></div>

<div class="container">
    <!-- Top Header -->
    <header class="page-header">
        <div class="brand-area">
            <div class="brand-logo-badge">W</div>
            <div class="header-titles">
                <h1>WESS Integration <span style="font-size: 12.5px; font-weight: 600; color: var(--primary); background: var(--primary-light); padding: 2px 8px; border-radius: 6px; border: 1px solid var(--primary-border);">GHL Sync</span></h1>
                <p>Ample Life &bull; Salon & Spa 2-way sync for appointments, clients, and calendar availability</p>
            </div>
        </div>
        <div class="header-actions">
            <div id="verifyBadge" class="status-pill disconnected">
                <span class="status-dot"></span>
                <span id="verifyText">Checking Connection...</span>
            </div>
        </div>
    </header>

    <!-- Ajax-Based Tab Navigation Menu -->
    <div class="tabs-nav-bar">
        <button type="button" class="tab-nav-btn active" id="tabBtnCredentials" onclick="switchMainTab('credentials')">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"></path><circle cx="12" cy="12" r="3"></circle></svg>
            <span>WESS Credentials & Settings</span>
        </button>
        <button type="button" class="tab-nav-btn" id="tabBtnLogs" onclick="switchMainTab('logs')">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
            <span>Activity & Webhook Logs</span>
            <span class="tab-badge" id="logsCountBadge">0</span>
        </button>
    </div>

    <!-- ========================================================
         TAB 1: WESS CREDENTIALS & SETTINGS
    ======================================================== -->
    <div id="tabContentCredentials">
        <!-- Master Sync Kill-Switch Banner -->
        <div class="master-switch-banner">
            <div class="switch-info">
                <div class="switch-icon">⚡</div>
                <div>
                    <div class="switch-title">Location Master Sync Switch</div>
                    <div class="switch-desc">Instantly toggle all 2-way appointment and contact sync operations for this sub-account.</div>
                </div>
            </div>
            <div class="toggle-wrapper">
                <span class="toggle-status-text" id="toggleStatusLabel">Sync Active</span>
                <label class="switch-label">
                    <input type="checkbox" id="masterSyncToggle" checked onchange="handleMasterToggleChange(this.checked)">
                    <span class="switch-slider"></span>
                </label>
            </div>
        </div>

        <!-- 2-Column Main Layout -->
        <div class="main-layout">
            <!-- Left: API Credentials Form -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">WESS API Credentials</h2>
                    <p class="card-subtitle">Connect this sub-account to your WESS sandbox or production branch</p>
                </div>

                <form id="settingsForm">
                    <input type="hidden" name="locationId" id="locationId">
                    <input type="hidden" name="companyId" id="companyId">
                    <input type="hidden" name="userId" id="userId">

                    <div class="form-group">
                        <label class="form-label" for="base_url">
                            <span>WESS API Base URL</span>
                            <span class="label-badge">Required</span>
                        </label>
                        <div class="input-group">
                            <input type="url" class="form-control" id="base_url" name="base_url" value="{{ $defaultWessBaseUrl }}" required autocomplete="off">
                        </div>
                        <div class="form-hint">Sandbox: <code>https://api.prelive.wessconnect.net/api/v1/online</code></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="api_token">
                            <span>WESS Bearer Auth Token</span>
                            <span class="label-badge">Required</span>
                        </label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="api_token" name="api_token" placeholder="e.g. 5|blMkzRnxDNQBGv8rs4kp6I1XwCT3dNUwXGsAhGbC..." required autocomplete="off">
                            <button type="button" class="btn-toggle-pwd" id="toggleSecretBtn" onclick="toggleSecretVisibility()">Show</button>
                        </div>
                        <div class="form-hint">Generated under WESS Developer Portal / API Vendor Tokens.</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="branch_id">
                            <span>Associated WESS Branch</span>
                            <span class="label-badge" id="branchLoadingBadge">Select Branch</span>
                        </label>
                        <div class="input-group">
                            <select class="form-control" id="branch_id" name="branch_id">
                                <option value="1">Branch 1 (ID: 1)</option>
                                <option value="2">Branch 2 (ID: 2)</option>
                            </select>
                        </div>
                        <div class="form-hint">Bookings and customer records will be assigned to this branch.</div>
                    </div>

                    <div class="btn-actions">
                        <button type="button" id="testBtn" class="btn btn-secondary">
                            <span id="testBtnText">Test Connection</span>
                        </button>
                        <button type="submit" id="saveBtn" class="btn btn-primary">
                            <span id="saveBtnText">Save & Connect</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Right: Status Overview & Information -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Connection Status</h3>
                    <p class="card-subtitle">Live status across GoHighLevel & WESS</p>
                </div>

                <div class="metric-list">
                    <div class="metric-item">
                        <span class="metric-key">Sub-Account ID</span>
                        <span class="metric-val mono-val" id="dispLocationId">Detecting...</span>
                    </div>
                    <div class="metric-item">
                        <span class="metric-key">Agency / Company ID</span>
                        <span class="metric-val mono-val" id="dispCompanyId">-</span>
                    </div>
                    <div class="metric-item">
                        <span class="metric-key">GHL Authorization</span>
                        <span class="metric-val" id="dispGhlStatus" style="color: var(--text-muted);">-</span>
                    </div>
                    <div class="metric-item">
                        <span class="metric-key">WESS API Status</span>
                        <span class="metric-val" id="dispWessStatus" style="color: var(--text-muted);">-</span>
                    </div>
                    <div class="metric-item">
                        <span class="metric-key">Selected Branch</span>
                        <span class="metric-val" id="dispBranchName">-</span>
                    </div>
                    <div class="metric-item">
                        <span class="metric-key">2-Way Sync Engine</span>
                        <span class="metric-val" id="dispSyncEngine" style="color: var(--success);">Active</span>
                    </div>
                </div>

                <div class="info-card">
                    <div class="info-icon">ℹ️</div>
                    <div class="info-text">
                        <strong>Automatic OAuth Resolution:</strong> When an Agency token exists, this page automatically generates and maintains a secure sub-account token without extra logins.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================
         TAB 2: ACTIVITY & WEBHOOK LOGS
    ======================================================== -->
    <div id="tabContentLogs" style="display: none;">
        <div class="card">
            <div class="card-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                <div>
                    <h2 class="card-title">Activity & Webhook Logs</h2>
                    <p class="card-subtitle">Live records of GHL appointment/contact events and WESS synchronization requests</p>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" id="btnRefreshLogs" onclick="fetchLogs(currentLogsPage)">
                    <span id="refreshLogsIcon">↻</span>
                    <span>Refresh Logs</span>
                </button>
            </div>

            <!-- Controls: Search & Filter -->
            <div class="logs-controls">
                <div class="logs-search-wrapper">
                    <input type="text" class="logs-search-input" id="logsSearchInput" placeholder="Search by event, endpoint, keyword..." onkeyup="handleLogsSearch(event)">
                    <select class="logs-filter-select" id="logsStatusFilter" onchange="fetchLogs(1)">
                        <option value="">All Statuses</option>
                        <option value="success">Success (200 / 201)</option>
                        <option value="error">Errors & Failures</option>
                    </select>
                </div>
            </div>

            <!-- Logs Table Container -->
            <div class="table-responsive">
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th style="width: 110px;">Status</th>
                            <th>Event / Action</th>
                            <th>HTTP Method & Endpoint</th>
                            <th style="width: 170px;">Date & Time</th>
                            <th style="width: 110px; text-align: right;">Details</th>
                        </tr>
                    </thead>
                    <tbody id="logsTableBody">
                        <tr>
                            <td colspan="5" class="logs-empty-state">
                                <div class="spinner dark" style="margin-bottom: 10px;"></div>
                                <div class="logs-empty-title">Loading Activity Logs...</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Bar -->
            <div class="pagination-container" id="paginationContainer" style="display: none;">
                <div class="pagination-info" id="paginationInfo">Showing 0 to 0 of 0 entries</div>
                <div class="pagination-controls" id="paginationControls"></div>
            </div>
        </div>
    </div>
</div>

<!-- ========================================================
     DETAIL POPUP MODAL
======================================================== -->
<div class="modal-backdrop" id="logDetailBackdrop" onclick="handleBackdropClick(event)">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-title-area">
                <div class="modal-title" id="modalEventTitle">Event Details</div>
                <div id="modalStatusBadge" class="badge-status success">200 OK</div>
            </div>
            <button type="button" class="modal-close-btn" onclick="closeLogModal()">&times;</button>
        </div>

        <div class="modal-body">
            <!-- Metadata Grid -->
            <div class="modal-meta-grid">
                <div>
                    <div class="meta-field-label">HTTP Method</div>
                    <div class="meta-field-value" id="modalMethod">POST</div>
                </div>
                <div>
                    <div class="meta-field-label">Response Status</div>
                    <div class="meta-field-value" id="modalStatus">200 OK</div>
                </div>
                <div>
                    <div class="meta-field-label">Source</div>
                    <div class="meta-field-value" id="modalSource">ghl_webhook</div>
                </div>
                <div>
                    <div class="meta-field-label">Date & Time</div>
                    <div class="meta-field-value" id="modalDate">-</div>
                </div>
            </div>

            <!-- Endpoint -->
            <div>
                <div class="meta-field-label" style="margin-bottom: 5px;">API Endpoint Used</div>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <input type="text" readonly id="modalEndpoint" class="form-control" style="font-family: 'JetBrains Mono', monospace; font-size: 12.5px; background: #f8fafc;">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="copyModalField('modalEndpoint')">Copy</button>
                </div>
            </div>

            <!-- Error Banner (if any) -->
            <div id="modalErrorWrapper" style="display: none; background: #fef2f2; border: 1px solid #fecaca; border-radius: var(--radius-md); padding: 12px 14px; color: #dc2626; font-size: 13px;">
                <div style="font-weight: 700; margin-bottom: 2px;">Error Diagnostic:</div>
                <div id="modalErrorMessage" style="font-family: 'JetBrains Mono', monospace; font-size: 12px;"></div>
            </div>

            <!-- Payload Box -->
            <div class="code-box-wrapper">
                <div class="code-box-header">
                    <span>Sent Payload / Webhook Data</span>
                    <button type="button" onclick="copyModalText('modalPayload')">Copy JSON</button>
                </div>
                <pre class="code-block" id="modalPayload">{}</pre>
            </div>

            <!-- Response Box -->
            <div class="code-box-wrapper">
                <div class="code-box-header">
                    <span>Response Received / Output Body</span>
                    <button type="button" onclick="copyModalText('modalResponse')">Copy Response</button>
                </div>
                <pre class="code-block" id="modalResponse">{}</pre>
            </div>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" onclick="closeLogModal()">Close</button>
        </div>
    </div>
</div>

<!-- CryptoJS for SSO / REQUEST_USER_DATA decryption -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/crypto-js/4.1.1/crypto-js.min.js"></script>

<script>
    const sharedSecretKey = @json($sharedSecretKey);
    const csrfToken       = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    // Global logs state
    let currentLogsPage = 1;
    let logsSearchTimer = null;
    let activeLogsCache = [];

    /* ========================================================
       Tab Switching Logic
    ======================================================== */
    function switchMainTab(tabName) {
        const btnCreds = document.getElementById('tabBtnCredentials');
        const btnLogs  = document.getElementById('tabBtnLogs');
        const contentCreds = document.getElementById('tabContentCredentials');
        const contentLogs  = document.getElementById('tabContentLogs');

        if (tabName === 'credentials') {
            btnCreds.classList.add('active');
            btnLogs.classList.remove('active');
            contentCreds.style.display = 'block';
            contentLogs.style.display  = 'none';
        } else {
            btnLogs.classList.add('active');
            btnCreds.classList.remove('active');
            contentLogs.style.display  = 'block';
            contentCreds.style.display = 'none';

            // Refresh logs on tab visit
            fetchLogs(currentLogsPage);
        }
    }

    /* ========================================================
       Credentials & UI Helpers
    ======================================================== */
    function toggleSecretVisibility() {
        const input = document.getElementById('api_token');
        const btn   = document.getElementById('toggleSecretBtn');
        if (input.type === 'password') {
            input.type = 'text';
            btn.textContent = 'Hide';
        } else {
            input.type = 'password';
            btn.textContent = 'Show';
        }
    }

    function updateBadge(isVerified, customText = null) {
        const badge = document.getElementById('verifyBadge');
        const text  = document.getElementById('verifyText');
        if (isVerified) {
            badge.className = 'status-pill connected';
            text.textContent = customText || 'WESS Connected';
        } else {
            badge.className = 'status-pill disconnected';
            text.textContent = customText || 'Not Configured';
        }
    }

    function updateMasterToggleUI(isEnabled) {
        const toggle = document.getElementById('masterSyncToggle');
        const label  = document.getElementById('toggleStatusLabel');
        const dispEngine = document.getElementById('dispSyncEngine');

        toggle.checked = isEnabled;
        if (isEnabled) {
            label.textContent = 'Sync Active';
            label.className = 'toggle-status-text';
            dispEngine.textContent = 'Active';
            dispEngine.style.color = 'var(--success)';
        } else {
            label.textContent = 'Sync Paused';
            label.className = 'toggle-status-text paused';
            dispEngine.textContent = 'Paused (Disabled)';
            dispEngine.style.color = 'var(--warning)';
        }
    }

    function notify(type, title, message) {
        const toastContainer = document.getElementById('toastContainer');
        toastContainer.innerHTML = '';

        const toast = document.createElement('div');
        toast.className = `toast-alert ${type}`;
        toast.innerHTML = `
            <div class="toast-icon">${type === 'success' ? '✓' : (type === 'warning' ? '⚠' : '✕')}</div>
            <div class="toast-content">
                <div class="toast-title">${title}</div>
                <div class="toast-desc">${message}</div>
            </div>
            <button type="button" class="toast-close" onclick="this.parentElement.remove()">&times;</button>
        `;
        toastContainer.appendChild(toast);

        setTimeout(() => toast.classList.add('show'), 10);

        setTimeout(() => {
            if (toast && toast.parentElement) {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 280);
            }
        }, 6000);
    }

    function populateBranches(branches, selectedBranchId = null) {
        const select = document.getElementById('branch_id');
        if (!branches || branches.length === 0) return;

        select.innerHTML = '';
        branches.forEach(b => {
            const opt = document.createElement('option');
            opt.value = b.id;
            opt.textContent = `${b.name || 'Branch ' + b.id} (ID: ${b.id})`;
            if (selectedBranchId && String(b.id) === String(selectedBranchId)) {
                opt.selected = true;
            }
            select.appendChild(opt);
        });

        const selectedOption = select.options[select.selectedIndex];
        if (selectedOption) {
            document.getElementById('dispBranchName').textContent = selectedOption.textContent;
        }
    }

    /* ========================================================
       User Data Decryption & Resolution
    ======================================================== */
    async function getUserData() {
        try {
            const urlParams = new URLSearchParams(window.location.search);
            const queryLocationId = urlParams.get('location_id') || urlParams.get('locationId') || urlParams.get('location');
            const queryCompanyId  = urlParams.get('company_id') || urlParams.get('companyId');

            if (queryCompanyId) {
                document.getElementById('companyId').value = queryCompanyId;
                document.getElementById('dispCompanyId').textContent = queryCompanyId;
            }

            if (queryLocationId) {
                document.getElementById('locationId').value = queryLocationId;
                document.getElementById('dispLocationId').textContent = queryLocationId;
                fetchExistingCredentials(queryLocationId, queryCompanyId);
                fetchLogs(1);
            }

            if (window.parent !== window) {
                const encryptedUserData = await new Promise((resolve) => {
                    const timer = setTimeout(() => {
                        window.removeEventListener('message', messageHandler);
                        resolve(null);
                    }, 1800);

                    const messageHandler = ({ data }) => {
                        if (data && data.message === 'REQUEST_USER_DATA_RESPONSE') {
                            clearTimeout(timer);
                            window.removeEventListener('message', messageHandler);
                            resolve(data.payload);
                        }
                    };

                    window.addEventListener('message', messageHandler);
                    window.parent.postMessage({ message: 'REQUEST_USER_DATA' }, '*');
                });

                if (encryptedUserData && sharedSecretKey) {
                    const decryptedBytes = CryptoJS.AES.decrypt(encryptedUserData, sharedSecretKey);
                    const decryptedData  = decryptedBytes.toString(CryptoJS.enc.Utf8);
                    if (decryptedData) {
                        const userData = JSON.parse(decryptedData);
                        populateUserData(userData);
                    }
                }
            } else if (!queryLocationId) {
                document.getElementById('dispLocationId').textContent = 'Direct Preview (No GHL Context)';
                updateBadge(false, 'Open Inside GHL');
            }
        } catch (error) {
            console.error('User Data Decryption Error:', error);
        }
    }

    function populateUserData(userData) {
        const locationId = userData.activeLocation || userData.locationId;
        const companyId  = userData.companyId;

        if (locationId) {
            document.getElementById('locationId').value = locationId;
            document.getElementById('dispLocationId').textContent = locationId;
        }
        if (companyId) {
            document.getElementById('companyId').value = companyId;
            document.getElementById('dispCompanyId').textContent = companyId;
        }
        if (userData.userId) {
            document.getElementById('userId').value = userData.userId;
        }

        if (locationId) {
            fetchExistingCredentials(locationId, companyId);
            fetchLogs(1);
        }
    }

    function fetchExistingCredentials(locationId, companyId) {
        fetch("{{ route('api.custom-page.get') }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ 
                locationId: locationId,
                companyId: companyId 
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const dispGhl = document.getElementById('dispGhlStatus');
                if (data.ghl_connected) {
                    dispGhl.textContent = 'Active & Authorized';
                    dispGhl.style.color = 'var(--success)';
                } else {
                    dispGhl.textContent = 'Pending Authorization';
                    dispGhl.style.color = 'var(--warning)';
                }

                updateMasterToggleUI(data.is_sync_enabled !== false);

                if (data.credentials) {
                    const creds = data.credentials;
                    if (creds.api_token) document.getElementById('api_token').value = creds.api_token;
                    if (creds.base_url) document.getElementById('base_url').value = creds.base_url;

                    if (data.branches && data.branches.length > 0) {
                        populateBranches(data.branches, creds.branch_id);
                    } else if (creds.branch_id) {
                        document.getElementById('branch_id').value = creds.branch_id;
                        document.getElementById('dispBranchName').textContent = creds.branch_name || `Branch ${creds.branch_id}`;
                    }

                    const dispWess = document.getElementById('dispWessStatus');
                    dispWess.textContent = creds.is_connected ? 'Verified & Connected' : 'Not Connected';
                    dispWess.style.color = creds.is_connected ? 'var(--success)' : 'var(--text-muted)';

                    updateBadge(creds.is_connected);
                } else {
                    updateBadge(false);
                }
            } else {
                updateBadge(false);
            }
        })
        .catch(err => {
            console.error('Error loading saved credentials:', err);
            updateBadge(false);
        });
    }

    function handleMasterToggleChange(isEnabled) {
        const locationId = document.getElementById('locationId').value;
        if (!locationId) {
            notify('warning', 'Location Undetected', 'Open this page inside GoHighLevel sub-account to toggle sync.');
            updateMasterToggleUI(!isEnabled);
            return;
        }

        fetch("{{ route('api.custom-page.toggle-sync') }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                locationId: locationId,
                is_sync_enabled: isEnabled
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                updateMasterToggleUI(data.is_sync_enabled);
                notify('success', 'Master Switch Updated', data.message);
            } else {
                updateMasterToggleUI(!isEnabled);
                notify('error', 'Update Failed', data.message);
            }
        })
        .catch(err => {
            updateMasterToggleUI(!isEnabled);
            notify('error', 'Network Error', 'Failed to reach server. Please try again.');
        });
    }

    /* ========================================================
       Activity & Webhook Logs Logic (Ajax-Driven with Pagination)
    ======================================================== */
    function handleLogsSearch(event) {
        clearTimeout(logsSearchTimer);
        logsSearchTimer = setTimeout(() => {
            fetchLogs(1);
        }, 350);
    }

    function fetchLogs(page = 1) {
        currentLogsPage = page;
        const locationId = document.getElementById('locationId').value;
        const search     = document.getElementById('logsSearchInput').value;
        const status     = document.getElementById('logsStatusFilter').value;
        const tbody      = document.getElementById('logsTableBody');
        const refreshIcon = document.getElementById('refreshLogsIcon');

        if (refreshIcon) refreshIcon.style.display = 'inline-block';

        if (!locationId) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="5" class="logs-empty-state">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                        <div class="logs-empty-title">Location Context Required</div>
                        <div class="logs-empty-desc">Open this page inside a GoHighLevel sub-account to view live activity and webhook logs.</div>
                    </td>
                </tr>
            `;
            return;
        }

        fetch("{{ route('api.custom-page.logs') }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                locationId: locationId,
                page: page,
                search: search,
                status: status
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.logs) {
                const logs = data.logs;
                activeLogsCache = logs.data || [];

                document.getElementById('logsCountBadge').textContent = logs.total || 0;

                renderLogsTable(activeLogsCache);
                renderPagination(logs);
            } else {
                tbody.innerHTML = `<tr><td colspan="5" class="logs-empty-state"><div class="logs-empty-title">No Logs Found</div></td></tr>`;
            }
        })
        .catch(err => {
            console.error('Logs fetch error:', err);
            tbody.innerHTML = `<tr><td colspan="5" class="logs-empty-state" style="color: var(--danger);"><div class="logs-empty-title">Error Loading Logs</div><div class="logs-empty-desc">Failed to fetch logs. Please try refreshing.</div></td></tr>`;
        });
    }

    function renderLogsTable(items) {
        const tbody = document.getElementById('logsTableBody');
        if (!items || items.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="5" class="logs-empty-state">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                        <div class="logs-empty-title">No Activity Logs Yet</div>
                        <div class="logs-empty-desc">Incoming GoHighLevel webhooks (appointments, contacts, uninstalls) and WESS sync operations will appear here in real-time.</div>
                    </td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = items.map((item, index) => {
            const statusClass = item.is_success ? 'success' : (item.response_status ? 'error' : 'neutral');
            const statusLabel = item.response_status ? `${item.response_status}` : 'Pending';
            const methodClass = (item.method || 'POST').toLowerCase();

            return `
                <tr>
                    <td>
                        <span class="badge-status ${statusClass}">
                            ● ${statusLabel}
                        </span>
                    </td>
                    <td>
                        <strong style="color: var(--text-main);">${escapeHtml(item.event_type)}</strong>
                        <div style="font-size: 11.5px; color: var(--text-subtle);">${escapeHtml(item.source)}</div>
                    </td>
                    <td>
                        <span class="badge-method ${methodClass}">${escapeHtml(item.method)}</span>
                        <span class="endpoint-code" title="${escapeHtml(item.endpoint)}">${escapeHtml(item.endpoint)}</span>
                    </td>
                    <td>
                        <div style="font-weight: 600; color: #334155; font-size: 12.5px;">${escapeHtml(item.created_at)}</div>
                        <div style="font-size: 11.5px; color: var(--text-subtle);">${escapeHtml(item.created_at_human)}</div>
                    </td>
                    <td style="text-align: right;">
                        <button type="button" class="btn-view-detail" onclick="openLogModal(${index})">
                            <span>View</span> &rarr;
                        </button>
                    </td>
                </tr>
            `;
        }).join('');
    }

    function renderPagination(meta) {
        const container = document.getElementById('paginationContainer');
        const info      = document.getElementById('paginationInfo');
        const controls  = document.getElementById('paginationControls');

        if (!meta || meta.total === 0) {
            container.style.display = 'none';
            return;
        }

        container.style.display = 'flex';
        info.textContent = `Showing ${meta.from || 0} to ${meta.to || 0} of ${meta.total} entries`;

        let html = '';

        // Prev Button
        html += `<button type="button" class="page-btn" ${meta.current_page <= 1 ? 'disabled' : ''} onclick="fetchLogs(${meta.current_page - 1})">&lsaquo;</button>`;

        // Page buttons (window of 5 around current)
        const totalPages = meta.last_page;
        const current    = meta.current_page;
        let startPage    = Math.max(1, current - 2);
        let endPage      = Math.min(totalPages, current + 2);

        if (startPage > 1) {
            html += `<button type="button" class="page-btn" onclick="fetchLogs(1)">1</button>`;
            if (startPage > 2) html += `<span style="padding: 0 4px; color: #94a3b8;">...</span>`;
        }

        for (let p = startPage; p <= endPage; p++) {
            html += `<button type="button" class="page-btn ${p === current ? 'active' : ''}" onclick="fetchLogs(${p})">${p}</button>`;
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) html += `<span style="padding: 0 4px; color: #94a3b8;">...</span>`;
            html += `<button type="button" class="page-btn" onclick="fetchLogs(${totalPages})">${totalPages}</button>`;
        }

        // Next Button
        html += `<button type="button" class="page-btn" ${meta.current_page >= totalPages ? 'disabled' : ''} onclick="fetchLogs(${meta.current_page + 1})">&rsaquo;</button>`;

        controls.innerHTML = html;
    }

    /* ========================================================
       Detail Modal Popup Functions
    ======================================================== */
    function openLogModal(index) {
        const item = activeLogsCache[index];
        if (!item) return;

        document.getElementById('modalEventTitle').textContent = item.event_type || 'Event Details';

        const statusBadge = document.getElementById('modalStatusBadge');
        statusBadge.className = `badge-status ${item.is_success ? 'success' : 'error'}`;
        statusBadge.textContent = item.response_status ? `Status: ${item.response_status}` : 'Pending';

        document.getElementById('modalMethod').textContent = item.method || 'POST';
        document.getElementById('modalStatus').textContent = item.response_status ? `${item.response_status}` : '—';
        document.getElementById('modalSource').textContent = item.source || '—';
        document.getElementById('modalDate').textContent   = item.created_at || '—';
        document.getElementById('modalEndpoint').value     = item.endpoint || '—';

        // Error message handling
        const errWrapper = document.getElementById('modalErrorWrapper');
        const errMsg     = document.getElementById('modalErrorMessage');
        if (item.error_message) {
            errWrapper.style.display = 'block';
            errMsg.textContent = item.error_message;
        } else {
            errWrapper.style.display = 'none';
        }

        // Pretty JSON Payload
        const payloadBox = document.getElementById('modalPayload');
        try {
            const parsed = typeof item.payload === 'string' ? JSON.parse(item.payload) : item.payload;
            payloadBox.textContent = JSON.stringify(parsed || {}, null, 2);
        } catch(e) {
            payloadBox.textContent = String(item.payload || '{}');
        }

        // Pretty JSON Response
        const responseBox = document.getElementById('modalResponse');
        try {
            const parsed = typeof item.response_body === 'string' ? JSON.parse(item.response_body) : item.response_body;
            responseBox.textContent = JSON.stringify(parsed || {}, null, 2);
        } catch(e) {
            responseBox.textContent = String(item.response_body || '{}');
        }

        document.getElementById('logDetailBackdrop').classList.add('show');
    }

    function closeLogModal() {
        document.getElementById('logDetailBackdrop').classList.remove('show');
    }

    function handleBackdropClick(event) {
        if (event.target && event.target.id === 'logDetailBackdrop') {
            closeLogModal();
        }
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeLogModal();
    });

    function copyModalField(elementId) {
        const input = document.getElementById(elementId);
        input.select();
        navigator.clipboard.writeText(input.value).then(() => {
            notify('success', 'Copied to Clipboard', 'Endpoint copied successfully.');
        });
    }

    function copyModalText(elementId) {
        const text = document.getElementById(elementId).textContent;
        navigator.clipboard.writeText(text).then(() => {
            notify('success', 'Copied to Clipboard', 'JSON content copied.');
        });
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /* ========================================================
       Form Submit & Live Test Actions
    ======================================================== */
    document.getElementById('testBtn').addEventListener('click', function() {
        const baseUrl = document.getElementById('base_url').value;
        const token   = document.getElementById('api_token').value;
        const btn     = document.getElementById('testBtn');
        const btnText = document.getElementById('testBtnText');

        if (!token) {
            notify('warning', 'Missing Token', 'Please enter your WESS Bearer Auth Token.');
            return;
        }

        btn.disabled = true;
        btnText.innerHTML = '<span class="spinner dark"></span> Testing...';

        fetch("{{ route('api.custom-page.test') }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ base_url: baseUrl, api_token: token })
        })
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            btnText.textContent = 'Test Connection';

            if (data.success) {
                notify('success', 'Connection Verified!', data.message);
                updateBadge(true);
                if (data.branches && data.branches.length > 0) {
                    populateBranches(data.branches);
                }
            } else {
                notify('error', 'Connection Failed', data.message);
                updateBadge(false);
            }
        })
        .catch(err => {
            btn.disabled = false;
            btnText.textContent = 'Test Connection';
            notify('error', 'Network Error', 'Could not reach server endpoint.');
        });
    });

    document.getElementById('settingsForm').addEventListener('submit', function(e) {
        e.preventDefault();

        const btn     = document.getElementById('saveBtn');
        const btnText = document.getElementById('saveBtnText');
        const locationId = document.getElementById('locationId').value;
        const companyId  = document.getElementById('companyId').value;
        const baseUrl    = document.getElementById('base_url').value;
        const token      = document.getElementById('api_token').value;
        const branchSelect = document.getElementById('branch_id');
        const branchId   = branchSelect.value;
        const branchName = branchSelect.options[branchSelect.selectedIndex]?.text || '';
        const syncEnabled= document.getElementById('masterSyncToggle').checked;

        if (!token) {
            notify('warning', 'Token Required', 'Please provide a valid WESS API token.');
            return;
        }

        btn.disabled = true;
        btnText.innerHTML = '<span class="spinner"></span> Connecting...';

        fetch("{{ route('api.custom-page.save') }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                locationId: locationId,
                companyId: companyId,
                base_url: baseUrl,
                api_token: token,
                branch_id: branchId,
                branch_name: branchName,
                is_sync_enabled: syncEnabled
            })
        })
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            btnText.textContent = 'Save & Connect';

            if (data.success) {
                notify('success', 'Configuration Saved', data.message);
                updateBadge(true);
                document.getElementById('dispWessStatus').textContent = 'Verified & Connected';
                document.getElementById('dispWessStatus').style.color = 'var(--success)';
                if (data.branches) populateBranches(data.branches, branchId);
            } else {
                notify('error', 'Save Failed', data.message);
                updateBadge(false);
            }
        })
        .catch(err => {
            btn.disabled = false;
            btnText.textContent = 'Save & Connect';
            notify('error', 'Network Error', 'Failed to save configuration.');
        });
    });

    // Run on startup
    window.addEventListener('DOMContentLoaded', getUserData);
</script>

</body>
</html>
