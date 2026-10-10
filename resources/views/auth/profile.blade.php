<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile & Settings — WESS Horizon</title>
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
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.05);
            --shadow-card: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 0 0 1px rgba(0, 0, 0, 0.04);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--bg-page);
            background-image: radial-gradient(circle at 15% 10%, rgba(37, 99, 235, 0.04) 0%, transparent 40%),
                              radial-gradient(circle at 85% 90%, rgba(220, 38, 38, 0.03) 0%, transparent 45%);
            min-height: 100vh;
            color: var(--text-primary);
            display: flex;
            flex-direction: column;
        }

        /* Top Navbar */
        .top-navbar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            padding: 14px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 40;
        }

        .nav-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: var(--text-primary);
        }

        .brand-logo {
            height: 38px;
            width: auto;
        }

        .brand-info {
            display: flex;
            flex-direction: column;
        }

        .brand-title {
            font-family: 'Outfit', sans-serif;
            font-size: 18px;
            font-weight: 700;
            letter-spacing: -0.02em;
            line-height: 1.1;
        }

        .brand-badge {
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #dc2626;
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            font-size: 13.5px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 1px solid transparent;
        }

        .btn-primary {
            background: var(--primary);
            color: #ffffff;
            box-shadow: 0 2px 6px rgba(37, 99, 235, 0.2);
        }

        .btn-primary:hover {
            background: var(--primary-hover);
        }

        .btn-outline {
            background: #ffffff;
            border-color: var(--border);
            color: var(--text-secondary);
        }

        .btn-outline:hover {
            background: #f1f5f9;
            color: var(--text-primary);
        }

        .btn-danger-outline {
            background: #ffffff;
            border-color: rgba(220, 38, 38, 0.3);
            color: var(--danger);
        }

        .btn-danger-outline:hover {
            background: var(--danger-bg);
        }

        /* Main Container */
        .container {
            max-width: 960px;
            width: 100%;
            margin: 36px auto;
            padding: 0 20px;
            flex: 1;
        }

        /* Header Hero */
        .profile-hero {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 28px 32px;
            margin-bottom: 28px;
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 20px;
        }

        .user-info-wrapper {
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .avatar-circle {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2563eb, #dc2626);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: 700;
            font-family: 'Outfit', sans-serif;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
        }

        .user-details h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 22px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .user-meta {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 13px;
            color: var(--text-secondary);
        }

        .role-tag {
            background: #eff6ff;
            color: #2563eb;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 11.5px;
        }

        /* Alert notifications */
        .alert {
            padding: 14px 18px;
            border-radius: var(--radius-md);
            font-size: 13.5px;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 24px;
        }

        .alert-success {
            background: var(--success-bg);
            border: 1px solid rgba(5, 150, 105, 0.25);
            color: var(--success);
        }

        .alert-danger {
            background: var(--danger-bg);
            border: 1px solid rgba(220, 38, 38, 0.25);
            color: var(--danger);
        }

        /* Two Column Grid */
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
            gap: 24px;
        }

        .card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 30px;
            box-shadow: var(--shadow-sm);
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border);
        }

        .card-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: #eff6ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .card-title {
            font-family: 'Outfit', sans-serif;
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .card-desc {
            font-size: 12.5px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 6px;
        }

        .form-control {
            width: 100%;
            height: 42px;
            padding: 8px 14px;
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 13.5px;
            color: var(--text-primary);
            outline: none;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            border-color: var(--border-focus);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .form-hint {
            font-size: 11.5px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .card-footer {
            margin-top: 24px;
            padding-top: 18px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
        }

        /* Footer */
        .footer {
            margin-top: auto;
            border-top: 1px solid var(--border);
            padding: 20px;
            text-align: center;
            font-size: 12px;
            color: var(--text-muted);
            background: #ffffff;
        }

        @media (max-width: 768px) {
            .settings-grid {
                grid-template-columns: 1fr;
            }
            .profile-hero {
                flex-direction: column;
                align-items: flex-start;
            }
            .top-navbar {
                padding: 12px 16px;
            }
        }
    </style>
</head>
<body>

    <!-- Top Navbar -->
    <header class="top-navbar">
        <a href="{{ route('custom.page') }}" class="nav-brand" style="text-decoration: none;">
            <div style="width: 38px; height: 38px; background: linear-gradient(135deg, #2563eb, #1e40af); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: white; font-weight: 800; font-size: 18px;">W</div>
            <div class="brand-info">
                <span class="brand-title">WESS</span>
                <span class="brand-badge">Administration</span>
            </div>
        </a>

        <div class="nav-actions">
            <a href="{{ route('custom.page') }}" class="btn btn-primary">
                <span class="material-icons-outlined" style="font-size: 17px;">settings</span>
                <span>WESS Settings</span>
            </a>

            <a href="{{ route('logs.index') }}" class="btn btn-outline">
                <span class="material-icons-outlined" style="font-size: 17px;">receipt_long</span>
                <span>Logs</span>
            </a>

            <form action="{{ route('logout') }}" method="POST" style="display: inline;">
                @csrf
                <button type="submit" class="btn btn-danger-outline" title="Sign out of Admin Session">
                    <span class="material-icons-outlined" style="font-size: 17px;">logout</span>
                    <span>Sign Out</span>
                </button>
            </form>
        </div>
    </header>

    <!-- Main Container -->
    <main class="container">

        <!-- Status Notifications -->
        @if (session('status'))
            <div class="alert alert-success">
                <span class="material-icons-outlined">check_circle</span>
                <span>{{ session('status') }}</span>
            </div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger">
                <span class="material-icons-outlined">error_outline</span>
                <span>{{ $errors->first() }}</span>
            </div>
        @endif

        <!-- Profile Hero Banner -->
        <section class="profile-hero">
            <div class="user-info-wrapper">
                <div class="avatar-circle">
                    {{ strtoupper(substr($user->name ?? 'A', 0, 1)) }}
                </div>
                <div class="user-details">
                    <h2>{{ $user->name ?? 'Administrator' }}</h2>
                    <div class="user-meta">
                        <span>{{ $user->email }}</span>
                        <span class="role-tag">Super Admin</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Settings Cards Grid -->
        <div class="settings-grid">
            <!-- Card 1: Account Information -->
            <div class="card">
                <div class="card-header">
                    <div class="card-icon">
                        <span class="material-icons-outlined" style="font-size: 20px;">badge</span>
                    </div>
                    <div>
                        <h3 class="card-title">Profile Information</h3>
                        <p class="card-desc">Update your admin display name and login email address</p>
                    </div>
                </div>

                <form method="POST" action="{{ route('profile.update') }}">
                    @csrf

                    <div class="form-group">
                        <label for="name" class="form-label">Full Name</label>
                        <input type="text" id="name" name="name" class="form-control" value="{{ old('name', $user->name) }}" required>
                    </div>

                    <div class="form-group">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" id="email" name="email" class="form-control" value="{{ old('email', $user->email) }}" required>
                        <p class="form-hint">Used for signing into Laravel Horizon dashboard</p>
                    </div>

                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary">
                            <span class="material-icons-outlined" style="font-size: 16px;">save</span>
                            <span>Save Profile</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Card 2: Security & Password -->
            <div class="card">
                <div class="card-header">
                    <div class="card-icon" style="background: #fef2f2; color: #dc2626;">
                        <span class="material-icons-outlined" style="font-size: 20px;">lock</span>
                    </div>
                    <div>
                        <h3 class="card-title">Change Password</h3>
                        <p class="card-desc">Ensure your account is using a secure, long password</p>
                    </div>
                </div>

                <form method="POST" action="{{ route('profile.update') }}">
                    @csrf
                    <!-- Include current name and email so validation passes if only changing password -->
                    <input type="hidden" name="name" value="{{ $user->name }}">
                    <input type="hidden" name="email" value="{{ $user->email }}">

                    <div class="form-group">
                        <label for="current_password" class="form-label">Current Password</label>
                        <input type="password" id="current_password" name="current_password" class="form-control" placeholder="••••••••" required>
                    </div>

                    <div class="form-group">
                        <label for="new_password" class="form-label">New Password</label>
                        <input type="password" id="new_password" name="new_password" class="form-control" placeholder="••••••••" required minlength="6">
                        <p class="form-hint">Minimum 6 characters</p>
                    </div>

                    <div class="form-group">
                        <label for="new_password_confirmation" class="form-label">Confirm New Password</label>
                        <input type="password" id="new_password_confirmation" name="new_password_confirmation" class="form-control" placeholder="••••••••" required minlength="6">
                    </div>

                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary" style="background: #dc2626; border-color: #dc2626;">
                            <span class="material-icons-outlined" style="font-size: 16px;">key</span>
                            <span>Update Password</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </main>

    <!-- Universal Footer -->
    <footer class="footer">
        <div>
            &copy; {{ date('Y') }} WESS Integration by Ample Life. All rights reserved.
        </div>
    </footer>

</body>
</html>
