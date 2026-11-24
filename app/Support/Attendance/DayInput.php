<?php

namespace App\Support\Attendance;

/**
 * Everything DayCalculator needs to decide one employee's day. Plain values only (no models, no clock), so the rules
 * can be tested as a table.
 *
 *   date               "Y-m-d", the calendar day being decided
 *   shiftStart/End     "H:i" on that day; the end must be after the start (no shifts across midnight in Phase 3)
 *   graceMinutes       minutes after the start that still count as on time
 *   halfDayHours       worked hours below which a two-punch day is a half day
 *   weeklyOffs         lowercase English day names ("saturday", "sunday")
 *   isHoliday          the date is in the holiday calendar
 *   isExempt           the employee is attendance-exempt
 *   punches            naive "Y-m-d H:i:s" machine times for that date, sorted (the calculator sorts and de-duplicates
 *                      again, and ignores any value not on `date`)
 *   closed             the day is over (only then is a missing working day Absent and a single punch flagged)
 *   overtimeMinMinutes overtime shorter than this is not recorded
 *   onLeave            approved leave covers the day; always false until the Leave phase supplies it
 */
final readonly class DayInput
{
    /**
     * @param  list<string>  $weeklyOffs
     * @param  list<string>  $punches
     */
    public function __construct(
        public string $date,
        public string $shiftStart,
        public string $shiftEnd,
        public int $graceMinutes,
        public float $halfDayHours,
        public array $weeklyOffs,
        public bool $isHoliday,
        public bool $isExempt,
        public array $punches,
        public bool $closed,
        public int $overtimeMinMinutes,
        public bool $onLeave = false,
    ) {}
}
