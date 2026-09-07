package com.medicalife.vendingattendance;

import android.content.pm.ApplicationInfo;
import android.os.Bundle;
import android.webkit.WebSettings;
import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        if (getBridge() != null) {
            // Capacitor initializes its WebView during super.onCreate. Apply the
            // native build policy before the UI thread can load the web app.
            getBridge().getWebView().getSettings().setMixedContentMode(
                mixedContentMode(getApplicationInfo().flags)
            );
        }
    }

    static int mixedContentMode(int applicationFlags) {
        // Cleartext is independently allowed by src/debug/AndroidManifest.xml.
        // Release never inherits a relaxed setting from shared Capacitor assets.
        return (applicationFlags & ApplicationInfo.FLAG_DEBUGGABLE) != 0
            ? WebSettings.MIXED_CONTENT_ALWAYS_ALLOW
            : WebSettings.MIXED_CONTENT_NEVER_ALLOW;
    }
}
