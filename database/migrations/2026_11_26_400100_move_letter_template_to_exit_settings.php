<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6c: the "Letter templates" settings group becomes "Exit". A saved experience_template moves from group
 * `letters` to group `exit` (same key, same JSON value), so nobody loses an edited template. Idempotent: a row already
 * in `exit` is kept (the newer value wins), and the old row is removed only after the copy. down() moves it back.
 *
 * Data only; no schema change. Settings rows are (group, key, value JSON) with a unique (group, key).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->move('letters', 'exit', ['experience_template']);
    }

    public function down(): void
    {
        $this->move('exit', 'letters', ['experience_template']);
    }

    /** @param  list<string>  $keys  only these keys move */
    private function move(string $from, string $to, array $keys): void
    {
        DB::transaction(function () use ($from, $to, $keys): void {
            $rows = DB::table('settings')->where('group', $from)->whereIn('key', $keys)->get();

            foreach ($rows as $row) {
                $exists = DB::table('settings')->where('group', $to)->where('key', $row->key)->exists();

                if (! $exists) {
                    DB::table('settings')->insert([
                        'group' => $to,
                        'key' => $row->key,
                        'value' => $row->value,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ]);
                }

                DB::table('settings')->where('id', $row->id)->delete();
            }
        });
    }
};
