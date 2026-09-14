package com.medicalife.vendingattendance;

import org.junit.Test;
import static org.junit.Assert.*;

public class FieldPublicKeyFingerprintTest {
    @Test public void matchesOpenSslCanonicalPemRatherThanRawDerOrUnwrappedPem() throws Exception {
        // Synthetic public-only fixture. No private key or real phone data.
        String spki = "MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE1VasBXN55qPb5rQwNKtqWkVRC5RZ7c0zm1TpkLaaV2lf5ugLvNi40mwwMS4SwTt9iU6tITA9VRtbuhLYeuhfBQ==";
        assertEquals("ea3edc187aa2b642efd6898b619096cfb0b41bc08f3dd2ca9a431fe9c0312717", FieldPublicKeyFingerprint.fromBase64(spki));
    }
}
