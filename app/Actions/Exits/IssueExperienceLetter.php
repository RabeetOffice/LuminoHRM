<?php

namespace App\Actions\Exits;

use App\Models\Resignation;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Exits\ExitRouting;
use App\Support\Exits\ExitStates;
use App\Support\Exits\ExperienceLetter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issues (or re-issues) the experience letter of an approved exit: renders the Settings > Exit template for the person and
 * stores the text, who issued it and when on the resignation. Re-issuing replaces the text (the audit entry carries the old
 * and new text length, not the text). Lock order: employee row, then the resignation row.
 */
class IssueExperienceLetter
{
    public const NOT_APPROVED = 'The exit must be approved before a letter is issued.';

    public const OWN = 'You cannot issue your own experience letter.';

    /**
     * @throws ValidationException
     * @throws AuthorizationException exit.clearance is missing, or an Admin / HR person's letter and the user is not an Admin
     */
    public function handle(User $by, Resignation $resignation): Resignation
    {
        return DB::transaction(function () use ($by, $resignation): Resignation {
            $employee = ExitGuards::lockEmployee((int) $resignation->employee_id);
            $locked = ExitGuards::lockResignation((int) $resignation->id, $employee);
            $routing = new ExitRouting;

            if (! $by->can('exit.clearance')) {
                throw new AuthorizationException;
            }

            if ($routing->isOwn($by, $locked)) {
                ExitGuards::refuse(self::OWN);
            }

            if (! $routing->mayManage($by, $locked)) {
                throw new AuthorizationException;
            }

            if ($locked->status !== ExitStates::APPROVED) {
                ExitGuards::refuse(self::NOT_APPROVED);
            }

            $template = settings('exit.experience_template');
            $template = is_string($template) && trim($template) !== '' ? $template : (string) config('hrm.defaults.exit.experience_template');
            $text = ExperienceLetter::render($template, $locked);
            $oldLength = $locked->letter_text === null ? null : mb_strlen($locked->letter_text);

            $locked->letter_text = $text;
            $locked->letter_issued_by = $by->id;
            $locked->letter_issued_at = now();
            $locked->save();

            Audit::record('exit', 'Experience letter issued', $locked, [
                ...ExitGuards::context($locked, $employee),
                'reissue' => $oldLength !== null,
                'old_length' => $oldLength,
                'new_length' => mb_strlen($text),
            ], $by);

            return $locked->refresh();
        }, attempts: 3);
    }
}
