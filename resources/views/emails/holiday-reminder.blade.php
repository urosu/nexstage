<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $holiday->type === 'commercial' ? 'Upcoming Sale Event' : 'Upcoming Holiday' }} — {{ $holiday->name }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background: #f4f4f5; margin: 0; padding: 24px; }
        .container { max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden; border: 1px solid #e4e4e7; }
        .header-public     { background: #d97706; padding: 24px 32px; }
        .header-commercial { background: #7c3aed; padding: 24px 32px; }
        .header-public h1, .header-commercial h1 { color: #ffffff; margin: 0; font-size: 18px; font-weight: 600; }
        .header-public p  { color: #fde68a; margin: 4px 0 0; font-size: 13px; }
        .header-commercial p { color: #ddd6fe; margin: 4px 0 0; font-size: 13px; }
        .body { padding: 32px; }
        .label { font-size: 11px; font-weight: 600; color: #71717a; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px; }
        .value { font-size: 15px; color: #18181b; margin-bottom: 20px; }
        .badge-public     { display: inline-block; background: #fffbeb; color: #d97706; border: 1px solid #fde68a; border-radius: 4px; padding: 2px 8px; font-size: 12px; font-weight: 600; }
        .badge-commercial { display: inline-block; background: #f5f3ff; color: #7c3aed; border: 1px solid #ddd6fe; border-radius: 4px; padding: 2px 8px; font-size: 12px; font-weight: 600; }
        .tip { background: #f4f4f5; border-radius: 6px; padding: 16px; margin-bottom: 20px; font-size: 13px; color: #52525b; line-height: 1.5; }
        .cta { display: inline-block; margin-top: 8px; background: #4f46e5; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-size: 14px; font-weight: 500; }
        .footer { padding: 16px 32px; border-top: 1px solid #f4f4f5; font-size: 12px; color: #a1a1aa; }
    </style>
</head>
<body>
    <div class="container">
        <div class="{{ $holiday->type === 'commercial' ? 'header-commercial' : 'header-public' }}">
            <h1>{{ $holiday->type === 'commercial' ? 'Upcoming Sale Event' : 'Upcoming Holiday Reminder' }}</h1>
            <p>{{ $workspace->name }}</p>
        </div>
        <div class="body">
            <div class="label">{{ $holiday->type === 'commercial' ? 'Event' : 'Holiday' }}</div>
            <div class="value">{{ $holiday->name }}</div>

            <div class="label">Date</div>
            <div class="value">{{ $holiday->date->format('l, F j, Y') }}</div>

            <div class="label">Time Until Event</div>
            <div class="value">
                <span class="{{ $holiday->type === 'commercial' ? 'badge-commercial' : 'badge-public' }}">
                    @if($daysAway === 1)
                        Tomorrow
                    @else
                        {{ $daysAway }} days away
                    @endif
                </span>
            </div>

            @if($holiday->type === 'commercial')
                <div class="tip">
                    <strong>{{ $holiday->name }}</strong> is a key ecommerce date that typically drives significantly higher traffic and CPMs.
                    Start your campaigns 7–14 days early to lock in audience inventory and avoid last-minute budget competition.
                    Shoppers often browse and compare before committing — early exposure builds purchase intent.
                </div>
            @else
                <div class="tip">
                    Now is a good time to review your ad campaigns and make sure you have the right creative and budgets set up before <strong>{{ $holiday->name }}</strong>.
                    Holiday periods often see increased competition and CPMs, so scheduling campaigns in advance helps you get ahead.
                </div>
            @endif

            <a href="{{ url('/' . $workspace->slug . '/campaigns') }}" class="cta">Review campaigns →</a>
        </div>
        <div class="footer">
            You are receiving this email because you are the owner of the <strong>{{ $workspace->name }}</strong> workspace in Nexstage.
            To change your notification timing, visit <a href="{{ url('/' . $workspace->slug . '/settings/workspace') }}">Workspace Settings</a>.
        </div>
    </div>
</body>
</html>
