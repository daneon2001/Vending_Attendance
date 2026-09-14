package com.medicalife.vendingattendance;

import java.nio.charset.StandardCharsets;
import java.security.KeyPair;
import java.security.KeyPairGenerator;
import java.security.Signature;
import java.security.spec.ECGenParameterSpec;
import org.junit.Test;
import static org.junit.Assert.*;

/** Algorithm/encoding test only; does not claim hardware Keystore validation. */
public class FieldDeviceSignatureTest {
    @Test
    public void p256DerSignatureBindsExactUtf8Challenge() throws Exception {
        KeyPairGenerator generator = KeyPairGenerator.getInstance("EC");
        generator.initialize(new ECGenParameterSpec("secp256r1"));
        KeyPair keys = generator.generateKeyPair();
        Signature signer = Signature.getInstance("SHA256withECDSA");
        byte[] message = "FIELD_MOBILE_V1\nPrueba de posesión".getBytes(StandardCharsets.UTF_8);
        signer.initSign(keys.getPrivate());
        signer.update(message);
        byte[] proof = signer.sign();
        assertEquals(0x30, proof[0] & 0xff); // ASN.1 DER SEQUENCE, not JOSE r||s.
        signer.initVerify(keys.getPublic());
        signer.update(message);
        assertTrue(signer.verify(proof));
        signer.initVerify(keys.getPublic());
        signer.update("altered".getBytes(StandardCharsets.UTF_8));
        assertFalse(signer.verify(proof));
        assertEquals("X.509", keys.getPublic().getFormat());
    }
}
