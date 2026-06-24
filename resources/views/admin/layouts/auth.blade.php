<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin Login')</title>
    <style>
        :root {
            --bg-gradient: linear-gradient(135deg, #f8fafc 0%, #f0fdf4 50%, #dcfce7 100%);
            --card-bg: #ffffff;
            --border: rgba(16, 185, 129, 0.1);
            --accent: #10b981;
            --accent-strong: #059669;
            --text: #334155;
            --muted: #64748b;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg-gradient);
            color: var(--text);
            position: relative;
            overflow-x: hidden;
        }
        .glow-shape-1 {
            position: absolute;
            top: -20%;
            left: -10%;
            width: 600px;
            height: 600px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(16, 185, 129, 0.15) 0%, rgba(16, 185, 129, 0) 70%);
            filter: blur(80px);
            z-index: -1;
            pointer-events: none;
        }
        .glow-shape-2 {
            position: absolute;
            bottom: -20%;
            right: -10%;
            width: 700px;
            height: 700px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(132, 204, 22, 0.12) 0%, rgba(132, 204, 22, 0) 70%);
            filter: blur(90px);
            z-index: -1;
            pointer-events: none;
        }
        .page-container { width: 100%; max-width: 1200px; margin: 0 auto; padding: 32px 20px 48px; position: relative; z-index: 2; }
        .auth-wrapper { min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card { 
            width: 100%; 
            max-width: 480px; 
            background: var(--card-bg); 
            border: 1px solid var(--border); 
            border-radius: 24px; 
            padding: 40px; 
            box-shadow: 0 20px 40px -15px rgba(16, 185, 129, 0.12), 0 15px 30px -10px rgba(0, 0, 0, 0.04);
            backdrop-filter: blur(8px); 
        }
        .card-header { margin-bottom: 20px; }
        .eyebrow { letter-spacing: 0.08em; text-transform: uppercase; font-size: 12px; color: var(--muted); margin: 0 0 8px; }
        h1 { margin: 0; color: #0f172a; font-size: 28px; font-weight: 700; letter-spacing: -0.02em; }
        p { color: var(--muted); margin: 6px 0 0; line-height: 1.6; }
        label { display: block; font-weight: 600; margin-bottom: 8px; color: #334155; letter-spacing: -0.01em; }
        input { 
            width: 100%; 
            padding: 14px 16px; 
            border-radius: 12px; 
            border: 1px solid #cbd5e1; 
            background: #ffffff; 
            color: #0f172a; 
            font-size: 16px; 
            transition: border-color 0.2s, box-shadow 0.2s; 
        }
        input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.15); }
        .input-row { display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: center; }
        .btn { border: none; cursor: pointer; padding: 14px 18px; border-radius: 12px; font-weight: 700; font-size: 16px; transition: transform 0.08s ease, box-shadow 0.2s; }
        .btn:disabled { opacity: 0.65; cursor: not-allowed; transform: none; box-shadow: none; }
        .btn.primary { 
            background: linear-gradient(135deg, var(--accent), var(--accent-strong)); 
            color: #ffffff; 
            box-shadow: 0 10px 20px -5px rgba(16, 185, 129, 0.3); 
        }
        .btn.secondary { 
            background: #f1f5f9; 
            color: #334155; 
            border: 1px solid #e2e8f0; 
        }
        .btn:not(:disabled):hover { transform: translateY(-1px); }
        .otp-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 12px; }
        .otp-input { text-align: center; font-size: 24px; letter-spacing: 0.08em; color: #0f172a; }
        .status { border-radius: 12px; padding: 12px 14px; margin-bottom: 14px; font-weight: 600; display: none; }
        .status.show { display: block; }
        .status.success { background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.25); color: #065f46; }
        .status.error { background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.25); color: #991b1b; }
        .muted { color: var(--muted); font-size: 14px; }
        @media (max-width: 640px) { .card { padding: 24px; } .input-row { grid-template-columns: 1fr; } .btn { width: 100%; } }
    </style>
</head>
<body>
    <div class="glow-shape-1"></div>
    <div class="glow-shape-2"></div>
    @yield('content')
</body>
</html>
