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
};

export type LeaveValues = {
    hr_step_enabled: boolean;
    carry_forward_cap: number;
    prorate_new_joiners: boolean;
};

export type PayrollValues = {
    day_pay_basis: string;
    default_tax_mode: string;
    leave_encashment: boolean;
    notice_period_days: number;
    payslip_footer: string;
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
