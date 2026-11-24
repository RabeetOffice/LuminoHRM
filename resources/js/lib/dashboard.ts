import {
    CalendarClock,
    Clock,
    UserCheck,
    UserX,
    Users,
    Wallet,
    WalletCards,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

export type TileColor = 'green' | 'blue' | 'rose' | 'amber' | 'violet' | 'teal';

// Soft tinted tile: background and border from its own colour, icon square a
// little stronger. Dark mode uses a low-opacity tint over the dark surface.
// Shared so later pages can reuse the same palette.
export const tileTones: Record<TileColor, { tile: string; icon: string }> = {
    green: {
        tile: 'border-emerald-200 bg-emerald-50 dark:border-emerald-500/20 dark:bg-emerald-500/10',
        icon: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300',
    },
    blue: {
        tile: 'border-blue-200 bg-blue-50 dark:border-blue-500/20 dark:bg-blue-500/10',
        icon: 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300',
    },
    rose: {
        tile: 'border-rose-200 bg-rose-50 dark:border-rose-500/20 dark:bg-rose-500/10',
        icon: 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-300',
    },
    amber: {
        tile: 'border-amber-200 bg-amber-50 dark:border-amber-500/20 dark:bg-amber-500/10',
        icon: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300',
    },
    violet: {
        tile: 'border-violet-200 bg-violet-50 dark:border-violet-500/20 dark:bg-violet-500/10',
        icon: 'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-300',
    },
    teal: {
        tile: 'border-teal-200 bg-teal-50 dark:border-teal-500/20 dark:bg-teal-500/10',
        icon: 'bg-teal-100 text-teal-700 dark:bg-teal-500/20 dark:text-teal-300',
    },
};

export type DashboardTileKey =
    | 'employees'
    | 'present'
    | 'absent'
    | 'late'
    | 'pending-leaves'
    | 'payroll'
    | 'leave-balance';

export type DashboardTile = {
    key: DashboardTileKey;
    label: string;
    icon: LucideIcon;
    color: TileColor;
};

/** Whose employees the first tile counts: the company (Admin, HR), the viewer's team (Manager), or no tile (null). */
export type EmployeeTileScope = 'company' | 'team' | null;

/**
 * Spec 9.1 tiles. The first counts employees and exists only when the server sent a count (`employeeCount.scope`):
 * "Total Employees" for the company, "Team Members" for a Manager, and nothing at all for a plain Employee (five
 * tiles). The last depends on whether the user may see payroll.
 */
export function dashboardTiles(
    canViewPayroll: boolean,
    employeeScope: EmployeeTileScope,
): DashboardTile[] {
    const employees: DashboardTile[] =
        employeeScope === null
            ? []
            : [
                  {
                      key: 'employees',
                      label:
                          employeeScope === 'team'
                              ? 'Team Members'
                              : 'Total Employees',
                      icon: Users,
                      color: 'green',
                  },
              ];

    return [
        ...employees,
        {
            key: 'present',
            label: 'Present Today',
            icon: UserCheck,
            color: 'blue',
        },
        { key: 'absent', label: 'Absent Today', icon: UserX, color: 'rose' },
        { key: 'late', label: 'Late Today', icon: Clock, color: 'amber' },
        {
            key: 'pending-leaves',
            label: 'Pending Leaves',
            icon: CalendarClock,
            color: 'violet',
        },
        canViewPayroll
            ? {
                  key: 'payroll',
                  label: 'Payroll This Month',
                  icon: Wallet,
                  color: 'teal',
              }
            : {
                  key: 'leave-balance',
                  label: 'Leave Balance',
                  icon: WalletCards,
                  color: 'teal',
              },
    ];
}

/** The employee tile's footnote. */
export function probationNote(count: number): string {
    return count === 0 ? 'No one on probation' : `${count} on probation`;
}

/** Morning 05:00-11:59, afternoon 12:00-16:59, evening otherwise. */
export function greetingFor(
    hour: number,
): 'Good morning' | 'Good afternoon' | 'Good evening' {
    if (hour >= 5 && hour < 12) {
        return 'Good morning';
    }

    return hour >= 12 && hour < 17 ? 'Good afternoon' : 'Good evening';
}

/**
 * An unusable timezone (empty, missing or not an IANA name) must not blank the dashboard:
 * return undefined so Intl uses the browser zone.
 */
function usableTimeZone(
    timeZone: string | null | undefined,
): string | undefined {
    if (!timeZone) {
        return undefined;
    }

    try {
        new Intl.DateTimeFormat('en-GB', { timeZone });

        return timeZone;
    } catch {
        return undefined;
    }
}

/** Hour of day (0-23) of an instant in the given IANA timezone. */
export function hourIn(date: Date, timeZone: string): number {
    const hour = new Intl.DateTimeFormat('en-GB', {
        hour: 'numeric',
        hourCycle: 'h23',
        timeZone: usableTimeZone(timeZone),
    }).format(date);

    return Number(hour);
}

/** e.g. "Monday, 23 November 2026", in the given timezone. */
export function formatLongDate(date: Date, timeZone: string): string {
    return new Intl.DateTimeFormat('en-GB', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: usableTimeZone(timeZone),
    })
        .format(date)
        .replace(/^(\w+) /, '$1, ');
}
