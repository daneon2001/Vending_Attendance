package com.medicalife.vendingattendance;

import android.app.KeyguardManager;
import android.content.Context;
import android.content.pm.PackageManager;
import android.content.pm.ApplicationInfo;
import android.os.Build;
import android.security.keystore.KeyInfo;
import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyProperties;
import android.util.Base64;
import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import java.nio.charset.StandardCharsets;
import java.security.KeyPairGenerator;
import java.security.KeyFactory;
import java.security.KeyStore;
import java.security.PrivateKey;
import java.security.Signature;
import java.security.spec.ECGenParameterSpec;
import java.util.UUID;
import org.json.JSONObject;

/** Separate namespace from vending HMAC storage. No delete/export-private API. */
@CapacitorPlugin(name = "FieldDeviceKey")
public class FieldDeviceKeyPlugin extends Plugin {
    private String alias(String uuid) {
        if (uuid == null || !UUID.fromString(uuid).toString().equals(uuid)) {
            throw new IllegalArgumentException("Invalid identity");
        }
        return "field_mobile_v1_" + uuid;
    }

    private KeyStore store() throws Exception {
        KeyStore store = KeyStore.getInstance("AndroidKeyStore");
        store.load(null);
        return store;
    }

    private void requireUnlocked() {
        KeyguardManager manager = (KeyguardManager) getContext().getSystemService(Context.KEYGUARD_SERVICE);
        if (manager == null || manager.isDeviceLocked()) {
            throw new IllegalStateException("Device locked");
        }
    }

    /** Existing key inspection only: never creates a replacement on restart. */
    @PluginMethod
    public synchronized void inspectKey(PluginCall call) {
        try {
            requireUnlocked();
            String keyAlias = alias(call.getString("deviceUuid"));
            KeyStore keyStore = store();
            JSObject result = new JSObject();
            boolean available = keyStore.containsAlias(keyAlias);
            result.put("available", available);
            result.put("originRecoveryAllowed", FieldOriginRecoveryPolicy.allows(
                BuildConfig.FIELD_RECOVERY_TARGET, BuildConfig.FIELD_RECOVERY_PREVIOUS_SHA256,
                call.getString("previousOrigin"), call.getString("currentOrigin")));
            result.put("backing", "UNKNOWN");
            result.put("strongBoxAvailable", Build.VERSION.SDK_INT >= Build.VERSION_CODES.P
                && getContext().getPackageManager().hasSystemFeature(PackageManager.FEATURE_STRONGBOX_KEYSTORE));
            if (available) {
                // Match OpenSSL's canonical SPKI PEM fingerprint, never export the private key.
                String encoded = Base64.encodeToString(keyStore.getCertificate(keyAlias).getPublicKey().getEncoded(), Base64.NO_WRAP);
                result.put("keyFingerprint", FieldPublicKeyFingerprint.fromBase64(encoded));
                result.put("keyVersion", 1);
                PrivateKey key = (PrivateKey) keyStore.getKey(keyAlias, null);
                KeyInfo info = KeyFactory.getInstance(key.getAlgorithm(), "AndroidKeyStore").getKeySpec(key, KeyInfo.class);
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                    int level = info.getSecurityLevel();
                    if (level == KeyProperties.SECURITY_LEVEL_TRUSTED_ENVIRONMENT || level == KeyProperties.SECURITY_LEVEL_STRONGBOX) {
                        result.put("backing", "HARDWARE");
                    } else if (level == KeyProperties.SECURITY_LEVEL_SOFTWARE) {
                        result.put("backing", "SOFTWARE");
                    }
                } else {
                    result.put("backing", info.isInsideSecureHardware() ? "HARDWARE" : "SOFTWARE");
                }
            }
            call.resolve(result);
        } catch (Exception ignored) {
            call.reject("No fue posible consultar la clave del dispositivo.");
        }
    }

    @PluginMethod
    public synchronized void createKey(PluginCall call) {
        try {
            requireUnlocked();
            String uuid = call.getString("deviceUuid");
            String alias = alias(uuid);
            KeyStore store = store();
            if (!store.containsAlias(alias)) {
                KeyPairGenerator generator = KeyPairGenerator.getInstance(KeyProperties.KEY_ALGORITHM_EC, "AndroidKeyStore");
                generator.initialize(new KeyGenParameterSpec.Builder(alias, KeyProperties.PURPOSE_SIGN)
                    .setAlgorithmParameterSpec(new ECGenParameterSpec("secp256r1"))
                    .setDigests(KeyProperties.DIGEST_SHA256)
                    .build());
                generator.generateKeyPair();
            }
            byte[] publicBytes = store.getCertificate(alias).getPublicKey().getEncoded();
            String publicKey = "-----BEGIN PUBLIC KEY-----\n"
                + Base64.encodeToString(publicBytes, Base64.NO_WRAP) + "\n-----END PUBLIC KEY-----\n";
            JSObject result = new JSObject();
            result.put("deviceUuid", uuid);
            result.put("publicKey", publicKey);
            result.put("keyVersion", 1);
            result.put("keyStore", "AndroidKeyStore");
            call.resolve(result);
        } catch (Exception ignored) {
            call.reject("No fue posible preparar la clave del dispositivo.");
        }
    }

    @PluginMethod
    public synchronized void sign(PluginCall call) {
        try {
            requireUnlocked();
            String uuid = call.getString("deviceUuid");
            String alias = alias(uuid);
            String message = call.getString("message");
            if (message == null || message.length() > 4096 || !message.startsWith("FIELD_MOBILE_V1\n")) {
                throw new IllegalArgumentException("Invalid challenge");
            }
            JSONObject body = new JSONObject(message.substring("FIELD_MOBILE_V1\n".length()));
            if (!uuid.equals(body.getString("device_uuid"))
                || !("ENROLLMENT".equals(body.getString("purpose")) || "ACTOR".equals(body.getString("purpose")))) {
                throw new IllegalArgumentException("Invalid binding");
            }
            PrivateKey key = (PrivateKey) store().getKey(alias, null);
            Signature signer = Signature.getInstance("SHA256withECDSA");
            signer.initSign(key);
            signer.update(message.getBytes(StandardCharsets.UTF_8));
            JSObject result = new JSObject();
            result.put("signature", Base64.encodeToString(signer.sign(), Base64.NO_WRAP));
            call.resolve(result);
        } catch (Exception ignored) {
            call.reject("No fue posible verificar la clave del dispositivo.");
        }
    }
}
