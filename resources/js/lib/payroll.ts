/**
 * Payroll money on the page. Money is always an integer number of paisa (1 rupee = 100 paisa); it is never a float
 * and is turned into text only for display. Percentages are integer basis points (500 = 5.00%). The server's
 * App\Support\Payroll\Money gives the same strings ("Rs 64,930").
 */

/** "Rs 64,930" (whole rupees, Western grouping); "Rs 64,930.50" only when paisa appear; "-Rs 500" below zero. */
export function formatMoney(paisa: number): string {
    const sign = paisa < 0 ? '-' : '';
    const abs = Math.abs(Math.trunc(paisa));
    const rupees = Math.floor(abs / 100);
    const cents = abs % 100;
    let text = `Rs ${groupDigits(String(rupees))}`;

    if (cents !== 0) {
        text += `.${String(cents).padStart(2, '0')}`;
    }

    return sign + text;
}

/** Whole rupees with thousands separators and no "Rs": 6493000 paisa -> "64,930". For inputs. */
export function rupeesText(paisa: number): string {
    return groupDigits(String(Math.floor(Math.abs(Math.trunc(paisa)) / 100)));
}

/** "1234567" -> "1,234,567" (Western grouping, like PHP's number_format). */
export function groupDigits(digits: string): string {
    return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

/** Largest whole-rupee amount a form accepts (the server's limit). */
export const MAX_RUPEES = 99_999_999;

/**
 * A typed whole-rupee amount ("64,930", "64930", " 1,000 ") as a whole number of rupees, or null when it is not one
 * (empty, decimals such as "1,234.5", a sign, letters, misplaced commas, above MAX_RUPEES).
 */
export function parseRupees(text: string): number | null {
    const value = text.trim();

    if (!/^(\d{1,3}(,\d{3})*|\d+)$/.test(value)) {
        return null;
    }

    const rupees = Number(value.replace(/,/g, ''));

    return Number.isSafeInteger(rupees) && rupees <= MAX_RUPEES ? rupees : null;
}

/** A typed whole-rupee amount in paisa (exact: integer maths only), or null when it is not whole rupees. */
export function rupeesToPaisa(text: string): number | null {
    const rupees = parseRupees(text);

    return rupees === null ? null : rupees * 100;
}

/**
 * A typed percentage ("5", "5.25", "100") in basis points (500, 525, 10000), or null: at most two decimals, 0 to 100.
 * String arithmetic only, so "5.10" is exactly 510 (never 509.99...). Matches the server's Money::bp.
 */
export function percentToBp(text: string): number | null {
    const match = /^(\d{1,3})(?:\.(\d{1,2}))?$/.exec(text.trim());

    if (match === null) {
        return null;
    }

    const points =
        Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));

    return points <= 10_000 ? points : null;
}

/** Basis points as a percentage people type and read: 525 -> "5.25", 500 -> "5", 510 -> "5.1". */
export function bpToPercent(bp: number): string {
    const whole = Math.floor(bp / 100);
    const rest = bp % 100;

    if (rest === 0) {
        return String(whole);
    }

    return `${whole}.${String(rest).padStart(2, '0').replace(/0$/, '')}`;
}

const MONTHS = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

/** "2026-11" -> "November 2026" (read as text: no Date, no time zone). Anything else comes back as given. */
export function monthLabel(month: string): string {
    const match = /^(\d{4})-(\d{2})$/.exec(month);
    const index = match ? Number(match[2]) - 1 : -1;

    if (!match || index < 0 || index > 11) {
        return month;
    }

    return `${MONTHS[index]} ${match[1]}`;
}

/** "2026-11" -> "Nov 2026". */
export function shortMonthLabel(month: string): string {
    const label = monthLabel(month);

    return label === month ? month : `${label.slice(0, 3)} ${label.slice(-4)}`;
}

export type TaxMode = 'none' | 'fixed' | 'percentage';
export type TaxBase = 'gross_taxable' | 'basic';

/** How a profile's tax reads: "5% of gross taxable", "5.25% of basic", "Rs 2,000 fixed", "None". */
export function taxLabel(
    mode: TaxMode | string,
    value: number,
    base: TaxBase | string | null,
): string {
    if (mode === 'fixed') {
        return `${formatMoney(value)} fixed`;
    }

    if (mode === 'percentage') {
        return `${bpToPercent(value)}% of ${base === 'basic' ? 'basic' : 'gross taxable'}`;
    }

    return 'None';
}

/** One allowance or fixed deduction as the salary form edits it (amount as typed, whole rupees). */
export type ComponentDraft = {
    name: string;
    type: 'allowance' | 'deduction';
    amount: string;
    is_taxable: boolean;
};

/**
 * The form's live totals in paisa: gross (basic + allowances), taxable (basic + taxable allowances) and fixed
 * deductions. An amount that is not whole rupees counts as 0.
 */
export function salaryTotals(
    basic: string,
    components: ComponentDraft[],
): { gross: number; taxable: number; deductions: number } {
    const basicPaisa = rupeesToPaisa(basic) ?? 0;
    let gross = basicPaisa;
    let taxable = basicPaisa;
    let deductions = 0;

    for (const component of components) {
        const amount = rupeesToPaisa(component.amount) ?? 0;

        if (component.type === 'allowance') {
            gross += amount;

            if (component.is_taxable) {
                taxable += amount;
            }
        } else {
            deductions += amount;
        }
    }

    return { gross, taxable, deductions };
}

/**
 * The date a new salary form starts at ("Y-m-d", string maths only): the first of this month, moved to the first of
 * the month after the latest approved payroll month when that is later, and to the joining date when that is later.
 */
export function firstOpenDate(
    today: string,
    latestLocked: string | null,
    joiningDate: string,
): string {
    let date = `${today.slice(0, 7)}-01`;

    if (latestLocked !== null && date.slice(0, 7) <= latestLocked) {
        date = `${nextMonth(latestLocked)}-01`;
    }

    return joiningDate > date ? joiningDate : date;
}

/** "2026-12" -> "2027-01". */
export function nextMonth(month: string): string {
    const year = Number(month.slice(0, 4));
    const index = Number(month.slice(5, 7));

    return index === 12
        ? `${year + 1}-01`
        : `${year}-${String(index + 1).padStart(2, '0')}`;
}

// --- Loans, advances and opening figures (Task 5) ---------------------------

export type LoanType = 'loan' | 'advance';
export type LoanStatus = 'active' | 'cleared' | 'cancelled';

/** "Loan" / "Advance". */
export function loanTypeLabel(type: string): string {
    return type === 'advance' ? 'Advance' : 'Loan';
}

/**
 * How a loan's status reads and its pill colour: active (still recovering)
 * green, cleared (nothing left) grey, cancelled (remainder written off) red.
 */
export function loanStatusPill(status: string): {
    label: string;
    tone: 'green' | 'grey' | 'red';
} {
    if (status === 'cancelled') {
        return { label: 'Cancelled', tone: 'red' };
    }

    if (status === 'cleared') {
        return { label: 'Cleared', tone: 'grey' };
    }

    return { label: 'Active', tone: 'green' };
}

/** A tax year named by its start year: 2026 -> "2026-27" (July to June). Matches TaxYear::label. */
export function taxYearLabel(year: number): string {
    return `${year}-${String((year + 1) % 100).padStart(2, '0')}`;
}

/** Whether text is a month written "YYYY-MM" (01..12). */
export function isMonth(text: string): boolean {
    return /^\d{4}-(0[1-9]|1[0-2])$/.test(text);
}

// --- Payroll runs (Task 8) --------------------------------------------------

export type RunStatus = 'draft' | 'approved' | 'paid';

/** How a run's status reads and its pill colour: Draft amber, Approved green, Paid blue. */
export function runStatusPill(status: string): {
    label: string;
    tone: 'amber' | 'green' | 'blue';
} {
    if (status === 'paid') {
        return { label: 'Paid', tone: 'blue' };
    }

    if (status === 'approved') {
        return { label: 'Approved', tone: 'green' };
    }

    return { label: 'Draft', tone: 'amber' };
}

export type RunCheck = {
    key: string;
    severity: 'block' | 'warn';
    message: string;
    people: { id: number; name: string }[];
    count: number;
};

/** Blocking checks first, then warnings; each group keeps the server's order. */
export function sortChecks(checks: RunCheck[]): RunCheck[] {
    return [
        ...checks.filter((c) => c.severity === 'block'),
        ...checks.filter((c) => c.severity !== 'block'),
    ];
}

export type RunLine = {
    id: number;
    employee: {
        id: number;
        name: string;
        code: string;
        department: string | null;
    };
    days_paid: number;
    gross: number;
    attendance_deduction: number;
    other_deductions: number;
    loan_deduction: number;
    tax: number;
    net: number;
    excluded: boolean;
    excluded_reason: string | null;
    tax_overridden: boolean;
    warnings: number;
    blocking: string[];
    can_edit: boolean;
    payslip_url: string | null;
};

/** Everything taken off a line before tax: attendance, fixed and one-off deductions, and the loan installment. */
export function lineDeductions(line: RunLine): number {
    return (
        line.attendance_deduction + line.other_deductions + line.loan_deduction
    );
}

/** A line needs a look when something blocks approval or it carries a warning. Excluded lines never do. */
export function needsAttention(line: RunLine): boolean {
    return !line.excluded && (line.blocking.length > 0 || line.warnings > 0);
}

/** The lines matching a name or code search and, optionally, only those that need attention. */
export function filterLines(
    lines: RunLine[],
    search: string,
    onlyAttention: boolean,
): RunLine[] {
    const term = search.trim().toLowerCase();

    return lines.filter(
        (line) =>
            (!onlyAttention || needsAttention(line)) &&
            (term === '' ||
                line.employee.name.toLowerCase().includes(term) ||
                line.employee.code.toLowerCase().includes(term)),
    );
}

/** "3 people" / "1 person". */
export function peopleCount(n: number): string {
    return `${n} ${n === 1 ? 'person' : 'people'}`;
}
