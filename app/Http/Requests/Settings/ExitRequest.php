<?php

namespace App\Http\Requests\Settings;

use Closure;

/**
 * Settings > Exit: the custom clearance items (added after the built-in three) and the experience letter text.
 * Editable by exit.clearance (Admin and HR; see config hrm.settings_groups).
 */
class ExitRequest extends SettingsGroupRequest
{
    /** Placeholders the experience letter fills in. {name} and {company} are aliases of the two long forms. */
    public const PLACEHOLDERS = [
        '{employee_name}', '{name}', '{designation}', '{department}', '{joining_date}', '{last_working_day}',
        '{company_name}', '{company}',
    ];

    public const ITEM_LENGTH = 'Clearance items must be 1 to 80 characters.';

    public const ITEM_DUPLICATE = 'Each clearance item must be different.';

    public const ITEM_COUNT = 'At most 20 clearance items.';

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'clearance_items' => [
                'bail', 'present', 'array',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $items = array_values((array) $value);

                    if (count($items) > (int) config('hrm.exit.max_clearance_items')) {
                        $fail(self::ITEM_COUNT);

                        return;
                    }

                    $seen = [];

                    foreach ($items as $item) {
                        $text = is_string($item) ? trim($item) : '';

                        if ($text === '' || mb_strlen($text) > 80) {
                            $fail(self::ITEM_LENGTH);

                            return;
                        }

                        $key = mb_strtolower($text);

                        if (isset($seen[$key])) {
                            $fail(self::ITEM_DUPLICATE);

                            return;
                        }

                        $seen[$key] = true;
                    }
                },
            ],
            'experience_template' => [
                'bail', 'required', 'string', 'max:5000',
                function (string $attribute, mixed $value, Closure $fail): void {
                    preg_match_all('/\{[A-Za-z_][A-Za-z0-9_]*\}/', (string) $value, $found);
                    $unknown = array_values(array_diff(array_unique($found[0]), self::PLACEHOLDERS));

                    if ($unknown !== []) {
                        $fail('Unknown placeholder '.implode(', ', $unknown).'. Supported: '.implode(', ', self::PLACEHOLDERS).'.');
                    }
                },
            ],
        ];
    }

    /**
     * The items are saved trimmed and in the order given.
     *
     * @return array<string, mixed>
     */
    public function settingsValues(): array
    {
        $values = $this->validated();

        if (array_key_exists('clearance_items', $values)) {
            $values['clearance_items'] = array_values(array_map(fn ($item) => trim((string) $item), (array) $values['clearance_items']));
        }

        return $values;
    }
}
