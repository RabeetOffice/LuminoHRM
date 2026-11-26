<?php

namespace App\Actions\Assets;

use App\Models\AssetAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Change the two notes of an assignment, open or returned (the only thing a returned assignment lets change). ONE
 * transaction whose first statement locks the assignment row; the asset and employee rows are not needed (no status,
 * holder or date changes here, and there is no undo of an assignment: return it the same day and assign again).
 *
 * Refusals (ValidationException): a note over 500 characters on [note] / [return_note]; a return note on an assignment
 * that is still open [return_note].
 *
 * Audit `Asset assignment note changed` (area assets, subject the employee): `assignment`, `asset_id`, `code`, and `old` /
 * `attributes` of the notes that changed. Nothing is written when nothing changes.
 */
class EditAssignmentNote
{
    public const NOT_RETURNED_YET = 'This asset has not been returned yet, so it has no return note.';

    private bool $changed = false;

    public function changed(): bool
    {
        return $this->changed;
    }

    public function handle(User $by, AssetAssignment $a, ?string $note, ?string $returnNote): AssetAssignment
    {
        $this->changed = false;
        $id = $a->getKey();

        return DB::transaction(function () use ($by, $id, $note, $returnNote): AssetAssignment {
            $current = AssetAssignment::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            $note = AssignAsset::cleanNote($note, 'note');
            $returnNote = AssignAsset::cleanNote($returnNote, 'return_note');

            if ($current->isOpen() && $returnNote !== null) {
                throw ValidationException::withMessages(['return_note' => self::NOT_RETURNED_YET]);
            }

            $old = ['note' => $current->note, 'return_note' => $current->return_note];
            $current->note = $note;
            $current->return_note = $returnNote;

            $dirty = array_keys($current->getDirty());

            if ($dirty === []) {
                return $current;
            }

            $current->save();
            $this->changed = true;

            $current->loadMissing('asset:id,asset_code');

            Audit::record('assets', 'Asset assignment note changed', Employee::query()->findOrFail($current->employee_id), [
                'assignment' => $current->id,
                'asset_id' => $current->asset_id,
                'code' => $current->asset?->asset_code,
                'old' => array_intersect_key($old, array_flip($dirty)),
                'attributes' => array_intersect_key(['note' => $note, 'return_note' => $returnNote], array_flip($dirty)),
            ], $by);

            return $current;
        }, 3);
    }
}
