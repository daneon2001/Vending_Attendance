package com.medicalife.vendingattendance;

/** Explicit one-way internal DEMO transition; never authorizes RELEASE migration. */
final class FieldOriginRecoveryPolicy {
    static boolean allows(boolean debuggable, String previous, String current) {
        return debuggable
            && ("https://192.168.101.15:8443".equals(previous)
                || "https://192.168.1.80:8443".equals(previous))
            && "https://192.168.1.82:8443".equals(current);
    }
}
