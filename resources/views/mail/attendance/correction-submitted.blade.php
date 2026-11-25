<x-mail::message>
Hello {{ $recipientName }},

@if ($afterManager)
{{ $employeeName }}'s attendance correction request was approved by their manager and now waits for HR.
@else
{{ $employeeName }} asked for a correction of their attendance and it waits for your decision.
@endif

<x-mail::panel>
**Day:** {{ $date }}<br>
**Clock-in:** {{ $clockIn }}<br>
**Clock-out:** {{ $clockOut }}<br>
**Reason:** {{ $reason }}
</x-mail::panel>

<x-mail::button :url="$url">
Open correction requests
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
