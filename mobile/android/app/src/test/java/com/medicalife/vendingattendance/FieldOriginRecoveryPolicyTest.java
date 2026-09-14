package com.medicalife.vendingattendance;

import org.junit.Test;
import static org.junit.Assert.*;

public class FieldOriginRecoveryPolicyTest {
    private final String oldOrigin = "https://192.168.101.15:8443";
    private final String newOrigin = "https://192.168.1.82:8443";

    @Test public void onlyAuthorizedDebugTransitionIsAllowed() {
        assertTrue(FieldOriginRecoveryPolicy.allows(true, oldOrigin, newOrigin));
        assertTrue(FieldOriginRecoveryPolicy.allows(true, "https://192.168.1.80:8443", newOrigin));
        assertFalse(FieldOriginRecoveryPolicy.allows(false, "https://192.168.1.80:8443", newOrigin));
        assertFalse(FieldOriginRecoveryPolicy.allows(true, newOrigin, "https://192.168.1.80:8443"));
        assertFalse(FieldOriginRecoveryPolicy.allows(false, oldOrigin, newOrigin));
        assertFalse(FieldOriginRecoveryPolicy.allows(true, newOrigin, oldOrigin));
        for (String target : new String[] {"http://192.168.1.82:8443", "https://192.168.1.83:8443", "https://evil.test", newOrigin + "/", newOrigin + ".evil.test", null}) {
            assertFalse(FieldOriginRecoveryPolicy.allows(true, oldOrigin, target));
            assertFalse(FieldOriginRecoveryPolicy.allows(false, oldOrigin, target));
        }
        assertFalse(FieldOriginRecoveryPolicy.allows(true, "https://other.test", newOrigin));
    }
}
