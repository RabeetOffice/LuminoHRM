<?php

use App\Support\Attendance\IngestResult;
use App\Support\Attendance\PunchIngestor;
use Carbon\CarbonImmutable;

it('parses PIN, time, status and verify, ignoring extra fields', function () {
    $parsed = PunchIngestor::parse("101\t2026-11-23 09:00:17\t0\t1\t0\t0\n");

    expect($parsed['rows'])->toBe([
        ['pin' => '101', 'punched_at' => '2026-11-23 09:00:17', 'status' => '0', 'verify' => '1'],
    ])->and($parsed['malformed'])->toBe(0);
});

it('counts malformed lines and ignores blank ones', function () {
    $parsed = PunchIngestor::parse("garbage\n\n\r\n101\tnot-a-date\t0\t1\n\t2026-11-23 09:00:00\n101\t2026-02-31 09:00:00\n102\t2026-11-23 18:30:00\t1\t1\r\n");

    expect($parsed['rows'])->toHaveCount(1)
        ->and($parsed['rows'][0]['pin'])->toBe('102')
        ->and($parsed['malformed'])->toBe(4);
});

it('treats a PIN longer than the column as malformed', function () {
    $parsed = PunchIngestor::parse(str_repeat('9', 21)."\t2026-11-23 09:00:00\t0\t1\n".str_repeat('9', 20)."\t2026-11-23 09:00:00\n");

    expect($parsed['rows'])->toHaveCount(1)->and($parsed['malformed'])->toBe(1);
});

it('treats a PIN that is not valid UTF-8 as malformed', function () {
    expect(PunchIngestor::parse("\xFF\xFE\t2026-11-23 09:00:00\n")['malformed'])->toBe(1);
});

it('cuts status and verify to the column width', function () {
    $row = PunchIngestor::parse("101\t2026-11-23 09:00:00\t".str_repeat('1', 15)."\t".str_repeat('2', 15)."\n")['rows'][0];

    expect($row['status'])->toBe(str_repeat('1', 10))->and($row['verify'])->toBe(str_repeat('2', 10));
});

it('flags punch times more than 24 hours ahead or before 2000 as clock faults', function () {
    $now = CarbonImmutable::parse('2026-11-24 12:00:00', 'Asia/Karachi');

    expect(PunchIngestor::isClockFault('2026-11-25 12:00:00', $now))->toBeFalse()
        ->and(PunchIngestor::isClockFault('2026-11-25 12:00:01', $now))->toBeTrue()
        ->and(PunchIngestor::isClockFault('2000-01-01 00:00:00', $now))->toBeFalse()
        ->and(PunchIngestor::isClockFault('1999-12-31 23:59:59', $now))->toBeTrue();
});

it('acknowledges stored plus duplicates and lists clock faults in the summary', function () {
    $result = new IngestResult(3, 2, 1, ['101 1999-01-01 00:00:00']);

    expect($result->acknowledged())->toBe(5)
        ->and($result->summary())->toBe('ATTLOG upload: 3 stored, 2 duplicate(s), 1 skipped; clock fault: 101 1999-01-01 00:00:00');
});
