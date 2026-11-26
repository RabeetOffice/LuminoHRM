{{-- A payslip (App\Support\Payroll\PayslipPdf::render). Every value arrives formatted in $p, built from the frozen breakdown and snapshots only. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $p['title'] }}</title>
<style>
    @page { margin: 28px 34px; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { font-size: 10px; color: #1f2937; margin: 0; }
    .muted { color: #6b7280; }
    .header { width: 100%; border-bottom: 2px solid #10b77f; padding-bottom: 8px; margin-bottom: 12px; }
    .header td { vertical-align: middle; }
    .logo { max-height: 44px; max-width: 160px; }
    .company { font-size: 15px; font-weight: bold; }
    .title { font-size: 13px; font-weight: bold; text-align: right; }
    h2 { font-size: 11px; margin: 12px 0 4px; color: #047857; text-transform: uppercase; letter-spacing: 0.5px; }
    table.grid { width: 100%; border-collapse: collapse; }
    table.grid td { padding: 3px 6px; border-bottom: 1px solid #e5e7eb; }
    table.grid td.amount { text-align: right; white-space: nowrap; }
    table.grid tr.total td { font-weight: bold; border-top: 1px solid #9ca3af; border-bottom: none; }
    .cols { width: 100%; border-collapse: collapse; }
    .cols > tbody > tr > td { width: 50%; vertical-align: top; padding: 0; }
    .cols > tbody > tr > td.left { padding-right: 10px; }
    .cols > tbody > tr > td.right { padding-left: 10px; }
    .net { margin-top: 14px; padding: 10px 12px; border: 1px solid #10b77f; border-radius: 6px; background: #ecfdf5; }
    .net .label { font-size: 12px; font-weight: bold; }
    .net .value { font-size: 20px; font-weight: bold; text-align: right; color: #047857; }
    .footer { margin-top: 16px; padding-top: 8px; border-top: 1px solid #e5e7eb; font-size: 9px; color: #6b7280; white-space: pre-line; }
</style>
</head>
<body>
<table class="header">
    <tr>
        <td>
            @if ($p['logo'])
                <img class="logo" src="{{ $p['logo'] }}" alt=""><br>
            @endif
            @if ($p['company']['name'] !== '')
                <div class="company">{{ $p['company']['name'] }}</div>
            @endif
            @if ($p['company']['address'] !== '')
                <div class="muted">{{ $p['company']['address'] }}</div>
            @endif
        </td>
        <td class="title">{{ $p['title'] }}</td>
    </tr>
</table>

<h2>Employee</h2>
<table class="cols"><tbody><tr>
    <td class="left">
        <table class="grid">
            @foreach (array_slice($p['employee'], 0, 3) as [$label, $value])
                <tr><td class="muted">{{ $label }}</td><td class="amount">{{ $value }}</td></tr>
            @endforeach
        </table>
    </td>
    <td class="right">
        <table class="grid">
            @foreach (array_slice($p['employee'], 3) as [$label, $value])
                <tr><td class="muted">{{ $label }}</td><td class="amount">{{ $value }}</td></tr>
            @endforeach
        </table>
    </td>
</tr></tbody></table>

<table class="cols"><tbody><tr>
    <td class="left">
        <h2>Earnings</h2>
        <table class="grid">
            @foreach ($p['earnings'] as [$label, $amount])
                <tr><td>{{ $label }}</td><td class="amount">{{ $amount }}</td></tr>
            @endforeach
            <tr class="total"><td>Gross pay</td><td class="amount">{{ $p['gross'] }}</td></tr>
        </table>
    </td>
    <td class="right">
        <h2>Deductions</h2>
        <table class="grid">
            @forelse ($p['deductions'] as [$label, $amount])
                <tr><td>{{ $label }}</td><td class="amount">{{ $amount }}</td></tr>
            @empty
                <tr><td class="muted">None</td><td class="amount">Rs 0</td></tr>
            @endforelse
            <tr class="total"><td>Total deductions</td><td class="amount">{{ $p['total_deductions'] }}</td></tr>
        </table>

        <h2>Tax</h2>
        <table class="grid">
            <tr><td>Mode</td><td class="amount">{{ $p['tax']['mode'] }}</td></tr>
            <tr><td>Taxable pay</td><td class="amount">{{ $p['tax']['taxable_pay'] }}</td></tr>
            <tr class="total"><td>Tax</td><td class="amount">{{ $p['tax']['amount'] }}</td></tr>
        </table>
    </td>
</tr></tbody></table>

<table class="net" width="100%">
    <tr>
        <td class="label">Net pay</td>
        <td class="value">{{ $p['net'] }}</td>
    </tr>
</table>

<h2>Attendance</h2>
<table class="cols"><tbody><tr>
    <td class="left">
        <table class="grid">
            @foreach (array_slice($p['attendance'], 0, 5) as [$label, $value])
                <tr><td class="muted">{{ $label }}</td><td class="amount">{{ $value }}</td></tr>
            @endforeach
        </table>
    </td>
    <td class="right">
        <table class="grid">
            @foreach (array_slice($p['attendance'], 5) as [$label, $value])
                <tr><td class="muted">{{ $label }}</td><td class="amount">{{ $value }}</td></tr>
            @endforeach
        </table>
    </td>
</tr></tbody></table>

@if ($p['company']['footer'] !== '')
    <div class="footer">{{ $p['company']['footer'] }}</div>
@endif
</body>
</html>
