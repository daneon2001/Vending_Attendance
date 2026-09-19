package com.medicalife.vendingattendance;
import org.junit.Test;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import static org.junit.Assert.*;
public class FieldOriginRecoveryPolicyTest {
    private String digest(String s) throws Exception {
        StringBuilder result = new StringBuilder();
        for (byte b : MessageDigest.getInstance("SHA-256").digest(s.getBytes(StandardCharsets.UTF_8))) result.append(String.format("%02x", b & 255));
        return result.toString();
    }
    @Test public void explicitLanToDomainTransitionOnly() throws Exception {
        String old = "https://192.0.2.10:8443", target = "https://beta.example.test";
        assertTrue(FieldOriginRecoveryPolicy.allows(target, digest(old), old, target));
        assertFalse(FieldOriginRecoveryPolicy.allows("", digest(old), old, target));
        assertFalse(FieldOriginRecoveryPolicy.allows(target, "", old, target));
        assertFalse(FieldOriginRecoveryPolicy.allows(target, digest(target), old, target));
        for (String invalid : new String[]{null, "http://beta.example.test", target+"/", target+".evil.test", target+"?q=1", "https://user@beta.example.test"})
            assertFalse(FieldOriginRecoveryPolicy.allows(target, digest(old), old, invalid));
        assertFalse(FieldOriginRecoveryPolicy.allows(target, digest(target), target, target));
    }
    @Test public void exactDomainTransitionRequiresAuthorizedPreviousDigest() throws Exception {
        String previous = "https://identity.example.test", target = "https://next.example.test:8443";
        assertTrue(FieldOriginRecoveryPolicy.allows(target, digest(previous), previous, target));
        assertFalse(FieldOriginRecoveryPolicy.allows(target, digest(target), previous, target));
        assertFalse(FieldOriginRecoveryPolicy.allows(target, digest(previous), previous, "https://other.example.test"));
    }
}
