<?php

return [
    'fingerprint' => [
        'default_vendor' => env('BIOMETRIC_FINGERPRINT_VENDOR', 'digitalpersona'),
        'default_source' => env('BIOMETRIC_FINGERPRINT_SOURCE', 'scanner'),
    ],
    'face' => [
        'default_vendor' => env('BIOMETRIC_FACE_VENDOR', 'digitalpersona'),
        'default_source' => env('BIOMETRIC_FACE_SOURCE', 'camera'),
    ],
];
