<?php

namespace App\Http\Requests\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UsageReportRequest extends FormRequest
{
    public const int MAX_RANGE_DAYS = 92;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:start_date'],
        ];
    }

    /**
     * Reject ranges wider than Pixelforge's own 92-day /v1/usage limit.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            [$start, $end] = $this->range();

            if ($start->diffInDays($end) + 1 > self::MAX_RANGE_DAYS) {
                $validator->errors()->add(
                    'start_date',
                    'The date range may not exceed '.self::MAX_RANGE_DAYS.' days.',
                );
            }
        });
    }

    /**
     * Resolved UTC date range — defaults to the last 30 days ending today,
     * matching Pixelforge's own /v1/usage default window.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(): array
    {
        $endInput = $this->input('end_date');
        $startInput = $this->input('start_date');

        $end = is_string($endInput) && $endInput !== ''
            ? CarbonImmutable::parse($endInput)->startOfDay()
            : CarbonImmutable::now()->startOfDay();

        $start = is_string($startInput) && $startInput !== ''
            ? CarbonImmutable::parse($startInput)->startOfDay()
            : $end->subDays(29);

        return [$start, $end];
    }
}
