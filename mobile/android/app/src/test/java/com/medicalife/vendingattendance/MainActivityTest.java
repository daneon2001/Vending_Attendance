package com.medicalife.vendingattendance;

import android.content.pm.ApplicationInfo;
import android.webkit.WebSettings;
import org.junit.Test;

import static org.junit.Assert.assertEquals;

public class MainActivityTest {
    @Test
    public void debugAllowsLocalDemoMixedContent() {
        assertEquals(WebSettings.MIXED_CONTENT_ALWAYS_ALLOW,
            MainActivity.mixedContentMode(ApplicationInfo.FLAG_DEBUGGABLE));
    }

    @Test
    public void releaseNeverAllowsMixedContent() {
        assertEquals(WebSettings.MIXED_CONTENT_NEVER_ALLOW,
            MainActivity.mixedContentMode(0));
    }

    @Test
    public void cleartextFlagAloneCannotEnableMixedContentInRelease() {
        assertEquals(WebSettings.MIXED_CONTENT_NEVER_ALLOW,
            MainActivity.mixedContentMode(ApplicationInfo.FLAG_USES_CLEARTEXT_TRAFFIC));
    }

    @Test
    public void unrelatedFlagsDoNotChangeDebugPolicy() {
        assertEquals(WebSettings.MIXED_CONTENT_ALWAYS_ALLOW,
            MainActivity.mixedContentMode(
                ApplicationInfo.FLAG_DEBUGGABLE | ApplicationInfo.FLAG_USES_CLEARTEXT_TRAFFIC));
    }
}
