<?php

namespace App\Http\Requests\Credits;

use App\Support\Money;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class PurchaseCreditsRequest extends FormRequest
{
    public const MAX_PURCHASE_NANODOLLARS = 1_000 * Money::NANODOLLARS_PER_USD;

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            // Strict decimal string so Money::fromUsdString can parse it exactly
            // (no floats, no scientific notation, no commas).
            'amount_usd' => ['required', 'string', 'regex:/^\d{1,6}(\.\d{1,9})?$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $amount = Money::fromUsdString((string) $this->input('amount_usd'));

            if ($amount <= 0) {
                $validator->errors()->add('amount_usd', 'The purchase amount must be greater than $0.00.');
            } elseif ($amount > self::MAX_PURCHASE_NANODOLLARS) {
                $validator->errors()->add('amount_usd', 'The maximum purchase is $1,000.00.');
            }
        });
    }
}
