<?php

namespace App\Support\Leave;

/**
 * One employee's balance for one leave type and year, as LeaveBalances computes it. Never stored.
 *
 *   remaining = allocated + carried + adjustment − openingUsed − used
 *   available = remaining − pending
 *   overdrawn = remaining < 0
 *
 * For an unpaid type only used and pending mean anything: allocated, carried, adjustment, openingUsed and remaining
 * are 0, available is INF (never limited; never shown) and overdrawn is false. Every figure is a multiple of 0.5.
 */
final readonly class BalanceLine
{
    public function __construct(
        public int $typeId,
        public string $typeName,
        public string $colour,
        public int $year,
        public bool $isPaid,
        public float $allocated,
        public float $carried,
        public float $adjustment,
        public float $openingUsed,
        public float $used,
        public float $pending,
        public float $remaining,
        public float $available,
        public bool $overdrawn,
        /** True when the year has no balance row yet and $allocated is allocationFor() (a projection; nothing written). */
        public bool $projected = false,
    ) {}

    /** allocated + carried + adjustment: what the person may take this year before opening used and leave days. */
    public function entitlement(): float
    {
        return LeaveDays::fromHalves(
            LeaveDays::halves($this->allocated) + LeaveDays::halves($this->carried) + LeaveDays::halves($this->adjustment),
        );
    }

    /**
     * For page props: numbers as floats, plus display strings. An unpaid type has remaining / available / entitlement
     * null (INF cannot be sent as JSON) and unlimited true.
     *
     * label: "9.5 of 14" (remaining of entitlement), "3 used" for an unpaid type.
     *
     * @return array{type_id: int, type_name: string, colour: string, year: int, is_paid: bool, unlimited: bool, allocated: float, carried: float, adjustment: float, opening_used: float, used: float, pending: float, remaining: float|null, available: float|null, entitlement: float|null, overdrawn: bool, projected: bool, label: string}
     */
    public function toArray(): array
    {
        return [
            'type_id' => $this->typeId,
            'type_name' => $this->typeName,
            'colour' => $this->colour,
            'year' => $this->year,
            'is_paid' => $this->isPaid,
            'unlimited' => ! $this->isPaid,
            'allocated' => $this->allocated,
            'carried' => $this->carried,
            'adjustment' => $this->adjustment,
            'opening_used' => $this->openingUsed,
            'used' => $this->used,
            'pending' => $this->pending,
            'remaining' => $this->isPaid ? $this->remaining : null,
            'available' => $this->isPaid ? $this->available : null,
            'entitlement' => $this->isPaid ? $this->entitlement() : null,
            'overdrawn' => $this->overdrawn,
            'projected' => $this->projected,
            'label' => $this->isPaid
                ? self::format($this->remaining).' of '.self::format($this->entitlement())
                : self::format($this->used).' used',
        ];
    }

    /** A day amount with at most one decimal: 14 → "14", 9.5 → "9.5", -1.5 → "-1.5". */
    public static function format(float $days): string
    {
        $halves = LeaveDays::halves($days);

        return $halves % 2 === 0 ? (string) intdiv($halves, 2) : number_format($halves / 2, 1, '.', '');
    }
}
