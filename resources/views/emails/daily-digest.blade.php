<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $isWeekly ? 'Weekly digest' : 'Daily digest' }} — {{ $workspace->name }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background: #f4f4f5; margin: 0; padding: 24px; }
        .container { max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden; border: 1px solid #e4e4e7; }
        .header { background: #4f46e5; padding: 24px 32px; }
        .header h1 { color: #ffffff; margin: 0; font-size: 18px; font-weight: 600; }
        .header p { color: #c7d2fe; margin: 4px 0 0; font-size: 13px; }
        .body { padding: 32px; }
        .narrative { font-size: 15px; color: #18181b; font-weight: 500; margin-bottom: 24px; line-height: 1.5; }
        .metrics { display: flex; margin-bottom: 28px; border: 1px solid #e4e4e7; border-radius: 6px; overflow: hidden; }
        .metric { flex: 1; padding: 16px; border-right: 1px solid #e4e4e7; }
        .metric:last-child { border-right: none; }
        .metric-label { font-size: 11px; font-weight: 600; color: #71717a; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px; }
        .metric-value { font-size: 20px; font-weight: 700; color: #18181b; }
        .section-title { font-size: 11px; font-weight: 600; color: #71717a; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 12px; }
        .attention-item { display: flex; align-items: flex-start; gap: 10px; padding: 10px 0; border-bottom: 1px solid #f4f4f5; }
        .attention-item:last-child { border-bottom: none; }
        .dot { width: 8px; height: 8px; border-radius: 50%; margin-top: 5px; flex-shrink: 0; }
        .dot-critical { background: #ef4444; }
        .dot-warning  { background: #f59e0b; }
        .dot-info     { background: #6366f1; }
        .attention-text { font-size: 14px; color: #3f3f46; line-height: 1.4; margin-bottom: 2px; }
        .attention-link { font-size: 12px; color: #4f46e5; text-decoration: none; }
        .cta { display: inline-block; margin-top: 24px; background: #4f46e5; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-size: 14px; font-weight: 500; }
        .footer { padding: 16px 32px; border-top: 1px solid #f4f4f5; font-size: 12px; color: #a1a1aa; }
        .footer a { color: #a1a1aa; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{{ $isWeekly ? 'Weekly digest' : 'Daily digest' }}</h1>
            <p>{{ $workspace->name }} &middot; {{ $isWeekly ? $startDate . ' – ' . $endDate : $endDate }}</p>
        </div>
        <div class="body">
            @if($narrative)
                <div class="narrative">{{ $narrative }}</div>
            @endif

            <div class="metrics">
                <div class="metric">
                    <div class="metric-label">Revenue</div>
                    <div class="metric-value">€{{ number_format($heroMetrics['revenue'], 0) }}</div>
                </div>
                <div class="metric">
                    <div class="metric-label">Orders</div>
                    <div class="metric-value">{{ number_format($heroMetrics['orders']) }}</div>
                </div>
                @if($heroMetrics['roas'] !== null)
                    <div class="metric">
                        <div class="metric-label">ROAS</div>
                        <div class="metric-value">{{ number_format($heroMetrics['roas'], 1) }}x</div>
                    </div>
                @endif
            </div>

            @if(count($attentionItems) > 0)
                <div class="section-title">Things to look at</div>
                @foreach($attentionItems as $item)
                    <div class="attention-item">
                        <div class="dot dot-{{ $item['severity'] }}"></div>
                        <div>
                            <div class="attention-text">{{ $item['text'] }}</div>
                            <a href="{{ $item['href'] }}" class="attention-link">View →</a>
                        </div>
                    </div>
                @endforeach
            @endif

            <a href="{{ url('/' . $workspace->slug . '/dashboard') }}" class="cta">Open Nexstage →</a>
        </div>
        <div class="footer">
            You are receiving this because you are the owner of <strong>{{ $workspace->name }}</strong> in Nexstage.
            To change your email preferences, visit <a href="{{ url('/' . $workspace->slug . '/settings/notifications') }}">notification settings</a>.
        </div>
    </div>
</body>
</html>
