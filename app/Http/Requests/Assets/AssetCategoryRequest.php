<?php

namespace App\Http\Requests\Assets;

use App\Support\Names;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Add or rename an asset category: a name of 1 to 60 characters, trimmed with inner whitespace collapsed. A duplicate
 * (ignoring case) is refused by SaveAssetCategory, under the lock.
 */
class AssetCategoryRequest extends FormRequest
{
    /** Access is enforced by the can:assets.assign route middleware. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => Names::normalise($this->input('name'))]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:60'],
        ];
    }
}
