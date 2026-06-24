<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Greenpreneur | Download the App</title>
    <style>
        :root {
            color-scheme: light;
            --bg-start: #f8fafc;
            --bg-end: #f0fdf4;
            --card-bg: rgba(255, 255, 255, 0.85);
            --card-border: rgba(16, 185, 129, 0.15);
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --accent: #10b981;
            --accent-glow: rgba(16, 185, 129, 0.3);
            --shadow: 0 20px 40px -15px rgba(16, 185, 129, 0.12), 0 15px 30px -10px rgba(0, 0, 0, 0.04);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: "Inter", "Segoe UI", system-ui, -apple-system, sans-serif;
            background: radial-gradient(circle at top, #e8f5e9 0%, var(--bg-end) 60%, var(--bg-start) 100%);
            color: var(--text-primary);
            min-height: 100vh;
            overflow-x: hidden;
            position: relative;
        }

        .glow-shape-1 {
            position: absolute;
            top: -10%;
            left: 5%;
            width: 450px;
            height: 450px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(16, 185, 129, 0.15) 0%, rgba(16, 185, 129, 0) 70%);
            filter: blur(80px);
            z-index: 1;
            pointer-events: none;
        }
        .glow-shape-2 {
            position: absolute;
            bottom: 5%;
            right: 5%;
            width: 500px;
            height: 500px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(132, 204, 22, 0.12) 0%, rgba(132, 204, 22, 0) 70%);
            filter: blur(90px);
            z-index: 1;
            pointer-events: none;
        }

        a { color: inherit; text-decoration: none; }

        .page { padding: 48px 20px 40px; }

        .hero-card {
            max-width: 1100px;
            margin: 0 auto;
            padding: 40px;
            border-radius: 28px;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            box-shadow: var(--shadow);
            backdrop-filter: blur(18px);
        }

        .hero-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 40px;
            align-items: center;
        }

        /* --- Text & Hero Section --- */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 8px 16px;
            border-radius: 999px;
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            background: rgba(16, 185, 129, 0.12);
            color: var(--accent);
            font-weight: 600;
        }

        .headline {
            font-size: clamp(2.4rem, 4vw, 3.4rem);
            font-weight: 700;
            margin: 18px 0 10px;
            line-height: 1.1;
        }

        .subheadline {
            font-size: clamp(1.1rem, 2vw, 1.35rem);
            color: var(--text-secondary);
            margin-bottom: 18px;
            font-weight: 500;
        }

        .description {
            font-size: 1.05rem;
            line-height: 1.7;
            color: var(--text-secondary);
            margin-bottom: 32px;
            max-width: 90%;
        }

        /* --- Buttons (One Line Fix) --- */
        .cta-buttons {
            display: flex;
            flex-direction: row; /* Force row */
            flex-wrap: nowrap;   /* Do not wrap */
            gap: 12px;
            width: 100%;
        }

        .store-button {
            flex: 1; /* Both buttons grow equally */
            display: inline-flex;
            align-items: center;
            justify-content: center; /* Center content */
            gap: 10px;
            padding: 12px 16px;
            border-radius: 16px;
            font-weight: 600;
            text-align: left;
            background: linear-gradient(180deg, #0c0c0c 0%, #000000 100%);
            border: 1px solid rgba(255, 255, 255, 0.35);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.35);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            white-space: nowrap; /* Prevent text breaking */
            min-width: 0; 
        }

        .store-button .store-icon {
            width: 28px;
            height: 28px;
            flex-shrink: 0;
        }

        .store-button .store-text {
            display: flex;
            flex-direction: column;
            gap: 2px;
            color: #ffffff;
            overflow: hidden;
        }

        .store-button .store-label {
            font-size: 0.65rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.8);
        }

        .store-button .store-name {
            font-size: 1.05rem;
            font-weight: 700;
        }

        .store-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 16px 28px rgba(0, 0, 0, 0.45);
        }

        /* --- iPhone 17 Pro Styling --- */
        .phone-wrap { display: flex; justify-content: center; position: relative; }

        .phone {
            width: 320px;
            height: 650px;
            /* Titanium Border Look */
            background: #000;
            border-radius: 55px;
            padding: 12px; /* Bezel thickness */
            box-shadow: 
                0 0 0 2px #3a3a3a, /* Inner bezel frame */
                0 0 0 4px #1a1a1a, /* Outer bezel frame */
                0 30px 80px rgba(0, 0, 0, 0.8); /* Deep shadow */
            position: relative;
            z-index: 10;
        }

        /* Power/Volume Buttons */
        .phone::before {
            content: ''; position: absolute; top: 180px; left: -6px; width: 4px; height: 50px;
            background: #2a2a2a; border-radius: 4px 0 0 4px;
        }
        .phone::after {
            content: ''; position: absolute; top: 120px; right: -6px; width: 4px; height: 80px;
            background: #2a2a2a; border-radius: 0 4px 4px 0;
        }

        .phone-screen {
            background: linear-gradient(180deg, #0f1218 0%, #000000 100%);
            border-radius: 44px;
            width: 100%;
            height: 100%;
            overflow: hidden;
            position: relative;
            display: flex;
            flex-direction: column;
        }

        /* Dynamic Island */
        .dynamic-island {
            width: 100px;
            height: 30px;
            background: #000;
            position: absolute;
            top: 10px;
            left: 50%;
            transform: translateX(-50%);
            border-radius: 20px;
            z-index: 20;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 10px;
        }
        .di-camera { width: 8px; height: 8px; background: #1a1b25; border-radius: 50%; }

        /* --- Content Inside Phone --- */
        .screen-content {
            padding: 50px 20px 20px; /* Top padding clears Dynamic Island */
            display: flex;
            flex-direction: column;
            gap: 16px;
            height: 100%;
        }

        /* App Header inside phone */
        .phone-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
            animation: slideDown 0.6s ease-out;
        }
        .phone-app-icon {
            width: 54px;
            height: 54px;
            background: #fff;
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.2);
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        .phone-app-icon img { width: 100%; height: 100%; object-fit: contain; }
        
        .phone-app-text h3 { font-size: 16px; font-weight: 700; color: #fff; }
        .phone-app-text p { font-size: 12px; color: #888; }

        /* Mock UI Widgets */
        .mock-widget {
            background: rgba(30, 35, 50, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 18px;
            padding: 16px;
            backdrop-filter: blur(10px);
            animation: fadeIn 0.8s ease-out forwards;
            opacity: 0;
            transform: translateY(10px);
        }

        /* Widget 1: Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .stat-box {
            background: rgba(255,255,255,0.05);
            padding: 12px;
            border-radius: 12px;
            text-align: center;
        }
        .stat-num { font-size: 18px; font-weight: 700; color: var(--accent); display: block; }
        .stat-label { font-size: 10px; color: #aaa; text-transform: uppercase; letter-spacing: 0.5px; }

        /* Widget 2: Event */
        .event-tag { 
            background: rgba(255, 77, 77, 0.2); color: #ff6b6b; 
            font-size: 10px; padding: 4px 8px; border-radius: 4px; font-weight: 700;
            display: inline-block; margin-bottom: 8px;
        }
        .event-title { font-size: 14px; font-weight: 600; margin-bottom: 4px; }
        .event-date { font-size: 12px; color: #ccc; display: flex; align-items: center; gap: 5px; margin-bottom: 12px; }
        .btn-join {
            width: 100%; background: var(--accent); color: #000; border: none;
            padding: 8px; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer;
        }

        /* Widget 3: User */
        .user-row { display: flex; align-items: center; gap: 10px; }
        .user-avatar { width: 36px; height: 36px; background: linear-gradient(45deg, #444, #666); border-radius: 50%; }
        .user-info { flex: 1; }
        .user-name { font-size: 13px; font-weight: 600; }
        .user-action { font-size: 11px; color: var(--accent); }

        .install-fab {
            margin-top: auto;
            background: #fff;
            color: #000;
            text-align: center;
            padding: 14px;
            border-radius: 99px;
            font-weight: 700;
            font-size: 14px;
            box-shadow: 0 0 20px rgba(255,255,255,0.2);
            animation: pulse 2s infinite;
        }

        /* --- Footer --- */
        footer {
            max-width: 1100px;
            margin: 28px auto 0;
            text-align: center;
            color: rgba(198, 201, 229, 0.7);
            font-size: 0.9rem;
            display: grid;
            gap: 6px;
        }

        /* --- Media Queries --- */
        @media (min-width: 900px) {
            .phone { animation: float 6s ease-in-out infinite; }
        }

        @media (max-width: 900px) {
            .hero-card { padding: 28px 22px; }
            .hero-grid { grid-template-columns: 1fr; }
            .phone-wrap { order: 2; margin-top: 20px; }
            .phone { height: 600px; }
        }

        @media (max-width: 400px) {
            /* Fallback for tiny screens if side-by-side really doesn't fit */
            .cta-buttons { flex-direction: column; }
            .headline { font-size: 2rem; }
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-15px); }
        }
        @keyframes slideDown { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        @keyframes fadeIn { to { opacity: 1; transform: translateY(0); } }
        @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.02); } 100% { transform: scale(1); } }

        .phone-image-container {
            width: 100%;
            max-width: 338px;
            aspect-ratio: 9/16;
            border-radius: 40px;
            overflow: hidden;
            box-shadow: 0 20px 40px -10px rgba(16, 185, 129, 0.25);
            z-index: 10;
            position: relative;
            transition: transform 0.5s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.5s ease;
        }
        .phone-image-container:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 30px 60px -15px rgba(16, 185, 129, 0.35);
        }
        .hero-mockup-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        @media (min-width: 900px) {
            .phone-image-container { animation: float 6s ease-in-out infinite; }
        }
    </style>
</head>
<body>
    <div class="glow-shape-1"></div>
    <div class="glow-shape-2"></div>
    <main class="page">
        <section class="hero-card">
            <div class="hero-grid">
                <div>
                    <span class="badge">GREENPRENEUR PLATFORM</span>
                    <h1 class="headline">Learn. Connect. Grow Green.</h1>
                    
                    <p class="subheadline">India's Green Learning, Networking and Sustainability Community</p>
                    
                    <p class="description">
                        Discover sustainable skills, connect with eco-conscious professionals, join green communities, participate in eco-circles, track your impact, and grow your profile in the Greenpreneur ecosystem.
                    </p>

                    <div class="cta-buttons">
                        <a class="store-button primary"
                           href="https://play.google.com/store/apps/details?id=com.unity.greenpreneur"
                           target="_blank" rel="noopener noreferrer">
                            <span class="store-icon">
                                <svg width="100%" height="100%" viewBox="0 0 24 24" fill="none">
                                    <path d="M3.7 2.6C2.9 2 1.8 2.6 1.8 3.6v16.8c0 1 1.1 1.6 1.9 1L13.6 12 3.7 2.6z" fill="#00d2ff"/>
                                    <path d="M13.6 12l3.6-3.4 4.1 2.4c1 .6 1 .9 0 1.5l-4.1 2.4L13.6 12z" fill="#ffe000"/>
                                    <path d="M3.7 21.4L13.6 12l3.6 3.4-5.5 3.2c-.7.4-1.6.4-2.3-.1l-5.7-4.1z" fill="#00f076"/>
                                </svg>
                            </span>
                            <span class="store-text">
                                <span class="store-label">Get it on</span>
                                <span class="store-name">Google Play</span>
                            </span>
                        </a>

                        <a class="store-button secondary"
                           href="https://apps.apple.com/in/app/greenpreneur-unity/id6782311572"
                           target="_blank" rel="noopener noreferrer">
                            <span class="store-icon">
                                <svg width="100%" height="100%" viewBox="0 0 24 24" fill="none">
                                    <path d="M15.9 5.7c-1 .8-1.7 1.9-1.6 3.1 1.1.1 2.2-.6 3-1.5.7-.9 1.2-2.1 1.1-3.4-1.2.1-2.4.8-3.5 1.8z" fill="#ffffff"/>
                                    <path d="M19.3 16.9c-.6 1.4-1.2 2.7-2.3 2.7-1 0-1.3-.7-2.5-.7-1.3 0-1.7.7-2.7.7-1.1 0-1.9-1.2-2.5-2.6-1.4-2.6-1.5-5.6-.7-7 .6-1 1.7-1.7 2.9-1.7 1.1 0 1.8.7 2.7.7.8 0 1.8-.8 3.1-.7 1 .1 2 .5 2.7 1.4-2.4 1.4-2 5.1-.7 6.2z" fill="#ffffff"/>
                                </svg>
                            </span>
                            <span class="store-text">
                                <span class="store-label">Download on the</span>
                                <span class="store-name">App Store</span>
                            </span>
                        </a>
                    </div>
                </div>

                <div class="phone-wrap">
                    <div class="phone-image-container">
                        <img class="hero-mockup-img" src="{{ asset('images/greenpreneur-mockup.png') }}" alt="Greenpreneur Mobile Mockup">
                    </div>
                </div>

            </div>
        </section>

        <footer>
            <div>© 2026 Greenpreneur</div>
            <div>You are viewing the official download page.</div>
        </footer>
    </main>
</body>
</html>