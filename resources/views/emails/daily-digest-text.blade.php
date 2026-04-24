{{ $isWeekly ? 'Weekly digest' : 'Daily digest' }} — {{ $workspace->name }}
{{ str_repeat('=', 40) }}

Period: {{ $isWeekly ? $startDate . ' – ' . $endDate : $endDate }}

@if($narrative)
{{ $narrative }}

@endif
METRICS
-------
Revenue: €{{ number_format($heroMetrics['revenue'], 0) }}
Orders:  {{ number_format($heroMetrics['orders']) }}
@if($heroMetrics['roas'] !== null)
ROAS:    {{ number_format($heroMetrics['roas'], 1) }}x
@endif

@if(count($attentionItems) > 0)
THINGS TO LOOK AT
-----------------
@foreach($attentionItems as $i => $item)
{{ $i + 1 }}. {{ $item['text'] }}
   {{ $item['href'] }}

@endforeach
@endif
Open Nexstage: {{ url('/' . $workspace->slug . '/dashboard') }}

---
To change your email preferences: {{ url('/' . $workspace->slug . '/settings/notifications') }}
