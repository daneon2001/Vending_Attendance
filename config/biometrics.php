<?php

return [
    'fingerprint' => [
        'default_vendor' => env('BIOMETRIC_FINGERPRINT_VENDOR', 'digitalpersona'),
        'default_source' => env('BIOMETRIC_FINGERPRINT_SOURCE', 'scanner'),
        'enrolment_allowed_formats' => [
            'DPFP_PROPRIETARY',
            'zkteco-v1',
        ],
        'legacy_compatible_formats' => [
            'DPFP_PROPRIETARY',
        ],
    ],
    'face' => [
        'default_vendor' => env('BIOMETRIC_FACE_VENDOR', 'digitalpersona'),
        'default_source' => env('BIOMETRIC_FACE_SOURCE', 'camera'),
    ],
];
