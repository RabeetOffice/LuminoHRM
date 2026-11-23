import { Search } from 'lucide-react';
import { useId } from 'react';

type Props = {
    value: string;
    onChange: (value: string) => void;
};

export function SidebarSearch({ value, onChange }: Props) {
    const id = useId();

    return (
        <div className="relative px-2 group-data-[collapsible=icon]:hidden">
            <label htmlFor={id} className="sr-only">
                Search menu
            </label>
            <Search
                aria-hidden="true"
                className="pointer-events-none absolute top-1/2 left-5 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <input
                id={id}
                type="search"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder="Search menu..."
                autoComplete="off"
                className="h-9 w-full rounded-md border border-input bg-background pr-3 pl-8 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30"
            />
        </div>
    );
}
