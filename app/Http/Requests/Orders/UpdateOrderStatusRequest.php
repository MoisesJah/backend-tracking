<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization is handled in the controller
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                Rule::enum(OrderStatus::class),
            ],
            'error_reason' => [
                'nullable',
                'string',
                'max:500',
            ],
            'delivery_image' => [
                'nullable',
                'image',
                'max:5120', // 5MB
                'mimes:jpeg,png,jpg,gif,webp',
            ],
            'evidence_image' => [
                'nullable',
                'image',
                'max:5120',
                'mimes:jpeg,png,jpg,gif,webp',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'status.required' => 'El estado del pedido es requerido',
            'status.enum' => 'El estado del pedido no es válido',
            'error_reason.max' => 'La razón del error no puede exceder 500 caracteres',
            'delivery_image.image' => 'El archivo debe ser una imagen válida',
            'delivery_image.max' => 'La imagen no puede exceder 5MB',
            'delivery_image.mimes' => 'La imagen debe ser JPEG, PNG, JPG, GIF o WEBP',
            'evidence_image.image' => 'El archivo debe ser una imagen válida',
            'evidence_image.max' => 'La imagen no puede exceder 5MB',
            'evidence_image.mimes' => 'La imagen debe ser JPEG, PNG, JPG, GIF o WEBP',
        ];
    }

    /**
     * Get the validated data as an enum.
     */
    public function getStatus(): OrderStatus
    {
        return OrderStatus::from($this->validated('status'));
    }

    /**
     * Get the error reason if provided.
     */
    public function getErrorReason(): ?string
    {
        return $this->validated('error_reason');
    }

    /**
     * Check if an image was uploaded.
     */
    public function hasEvidenceImage(): bool
    {
        return $this->hasFile('evidence_image') || $this->hasFile('delivery_image');
    }

    public function evidenceImageField(): string
    {
        return $this->hasFile('evidence_image') ? 'evidence_image' : 'delivery_image';
    }
}
