<?php

namespace App\Http\Requests;

use App\Models\EmployeeFingerprint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnrolmentCompleteRequest extends FormRequest
{
    public const FINGERPRINT_TEMPLATE_REQUIRED_MESSAGE = 'The template_b64 field is required for new enrolments.';

    public const FINGERPRINT_TEMPLATE_INVALID_B64_MESSAGE = 'The template_b64 field must be valid Base64.';

    public const FINGERPRINT_TEMPLATE_FORMAT_REQUIRED_MESSAGE = 'The template_format field is required for fingerprint enrolments.';

    public const FINGERPRINT_TEMPLATE_FORMAT_INVALID_MESSAGE = 'The selected template_format is invalid for fingerprint enrolments.';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('enrolment_type')) {
            $this->merge([
                'enrolment_type' => strtoupper((string) $this->input('enrolment_type')),
            ]);
        }

        foreach (['template_b64', 'template_format', 'template_vendor_id', 'employee_code', 'device_serial'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $this->merge([
                    $field => trim((string) $this->input($field)),
                ]);
            }
        }

        if (! $this->filled('employee_code') && $this->has('empleado_id') && is_scalar($this->input('empleado_id'))) {
            $this->merge([
                'employee_code' => trim((string) $this->input('empleado_id')),
            ]);
        }

        if (! $this->filled('template_b64') && $this->has('fingerprint') && is_scalar($this->input('fingerprint'))) {
            $this->merge([
                'template_b64' => trim((string) $this->input('fingerprint')),
            ]);
        }

        if ($this->has('template_format')) {
            $this->merge([
                'template_format' => $this->normalizeFingerprintTemplateFormat((string) $this->input('template_format')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['nullable', 'integer', 'exists:employees,id', 'required_without:employee_code'],
            'employee_code' => ['nullable', 'string', 'max:191', 'required_without:employee_id'],
            'clock_id' => ['nullable', 'integer', 'exists:clocks,id', 'required_without:unit_id'],
            'unit_id' => ['nullable', 'integer', 'exists:locations,id', 'required_without:clock_id'],
            'enrolment_type' => ['required', Rule::in(['FINGERPRINT', 'FACE'])],
            'template_vendor_id' => ['required', 'string', 'max:191'],
            'template_b64' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $this->requiresNewFingerprintTemplateValidation()) {
                        return;
                    }

                    $normalized = trim((string) $value);
                    if ($normalized === '') {
                        return;
                    }

                    if (! $this->fingerprintTemplateB64IsValid($normalized)) {
                        $fail(self::FINGERPRINT_TEMPLATE_INVALID_B64_MESSAGE);
                    }
                },
            ],
            'template_format' => [
                'nullable',
                'string',
                'max:40',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $this->requiresNewFingerprintTemplateValidation()) {
                        return;
                    }

                    $normalized = trim((string) $value);
                    if ($normalized === '') {
                        return;
                    }

                    if (! $this->fingerprintTemplateFormatIsAllowed($normalized)) {
                        $fail(self::FINGERPRINT_TEMPLATE_FORMAT_INVALID_MESSAGE);
                    }
                },
            ],
            'device_serial' => ['nullable', 'string', 'max:191'],
            'samples_count' => ['nullable', 'integer', 'min:0', 'max:99'],
            'quality_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'template_version' => ['nullable', 'string', 'max:80'],
            'metadata' => ['nullable', 'array'],
            'performed_at' => ['required', 'date'],
        ];
    }

    public function isFingerprintEnrolment(): bool
    {
        return strtoupper(trim((string) $this->input('enrolment_type'))) === EmployeeFingerprint::TYPE_FINGERPRINT;
    }

    public function requiresNewFingerprintTemplateValidation(): bool
    {
        if (! $this->isFingerprintEnrolment()) {
            return false;
        }

        $vendorTemplateId = trim((string) $this->input('template_vendor_id'));
        if ($vendorTemplateId === '') {
            return false;
        }

        return ! EmployeeFingerprint::query()
            ->where('vendor_template_id', $vendorTemplateId)
            ->exists();
    }

    public function fingerprintTemplateB64IsValid(?string $value = null): bool
    {
        $normalized = trim((string) ($value ?? $this->input('template_b64')));

        if ($normalized === '') {
            return false;
        }

        return base64_decode($normalized, true) !== false;
    }

    public function fingerprintTemplateFormatIsAllowed(?string $value = null): bool
    {
        $normalized = $this->normalizeFingerprintTemplateFormat((string) ($value ?? $this->input('template_format')));

        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, $this->fingerprintAllowedFormats(), true);
    }

    /**
     * @return array<int, string>
     */
    public function fingerprintAllowedFormats(): array
    {
        return collect((array) config('biometrics.fingerprint.enrolment_allowed_formats', [
            'DPFP_PROPRIETARY',
            'zkteco-v1',
        ]))
            ->map(fn (mixed $format): string => $this->normalizeFingerprintTemplateFormat((string) $format))
            ->filter()
            ->values()
            ->all();
    }

    private function normalizeFingerprintTemplateFormat(?string $value): string
    {
        $normalized = strtoupper(trim((string) $value));

        return match ($normalized) {
            'DPFP.TEMPLATE.BYTES', 'DPFP_TEMPLATE_BYTES', 'DPFP-TEMPLATE-BYTES' => 'DPFP_PROPRIETARY',
            default => $normalized,
        };
    }
}
