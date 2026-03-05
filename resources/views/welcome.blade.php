<!DOCTYPE html>
<html lang="en" data-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cipi</title>
    <style>
        *,
        *::before,
        *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --bg: #09090b;
            --bg-subtle: #18181b;
            --text: #fafafa;
            --text-secondary: #a1a1aa;
            --text-tertiary: #52525b;
            --border: #27272a;
            --accent: #38bdf8;
            --accent-hover: #7dd3fc;
            --font-sans: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            --font-mono: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace;
        }

        [data-theme="light"] {
            --bg: #ffffff;
            --bg-subtle: #f4f4f5;
            --text: #09090b;
            --text-secondary: #71717a;
            --text-tertiary: #a1a1aa;
            --border: #e4e4e7;
            --accent: #0ea5e9;
            --accent-hover: #0284c7;
        }

        body {
            font-family: var(--font-sans);
            background: var(--bg);
            color: var(--text);
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem 1.5rem;
            transition: background 0.2s ease, color 0.2s ease;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .theme-toggle {
            position: fixed;
            top: 1.25rem;
            right: 1.25rem;
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text-secondary);
            width: 32px;
            height: 32px;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.15s, border-color 0.15s;
        }

        .theme-toggle:hover {
            color: var(--text);
            border-color: var(--text-tertiary);
        }

        .theme-toggle svg {
            width: 14px;
            height: 14px;
        }

        [data-theme="dark"] .icon-sun {
            display: none;
        }

        [data-theme="light"] .icon-moon {
            display: none;
        }

        main {
            text-align: center;
            max-width: 480px;
            animation: fadeUp 0.5s ease both;
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(8px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .badge {
            display: inline-block;
            font-family: var(--font-mono);
            font-size: 0.75rem;
            letter-spacing: 0.05em;
            color: var(--accent);
            margin-bottom: 2rem;
        }

        h1 {
            font-size: clamp(1.5rem, 4vw, 2rem);
            font-weight: 600;
            letter-spacing: -0.025em;
            line-height: 1.3;
            margin-bottom: 1rem;
        }

        h1 em {
            font-style: italic;
            color: var(--accent);
        }

        .description {
            font-size: 0.9375rem;
            line-height: 1.7;
            color: var(--text-secondary);
            margin-bottom: 2.5rem;
        }

        .actions {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1.5rem;
        }

        .actions a {
            font-size: 0.8125rem;
            font-weight: 500;
            text-decoration: none;
            transition: color 0.15s;
        }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            color: var(--bg);
            background: var(--text);
            padding: 0.5rem 1.125rem;
            border-radius: 6px;
            transition: opacity 0.15s;
        }

        .btn-primary:hover {
            opacity: 0.85;
        }

        .btn-primary svg {
            width: 13px;
            height: 13px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .btn-ghost {
            color: var(--text-secondary);
        }

        .btn-ghost:hover {
            color: var(--text);
        }

        footer {
            position: fixed;
            bottom: 1.25rem;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 0.6875rem;
            color: var(--text-tertiary);
            letter-spacing: 0.02em;
        }

        footer a {
            color: var(--text-tertiary);
            text-decoration: none;
            transition: color 0.15s;
        }

        footer a:hover {
            color: var(--text-secondary);
        }
    </style>
</head>

<body>

    <button class="theme-toggle" onclick="toggle()" aria-label="Toggle theme">
        <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
            stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 12.79A9 9 0 1 1 11.21 3a7 7 0 0 0 9.79 9.79z" />
        </svg>
        <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
            stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="5" />
            <line x1="12" y1="1" x2="12" y2="3" />
            <line x1="12" y1="21" x2="12" y2="23" />
            <line x1="4.22" y1="4.22" x2="5.64" y2="5.64" />
            <line x1="18.36" y1="18.36" x2="19.78" y2="19.78" />
            <line x1="1" y1="12" x2="3" y2="12" />
            <line x1="21" y1="12" x2="23" y2="12" />
            <line x1="4.22" y1="19.78" x2="5.64" y2="18.36" />
            <line x1="18.36" y1="5.64" x2="19.78" y2="4.22" />
        </svg>
    </button>

    <main>
        <span class="badge">Cipi</span>
        <h1>Easy Laravel <em>Deployments</em></h1>
        <p class="description">
            The open-source CLI built exclusively for Laravel, with AI and MCP integration. One command installs a
            complete production stack. One command creates an isolated app with its own database, workers, SSL, and
            zero-downtime deploys.
        </p>
        <div class="actions">
            <a href="https://cipi.sh" class="btn-primary" target="_blank" rel="noopener">
                Get Started
                <svg viewBox="0 0 24 24">
                    <path d="M7 17L17 7M17 7H7M17 7v10" />
                </svg>
            </a>
            <a href="/docs" class="btn-ghost" rel="noopener">API Reference</a>
        </div>
    </main>

    <footer>
        <a href="https://cipi.sh">cipi.sh</a> &middot; MIT Licensed
    </footer>

    <script>
        const html = document.documentElement;
        if (window.matchMedia('(prefers-color-scheme: light)').matches) html.dataset.theme = 'light';
        function toggle() {
            html.dataset.theme = html.dataset.theme === 'dark' ? 'light' : 'dark';
        }
    </script>
</body>

</html>
