<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Validator;

final class ExplainRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->rejectUnknownFields($validator, ['userId', 'defaultValue']);

                $userId = $this->input('userId');

                if ($userId !== null && ! is_string($userId)) {
                    $validator->errors()->add('userId', 'Must be a string or null.');
                }

                if ($this->has('defaultValue') && ! is_bool($this->input('defaultValue'))) {
                    $validator->errors()->add('defaultValue', 'Must be a boolean.');
                }
            },
        ];
    }
}
