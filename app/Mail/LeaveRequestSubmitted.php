<?php

namespace App\Mail;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * "A leave request waits for your decision": to the reporting manager on submit, or to every final approver
 * (LeaveRouting::finalApprovers) when the request goes straight to the final step; Task 6 sends it again with
 * $afterManager = true to the final approvers once the manager approved. It names only the requester, the type, the
 * dates, the days and the reason: nothing about anyone else, never a balance. Queued after the commit: the request
 * never waits for SMTP, and a mail failure never undoes it.
 */
class LeaveRequestSubmitted extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public LeaveRequest $request,
        public string $recipientName,
        public bool $afterManager,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Leave request from {$this->request->employee->full_name}");
    }

    public function content(): Content
    {
        $r = $this->request;

        return new Content(markdown: 'mail.leave.submitted', with: [
            'recipientName' => $this->recipientName,
            'employeeName' => $r->employee->full_name,
            'typeName' => $r->type->name,
            'dates' => self::dates($r->from_date->format('Y-m-d'), $r->to_date->format('Y-m-d'), $r->half),
            'days' => self::days((float) $r->days),
            'reason' => $r->reason,
            'afterManager' => $this->afterManager,
            'url' => self::link($r),
        ]);
    }

    /**
     * The dates of a request for a mail: "Mon 1 Dec 2026 – Wed 3 Dec 2026", "Mon 1 Dec 2026" for one full day, or
     * "Mon 1 Dec 2026, first half" / ", second half" for a half day. Inputs are "Y-m-d"; no time zone is involved.
     */
    public static function dates(string $from, string $to, ?string $half): string
    {
        $label = fn (string $d): string => (new \DateTimeImmutable($d))->format('D j M Y');

        if ($half !== null) {
            return $label($from).', '.($half === 'am' ? 'first half' : 'second half');
        }

        return $from === $to ? $label($from) : $label($from).' – '.$label($to);
    }

    /** "1 day", "0.5 day", "2.5 days". */
    public static function days(float $days): string
    {
        $text = fmod($days, 1.0) === 0.0 ? (string) (int) $days : number_format($days, 1, '.', '');

        return $text.' '.Str::plural('day', $days <= 1 ? 1 : 2);
    }

    /** The request's detail page (Task 6); My Leaves until that route exists. */
    private static function link(LeaveRequest $r): string
    {
        return Route::has('leave.requests.show')
            ? url(route('leave.requests.show', $r, false))
            : url(route('my.leaves', [], false));
    }
}
