import type { ComponentProps } from 'react';
import { Input } from '@/components/ui/input';
import { groupDigits, parseRupees } from '@/lib/payroll';
import { cn } from '@/lib/utils';

type Props = Omit<
    ComponentProps<'input'>,
    'value' | 'onChange' | 'type' | 'inputMode'
> & {
    /** Whole rupees as typed ("64,930"); never a float. */
    value: string;
    onChange: (value: string) => void;
};

/**
 * A whole-rupee amount: an "Rs" prefix, digits only, and thousands separators
 * added when the field loses focus ("64930" -> "64,930", the grouping of
 * Money::format). The value stays text; the page turns it into whole rupees
 * when it submits and the server checks it again.
 */
export function MoneyInput({ value, onChange, className, ...props }: Props) {
    return (
        <div className="relative">
            <span
                aria-hidden="true"
                className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-muted-foreground"
            >
                Rs
            </span>
            <Input
                {...props}
                type="text"
                inputMode="numeric"
                autoComplete="off"
                value={value}
                className={cn('pl-9 text-right tabular-nums', className)}
                // Letters and spaces are dropped; a decimal point is kept so
                // "1,234.5" is refused as not whole rupees, never read as 12,345.
                onChange={(e) =>
                    onChange(e.target.value.replace(/[^\d,.]/g, ''))
                }
                onBlur={(e) => {
                    const rupees = parseRupees(value);

                    if (rupees !== null) {
                        onChange(groupDigits(String(rupees)));
                    }

                    props.onBlur?.(e);
                }}
            />
        </div>
    );
}
