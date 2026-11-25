{{-- The panel lines are built (and escaped) in LeaveRequestDecided::panel(). --}}
<x-mail::message>
Hello {{ $greetName }},

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
@elseif ($outcome === 'withdrawn')
Your leave request was cancelled before it was decided. Nothing was taken from your balance.
@else
Some days of your approved leave went back to your balance.
@endif

<x-mail::panel>
{!! $panel !!}
</x-mail::panel>

<x-mail::button :url="$url">
Open the request
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
