import { useForm } from '@inertiajs/react';
import {
    Field,
    fieldAria,
    SettingsCard,
    useSettingsSubmit,
} from '@/components/settings/settings-form';
import { Textarea } from '@/components/ui/textarea';
import type { LettersValues } from '@/types/hrm-settings';

export function LettersPanel({
    values,
    placeholders,
}: {
    values: LettersValues;
    placeholders: string[];
}) {
    const form = useForm(values);
    const { card } = useSettingsSubmit('letters', form);
    const { data, errors, setData } = form;

    return (
        <form
            onSubmit={(e) => e.preventDefault()}
            className="grid gap-6"
            noValidate
        >
            <SettingsCard
                title="Experience letter"
                description="The text of the experience letter. The PDF itself arrives in a later phase."
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
