{{ $holiday->type === 'commercial' ? 'Upcoming Sale Event' : 'Upcoming Holiday Reminder' }} — {{ $workspace->name }}
========================================================

{{ $holiday->type === 'commercial' ? 'Event' : 'Holiday' }}: {{ $holiday->name }}
Date: {{ $holiday->date->format('l, F j, Y') }}
Time until event: @if($daysAway === 1)Tomorrow@else{{ $daysAway }} days away@endif

@if($holiday->type === 'commercial')
{{ $holiday->name }} is a key ecommerce date that typically drives significantly higher traffic and CPMs.
Start your campaigns 7–14 days early to lock in audience inventory and avoid last-minute budget competition.
Shoppers often browse and compare before committing — early exposure builds purchase intent.
@else
Now is a good time to review your ad campaigns and make sure you have the right creative and budgets set up before {{ $holiday->name }}. Holiday periods often see increased competition and CPMs, so scheduling campaigns in advance helps you get ahead.
@endif

Review campaigns: {{ url('/' . $workspace->slug . '/campaigns') }}

---
You are receiving this email because you are the owner of the {{ $workspace->name }} workspace in Nexstage.
To change your notification timing, visit: {{ url('/' . $workspace->slug . '/settings/workspace') }}
