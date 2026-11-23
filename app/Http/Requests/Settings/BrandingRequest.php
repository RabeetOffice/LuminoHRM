<?php

namespace App\Http\Requests\Settings;

class BrandingRequest extends SettingsGroupRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        // png, jpg and webp only. SVG is refused: it can carry script and is served from our own origin.
        // The logo loads for every visitor of the sign-in page, so the canvas is limited too.
        $logo = ['bail', 'nullable', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024', 'dimensions:min_width=16,min_height=16,max_width=2000,max_height=2000'];

        return [
            // \z, not $: a trailing newline must not slip through before this reaches a style attribute.
            'accent' => ['bail', 'required', 'string', 'regex:/^#[0-9a-fA-F]{6}\z/'],
            'logo' => $logo,
            'logo_dark' => $logo,
            'remove_logo' => ['sometimes', 'boolean'],
            'remove_logo_dark' => ['sometimes', 'boolean'],
        ];
    }

    /** Only the accent is a plain value; the controller handles the files. */
    public function settingsValues(): array
    {
        return ['accent' => $this->validated('accent')];
    }
}
