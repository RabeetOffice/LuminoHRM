{{-- An experience letter (App\Support\Exits\ExperienceLetter::pdf): the stored, already filled-in text. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Experience letter</title>
<style>
    @page { margin: 40px 50px; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { font-size: 12px; color: #1f2937; margin: 0; line-height: 1.6; }
    .muted { color: #6b7280; }
    .header { width: 100%; border-bottom: 2px solid #10b77f; padding-bottom: 10px; margin-bottom: 26px; }
    .logo { max-height: 50px; max-width: 180px; }
    .company { font-size: 17px; font-weight: bold; }
    .date { text-align: right; margin-bottom: 22px; }
    .title { font-size: 14px; font-weight: bold; text-align: center; margin-bottom: 18px; text-transform: uppercase; letter-spacing: 1px; }
    .body { white-space: pre-line; }
</style>
</head>
<body>
<table class="header">
    <tr>
        <td>
            @if ($l['logo'])
                <img class="logo" src="{{ $l['logo'] }}" alt=""><br>
            @endif
            @if ($l['company'] !== '')
                <div class="company">{{ $l['company'] }}</div>
            @endif
            @if ($l['address'] !== '')
                <div class="muted">{{ $l['address'] }}</div>
            @endif
        </td>
    </tr>
</table>
<div class="date">{{ $l['date'] }}</div>
<div class="title">Experience letter</div>
<div class="body">{{ $l['text'] }}</div>
</body>
</html>
