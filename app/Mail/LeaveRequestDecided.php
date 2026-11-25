<?php

namespace App\Mail;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;

/**
 * "Your leave request was approved / rejected", "Your approved leave was cancelled" and "Leave days went back to your
 * balance" (a holiday added on a leave day, Task 8): to the requester only. It names only the requester's own request
 * (type, dates, days, the note); nothing about anyone else. Queued after the commit.
 *
 * Outcomes: approved | rejected | cancelled | refunded.
 */
class LeaveRequestDecided extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public const OUTCOMES = ['approved', 'rejected', 'cancelled', 'refunded'];

    public int $tries = 3;

    public function __construct(
        public LeaveRequest $request,
        public string $recipientName,
        public string $outcome,
        public ?string $note = null,
    ) {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException("Unknown leave decision outcome [{$outcome}].");
        }

        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: match ($this->outcome) {
            'approved' => 'Your leave request was approved',
            'rejected' => 'Your leave request was rejected',
            'cancelled' => 'Your approved leave was cancelled',
            default => 'Leave days went back to your balance', // refunded
        });
    }

    public function content(): Content
    {
        $r = $this->request;

        return new Content(markdown: 'mail.leave.decided', with: [
            'recipientName' => $this->recipientName,
            'outcome' => $this->outcome,
            'typeName' => $r->type->name,
            'dates' => LeaveRequestSubmitted::dates($r->from_date->format('Y-m-d'), $r->to_date->format('Y-m-d'), $r->half),
            'days' => LeaveRequestSubmitted::days((float) $r->days),
            'stillApproved' => $r->status === 'approved',
            'cancelledFrom' => $r->cancelled_from !== null
                ? (new \DateTimeImmutable($r->cancelled_from->format('Y-m-d')))->format('D j M Y')
                : null,
            'note' => $this->note,
            'url' => url(route('leave.requests.show', $r, false)),
        ]);
    }
}
