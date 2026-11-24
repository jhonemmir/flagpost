<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\FlagpostConfig;
use Illuminate\Validation\Validator;

final class UpdateFlagRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $descriptionLimit = FlagpostConfig::limit('description_characters');

        return [
            'description' => ['present', 'nullable', 'string', 'max:'.$descriptionLimit],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->rejectUnknownFields($validator, ['description']);
            },
        ];
    }
}
