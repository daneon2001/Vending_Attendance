package com.medicalife.vendingattendance;

import java.nio.file.Paths;
import javax.xml.parsers.DocumentBuilderFactory;
import org.junit.Test;
import org.w3c.dom.Element;
import org.w3c.dom.NodeList;
import static org.junit.Assert.*;

public class ProductIdentityTest {
    @Test
    public void visibleNameChangesWithoutChangingPersistentIdentifiers() throws Exception {
        DocumentBuilderFactory factory = DocumentBuilderFactory.newInstance();
        factory.setFeature("http://apache.org/xml/features/disallow-doctype-decl", true);
        NodeList strings = factory.newDocumentBuilder()
            .parse(Paths.get("src/main/res/values/strings.xml").toFile()).getElementsByTagName("string");
        java.util.Map<String, String> values = new java.util.HashMap<>();
        for (int i = 0; i < strings.getLength(); i++) {
            Element entry = (Element) strings.item(i);
            values.put(entry.getAttribute("name"), entry.getTextContent());
        }
        assertEquals("Asistencia MDM", values.get("app_name"));
        assertEquals("Asistencia MDM", values.get("title_activity_main"));
        assertEquals("com.medicalife.vendingattendance", values.get("package_name"));
        assertEquals("com.medicalife.vendingattendance", values.get("custom_url_scheme"));
    }
}
