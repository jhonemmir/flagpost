<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\FlagpostConfig;
use Illuminate\Validation\Validator;

final class CreateFlagRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $descriptionLimit = FlagpostConfig::limit('description_characters');

        return [
            'key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9._-]{0,63}\z/'],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.$descriptionLimit],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->rejectUnknownFields($validator, ['key', 'description']);
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.regex' => 'Must match ^[a-z0-9][a-z0-9._-]{0,63}$.',
        ];
    }
}
