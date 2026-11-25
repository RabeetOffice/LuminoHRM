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
 * Outcomes:
 *   approved | rejected  a decision on the request;
 *   cancelled            approved leave cancelled (HR from a date on, or a holiday covering every day of it: pass the
 *                        reason as the note);
 *   refunded             some days of approved leave went back to the balance (a holiday on a leave day);
 *   withdrawn            an OPEN request cancelled by the system (for example the employment ended), reason as note.
 * The Days line is left out when the request holds no days.
 */
class LeaveRequestDecided extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public const OUTCOMES = ['approved', 'rejected', 'cancelled', 'refunded', 'withdrawn'];

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
            'withdrawn' => 'Your leave request was cancelled',
            default => 'Leave days went back to your balance', // refunded
        });
    }

    public function content(): Content
    {
        $r = $this->request;
        $days = (float) $r->days;

        return new Content(markdown: 'mail.leave.decided', with: [
            // Typed text goes through Markdown after Blade: escaped (LeaveRequestSubmitted::plain) so it stays literal.
            // Keys differ from the public properties: those are passed to the view too and would win, unescaped.
            'greetName' => LeaveRequestSubmitted::plain($this->recipientName),
            'outcome' => $this->outcome,
            'panel' => self::panel(array_filter([
                'Type' => $r->type->name,
                'Dates' => LeaveRequestSubmitted::dates($r->from_date->format('Y-m-d'), $r->to_date->format('Y-m-d'), $r->half),
                'Days' => $days > 0 ? LeaveRequestSubmitted::days($days) : null, // never a "Days: 0" line
                'Note' => $this->note,
            ], fn (?string $v): bool => $v !== null && trim($v) !== '')),
            'stillApproved' => $r->status === 'approved',
            'cancelledFrom' => $r->cancelled_from !== null
                ? (new \DateTimeImmutable($r->cancelled_from->format('Y-m-d')))->format('D j M Y')
                : null,
            'url' => url(route('leave.requests.show', $r, false)),
        ]);
    }

    /**
     * The panel's lines ("**Type:** Annual<br>" …), output raw by the template: each value is Markdown-escaped
     * (LeaveRequestSubmitted::plain) and then HTML-escaped. Every line starts with "**", never with "<br>" (CommonMark
     * would take such a line as raw HTML and skip the Markdown escaping).
     *
     * @param  array<string, string>  $facts  label => text
     */
    private static function panel(array $facts): string
    {
        $lines = [];

        foreach ($facts as $label => $text) {
            $lines[] = "**{$label}:** ".e(LeaveRequestSubmitted::plain($text));
        }

        return implode("<br>\n", $lines);
    }
}
