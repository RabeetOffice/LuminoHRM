<?php

use App\Http\Controllers\Iclock\IclockController;

function parse(string $body): array
{
    return (new IclockController)->parseAttlog($body);
}

it('parses PIN, time, status and verify and ignores extra fields', function () {
    expect(parse("101\t2026-11-23 09:00:17\t0\t1\t0\t0\n"))->toBe([
        ['pin' => '101', 'punched_at' => '2026-11-23 09:00:17', 'status' => '0', 'verify' => '1'],
    ]);
});

it('handles CRLF line endings', function () {
    expect(parse("101\t2026-11-23 09:00:17\t0\t1\r\n102\t2026-11-23 09:01:00\t1\t1\r\n"))->toHaveCount(2);
});

it('skips overflow dates such as 2026-02-31', function () {
    expect(parse("101\t2026-02-31 09:00:00\t0\t1\n"))->toBe([]);
});

it('skips lines with an empty PIN', function () {
    expect(parse("\t2026-11-23 09:00:17\t0\t1\n"))->toBe([]);
});

it('accepts two-field lines with empty status and verify', function () {
    expect(parse("101\t2026-11-23 09:00:17\n"))->toBe([
        ['pin' => '101', 'punched_at' => '2026-11-23 09:00:17', 'status' => '', 'verify' => ''],
    ]);
});

it('skips lines with no tab or a bad time', function () {
    expect(parse("garbage\n101\tnot-a-date\t0\t1\n"))->toBe([]);
});
