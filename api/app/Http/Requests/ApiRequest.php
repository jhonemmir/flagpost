<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class ApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @param  list<string>  $allowed
     */
    protected function rejectUnknownFields(Validator $validator, array $allowed): void
    {
        foreach (array_keys($this->all()) as $field) {
            if (! in_array((string) $field, $allowed, true)) {
                $validator->errors()->add((string) $field, 'Unknown field.');
            }
        }
    }
}
