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
 *   punches               naive "Y-m-d H:i:s" machine times belonging to that day (grouped by punch_date); the calculator
 *                         sorts, de-duplicates and collapses double taps, and trusts every value given
 *   closed                the day is over (only then is a missing working day Absent, a half day decided, early
 *                         minutes counted and a single punch flagged)
 *   overtimeMinMinutes    overtime shorter than this is not recorded
 *   onLeave               approved leave covers the day; always false until the Leave phase supplies it
 *   duplicatePunchMinutes punches less than this many minutes after the previous punch form one cluster (a double tap) (settings('attendance.duplicate_punch_minutes'); 0 = keep every distinct punch)
 *   dayCutoff             "H:i" (settings('attendance.day_cutoff_time')): validated only. It decided each punch's day
 *                         when the punch was stored; the calculator no longer filters by it, so changing the cutoff
 *                         later affects new punches only
 *   closePairRule         true for machine punches: exactly two kept punches less than 60 minutes apart on a working
 *                         day are a single punch (a slow double tap or a forgotten punch). False for times a person
 *                         entered (a manual correction), which are taken literally: 09:00-09:40 is 40 minutes worked
 *   singleRole            null for machine punches (one kept punch is a clock-in before the shift midpoint, a clock-out
 *                         at or after it). 'in' or 'out' when a person entered exactly one time and said which it is:
 *                         that one kept punch is then always the clock-in ('in': the late rule applies) or always the
 *                         clock-out ('out': never late), whatever the midpoint. Ignored with two or more kept punches
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
        public bool $closePairRule = true,
        public ?string $singleRole = null,
    ) {}
}
