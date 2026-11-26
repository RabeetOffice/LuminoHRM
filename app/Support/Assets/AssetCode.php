<?php

namespace App\Support\Assets;

use App\Models\Asset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Hands out asset codes: AST-0001, AST-0002, ... (zero-padded to 4 digits, growing past AST-9999). Works exactly like
 * App\Support\EmployeeCode (read the existing codes FOR UPDATE and save the row in ONE transaction; on a lost race the
 * unique index refuses the INSERT, the caller asks isCodeConflict() and runs the transaction again).
 *
 *   $asset = DB::transaction(fn () => Asset::create([...$data, 'asset_code' => AssetCode::next()]), attempts: 3);
 *
 * SQLite (the test database) ignores row locks, so tests can only prove the backstop, not the waiting.
 */
class AssetCode
{
    public const PREFIX = 'AST-';

    /** What a typed code must look like (AST- and at least four digits). */
    public const PATTERN = '/^AST-\d{4,}$/';

    /** The next free code. Call it inside the transaction that will save the asset. */
    public static function next(): string
    {
        return DB::transaction(function () {
            $highest = 0;

            foreach (self::lockedCodes()->pluck('asset_code') as $code) {
                if (preg_match('/^AST-(\d+)$/', (string) $code, $m) === 1) {
                    $highest = max($highest, (int) $m[1]);
                }
            }

            return self::PREFIX.str_pad((string) ($highest + 1), 4, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Every existing code, read with FOR UPDATE so concurrent callers queue up behind the first.
     *
     * @return Builder<Asset>
     */
    public static function lockedCodes(): Builder
    {
        return Asset::query()->select('asset_code')->where('asset_code', 'like', self::PREFIX.'%')->lockForUpdate();
    }

    /**
     * True when the unique violation is on the asset code (not the serial number). Reads the driver's own message
     * ("UNIQUE constraint failed: assets.asset_code" on SQLite, "... for key 'assets_asset_code_unique'" on MariaDB).
     */
    public static function isCodeConflict(UniqueConstraintViolationException $e): bool
    {
        $driverMessage = (string) ($e->errorInfo[2] ?? '');

        return str_contains($driverMessage, 'assets.asset_code') || str_contains($driverMessage, 'assets_asset_code_unique');
    }
}
