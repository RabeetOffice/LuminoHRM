<?php

namespace App\Support\Exits;

use App\Models\Employee;
use App\Models\LeaveType;
use App\Support\Leave\LeaveBalances;
use App\Support\Leave\LeaveDays;
use App\Support\Payroll\Money;

/**
 * Leave encashment of a final settlement (design 5.5).
 *
 *   LeaveEncashment::for($employee, '2026-11-18', $segment->toArray(), 30);
 *   // null (settings('payroll.leave_encashment') off), or ['type_name' => 'Annual', 'halves' => 13, 'amount' => 1950000]
 *
 * Unused days = max(0, remaining) of the encashment leave type in the leave year of the last working day, read now
 * (after the exit's leave trim); an overdrawn balance pays nothing and is never deducted. Amount =
 * Money::div(monthly gross of the profile in force on the last day x halves, 2 x basis days): taxable.
 * The type: settings('payroll.encashment_leave_type_id') when it is a paid type, else the type named "Annual" (any
 * case). None found: ['type_name' => null, 'halves' => 0, 'amount' => 0] (the calculator warns, nothing is paid).
 */
final class LeaveEncashment
{
    /**
     * @param  array{basic: int, allowances: list<array{amount: int}|object>}|array<string, mixed>  $profileSegment  ProfileSegment::toArray() of the profile in force on the last day
     * @return array{type_name: string|null, halves: int, amount: int}|null
     */
    public static function for(Employee $employee, string $lastDay, array $profileSegment, int $basisDays): ?array
    {
        if (! (bool) settings('payroll.leave_encashment', false)) {
            return null;
        }

        $type = self::leaveType();

        if ($type === null) {
            return ['type_name' => null, 'halves' => 0, 'amount' => 0];
        }

        $line = app(LeaveBalances::class)->line($employee, $type, (int) substr($lastDay, 0, 4));
        $halves = LeaveDays::halves(max(0.0, (float) $line->remaining));

        $gross = (int) ($profileSegment['basic'] ?? 0);
        foreach ((array) ($profileSegment['allowances'] ?? []) as $allowance) {
            $gross += (int) (is_array($allowance) ? ($allowance['amount'] ?? 0) : ($allowance->amount ?? 0));
        }

        $amount = $halves > 0 && $basisDays > 0 ? Money::div($gross * $halves, 2 * $basisDays) : 0;

        return ['type_name' => (string) $type->name, 'halves' => $halves, 'amount' => $amount];
    }

    /** The encashment leave type: the setting's paid type, else the type named "Annual" (ignoring case), else null. */
    public static function leaveType(): ?LeaveType
    {
        $id = settings('payroll.encashment_leave_type_id');

        if (is_numeric($id) && (int) $id > 0) {
            $type = LeaveType::query()->whereKey((int) $id)->where('is_paid', true)->first();

            if ($type !== null) {
                return $type;
            }
        }

        return LeaveType::query()->whereRaw('LOWER(name) = ?', ['annual'])->orderBy('id')->first();
    }

    /** "6.5 days" / "1 day" from halves. */
    public static function daysLabel(int $halves): string
    {
        $days = intdiv($halves, 2).($halves % 2 === 1 ? '.5' : '');

        return $days.' '.($halves === 2 ? 'day' : 'days');
    }
}
