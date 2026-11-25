<x-mail::message>
Hello {{ $recipientName }},

@if ($afterManager)
{{ $employeeName }}'s leave request was approved by their manager and now waits for your decision.
@else
{{ $employeeName }} applied for leave and it waits for your decision.
@endif

<x-mail::panel>
**Type:** {{ $typeName }}<br>
**Dates:** {{ $dates }}<br>
**Days:** {{ $days }}<br>
**Reason:** {{ $reason }}
</x-mail::panel>

<x-mail::button :url="$url">
Open the request
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
