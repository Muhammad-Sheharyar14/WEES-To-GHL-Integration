<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Sign In — WESS Integration</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
    <style>
        :root {
            --bg-page: #f8fafc;
            --bg-card: #ffffff;
            --border: #e2e8f0;
            --border-focus: #2563eb;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #64748b;
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --danger: #dc2626;
            --danger-bg: #fef2f2;
            --success: #059669;
            --success-bg: #ecfdf5;
            --shadow-card: 0 20px 40px -15px rgba(0, 0, 0, 0.07), 0 0 0 1px rgba(0, 0, 0, 0.04);
            --radius-md: 12px;
            --radius-lg: 16px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--bg-page);
            background-image: 
                radial-gradient(circle at 20% 15%, rgba(37, 99, 235, 0.06) 0%, transparent 45%),
                radial-gradient(circle at 80% 85%, rgba(225, 29, 72, 0.05) 0%, transparent 45%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-primary);
            padding: 24px 16px;
        }

        .login-card {
            width: 100%;
            max-width: 440px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 40px 36px;
            box-shadow: var(--shadow-card);
            position: relative;
        }

        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #2563eb, #dc2626);
            border-top-left-radius: var(--radius-lg);
            border-top-right-radius: var(--radius-lg);
        }

        .brand-header {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 24px;
            text-decoration: none;
        }

        .brand-logo {
            height: 42px;
            width: auto;
            object-fit: contain;
        }

        .brand-text {
            display: flex;
            flex-direction: column;
            text-align: left;
        }

        .brand-title {
            font-family: 'Outfit', sans-serif;
            font-size: 20px;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: -0.02em;
            line-height: 1.1;
        }

        .brand-badge {
            font-size: 10.5px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #dc2626;
        }

        .login-title {
            font-family: 'Outfit', sans-serif;
            font-size: 22px;
            font-weight: 700;
            text-align: center;
            color: var(--text-primary);
            margin-bottom: 6px;
            letter-spacing: -0.02em;
        }

        .login-subtitle {
            font-size: 13.5px;
            color: var(--text-secondary);
            text-align: center;
            margin-bottom: 28px;
        }

        .alert {
            padding: 12px 14px;
            border-radius: var(--radius-md);
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            line-height: 1.4;
        }

        .alert-danger {
            background-color: var(--danger-bg);
            border: 1px solid rgba(220, 38, 38, 0.2);
            color: var(--danger);
        }

        .alert-success {
            background-color: var(--success-bg);
            border: 1px solid rgba(5, 150, 105, 0.2);
            color: var(--success);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 14px;
            color: var(--text-muted);
            font-size: 18px;
            pointer-events: none;
        }

        .form-control {
            width: 100%;
            height: 44px;
            padding: 10px 14px 10px 42px;
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            font-size: 14px;
            color: var(--text-primary);
            transition: all 0.2s ease;
            outline: none;
        }

        .form-control:focus {
            border-color: var(--border-focus);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        .form-options {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            font-size: 13px;
        }

        .remember-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-secondary);
            cursor: pointer;
            user-select: none;
        }

        .remember-checkbox input {
            cursor: pointer;
            accent-color: var(--primary);
            width: 16px;
            height: 16px;
        }

        .btn-submit {
            width: 100%;
            height: 46px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: var(--primary);
            color: #ffffff;
            border: none;
            border-radius: var(--radius-md);
            font-size: 14.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
        }

        .btn-submit:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.3);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .login-footer {
            margin-top: 28px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
            text-align: center;
            font-size: 12.5px;
            color: var(--text-muted);
        }

        .login-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }

        .login-footer a:hover {
            text-decoration: underline;
        }


    </style>
</head>
<body>

    <div class="login-card">
        <!-- Brand Header with Logo -->
        <a href="{{ route('custom.page') }}" class="brand-header" style="text-decoration: none;">
            <div style="width: 42px; height: 42px; background: linear-gradient(135deg, #2563eb, #1e40af); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-weight: 800; font-size: 20px;">W</div>
            <div class="brand-text">
                <span class="brand-title">WESS</span>
                <span class="brand-badge">by Ample Life</span>
            </div>
        </a>

        <h1 class="login-title">Admin Sign In</h1>
        <p class="login-subtitle">WESS GoHighLevel Integration Administration</p>

        <!-- Status Message -->
        @if (session('status'))
            <div class="alert alert-success">
                <span class="material-icons-outlined" style="font-size: 18px;">check_circle</span>
                <span>{{ session('status') }}</span>
            </div>
        @endif

        <!-- Error Alert -->
        @if ($errors->any())
            <div class="alert alert-danger">
                <span class="material-icons-outlined" style="font-size: 18px;">error_outline</span>
                <span>{{ $errors->first() }}</span>
            </div>
        @endif

        <!-- Login Form -->
        <form method="POST" action="{{ route('login.post') }}">
            @csrf

            <div class="form-group">
                <label for="email" class="form-label">Email Address</label>
                <div class="input-wrapper">
                    <span class="material-icons-outlined input-icon">email</span>
                    <input type="email" id="email" name="email" class="form-control" value="{{ old('email') }}" required autofocus autocomplete="email" placeholder="admin@example.com">
                </div>
            </div>

            <div class="form-group">
                <label for="password" class="form-label">Password</label>
                <div class="input-wrapper">
                    <span class="material-icons-outlined input-icon">lock</span>
                    <input type="password" id="password" name="password" class="form-control" required autocomplete="current-password" placeholder="••••••••">
                </div>
            </div>

            <div class="form-options">
                <label class="remember-checkbox">
                    <input type="checkbox" name="remember" {{ old('remember') ? 'checked' : '' }}>
                    <span>Remember me</span>
                </label>
            </div>

            <button type="submit" class="btn-submit">
                <span>Sign In</span>
                <span class="material-icons-outlined" style="font-size: 18px;">arrow_forward</span>
            </button>
        </form>

        <div class="login-footer">
            <a href="{{ route('custom.page') }}">← Back to WESS Settings</a>
        </div>
    </div>

</body>
</html>
