<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class SyncOrdersRequest extends FormRequest
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
            'stores' => ['nullable', 'string'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
        ];
    }

    /**
     * @return list<string>
     */
    public function getStores(): array
    {
        $raw = (string) $this->input('stores', '');

        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public function getFromDate(): ?Carbon
    {
        $value = $this->input('from_date');

        return $value ? Carbon::parse((string) $value, 'UTC')->startOfDay() : null;
    }

    public function getToDate(): ?Carbon
    {
        $value = $this->input('to_date');

        return $value ? Carbon::parse((string) $value, 'UTC')->endOfDay() : null;
    }
}
