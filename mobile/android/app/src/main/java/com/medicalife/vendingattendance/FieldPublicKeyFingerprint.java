package com.medicalife.vendingattendance;

import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.util.Locale;

/** SHA256 of canonical OpenSSL SPKI PEM; accepts public DER base64 only. */
final class FieldPublicKeyFingerprint {
    static String fromBase64(String encoded) throws Exception {
        StringBuilder pem = new StringBuilder("-----BEGIN PUBLIC KEY-----\n");
        for (int offset = 0; offset < encoded.length(); offset += 64) {
            pem.append(encoded, offset, Math.min(offset + 64, encoded.length())).append('\n');
        }
        pem.append("-----END PUBLIC KEY-----\n");
        byte[] digest = MessageDigest.getInstance("SHA-256").digest(pem.toString().getBytes(StandardCharsets.US_ASCII));
        StringBuilder fingerprint = new StringBuilder();
        for (byte value : digest) fingerprint.append(String.format(Locale.ROOT, "%02x", value & 0xff));
        return fingerprint.toString();
    }
}
