<?php

namespace App\Mail;

use App\Models\AttendanceCorrection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Your correction request was approved / rejected": to the requester only, when the decision is final (HR approved,
 * or the manager or HR rejected). Queued, like every mail of the request flow.
 */
class CorrectionRequestDecided extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public AttendanceCorrection $correction,
        public string $recipientName,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $outcome = $this->correction->status === 'approved' ? 'approved' : 'rejected';

        return new Envelope(subject: "Your attendance correction request was {$outcome}");
    }

    public function content(): Content
    {
        $c = $this->correction;

        return new Content(markdown: 'mail.attendance.correction-decided', with: [
            'recipientName' => $this->recipientName,
            'approved' => $c->status === 'approved',
            'date' => $c->date->format('j M Y'),
            'clockIn' => CorrectionRequestSubmitted::time($c->requested_in),
            'clockOut' => CorrectionRequestSubmitted::time($c->requested_out),
            'note' => $c->decision_note,
        ]);
    }
}
