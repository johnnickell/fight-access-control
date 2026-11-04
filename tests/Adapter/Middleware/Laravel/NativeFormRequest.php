<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Laravel;

use Illuminate\Foundation\Http\FormRequest;

final class NativeFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        NativeController::$trace->events[] = 'form-authorize';

        return true;
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        NativeController::$trace->events[] = 'form-rules';

        return ['name' => 'required'];
    }
}
