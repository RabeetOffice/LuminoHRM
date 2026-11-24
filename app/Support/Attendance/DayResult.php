<?php

namespace App\Support\Attendance;

/**
 * The decided day, ready to be written to `attendances`. clockIn / clockOut are naive "Y-m-d H:i:s" strings (null when
 * there was no such punch); every marker is whole minutes, never negative.
 */
final readonly class DayResult
{
    public function __construct(
        public string $status,
        public ?string $clockIn,
        public ?string $clockOut,
        public int $workedMinutes,
        public int $lateMinutes,
        public int $earlyMinutes,
        public int $overtimeMinutes,
        public bool $needsAttention,
    ) {}
}
