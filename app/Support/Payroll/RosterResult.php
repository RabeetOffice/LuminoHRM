<?php

namespace App\Support\Payroll;

use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * Who a month's payroll run is about (PayrollRoster::forMonth):
 *
 * - $included: the people the run pays (complete Employee models with department, designation, shift, bankAccount and
 *   user loaded), ordered by employee code.
 * - $settledSeparately: people whose last working day falls in the month; they are paid through the final settlement,
 *   never by the run, and are only listed. `settlement` is the status of their final settlement for the month
 *   (draft, approved, paid) or null when none exists yet.
 * - $leftWithoutLastDay: leavers (inactive / resigned / terminated) with no last working day; never paid, listed so HR
 *   fixes the record.
 */
final readonly class RosterResult
{
    /**
     * @param  Collection<int, Employee>  $included
     * @param  list<array{id: int, name: string, code: string, last_working_day: string, settlement: string|null}>  $settledSeparately
     * @param  list<array{id: int, name: string, code: string, status: string}>  $leftWithoutLastDay
     */
    public function __construct(
        public Collection $included,
        public array $settledSeparately,
        public array $leftWithoutLastDay,
    ) {}
}
