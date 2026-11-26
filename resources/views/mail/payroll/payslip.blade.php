{{-- Values are escaped in PayslipIssued::content(). --}}
<x-mail::message>
Hello {{ $greetName }},

Your payslip for {{ $monthLabel }} is ready. It is attached to this email as a PDF.

<x-mail::panel>
**Net pay:** {{ $netPay }}
</x-mail::panel>

<x-mail::button :url="$url">
Open My Payslips
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
