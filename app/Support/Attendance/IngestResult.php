<?php

namespace App\Support\Attendance;

/**
 * What one ATTLOG upload did. stored: new punches written. duplicates: punches already stored (or repeated in the same
 * body), which count as acknowledged. skipped: malformed lines and clock faults, never stored and never acknowledged.
 * clockFaults: the skipped "PIN time" pairs whose time was impossible (for device_logs.summary).
 */
final readonly class IngestResult
{
    /** @param  list<string>  $clockFaults */
    public function __construct(
        public int $stored,
        public int $duplicates,
        public int $skipped,
        public array $clockFaults = [],
    ) {}

    /** The number the machine is told it may forget: every punch that is now in device_punches. */
    public function acknowledged(): int
    {
        return $this->stored + $this->duplicates;
    }

    public function summary(): string
    {
        $summary = "ATTLOG upload: {$this->stored} stored, {$this->duplicates} duplicate(s), {$this->skipped} skipped";

        if ($this->clockFaults !== []) {
            $summary .= '; clock fault: '.implode(', ', $this->clockFaults);
        }

        return $summary;
    }
}
