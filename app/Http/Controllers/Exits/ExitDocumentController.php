<?php

namespace App\Http\Controllers\Exits;

use App\Actions\Exits\IssueExperienceLetter;
use App\Http\Controllers\Controller;
use App\Models\FinalSettlement;
use App\Models\Resignation;
use App\Models\User;
use App\Support\Exits\ExitRouting;
use App\Support\Exits\ExperienceLetter;
use App\Support\Exits\SettlementStatementPdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The exit documents.
 *
 *   GET  /exit/settlements/{settlement}/statement   statement PDF for Admin / HR (exit.clearance + payroll.view; routes)
 *   GET  /my/resignation/{resignation}/statement    the employee's own statement, approved / paid settlements only
 *   POST /exit/{resignation}/letter                 issue or re-issue the experience letter (exit.clearance)
 *   GET  /exit/{resignation}/letter                 the issued letter PDF for Admin / HR
 *   GET  /my/resignation/{resignation}/letter       the employee's own letter, once issued
 *
 * Ids go through ExitRouting::findVisible (403 on null); the own routes also insist the exit is the caller's own. A statement
 * of a draft or a letter not yet issued is 404 with the refusal text.
 */
class ExitDocumentController extends Controller
{
    public function statement(Request $request, FinalSettlement $settlement): Response
    {
        $resignation = (new ExitRouting)->findVisible($this->user($request), (int) $settlement->resignation_id);
        abort_if($resignation === null, 403);

        return $this->statementResponse($settlement);
    }

    public function myStatement(Request $request, Resignation $resignation): Response
    {
        $this->ownOrForbid($request, $resignation);
        $settlement = $resignation->settlement;

        abort_if($settlement === null, 404, SettlementStatementPdf::NOT_READY);

        return $this->statementResponse($settlement);
    }

    public function issue(Request $request, Resignation $resignation, IssueExperienceLetter $issue): RedirectResponse
    {
        abort_if((new ExitRouting)->findVisible($this->user($request), (int) $resignation->id) === null, 403);

        $issue->handle($this->user($request), $resignation);

        return back()->with('success', 'Experience letter issued.');
    }

    public function letter(Request $request, Resignation $resignation): Response
    {
        abort_if((new ExitRouting)->findVisible($this->user($request), (int) $resignation->id) === null, 403);

        return $this->letterResponse($resignation);
    }

    public function myLetter(Request $request, Resignation $resignation): Response
    {
        $this->ownOrForbid($request, $resignation);

        return $this->letterResponse($resignation);
    }

    private function statementResponse(FinalSettlement $settlement): Response
    {
        abort_unless($settlement->isFrozen(), 404, SettlementStatementPdf::NOT_READY);

        $path = SettlementStatementPdf::pathOrRender($settlement);

        return SettlementStatementPdf::disk()->response($path, SettlementStatementPdf::filename($settlement), $this->headers(), 'inline');
    }

    private function letterResponse(Resignation $resignation): Response
    {
        abort_if($resignation->letter_text === null || $resignation->letter_issued_at === null, 404, ExperienceLetter::NOT_ISSUED);

        $resignation->loadMissing('employee');

        return response(ExperienceLetter::pdf($resignation), 200, [
            ...$this->headers(),
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', ExperienceLetter::filename($resignation)).'"',
        ]);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    private function ownOrForbid(Request $request, Resignation $resignation): void
    {
        abort_unless((new ExitRouting)->isOwn($this->user($request), $resignation), 403);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
