<?php

namespace App\Http\Requests\Order;

use App\Enums\Crypto;
use App\Enums\OrderSide;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return ! is_null($this->user());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'symbol' => ['required', 'string', Rule::in(Crypto::values())],
            'side'   => ['nullable', 'string', Rule::in(OrderSide::values())],
            // Validate as decimal-like strings; let the server handle precision
            'price'  => ['required', 'regex:/^\d+(?:\.\d{1,18})?$/', 'not_in:0', 'gt:0'],
            'amount' => ['required', 'regex:/^\d+(?:\.\d{1,18})?$/', 'not_in:0', 'gt:0'],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function messages(): array
    {
        return [
            'symbol.in'    => 'Selected symbol is not supported.',
            'price.regex'  => 'Price must be a positive decimal number.',
            'amount.regex' => 'Amount must be a positive decimal number.',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function validated($key = null, $default = null)
    {
        $data = parent::validated($key, $default);
        // Force side to buy regardless of input
        $data['side'] = 'buy';

        return $data;
    }
}
