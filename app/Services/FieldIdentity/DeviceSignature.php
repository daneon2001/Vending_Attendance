<?php

namespace App\Services\FieldIdentity;

final class DeviceSignature
{
    /** SPKI PEM public key; SHA256withECDSA signatures use ASN.1 DER + base64. */
    public function canonicalPublicKey(string $pem): string
    {
        abort_unless(strlen($pem) <= 1024 && preg_match('/^-----BEGIN PUBLIC KEY-----[\\s\\S]+-----END PUBLIC KEY-----\\s*$/D', $pem), 422, 'Clave pública no válida.');
        $key = @openssl_pkey_get_public($pem);
        $details = $key ? openssl_pkey_get_details($key) : false;
        abort_unless($details && $details['type'] === OPENSSL_KEYTYPE_EC
            && ($details['ec']['curve_name'] ?? '') === 'prime256v1', 422, 'Clave pública no válida.');

        return $details['key'];
    }

    public function verify(string $publicKey, string $message, string $signature): bool
    {
        if (strlen($signature) > 160) {
            return false;
        }
        $bytes = base64_decode($signature, true);

        return $bytes !== false && @openssl_verify($message, $bytes, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }
}
