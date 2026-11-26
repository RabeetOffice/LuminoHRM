<?php

// Shared helpers for the assignment tests (Task 3): required by each file, so they are guarded.

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Employee;
use Illuminate\Validation\ValidationException;

if (! function_exists('assertAssetInvariant')) {
    /** After every flow: an asset is `assigned` if and only if it has exactly one open assignment, and never two. */
    function assertAssetInvariant(): void
    {
        foreach (Asset::query()->get() as $asset) {
            $open = AssetAssignment::query()->where('asset_id', $asset->id)->whereNull('returned_on')->count();
            expect($open)->toBeLessThanOrEqual(1)
                ->and($asset->status === 'assigned')->toBe($open === 1, "asset {$asset->asset_code} status {$asset->status} with {$open} open");
        }
    }
}

if (! function_exists('aaPerson')) {
    /** @param  array<string, mixed>  $attrs */
    function aaPerson(array $attrs = []): Employee
    {
        return Employee::factory()->create(array_merge([
            'first_name' => 'Sara', 'last_name' => 'Ahmed', 'status' => 'active', 'joining_date' => '2025-01-15',
        ], $attrs));
    }
}

if (! function_exists('aaRefusal')) {
    /** The ValidationException messages thrown by $run, keyed by field. */
    function aaRefusal(Closure $run): array
    {
        try {
            $run();
        } catch (ValidationException $e) {
            return array_map(fn (array $m) => $m[0], $e->errors());
        }

        throw new RuntimeException('Expected a refusal, none was thrown.');
    }
}
