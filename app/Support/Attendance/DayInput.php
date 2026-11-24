<?php

namespace App\Support\Attendance;

/**
 * Everything DayCalculator needs to decide one employee's day. Plain values only (no models, no clock), so the rules
 * can be tested as a table.
 *
 *   date                  "Y-m-d", the calendar day being decided
 *   shiftStart/End        "H:i" on that day; the end must be after the start (no shifts across midnight in Phase 3)
 *   graceMinutes          whole minutes after the start that still count as on time
 *   halfDayHours          worked hours below which a closed two-punch day is a half day
 *   weeklyOffs            lowercase English day names ("saturday", "sunday")
 *   isHoliday             the date is in the holiday calendar
 *   isExempt              the employee is attendance-exempt
 *   punches               naive "Y-m-d H:i:s" machine times belonging to that day (the calculator sorts, de-duplicates
 *                         and ignores any value outside the day's window, see dayCutoff)
 *   closed                the day is over (only then is a missing working day Absent, a half day decided, early
 *                         minutes counted and a single punch flagged)
 *   overtimeMinMinutes    overtime shorter than this is not recorded
 *   onLeave               approved leave covers the day; always false until the Leave phase supplies it
 *   duplicatePunchMinutes a punch less than this many minutes after the previously kept punch is a double tap and is
 *                         dropped (settings('attendance.duplicate_punch_minutes'); 0 = keep every distinct punch)
 *   dayCutoff             "H:i": the day runs from this time on `date` to this time on the next calendar day, so a
 *                         punch after midnight but before the cutoff is this day's possible clock-out
 *                         (settings('attendance.day_cutoff_time'); "00:00" = plain calendar day)
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
        public int $duplicatePunchMinutes = 0,
        public string $dayCutoff = '00:00',
    ) {}
}
