import { Cake, CalendarDays, Star } from 'lucide-react';
import { useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    CALENDAR_WEEKDAYS,
    calendarAgenda,
    calendarWeeks,
    dayButtonLabel,
    dayHasEvents,
    halfLabel,
    halfMark,
    leaveDotClass,
    typeInitial,
    visibleChips,
} from '@/lib/leave';
import type { CalendarChip, CalendarDay } from '@/lib/leave';
import { cn } from '@/lib/utils';

type Props = {
    days: CalendarDay[];
    firstWeekday: number;
    /** "December 2026", for the "+N more" list's title. */
    label: string;
};

/**
 * The month: a Monday-first grid from the `sm` breakpoint up, and an agenda list of the days that have something on a
 * phone. Each day box shows the holiday (star), up to three leave chips (colour dot + first name, "½" for a half day,
 * the type's initial, dashed for the viewer's own pending request), "+N more" opening the day's full list, and a cake
 * for birthdays. The day number of a day with something on it is a button opening that list (types written out).
 * Shading marks the default shift's weekly offs.
 */
export function MonthCalendar({ days, firstWeekday, label }: Props) {
    const [openDay, setOpenDay] = useState<CalendarDay | null>(null);
    const [open, setOpen] = useState(false);
    const showDay = (day: CalendarDay) => {
        setOpenDay(day);
        setOpen(true);
    };

    return (
        <>
            <MonthGridView
                days={days}
                firstWeekday={firstWeekday}
                onMore={showDay}
            />
            <Agenda days={days} />

            {openDay !== null && (
                <Dialog open={open} onOpenChange={setOpen}>
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>
                                {openDay.weekday} {openDay.day} {label}
                            </DialogTitle>
                            <DialogDescription>
                                Everything on this day.
                            </DialogDescription>
                        </DialogHeader>
                        <DayDetails day={openDay} />
                    </DialogContent>
                </Dialog>
            )}
        </>
    );
}

function MonthGridView({
    days,
    firstWeekday,
    onMore,
}: {
    days: CalendarDay[];
    firstWeekday: number;
    onMore: (day: CalendarDay) => void;
}) {
    const weeks = calendarWeeks(days, firstWeekday);

    return (
        <div className="hidden sm:block">
            <div className="grid grid-cols-7 gap-2 pb-2" aria-hidden="true">
                {CALENDAR_WEEKDAYS.map((weekday) => (
                    <div
                        key={weekday}
                        className="text-center text-xs font-medium text-muted-foreground"
                    >
                        {weekday}
                    </div>
                ))}
            </div>
            <ol role="list" aria-label="Days" className="grid gap-2">
                {weeks.map((week, w) => (
                    <li key={w} className="grid grid-cols-7 gap-2">
                        {week.map((day, d) =>
                            day === null ? (
                                <div key={`b${w}-${d}`} aria-hidden="true" />
                            ) : (
                                <DayBox
                                    key={day.date}
                                    day={day}
                                    onMore={onMore}
                                />
                            ),
                        )}
                    </li>
                ))}
            </ol>
        </div>
    );
}

function DayBox({
    day,
    onMore,
}: {
    day: CalendarDay;
    onMore: (day: CalendarDay) => void;
}) {
    const { shown, more } = visibleChips(day.chips);

    return (
        <div
            className={cn(
                'flex min-h-28 min-w-0 flex-col gap-1 rounded-lg border p-1.5 lg:min-h-32',
                day.isWeekend && 'bg-muted/40',
                day.holidays.length > 0 &&
                    'bg-violet-50/60 dark:bg-violet-500/5',
                day.isToday && 'border-primary ring-1 ring-primary',
            )}
        >
            <div className="flex items-center justify-between gap-1">
                {dayHasEvents(day) ? (
                    // The day's full list, with each type written out: one keyboard stop per day that has something.
                    <button
                        type="button"
                        onClick={() => onMore(day)}
                        aria-label={`${dayButtonLabel(day)}${day.isToday ? ' (today)' : ''}: show details`}
                        title="Show this day"
                        className={cn(
                            '-m-0.5 rounded px-1 text-xs text-muted-foreground tabular-nums hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            day.isToday && 'font-semibold text-primary',
                        )}
                    >
                        {day.day}
                    </button>
                ) : (
                    <span
                        className={cn(
                            'text-xs text-muted-foreground tabular-nums',
                            day.isToday && 'font-semibold text-primary',
                        )}
                    >
                        <span className="sr-only">{day.weekday} </span>
                        {day.day}
                        {day.isToday && (
                            <span className="sr-only"> (today)</span>
                        )}
                    </span>
                )}
                {day.birthdays.length > 0 && (
                    <span
                        className="inline-flex items-center gap-0.5 text-pink-600 dark:text-pink-400"
                        title={`Birthday: ${day.birthdays.join(', ')}`}
                    >
                        <Cake aria-hidden="true" className="size-3.5" />
                        <span className="sr-only">
                            Birthday: {day.birthdays.join(', ')}
                        </span>
                        {day.birthdays.length > 1 && (
                            <span aria-hidden="true" className="text-[10px]">
                                {day.birthdays.length}
                            </span>
                        )}
                    </span>
                )}
            </div>
            {day.holidays.map((name) => (
                <div
                    key={name}
                    className="flex min-w-0 items-center gap-1 text-[11px] font-medium text-violet-700 dark:text-violet-300"
                    title={name}
                >
                    <Star aria-hidden="true" className="size-3 shrink-0" />
                    <span className="truncate">{name}</span>
                </div>
            ))}
            {shown.map((chip) => (
                <Chip key={chip.key} chip={chip} />
            ))}
            {more > 0 && (
                <button
                    type="button"
                    onClick={() => onMore(day)}
                    className="self-start rounded px-1 text-[11px] font-medium text-muted-foreground hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    +{more} more
                    <span className="sr-only"> on {day.day}</span>
                </button>
            )}
        </div>
    );
}

function Chip({ chip }: { chip: CalendarChip }) {
    const mark = halfMark(chip.half);
    const title = `${chip.pending ? 'Your pending request' : chip.name} · ${chip.type} · ${halfLabel(chip.half)}`;

    return (
        <div
            title={title}
            className={cn(
                'flex min-w-0 items-center gap-1 rounded-md px-1 py-0.5 text-[11px]',
                chip.pending
                    ? 'border border-dashed border-muted-foreground/50 text-muted-foreground'
                    : 'bg-muted/70',
            )}
        >
            <span
                aria-hidden="true"
                className={cn(
                    'size-2 shrink-0 rounded-full',
                    leaveDotClass(chip.colour),
                )}
            />
            <span className="truncate">{chip.label}</span>
            {mark && (
                <span aria-hidden="true" className="shrink-0 font-medium">
                    {mark}
                </span>
            )}
            {/* The type as text, not colour only (the day's dialog writes it out in full). */}
            <span
                aria-hidden="true"
                className="ml-auto shrink-0 text-[10px] font-medium text-muted-foreground"
            >
                {typeInitial(chip.type)}
            </span>
            <span className="sr-only">
                , {chip.type}, {halfLabel(chip.half)}
            </span>
        </div>
    );
}

/** The day's whole list (the "+N more" dialog and each phone agenda day). */
function DayDetails({ day }: { day: CalendarDay }) {
    return (
        <ul role="list" className="grid gap-2 text-sm">
            {day.holidays.map((name, i) => (
                <li key={`h-${i}-${name}`} className="flex items-center gap-2">
                    <Star
                        aria-hidden="true"
                        className="size-4 shrink-0 text-violet-600 dark:text-violet-400"
                    />
                    <span className="font-medium">{name}</span>
                    <span className="text-muted-foreground">Holiday</span>
                </li>
            ))}
            {day.chips.map((chip, i) => (
                <li
                    key={`c-${i}-${chip.key}`}
                    className="flex items-center gap-2"
                >
                    <span
                        aria-hidden="true"
                        className={cn(
                            'size-2.5 shrink-0 rounded-full',
                            leaveDotClass(chip.colour),
                            chip.pending &&
                                'ring-1 ring-muted-foreground ring-offset-1 ring-offset-background',
                        )}
                    />
                    <span className="min-w-0 truncate font-medium">
                        {chip.pending ? 'Your request' : chip.name}
                    </span>
                    <span className="min-w-0 truncate text-muted-foreground">
                        {chip.type}
                        {chip.half !== 'full' && ` · ${halfLabel(chip.half)}`}
                        {chip.pending && ' · Pending'}
                    </span>
                </li>
            ))}
            {day.birthdays.map((name, i) => (
                <li key={`b-${i}-${name}`} className="flex items-center gap-2">
                    <Cake
                        aria-hidden="true"
                        className="size-4 shrink-0 text-pink-600 dark:text-pink-400"
                    />
                    <span className="font-medium">{name}</span>
                    <span className="text-muted-foreground">Birthday</span>
                </li>
            ))}
        </ul>
    );
}

/** Phone width: the days that have something, as a list. */
function Agenda({ days }: { days: CalendarDay[] }) {
    const items = calendarAgenda(days);

    return (
        <div className="sm:hidden">
            {items.length === 0 ? (
                <EmptyMonth />
            ) : (
                <ol role="list" className="divide-y">
                    {items.map((day) => (
                        <li
                            key={day.date}
                            className={cn(
                                'flex gap-4 py-3',
                                day.isToday && 'rounded-lg bg-primary/5 px-2',
                            )}
                        >
                            <div className="w-10 shrink-0 text-center">
                                <div
                                    className={cn(
                                        'text-lg leading-tight font-semibold tabular-nums',
                                        day.isToday && 'text-primary',
                                    )}
                                >
                                    {day.day}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    {day.weekday}
                                </div>
                            </div>
                            <div className="min-w-0 flex-1">
                                <DayDetails day={day} />
                            </div>
                        </li>
                    ))}
                </ol>
            )}
        </div>
    );
}

export function EmptyMonth() {
    return (
        <div className="flex flex-col items-center px-6 py-12 text-center">
            <div className="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                <CalendarDays aria-hidden="true" className="size-6" />
            </div>
            <p className="mt-4 text-sm font-medium">Nothing on this month.</p>
            <p className="mt-1 text-sm text-muted-foreground">
                Holidays, leave and birthdays appear here.
            </p>
        </div>
    );
}
