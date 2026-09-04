# Open decisions

Items remain unresolved unless repository evidence explicitly answers them.

1. Final vending hardware model, CPU architecture and peripherals.
2. Minimum and target Android version.
3. Whether the final vending hardware can actually execute iOS; likely iOS would require Apple hardware, but no decision is assumed.
4. Authoritative GPS source and accuracy requirements.
5. Integrated vending GPS versus paired/operator phone.
6. Fingerprint, face, or dual biometric policy.
7. Android biometric SDK and its offline licensing/runtime constraints.
8. iOS biometric SDK and actual template interoperability.
9. Connectivity mix: 4G, Wi-Fi, Ethernet and failover behavior.
10. Average and peak employees assigned per machine.
11. Owner and approval workflow for EmployeeMachineAssignment.
12. Fortia contract: transport, fields, status semantics, cursor, SLA and error codes.
13. SYBI contract: machine identity, lifecycle, locations, versioning and SLA.
14. Long-term device credential choice (current per-device symmetric HMAC versus asymmetric/hardware-backed keys), operational rotation ceremony, application-key rotation impact, and hardware binding.
15. Biometric consent, purpose limitation, encryption and access policy.
16. Retention and deletion periods for templates, events, raw payloads and audit records.
17. Device enrolment, suspension, transfer, revocation and disposal process.
18. OTA/application update channel, signing and rollback.
19. Availability, latency, recovery and support SLA.
20. Continuity rules during long outages, clock drift, storage exhaustion and credential expiry.

Additional inherited questions:

- Whether DigitalPersona `DPFP_PROPRIETARY` and `zkteco-v1` templates can be generated/verified by the selected Android and iOS SDKs.
- Whether `employee_face_templates.embedding_encrypted` is truly application-encrypted and how keys are managed; the model name alone does not prove encryption controls.
- Whether Fortia continues to own branch scope or only employee identity in the new architecture.
