<?php

namespace App\Services\Biometrics;

use App\Models\EmployeeFingerprint;

class TemplateMetadataResolver
{
    /**
     * @return array{biometric_type:string,vendor:string,source:string}
     */
    public function fromTemplate(EmployeeFingerprint $template): array
    {
        $biometricType = $this->normalizeBiometricType($template->enrolment_type);
        $defaults = $this->defaultsFor($biometricType);

        return [
            'biometric_type' => $biometricType,
            'vendor' => $this->normalizeString($template->template_vendor ?? null, $defaults['vendor']),
            'source' => $this->normalizeString($template->template_source ?? null, $defaults['source']),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{biometric_type:string,vendor:string,source:string}
     */
    public function fromPayload(array $payload, ?string $biometricType = null): array
    {
        $resolvedType = $this->normalizeBiometricType($biometricType ?? ($payload['enrolment_type'] ?? null));
        $defaults = $this->defaultsFor($resolvedType);

        return [
            'biometric_type' => $resolvedType,
            'vendor' => $this->normalizeString($payload['template_vendor'] ?? null, $defaults['vendor']),
            'source' => $this->normalizeString($payload['template_source'] ?? null, $defaults['source']),
        ];
    }

    /**
     * @return array{vendor:string,source:string}
     */
    public function defaultsFor(string $biometricType): array
    {
        $normalized = $this->normalizeBiometricType($biometricType);

        if ($normalized === EmployeeFingerprint::TYPE_FACE) {
            return [
                'vendor' => $this->normalizeString(config('biometrics.face.default_vendor'), 'digitalpersona'),
                'source' => $this->normalizeString(config('biometrics.face.default_source'), 'camera'),
            ];
        }

        return [
            'vendor' => $this->normalizeString(config('biometrics.fingerprint.default_vendor'), 'digitalpersona'),
            'source' => $this->normalizeString(config('biometrics.fingerprint.default_source'), 'scanner'),
        ];
    }

    private function normalizeBiometricType(?string $biometricType): string
    {
        return strtoupper(trim((string) $biometricType)) === EmployeeFingerprint::TYPE_FACE
            ? EmployeeFingerprint::TYPE_FACE
            : EmployeeFingerprint::TYPE_FINGERPRINT;
    }

    private function normalizeString(mixed $value, string $fallback): string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : $fallback;
    }
}
