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
 * "A correction request waits for your decision": to the reporting manager on submit, or to every active Admin / HR
 * login when the request goes to HR (straight away, or after the manager approved). It names only the requester, the
 * day and the requested times: nothing about anyone else. Queued: the request never waits for SMTP, and a mail
 * failure never undoes it.
 */
class CorrectionRequestSubmitted extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public AttendanceCorrection $correction,
        public string $recipientName,
        public bool $afterManager,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $who = $this->correction->employee->full_name;

        return new Envelope(subject: "Attendance correction request from {$who}");
    }

    public function content(): Content
    {
        $c = $this->correction;

        return new Content(markdown: 'mail.attendance.correction-submitted', with: [
            'recipientName' => $this->recipientName,
            'employeeName' => $c->employee->full_name,
            'date' => $c->date->format('j M Y'),
            'clockIn' => self::side($c->requested_in),
            'clockOut' => self::side($c->requested_out),
            'reason' => $c->reason,
            'afterManager' => $this->afterManager,
            'url' => url(route('attendance.corrections.index', [], false)),
        ]);
    }

    /**
     * One requested side for a mail: "9:00 AM (changed)" for a time the employee asked for, "keeps recorded" for a
     * side left empty (it keeps whatever is recorded when the request is approved).
     */
    public static function side(?string $hm): string
    {
        return $hm === null ? 'keeps recorded' : self::time($hm).' (changed)';
    }

    /** "09:05" as "9:05 AM"; "–" when not given. */
    public static function time(?string $hm): string
    {
        if ($hm === null || preg_match('/^(\d{2}):(\d{2})$/', $hm, $m) !== 1) {
            return '–';
        }

        $h = (int) $m[1];

        return sprintf('%d:%s %s', $h % 12 === 0 ? 12 : $h % 12, $m[2], $h < 12 ? 'AM' : 'PM');
    }
}
