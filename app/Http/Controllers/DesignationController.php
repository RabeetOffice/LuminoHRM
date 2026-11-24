<?php

namespace App\Http\Controllers;

use App\Http\Requests\DesignationRequest;
use App\Models\Designation;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class DesignationController extends Controller
{
    private const DUPLICATE = 'This department already has a designation with this name.';

    public function store(DesignationRequest $request): RedirectResponse
    {
        try {
            // One transaction with the audit entry the model writes: no entry, no change.
            DB::transaction(fn () => Designation::create($request->validated()));
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['name' => self::DUPLICATE]);
        }

        return back()->with('success', 'Designation added.');
    }

    public function update(DesignationRequest $request, Designation $designation): RedirectResponse
    {
        try {
            DB::transaction(fn () => $designation->update($request->validated()));
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['name' => self::DUPLICATE]);
        }

        return back()->with('success', 'Designation updated.');
    }

    public function destroy(Designation $designation): RedirectResponse
    {
        if ($reason = $designation->deleteBlocker()) {
            return back()->with('error', $reason);
        }

        try {
            DB::transaction(fn () => $designation->delete());
        } catch (QueryException $e) {
            // Only a foreign key refusal (SQLSTATE 23000) is the friendly case; anything else is a real fault.
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            // An employee was given this designation after the check above and the restrict foreign key refused.
            return back()->with('error', $designation->deleteBlocker() ?? 'This designation is still in use.');
        }

        return back()->with('success', 'Designation deleted.');
    }
}
