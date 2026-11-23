<?php

namespace App\Http\Controllers;

use App\Http\Requests\DesignationRequest;
use App\Models\Designation;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;

class DesignationController extends Controller
{
    private const DUPLICATE = 'This department already has a designation with this name.';

    public function store(DesignationRequest $request): RedirectResponse
    {
        try {
            Designation::create($request->validated());
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['name' => self::DUPLICATE]);
        }

        return back()->with('success', 'Designation added.');
    }

    public function update(DesignationRequest $request, Designation $designation): RedirectResponse
    {
        try {
            $designation->update($request->validated());
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
            $designation->delete();
        } catch (QueryException) {
            // A foreign key (employees, from Phase 2) still references this designation.
            return back()->with('error', 'This designation is still in use.');
        }

        return back()->with('success', 'Designation deleted.');
    }
}
