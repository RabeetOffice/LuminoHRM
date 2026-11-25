<x-mail::message>
Hello {{ $recipientName }},

@if ($approved)
Your attendance correction request for {{ $date }} was approved. Your attendance for that day now shows the times below.
@else
Your attendance correction request for {{ $date }} was rejected. Your attendance for that day was not changed.
@endif

<x-mail::panel>
**Clock-in:** {{ $clockIn }}<br>
**Clock-out:** {{ $clockOut }}
@if ($note)
<br>**Note:** {{ $note }}
@endif
</x-mail::panel>

{{ config('app.name') }}
</x-mail::message>
