<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;

class PublicOrderLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'boleta' => ['required', 'string', 'max:100'],
            'dni' => ['required', 'string', 'max:30'],
        ];
    }

    public function messages(): array
    {
        return [
            'boleta.required' => 'La boleta Bsale es obligatoria.',
            'dni.required' => 'El DNI es obligatorio.',
        ];
    }

    public function boleta(): string
    {
        return trim((string) $this->validated('boleta'));
    }

    public function dni(): string
    {
        return trim((string) $this->validated('dni'));
    }
}
