[Nexstage] Critical Alert — {{ $workspace->name }}

ALERT TYPE: {{ $alert->type }}
WORKSPACE:  {{ $workspace->name }}
SEVERITY:   CRITICAL
DETECTED:   {{ $alert->created_at->format('Y-m-d H:i:s') }} UTC

@if(!empty($alert->data))
Details:
@foreach($alert->data as $key => $val)
@if(is_string($val) || is_numeric($val))
  {{ ucwords(str_replace('_', ' ', $key)) }}: {{ $val }}
@endif
@endforeach

@endif
View in Nexstage: {{ url('/insights') }}

---
You are receiving this email because you are the owner of the "{{ $workspace->name }}" workspace in Nexstage.
