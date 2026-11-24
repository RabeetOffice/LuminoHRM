import { X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { managerOptions } from '@/routes/employees';

export type ManagerOption = {
    id: number;
    name: string;
    code: string;
    designation: string;
};

type Props = {
    id: string;
    /** The person chosen now, if any. */
    value: ManagerOption | null;
    onChange: (manager: ManagerOption | null) => void;
    /** The employee being edited: they and everyone below them are never offered (that would be a loop). */
    excludeId?: number | null;
    error?: string;
    'aria-describedby'?: string;
};

const SEARCH_DELAY_MS = 250;

/**
 * Searchable "Reports to" field. It asks the server for matches as you type (at most 20, active or on probation
 * only, never the employee themselves or their own team), so it stays fast with thousands of people. Combobox
 * pattern: arrow keys move through the list, Enter picks, Escape closes.
 */
export function ManagerPicker({
    id,
    value,
    onChange,
    excludeId,
    error,
    'aria-describedby': describedBy,
}: Props) {
    const listId = useId();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [options, setOptions] = useState<ManagerOption[]>([]);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const [active, setActive] = useState(0);
    const box = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            setLoading(true);
            setFailed(false);

            const url = managerOptions({
                query: {
                    search: query,
                    ...(excludeId ? { exclude: excludeId } : {}),
                },
            }).url;

            fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error(String(response.status));
                    }

                    return response.json() as Promise<{
                        data: ManagerOption[];
                    }>;
                })
                .then((body) => {
                    setOptions(body.data);
                    setActive(0);
                    setLoading(false);
                })
                .catch((cause: unknown) => {
                    if ((cause as Error).name !== 'AbortError') {
                        setFailed(true);
                        setLoading(false);
                    }
                });
        }, SEARCH_DELAY_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [open, query, excludeId]);

    const choose = (manager: ManagerOption) => {
        onChange(manager);
        setOpen(false);
        setQuery('');
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setOpen(true);
            setActive((i) => Math.min(i + 1, Math.max(options.length - 1, 0)));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((i) => Math.max(i - 1, 0));
        } else if (event.key === 'Enter' && open) {
            event.preventDefault();

            if (options[active]) {
                choose(options[active]);
            }
        } else if (event.key === 'Escape' && open) {
            event.preventDefault();
            setOpen(false);
            setQuery('');
        }
    };

    const shown = open ? query : value ? `${value.name} (${value.code})` : '';

    const status = loading
        ? 'Searching...'
        : failed
          ? 'Could not load people. Try again.'
          : options.length === 0
            ? 'No one found.'
            : '';

    return (
        <div
            ref={box}
            className="relative"
            onBlur={(event) => {
                if (
                    !box.current?.contains(event.relatedTarget as Node | null)
                ) {
                    setOpen(false);
                    setQuery('');
                }
            }}
        >
            <div className="flex items-center gap-1">
                <Input
                    id={id}
                    role="combobox"
                    aria-expanded={open}
                    aria-controls={listId}
                    aria-autocomplete="list"
                    aria-activedescendant={
                        open && options[active]
                            ? `${listId}-${options[active].id}`
                            : undefined
                    }
                    aria-invalid={error ? true : undefined}
                    aria-describedby={describedBy}
                    autoComplete="off"
                    placeholder={
                        value ? undefined : 'Search by name, ID or email'
                    }
                    value={shown}
                    onFocus={() => setOpen(true)}
                    onClick={() => setOpen(true)}
                    onChange={(event) => {
                        setOpen(true);
                        setQuery(event.target.value);
                    }}
                    onKeyDown={onKeyDown}
                />
                {value && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-9 shrink-0"
                        aria-label="Remove the reporting manager"
                        onClick={() => {
                            onChange(null);
                            setQuery('');
                        }}
                    >
                        <X />
                    </Button>
                )}
            </div>
            {open && (
                <ul
                    id={listId}
                    role="listbox"
                    aria-label="Reporting managers"
                    className="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-md border bg-popover p-1 text-popover-foreground shadow-md"
                >
                    {options.map((option, index) => (
                        <li
                            key={option.id}
                            id={`${listId}-${option.id}`}
                            role="option"
                            aria-selected={value?.id === option.id}
                            onMouseDown={(event) => event.preventDefault()}
                            onClick={() => choose(option)}
                            onMouseEnter={() => setActive(index)}
                            className={cn(
                                'flex cursor-pointer flex-col rounded-sm px-2 py-1.5 text-sm',
                                index === active && 'bg-accent',
                            )}
                        >
                            <span className="font-medium">
                                {option.name}{' '}
                                <span className="font-normal text-muted-foreground">
                                    {option.code}
                                </span>
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {option.designation}
                            </span>
                        </li>
                    ))}
                    {status !== '' && (
                        <li
                            role="presentation"
                            className="px-2 py-1.5 text-sm text-muted-foreground"
                        >
                            {status}
                        </li>
                    )}
                </ul>
            )}
        </div>
    );
}
