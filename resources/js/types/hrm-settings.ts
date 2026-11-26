/** Shapes of the Settings screen props. Secrets and file paths never reach the browser. */

export type SettingsGroup =
    | 'company'
    | 'branding'
    | 'attendance'
    | 'leave'
    | 'payroll'
    | 'email'
    | 'letters';

export type CompanyValues = {
    name: string;
    address: string;
    currency: string;
    timezone: string;
    date_format: string;
};

export type BrandingValues = {
    accent: string;
    logo_url: string | null;
    logo_dark_url: string | null;
};

export type AttendanceValues = {
    shift_start: string;
    shift_end: string;
    grace_minutes: number;
    weekly_offs: string[];
    late_rule_count: number;
    half_day_hours: number;
    overtime_enabled: boolean;
    overtime_min_minutes: number;
    /** How overtime is paid: a multiple of hourly pay, or a fixed rate an hour. */
    overtime_rate_mode: 'multiple' | 'fixed';
    /** 100 to 300 (150 = one and a half times hourly pay). */
    overtime_multiplier_percent: number;
    /** Whole rupees an hour, 0 to 100,000. */
    overtime_fixed_rate: number;
    /** 1 to 24: hourly pay = one day's pay / this. */
    overtime_hours_per_day: number;
    /** "Y-m-d", or null while attendance has not started. */
    start_date: string | null;
    duplicate_punch_minutes: number;
    /** "H:i", 00:00 to 06:00. */
    day_cutoff_time: string;
};

export type LeaveValues = {
    hr_step_enabled: boolean;
    prorate_new_joiners: boolean;
};

export type PayrollValues = {
    day_pay_basis: string;
    default_tax_mode: string;
    leave_encashment: boolean;
    notice_period_days: number;
    payslip_footer: string;
    /** First month of the tax year, 1 to 12 (7 = July to June). */
    tax_year_start_month: number;
};

export type EmailValues = {
    host: string;
    port: number;
    username: string;
    encryption: string;
    from_address: string;
    from_name: string;
    has_password: boolean;
};

export type LettersValues = {
    experience_template: string;
};

export type SettingsValues = {
    company?: CompanyValues;
    branding?: BrandingValues;
    attendance?: AttendanceValues;
    leave?: LeaveValues;
    payroll?: PayrollValues;
    email?: EmailValues;
    letters?: LettersValues;
};

export type SettingsOptions = {
    timezones: string[];
    dateFormats: { value: string; example: string }[];
    placeholders: string[];
};
