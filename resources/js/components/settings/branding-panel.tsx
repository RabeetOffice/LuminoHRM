import { useForm } from '@inertiajs/react';
import { useEffect, useMemo } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Field,
    fieldAria,
    SettingsCard,
    useSettingsSubmit,
} from '@/components/settings/settings-form';
import { DEFAULT_ACCENT, isHexColour } from '@/lib/accent';
import type { BrandingValues } from '@/types/hrm-settings';

type LogoKey = 'logo' | 'logo_dark';

type BrandingForm = {
    accent: string;
    logo: File | null;
    logo_dark: File | null;
    remove_logo: boolean;
    remove_logo_dark: boolean;
};

const LOGO_TYPES = 'image/png,image/jpeg,image/webp';

function LogoField({
    id,
    label,
    current,
    file,
    remove,
    error,
    dark,
    onFile,
    onRemove,
}: {
    id: string;
    label: string;
    current: string | null;
    file: File | null;
    remove: boolean;
    error?: string;
    dark?: boolean;
    onFile: (file: File | null) => void;
    onRemove: (remove: boolean) => void;
}) {
    const preview = useMemo(
        () => (file ? URL.createObjectURL(file) : null),
        [file],
    );

    useEffect(() => {
        return () => {
            if (preview) {
                URL.revokeObjectURL(preview);
            }
        };
    }, [preview]);

    const shown = preview ?? (remove ? null : current);

    return (
        <Field
            id={id}
            label={label}
            hint="PNG, JPG or WebP, up to 1 MB. A wide logo about 160 by 32 pixels fits the sidebar best."
            error={error}
        >
            <div
                className={
                    'flex h-20 items-center justify-center rounded-md border border-dashed p-3 ' +
                    (dark ? 'bg-neutral-900' : 'bg-white')
                }
            >
                {shown ? (
                    <img
                        src={shown}
                        alt={`${label} preview`}
                        className="max-h-14 max-w-full object-contain"
                    />
                ) : (
                    <span
                        className={
                            'text-sm ' +
                            (dark ? 'text-neutral-400' : 'text-neutral-500')
                        }
                    >
                        No logo
                    </span>
                )}
            </div>
            <Input
                key={file ? 'chosen' : 'empty'}
                {...fieldAria(id, error, true)}
                type="file"
                accept={LOGO_TYPES}
                onChange={(e) => {
                    onFile(e.target.files?.[0] ?? null);
                    onRemove(false);
                }}
            />
            {current && !file && (
                <div className="flex items-center gap-2">
                    <Checkbox
                        id={`${id}-remove`}
                        checked={remove}
                        onCheckedChange={(v) => onRemove(v === true)}
                    />
                    <Label htmlFor={`${id}-remove`} className="font-normal">
                        Remove this logo
                    </Label>
                </div>
            )}
        </Field>
    );
}

export function BrandingPanel({ values }: { values: BrandingValues }) {
    const form = useForm<BrandingForm>({
        accent: values.accent,
        logo: null,
        logo_dark: null,
        remove_logo: false,
        remove_logo_dark: false,
    });
    const { card } = useSettingsSubmit('branding', form, {
        files: true,
        afterSave: {
            logo: null,
            logo_dark: null,
            remove_logo: false,
            remove_logo_dark: false,
        },
    });
    const { data, errors, setData } = form;
    const swatch = isHexColour(data.accent) ? data.accent : DEFAULT_ACCENT;

    const setFile = (key: LogoKey, file: File | null) => setData(key, file);

    return (
        <form
            onSubmit={(e) => e.preventDefault()}
            className="grid gap-6"
            noValidate
        >
            <SettingsCard
                title="Accent colour"
                description="Buttons, the active menu entry and links use this colour."
                {...card(['accent'])}
            >
                <Field
                    id="branding-accent"
                    label="Accent colour"
                    hint="A six-digit hex colour such as #10b77f. Text on top of it switches between white and dark to stay readable."
                    error={errors.accent}
                >
                    <div className="flex items-center gap-3">
                        <input
                            type="color"
                            aria-label="Pick the accent colour"
                            value={swatch}
                            onChange={(e) => setData('accent', e.target.value)}
                            className="h-9 w-12 cursor-pointer rounded-md border bg-transparent p-1"
                        />
                        <Input
                            {...fieldAria(
                                'branding-accent',
                                errors.accent,
                                true,
                            )}
                            value={data.accent}
                            maxLength={7}
                            spellCheck={false}
                            autoComplete="off"
                            className="w-36 font-mono"
                            onChange={(e) => setData('accent', e.target.value)}
                        />
                    </div>
                </Field>
            </SettingsCard>

            <SettingsCard
                title="Logo"
                description="Shown in the sidebar and on the sign-in page."
                {...card([
                    'logo',
                    'logo_dark',
                    'remove_logo',
                    'remove_logo_dark',
                ])}
            >
                <div className="flex items-center gap-3 rounded-md border bg-muted/40 p-3">
                    <img
                        src="/brand/logo-on-white.png"
                        alt="Lumino Digital HRM"
                        width={1271}
                        height={324}
                        className="h-10 w-auto rounded-sm object-contain"
                    />
                    <p className="text-sm text-muted-foreground">
                        Default logo (used until you upload one)
                    </p>
                </div>
                <div className="grid gap-5 md:grid-cols-2">
                    <LogoField
                        id="branding-logo"
                        label="Logo for light theme"
                        current={values.logo_url}
                        file={data.logo}
                        remove={data.remove_logo}
                        error={errors.logo}
                        onFile={(f) => setFile('logo', f)}
                        onRemove={(v) => setData('remove_logo', v)}
                    />
                    <LogoField
                        id="branding-logo-dark"
                        label="Logo for dark theme"
                        current={values.logo_dark_url}
                        file={data.logo_dark}
                        remove={data.remove_logo_dark}
                        error={errors.logo_dark}
                        dark
                        onFile={(f) => setFile('logo_dark', f)}
                        onRemove={(v) => setData('remove_logo_dark', v)}
                    />
                </div>
            </SettingsCard>
        </form>
    );
}
