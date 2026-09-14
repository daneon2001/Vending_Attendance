package com.medicalife.vendingattendance;

import java.nio.file.Files;
import java.nio.file.Paths;
import javax.xml.parsers.DocumentBuilderFactory;
import org.junit.Test;
import org.w3c.dom.Element;
import static org.junit.Assert.*;

public class FieldNetworkSecurityTest {
    @Test
    public void userCaTrustIsExplicitAndLimitedToDemoHostInDebug() throws Exception {
        DocumentBuilderFactory factory = DocumentBuilderFactory.newInstance();
        factory.setFeature("http://apache.org/xml/features/disallow-doctype-decl", true);
        Element root = factory.newDocumentBuilder().parse(Paths.get("src/debug/res/xml/field_demo_network_security.xml").toFile()).getDocumentElement();
        Element base = (Element) root.getElementsByTagName("base-config").item(0);
        assertEquals(1, base.getElementsByTagName("certificates").getLength());
        assertEquals("system", ((Element) base.getElementsByTagName("certificates").item(0)).getAttribute("src"));
        assertEquals(1, root.getElementsByTagName("domain-config").getLength());
        Element domain = (Element) root.getElementsByTagName("domain").item(0);
        assertEquals("192.168.1.82", domain.getTextContent());
        assertEquals("false", domain.getAttribute("includeSubdomains"));
        Element scoped = (Element) root.getElementsByTagName("domain-config").item(0);
        assertEquals("user", ((Element) scoped.getElementsByTagName("certificates").item(1)).getAttribute("src"));
        assertEquals(0, root.getElementsByTagName("debug-overrides").getLength());
    }

    @Test
    public void releaseDoesNotReferenceDemoTrustOrCleartext() throws Exception {
        String main = new String(Files.readAllBytes(Paths.get("src/main/AndroidManifest.xml")), java.nio.charset.StandardCharsets.UTF_8);
        String debug = new String(Files.readAllBytes(Paths.get("src/debug/AndroidManifest.xml")), java.nio.charset.StandardCharsets.UTF_8);
        assertTrue(main.contains("android:usesCleartextTraffic=" + (char) 34 + "false" + (char) 34));
        assertFalse(main.contains("networkSecurityConfig"));
        assertFalse(Files.exists(Paths.get("src/main/res/xml/field_demo_network_security.xml")));
        assertTrue(debug.contains("@xml/field_demo_network_security"));
    }
}
