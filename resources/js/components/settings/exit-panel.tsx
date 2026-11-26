import { useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import {
    Field,
    fieldAria,
    SettingsCard,
    useSettingsSubmit,
} from '@/components/settings/settings-form';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import type { ExitValues } from '@/types/hrm-settings';

const MAX_ITEMS = 20;
const MAX_ITEM_LENGTH = 80;

/** The three items every exit starts with; custom items follow them. */
const BUILT_IN = ['Assets returned', 'System access removed', 'Dues cleared'];

export function ExitPanel({
    values,
    placeholders,
}: {
    values: ExitValues;
    placeholders: string[];
}) {
    const form = useForm(values);
    const { card } = useSettingsSubmit('exit', form);
    const { data, errors, setData } = form;
    const items = data.clearance_items;

    const setItems = (next: string[]) => setData('clearance_items', next);
    const move = (from: number, to: number) => {
        const next = [...items];
        [next[from], next[to]] = [next[to], next[from]];
        setItems(next);
    };

    return (
        <form
            onSubmit={(e) => e.preventDefault()}
            className="grid gap-6"
            noValidate
        >
            <SettingsCard
                title="Clearance checklist"
                description="Every exit is checked against these items. Changes apply to exits approved from now on."
                {...card(['clearance_items'])}
            >
                <div className="grid gap-2">
                    <p className="text-sm font-medium">Built in</p>
                    <ul className="grid gap-1 text-sm text-muted-foreground">
                        {BUILT_IN.map((label) => (
                            <li key={label}>{label}</li>
                        ))}
                    </ul>
                </div>

                <Field
                    id="exit-item-0"
                    label="Your own items"
                    hint={`Up to ${MAX_ITEMS} items of up to ${MAX_ITEM_LENGTH} characters, each different.`}
                    error={errors.clearance_items}
                >
                    <div className="grid gap-2">
                        {items.map((item, i) => (
                            <div key={i} className="flex items-center gap-2">
                                <Input
                                    {...fieldAria(
                                        `exit-item-${i}`,
                                        i === 0
                                            ? errors.clearance_items
                                            : undefined,
                                        i === 0,
                                    )}
                                    value={item}
                                    maxLength={MAX_ITEM_LENGTH}
                                    aria-label={`Clearance item ${i + 1}`}
                                    onChange={(e) =>
                                        setItems(
                                            items.map((v, j) =>
                                                j === i ? e.target.value : v,
                                            ),
                                        )
                                    }
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Move item ${i + 1} up`}
                                    disabled={i === 0}
                                    onClick={() => move(i, i - 1)}
                                >
                                    <ArrowUp />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Move item ${i + 1} down`}
                                    disabled={i === items.length - 1}
                                    onClick={() => move(i, i + 1)}
                                >
                                    <ArrowDown />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Remove item ${i + 1}`}
                                    onClick={() =>
                                        setItems(
                                            items.filter((_, j) => j !== i),
                                        )
                                    }
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        ))}
                        <div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={items.length >= MAX_ITEMS}
                                onClick={() => setItems([...items, ''])}
                            >
                                <Plus /> Add item
                            </Button>
                        </div>
                    </div>
                </Field>
            </SettingsCard>

            <SettingsCard
                title="Experience letter"
                description="The text of the experience letter HR issues once an exit is approved."
                {...card(['experience_template'])}
            >
                <Field
                    id="letter-experience"
                    label="Letter text"
                    error={errors.experience_template}
                    hint={
                        <>
                            Placeholders filled in for each employee:{' '}
                            {placeholders.map((p, i) => (
                                <span key={p}>
                                    <code className="rounded bg-muted px-1 py-0.5 font-mono text-[0.8em]">
                                        {p}
                                    </code>
                                    {i < placeholders.length - 1 ? ' ' : ''}
                                </span>
                            ))}
                        </>
                    }
                >
                    <Textarea
                        {...fieldAria(
                            'letter-experience',
                            errors.experience_template,
                            true,
                        )}
                        value={data.experience_template}
                        maxLength={5000}
                        rows={14}
                        onChange={(e) =>
                            setData('experience_template', e.target.value)
                        }
                    />
                </Field>
            </SettingsCard>
        </form>
    );
}
