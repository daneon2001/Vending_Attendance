package com.medicalife.vendingattendance;

import java.net.URI;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;

/** An explicit build-time transition permit; never creates a key or bypasses proof of possession. */
final class FieldOriginRecoveryPolicy {
    static boolean allows(String target, String previousDigests, String previous, String current) {
        if (!origin(previous) || !origin(current) || previous.equals(current) || !current.equals(target) || previousDigests == null) return false;
        try {
            byte[] digest = MessageDigest.getInstance("SHA-256").digest(previous.getBytes(StandardCharsets.UTF_8));
            StringBuilder hex = new StringBuilder();
            for (byte b : digest) hex.append(String.format("%02x", b & 0xff));
            for (String allowed : previousDigests.split(",")) {
                if (allowed.matches("[a-f0-9]{64}") && MessageDigest.isEqual(allowed.getBytes(StandardCharsets.US_ASCII), hex.toString().getBytes(StandardCharsets.US_ASCII))) return true;
            }
        } catch (Exception ignored) { }
        return false;
    }
    private static boolean origin(String value) {
        if (value == null) return false;
        try {
            URI u = new URI(value);
            return "https".equals(u.getScheme()) && u.getHost() != null && u.getRawUserInfo() == null
                && u.getRawQuery() == null && u.getRawFragment() == null && "".equals(u.getRawPath())
                && u.getHost().equals(u.getHost().toLowerCase(java.util.Locale.ROOT));
        } catch (Exception ignored) { return false; }
    }
}
