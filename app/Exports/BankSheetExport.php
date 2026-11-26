<?php

namespace App\Exports;

use App\Models\PayrollItem;
use App\Models\PayrollRun;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The bank transfer sheet of an approved or paid run (Payroll > Runs > Bank sheet): bank-transfer-2026-11.xlsx.
 *
 * Sheet "Transfers": one row per line that is not excluded, has a net above zero and a bank account number in the
 * snapshot taken at approval (Employee Code, Name, Bank, Account Title, Account Number / IBAN as text, Amount in whole
 * rupees as a number, Reference "Salary November 2026"), then a total row. Sheet "Paid outside the sheet": the other
 * lines with a net above zero (no account number), reason "No bank account", and a total row. Excluded and zero-net
 * lines are on neither sheet.
 *
 * Bank details come only from the item's snapshot (a later change to the employee's bank account changes nothing);
 * code and name from the frozen breakdown when present, else the employee record. Writing the file runs no query.
 */
final class BankSheetExport implements Export, WithMultipleSheets
{
    public const TRANSFERS = 'Transfers';

    public const OUTSIDE = 'Paid outside the sheet';

    /** @var list<PayrollItem> */
    private array $transfers = [];

    /** @var list<PayrollItem> */
    private array $outside = [];

    /** @param  iterable<PayrollItem>  $items  the run's lines, employee loaded, in row order */
    public function __construct(private readonly PayrollRun $run, iterable $items)
    {
        foreach ($items as $item) {
            if ($item->excluded || $item->net <= 0) {
                continue;
            }

            if (trim((string) $item->account_number) !== '') {
                $this->transfers[] = $item;
            } else {
                $this->outside[] = $item;
            }
        }
    }

    public static function fileName(PayrollRun $run): string
    {
        return "bank-transfer-{$run->month}.xlsx";
    }

    public function transferCount(): int
    {
        return count($this->transfers);
    }

    public function outsideCount(): int
    {
        return count($this->outside);
    }

    /** @return list<PayrollSheet> */
    public function sheets(): array
    {
        $reference = 'Salary '.$this->run->label();

        $rows = [];
        $total = 0;
        foreach ($this->transfers as $item) {
            [$code, $name] = self::identity($item);
            $rows[] = [
                $code,
                $name,
                (string) $item->bank_name,
                (string) $item->account_title,
                trim((string) $item->account_number),
                PayrollSheet::rupees($item->net),
                $reference,
            ];
            $total += $item->net;
        }

        $outside = [];
        $outsideTotal = 0;
        foreach ($this->outside as $item) {
            [$code, $name] = self::identity($item);
            $outside[] = [$code, $name, PayrollSheet::rupees($item->net), 'No bank account'];
            $outsideTotal += $item->net;
        }

        return [
            new PayrollSheet(
                self::TRANSFERS,
                ['Employee Code', 'Name', 'Bank', 'Account Title', 'Account Number / IBAN', 'Amount', 'Reference'],
                $rows,
                ['', 'Total', '', '', '', PayrollSheet::rupees($total), ''],
                [6],
            ),
            new PayrollSheet(
                self::OUTSIDE,
                ['Employee Code', 'Name', 'Amount', 'Reason'],
                $outside,
                ['', 'Total', PayrollSheet::rupees($outsideTotal), ''],
                [3],
            ),
        ];
    }

    /**
     * Code and name as frozen in the breakdown, else from the employee record.
     *
     * @return array{0: string, 1: string}
     */
    public static function identity(PayrollItem $item): array
    {
        $frozen = is_array($item->breakdown) ? ($item->breakdown['employee'] ?? null) : null;

        return [
            (string) (is_array($frozen) && isset($frozen['code']) ? $frozen['code'] : $item->employee?->employee_code),
            (string) (is_array($frozen) && isset($frozen['name']) ? $frozen['name'] : $item->employee?->full_name),
        ];
    }
}
