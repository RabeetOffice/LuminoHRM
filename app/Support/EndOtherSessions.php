<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * After a password change or reset: sign the user out everywhere else.
 *
 * Deletes the user's rows in the sessions table (driver "database") except the
 * current one, and rotates the remember-me token so a stolen "remember me" cookie
 * stops working too. With any other session driver only the token is rotated.
 */
class EndOtherSessions
{
    /** @param  string|null  $keepSessionId  the session that stays signed in (null: end them all) */
    public static function for(User $user, ?string $keepSessionId = null): void
    {
        if (config('session.driver') === 'database') {
            $query = DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $user->getKey());

            if ($keepSessionId !== null) {
                $query->where('id', '!=', $keepSessionId);
            }

            $query->delete();
        }

        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
    }
}
