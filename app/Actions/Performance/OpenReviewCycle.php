<?php

namespace App\Actions\Performance;

use App\Models\Employee;
use App\Models\Kpi;
use App\Models\Review;
use App\Models\ReviewCycle;
use App\Models\ReviewScore;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Audit\Audit;
use App\Support\Performance\ReviewEligibility;
use App\Support\Performance\ReviewPeriods;
use App\Support\Performance\ReviewStates;
use App\Support\Settings;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opens a review cycle for a period of the setting's type, and creates a review (with its frozen KPI copy) for everyone
 * eligible at that moment. One transaction: every cycle row is locked first (the overlap check sees every cycle), and no
 * review row is read before that. The unique indexes on period_key and (period_start, period_end) are the backstop against
 * two opens at once; a collision becomes the "already exists" refusal.
 *
 * Refusals are ValidationExceptions on `period` (the due date ones on `due_on`), with the texts below.
 */
class OpenReviewCycle
{
    public const UNKNOWN = 'Choose a period.';

    public const FUTURE = 'That period has not started yet.';

    public const NOBODY = 'Nobody can be reviewed yet. Add KPIs first.';

    public const BAD_DATE = 'Enter a valid date.';

    public static function existsMessage(string $name): string
    {
        return "A cycle already exists for {$name}.";
    }

    public static function overlapMessage(string $name): string
    {
        return "That period overlaps {$name}.";
    }

    public static function dueBeforeEndMessage(string $periodEnd): string
    {
        return 'The due date cannot be before the period ends ('.self::displayDate($periodEnd).').';
    }

    /**
     * The returned cycle carries `no_kpis` (a relation-style value, never saved): the people who meet the period rules but
     * have no active KPI, so the flash and the cycle page can name them.
     */
    public function handle(User $by, string $periodKey, ?string $dueOn): ReviewCycle
    {
        $type = ReviewPeriods::type();
        $label = ReviewPeriods::parse($type, $periodKey)['label'] ?? $periodKey;

        try {
            return DB::transaction(fn (): ReviewCycle => $this->open($by, $type, $periodKey, $dueOn), 3);
        } catch (QueryException $e) {
            if (! self::isUniqueViolation($e)) {
                throw $e;
            }

            // Another open of the same period committed between our check and our insert.
            throw ValidationException::withMessages(['period' => self::existsMessage($label)]);
        }
    }

    private function open(User $by, string $type, string $periodKey, ?string $dueOn): ReviewCycle
    {
        // Every cycle row, locked: the overlap check below sees every cycle, and two opens queue here.
        $cycles = ReviewCycle::query()->orderBy('id')->lockForUpdate()->get();

        $today = app(AttendanceClock::class)->attendanceToday();
        $period = ReviewPeriods::find($type, $periodKey, $today);

        if ($period === null) {
            throw ValidationException::withMessages([
                'period' => ReviewPeriods::isFuture($type, $periodKey, $today) ? self::FUTURE : self::UNKNOWN,
            ]);
        }

        foreach ($cycles as $cycle) {
            if ($cycle->period_key === $period['key']) {
                throw ValidationException::withMessages(['period' => self::existsMessage($cycle->name)]);
            }
        }

        foreach ($cycles as $cycle) {
            if (ReviewPeriods::overlaps($period['start'], $period['end'], $cycle->period_start->format('Y-m-d'), $cycle->period_end->format('Y-m-d'))) {
                throw ValidationException::withMessages(['period' => self::overlapMessage($cycle->name)]);
            }
        }

        $due = self::dueDate($dueOn, $period['end']);

        $found = ReviewEligibility::forPeriod($period['end']);

        if ($found['eligible']->isEmpty()) {
            throw ValidationException::withMessages(['period' => self::NOBODY]);
        }

        $cycle = ReviewCycle::query()->create([
            'name' => $period['label'],
            'period_type' => $type,
            'period_key' => $period['key'],
            'period_start' => $period['start'],
            'period_end' => $period['end'],
            'due_on' => $due,
            'status' => 'open',
            'self_review_enabled' => (bool) Settings::get('performance.self_review_enabled', true),
            'opened_by' => $by->id,
        ]);

        foreach ($found['eligible'] as $employee) {
            self::createReview($cycle, $employee, $found['kpis'][$employee->id]);
        }

        $noKpis = $found['no_kpis'];

        Audit::record('performance', 'Review cycle opened', $cycle, [
            'name' => $cycle->name,
            'period_key' => $cycle->period_key,
            'period_start' => $period['start'],
            'period_end' => $period['end'],
            'due_on' => $due,
            'reviews' => $found['eligible']->count(),
            'no_kpis' => ['count' => $noKpis->count(), 'employee_ids' => $noKpis->pluck('id')->values()->all()],
            'self_review_enabled' => $cycle->self_review_enabled,
        ], $by);

        $cycle->setRelation('no_kpis', $noKpis);

        return $cycle;
    }

    /**
     * Creates one person's review in $cycle with the frozen copy of $kpis (in the order given: designation ones first). The
     * reviewer is the person's manager now, never the person themselves; none means HR. The caller holds the cycle lock.
     *
     * @param  Collection<int, Kpi>  $kpis
     */
    public static function createReview(ReviewCycle $cycle, Employee $employee, Collection $kpis): Review
    {
        $reviewer = $employee->manager_id !== null && $employee->manager_id !== $employee->id ? $employee->manager_id : null;

        $review = Review::query()->create([
            'review_cycle_id' => $cycle->id,
            'employee_id' => $employee->id,
            'reviewer_id' => $reviewer,
            'status' => ReviewStates::initial($cycle),
        ]);

        $stamp = now();
        $rows = [];

        foreach ($kpis->values() as $i => $kpi) {
            $rows[] = [
                'review_id' => $review->id,
                'kpi_id' => $kpi->id,
                'name' => $kpi->name,
                'description' => $kpi->description,
                'weight' => $kpi->weight,
                'sort_order' => $i + 1,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ];
        }

        if ($rows !== []) {
            ReviewScore::query()->insert($rows);
        }

        return $review;
    }

    /** The due date as "Y-m-d": the default when none is given, else a real date on or after the period end. */
    public static function dueDate(?string $dueOn, string $periodEnd): string
    {
        $dueOn = $dueOn === null ? '' : trim($dueOn);

        if ($dueOn === '') {
            return ReviewPeriods::dueDefault($periodEnd);
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $dueOn);

        if ($parsed === false || $parsed->format('Y-m-d') !== $dueOn) {
            throw ValidationException::withMessages(['due_on' => self::BAD_DATE]);
        }

        if ($dueOn < $periodEnd) {
            throw ValidationException::withMessages(['due_on' => self::dueBeforeEndMessage($periodEnd)]);
        }

        return $dueOn;
    }

    /** "30 Sep 2026", for messages. */
    public static function displayDate(string $ymd): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);

        return $parsed === false ? $ymd : $parsed->format('j M Y');
    }

    private static function isUniqueViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return $e->getCode() === '23000' && (str_contains($message, 'unique') || str_contains($message, 'duplicate'));
    }
}
