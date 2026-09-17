package com.medicalife.vendingattendance;

import java.nio.file.Files;
import java.nio.file.Paths;
import java.nio.charset.StandardCharsets;
import org.junit.Test;
import static org.junit.Assert.*;

/** Structural/native-resource checks, not a visual certification of launcher masks. */
public class BetaBrandResourcesTest {
    @Test
    public void originalMedicalLifeAssetIsReusedWithoutModification() throws Exception {
        byte[] original = Files.readAllBytes(Paths.get("../../../public/images/medical-life-one-mark.png"));
        assertArrayEquals(original, Files.readAllBytes(Paths.get("src/main/res/drawable-nodpi/medical_life_mark.png")));
        assertArrayEquals(original, Files.readAllBytes(Paths.get("../../public/medical-life-mark.png")));
    }

    @Test
    public void launcherAndSplashReferenceProductResourcesWithoutEnablingBackup() throws Exception {
        String manifest = new String(Files.readAllBytes(Paths.get("src/main/AndroidManifest.xml")), StandardCharsets.UTF_8);
        assertTrue(manifest.contains("android:icon=" + (char) 34 + "@drawable/product_icon" + (char) 34));
        assertTrue(manifest.contains("android:roundIcon=" + (char) 34 + "@drawable/product_icon" + (char) 34));
        assertTrue(manifest.contains("android:allowBackup=" + (char) 34 + "false" + (char) 34));
        String adaptive = new String(Files.readAllBytes(Paths.get("src/main/res/drawable-anydpi-v26/product_icon.xml")), StandardCharsets.UTF_8);
        assertTrue(adaptive.contains("<adaptive-icon"));
        assertTrue(adaptive.contains("@drawable/medical_life_mark"));
        String theme = new String(Files.readAllBytes(Paths.get("src/main/res/values/styles.xml")), StandardCharsets.UTF_8);
        assertTrue(theme.contains("windowSplashScreenAnimatedIcon"));
        assertFalse(theme.contains("@drawable/splash</item>"));
    }
}
