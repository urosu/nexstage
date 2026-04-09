<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Critical Alert — {{ $alert->type }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background: #f4f4f5; margin: 0; padding: 24px; }
        .container { max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden; border: 1px solid #e4e4e7; }
        .header { background: #dc2626; padding: 24px 32px; }
        .header h1 { color: #ffffff; margin: 0; font-size: 18px; font-weight: 600; }
        .header p { color: #fecaca; margin: 4px 0 0; font-size: 13px; }
        .body { padding: 32px; }
        .label { font-size: 11px; font-weight: 600; color: #71717a; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px; }
        .value { font-size: 15px; color: #18181b; margin-bottom: 20px; }
        .badge { display: inline-block; background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; border-radius: 4px; padding: 2px 8px; font-size: 12px; font-weight: 600; }
        .cta { display: inline-block; margin-top: 8px; background: #4f46e5; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-size: 14px; font-weight: 500; }
        .footer { padding: 16px 32px; border-top: 1px solid #f4f4f5; font-size: 12px; color: #a1a1aa; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Critical Alert</h1>
            <p>{{ $workspace->name }}</p>
        </div>
        <div class="body">
            <div class="label">Severity</div>
            <div class="value"><span class="badge">CRITICAL</span></div>

            <div class="label">Alert Type</div>
            <div class="value">{{ $alert->type }}</div>

            @if(!empty($alert->data))
                @foreach($alert->data as $key => $val)
                    @if(is_string($val) || is_numeric($val))
                        <div class="label">{{ ucwords(str_replace('_', ' ', $key)) }}</div>
                        <div class="value">{{ $val }}</div>
                    @endif
                @endforeach
            @endif

            <div class="label">Detected At</div>
            <div class="value">{{ $alert->created_at->format('Y-m-d H:i:s') }} UTC</div>

            <a href="{{ url('/insights') }}" class="cta">View in Nexstage →</a>
        </div>
        <div class="footer">
            You are receiving this email because you are the owner of the <strong>{{ $workspace->name }}</strong> workspace in Nexstage.
            This alert was generated automatically.
        </div>
    </div>
</body>
</html>
