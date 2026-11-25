<x-mail::message>
Hello {{ $recipientName }},

@if ($outcome === 'approved')
Your leave request was approved.
@elseif ($outcome === 'rejected')
Your leave request was rejected.
@elseif ($outcome === 'cancelled')
@if ($stillApproved && $cancelledFrom)
Your approved leave was cancelled from {{ $cancelledFrom }}. The days before it stay as leave; the cancelled days went back to your balance.
@else
Your approved leave was cancelled. Its days went back to your balance.
@endif
@else
Some days of your approved leave went back to your balance.
@endif

<x-mail::panel>
**Type:** {{ $typeName }}<br>
**Dates:** {{ $dates }}<br>
**Days:** {{ $days }}
@if ($note)
<br>**Note:** {{ $note }}
@endif
</x-mail::panel>

<x-mail::button :url="$url">
Open the request
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
