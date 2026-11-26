<?php

namespace App\Mail;

use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Support\Payroll\Money;
use App\Support\Payroll\PayslipPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Your payslip for November 2026": to the employee whose line it is, with the payslip PDF attached
 * (payslip-2026-11-LD-0007.pdf; default, owner may change: link only). It names only that person's own pay (the net
 * from the frozen breakdown) and links to My Payslips; nothing about anyone else. Queued after the commit.
 *
 * The attachment is read when the mail is built: a missing file is rendered again from the same stored data
 * (PayslipPdf::pathOrRender), so it is identical to the first one.
 */
class PayslipIssued extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public PayrollItem $item)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your payslip for '.PayrollRun::monthLabel($this->item->run->month));
    }

    public function content(): Content
    {
        $b = $this->item->breakdown ?? [];

        return new Content(markdown: 'mail.payroll.payslip', with: [
            // Typed text goes through Markdown after Blade: escaped so it stays literal (LeaveRequestSubmitted::plain).
            'greetName' => LeaveRequestSubmitted::plain((string) ($b['employee']['name'] ?? '')),
            'monthLabel' => PayrollRun::monthLabel($this->item->run->month),
            'netPay' => LeaveRequestSubmitted::plain(Money::format((int) ($b['totals']['net'] ?? 0))),
            'url' => url(route('my.payslips', [], false)),
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        $item = $this->item;

        return [
            Attachment::fromData(fn (): string => (string) PayslipPdf::disk()->get(PayslipPdf::pathOrRender($item)), PayslipPdf::filename($item))
                ->withMime('application/pdf'),
        ];
    }
}
