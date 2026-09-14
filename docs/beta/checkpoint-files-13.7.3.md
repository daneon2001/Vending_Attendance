# Exact checkpoint inventory ? Phase 13.7.3

Total: 214 modified/untracked files; 198 proposed; 10 local-only excluded; 6 Phase 14 documents deferred. UNEXPECTED: 0.

This is an authorization manifest, not staging. No file has been added to the index. Status M means modified; ?? means untracked. Tests are grouped as TEST_INFRASTRUCTURE, including behavioral/security regression coverage. Source allowlists and historical LAN documentation remain versionable; host-specific Apache/OpenSSL launch configuration and the old-LAN transport probe stay local.

## Files proposed for explicit staging

| Git | Category | Exact path |
| --- | --- | --- |
| `M` | EXPECTED_PHASE_CHANGE | `.gitignore` |
| `M` | EXPECTED_PHASE_CHANGE | `app/Models/Employee.php` |
| `M` | EXPECTED_PHASE_CHANGE | `app/Models/SupportTicket.php` |
| `M` | EXPECTED_PHASE_CHANGE | `app/Models/User.php` |
| `M` | EXPECTED_PHASE_CHANGE | `app/Services/Support/SupportEvidenceService.php` |
| `M` | EXPECTED_PHASE_CHANGE | `app/Services/Support/SupportNotificationService.php` |
| `M` | TEST_INFRASTRUCTURE | `artisan` |
| `M` | TEST_INFRASTRUCTURE | `bootstrap/app.php` |
| `M` | TEST_INFRASTRUCTURE | `composer.json` |
| `M` | EXPECTED_PHASE_CHANGE | `config/permissions.php` |
| `M` | EXPECTED_PHASE_CHANGE | `config/support.php` |
| `M` | EXPECTED_PHASE_CHANGE | `mobile/android/app/build.gradle` |
| `M` | EXPECTED_PHASE_CHANGE | `mobile/android/app/src/debug/AndroidManifest.xml` |
| `M` | EXPECTED_PHASE_CHANGE | `mobile/android/app/src/main/AndroidManifest.xml` |
| `M` | EXPECTED_PHASE_CHANGE | `mobile/android/app/src/main/java/com/medicalife/vendingattendance/MainActivity.java` |
| `M` | EXPECTED_PHASE_CHANGE | `mobile/android/app/src/main/res/values/styles.xml` |
| `M` | EXPECTED_PHASE_CHANGE | `mobile/capacitor.config.ts` |
| `M` | EXPECTED_PHASE_CHANGE | `mobile/index.html` |
| `M` | EXPECTED_PHASE_CHANGE | `mobile/src/main.ts` |
| `M` | EXPECTED_PHASE_CHANGE | `mobile/src/router/index.ts` |
| `M` | EXPECTED_PHASE_CHANGE | `mobile/src/support/SqliteSupportStore.ts` |
| `M` | EXPECTED_PHASE_CHANGE | `mobile/src/support/SupportCaptureService.ts` |
| `M` | EXPECTED_PHASE_CHANGE | `mobile/src/support/types.ts` |
| `M` | EXPECTED_PHASE_CHANGE | `mobile/src/views/HomePage.vue` |
| `M` | EXPECTED_PHASE_CHANGE | `mobile/src/views/ProvisioningPage.vue` |
| `M` | TEST_INFRASTRUCTURE | `mobile/tests/unit/support-ui.spec.ts` |
| `M` | TEST_INFRASTRUCTURE | `phpunit.xml` |
| `M` | EXPECTED_PHASE_CHANGE | `resources/js/Components/SupportNotificationFeed.vue` |
| `M` | EXPECTED_PHASE_CHANGE | `resources/js/Layouts/AuthenticatedLayout.vue` |
| `M` | EXPECTED_PHASE_CHANGE | `resources/js/Pages/Support/Show.vue` |
| `M` | EXPECTED_PHASE_CHANGE | `resources/js/Pages/VendingFleet/Releases.vue` |
| `M` | EXPECTED_PHASE_CHANGE | `resources/js/Pages/VendingMachines/Show.vue` |
| `M` | EXPECTED_PHASE_CHANGE | `resources/js/presentation/navigation.js` |
| `M` | EXPECTED_PHASE_CHANGE | `resources/js/presentation/support.js` |
| `M` | EXPECTED_PHASE_CHANGE | `routes/api.php` |
| `M` | EXPECTED_PHASE_CHANGE | `routes/support-web.php` |
| `M` | EXPECTED_PHASE_CHANGE | `routes/web.php` |
| `M` | TEST_INFRASTRUCTURE | `tests/Feature/Vending/EmployeeImportTest.php` |
| `M` | TEST_INFRASTRUCTURE | `tests/Frontend/externalVisualReview.test.js` |
| `M` | TEST_INFRASTRUCTURE | `tests/Frontend/support.test.js` |
| `M` | TEST_INFRASTRUCTURE | `tests/Frontend/vueRender.mjs` |
| `M` | TEST_INFRASTRUCTURE | `tests/Integration/VendingAttendanceMysqlConcurrencyTest.php` |
| `M` | TEST_INFRASTRUCTURE | `tests/Support/phase4_mysql_concurrency_worker.php` |
| `M` | TEST_INFRASTRUCTURE | `tests/Support/support_mysql_concurrency.php` |
| `M` | TEST_INFRASTRUCTURE | `tests/TestCase.php` |
| `??` | TEST_INFRASTRUCTURE | `.env.testing.example` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Enums/Support/SupportActivityStatus.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Enums/Support/SupportActivityType.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Http/Controllers/FieldIdentity/DeviceAdminController.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Http/Controllers/FieldIdentity/DeviceIdentityController.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Http/Controllers/FieldIdentity/FieldMobileErrors.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Http/Controllers/FieldIdentity/FieldMobileSessionController.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Http/Controllers/Support/FieldSupportActivityController.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Http/Controllers/Support/SupportActivityController.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Http/Controllers/Support/SupportActivityWebController.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Http/Middleware/AuthenticateFieldMobile.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Http/Middleware/FieldMobileTransport.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Http/Middleware/RequireFieldIdentityToken.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Http/Requests/Support/SupportActivityBrowseRequest.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Http/Requests/Support/SupportActivityRequest.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Models/EmployeeDevice.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Models/SupportActivityEvidence.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Models/SupportActivityNote.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Models/VendingSupportActivity.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Services/FieldIdentity/ActorContext.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Services/FieldIdentity/DeviceIdentityService.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Services/FieldIdentity/DeviceSignature.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Services/FieldIdentity/EnrollmentIdentity.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Services/FieldIdentity/FieldMobileSession.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Services/FieldIdentity/LocalDemoPhone.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Services/FieldIdentity/LocalOtpProvider.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Services/FieldIdentity/MexicanPhone.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Services/FieldIdentity/OtpProvider.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Services/FieldIdentity/RegisteredPhoneSource.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Services/Support/FieldSupportActivities.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Services/Support/FieldSupportActivityAccess.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Services/Support/SupportActivityAccess.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Services/Support/SupportActivityContributions.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Services/Support/SupportActivityPresencePolicy.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Services/Support/SupportActivityService.php` |
| `??` | EXPECTED_PHASE_CHANGE | `app/Services/Support/SupportActivityWebQueries.php` |
| `??` | TEST_INFRASTRUCTURE | `app/Support/Testing/GuardsDestructiveCommand.php` |
| `??` | TEST_INFRASTRUCTURE | `app/Support/Testing/SafeConnectionFactory.php` |
| `??` | TEST_INFRASTRUCTURE | `app/Support/Testing/SafeDatabaseManager.php` |
| `??` | TEST_INFRASTRUCTURE | `app/Support/Testing/SafeFreshCommand.php` |
| `??` | TEST_INFRASTRUCTURE | `app/Support/Testing/SafeRefreshCommand.php` |
| `??` | TEST_INFRASTRUCTURE | `app/Support/Testing/SafeResetCommand.php` |
| `??` | TEST_INFRASTRUCTURE | `app/Support/Testing/SafeRollbackCommand.php` |
| `??` | TEST_INFRASTRUCTURE | `app/Support/Testing/SafeWipeCommand.php` |
| `??` | TEST_INFRASTRUCTURE | `app/Support/Testing/TestDatabaseGuard.php` |
| `??` | TEST_INFRASTRUCTURE | `app/Support/Testing/TestDatabasePolicy.php` |
| `??` | TEST_INFRASTRUCTURE | `app/Support/Testing/TestEnvironment.php` |
| `??` | EXPECTED_PHASE_CHANGE | `database/migrations/2026_09_08_140000_add_employee_link_to_users_table.php` |
| `??` | EXPECTED_PHASE_CHANGE | `database/migrations/2026_09_08_150000_create_vending_support_activities.php` |
| `??` | EXPECTED_PHASE_CHANGE | `database/migrations/2026_09_08_160000_index_support_activity_queries.php` |
| `??` | EXPECTED_PHASE_CHANGE | `database/migrations/2026_09_09_120000_create_field_device_identity_tables.php` |
| `??` | EXPECTED_PHASE_CHANGE | `database/migrations/2026_09_10_190000_create_support_activity_contributions.php` |
| `??` | DOCUMENTATION | `docs/architecture/vending-baseline-resolution.md` |
| `??` | DOCUMENTATION | `docs/architecture/vending-device-identity.md` |
| `??` | DOCUMENTATION | `docs/architecture/vending-field-support.md` |
| `??` | DOCUMENTATION | `docs/architecture/vending-support-activity-domain.md` |
| `??` | DOCUMENTATION | `docs/architecture/vending-support-activity-mysql-validation.md` |
| `??` | DOCUMENTATION | `docs/beta/checkpoint-files-13.7.3.md` |
| `??` | DOCUMENTATION | `docs/beta/incident-reference-reconciliation-20260913.md` |
| `??` | DOCUMENTATION | `docs/beta/incident-test-database-20260913.md` |
| `??` | DOCUMENTATION | `docs/beta/internal-beta-checklist.md` |
| `??` | DOCUMENTATION | `docs/beta/internal-beta-closeout-13.7.1.md` |
| `??` | DOCUMENTATION | `docs/beta/internal-beta-discovery.md` |
| `??` | DOCUMENTATION | `docs/beta/internal-beta-installation.md` |
| `??` | DOCUMENTATION | `docs/beta/internal-beta-rc-closeout-13.7.3.md` |
| `??` | DOCUMENTATION | `docs/beta/internal-beta-validation.md` |
| `??` | DOCUMENTATION | `docs/beta/internal-beta-visual-review.md` |
| `??` | DOCUMENTATION | `docs/beta/lan-rebinding-192.168.1.82.md` |
| `??` | DOCUMENTATION | `docs/beta/lan-rebinding-validation-13.7.2.md` |
| `??` | DOCUMENTATION | `docs/beta/origin-safe-field-mobile-recovery-13.7.2.1.md` |
| `??` | DOCUMENTATION | `docs/beta/physical-session-recovery-build5.md` |
| `??` | DOCUMENTATION | `docs/beta/session-recovery-13.7.2.md` |
| `??` | DOCUMENTATION | `docs/operations/vending-device-demo-review.md` |
| `??` | DOCUMENTATION | `docs/operations/vending-device-enrollment-runbook.md` |
| `??` | DOCUMENTATION | `docs/operations/vending-device-identity-local-schema-enablement.md` |
| `??` | DOCUMENTATION | `docs/operations/vending-field-https-admin-review.md` |
| `??` | DOCUMENTATION | `docs/operations/vending-field-support-evidence-offline.md` |
| `??` | DOCUMENTATION | `docs/operations/vending-field-support-local-schema-enablement.md` |
| `??` | DOCUMENTATION | `docs/operations/vending-field-support-mobile-demo.md` |
| `??` | DOCUMENTATION | `docs/operations/vending-field-support-visual-environment.md` |
| `??` | DOCUMENTATION | `docs/operations/vending-field-support-web-review.md` |
| `??` | DOCUMENTATION | `docs/testing/database-safety-final-classification.md` |
| `??` | DOCUMENTATION | `docs/testing/database-safety.md` |
| `??` | EXPECTED_PHASE_CHANGE | `mobile/android/app/src/debug/res/xml/field_demo_network_security.xml` |
| `??` | EXPECTED_PHASE_CHANGE | `mobile/android/app/src/main/java/com/medicalife/vendingattendance/FieldDeviceKeyPlugin.java` |
| `??` | EXPECTED_PHASE_CHANGE | `mobile/android/app/src/main/java/com/medicalife/vendingattendance/FieldOriginRecoveryPolicy.java` |
| `??` | EXPECTED_PHASE_CHANGE | `mobile/android/app/src/main/java/com/medicalife/vendingattendance/FieldPublicKeyFingerprint.java` |
| `??` | EXPECTED_PHASE_CHANGE | `mobile/android/app/src/main/res/drawable-anydpi-v26/product_icon.xml` |
| `??` | EXPECTED_PHASE_CHANGE | `mobile/android/app/src/main/res/drawable-nodpi/medical_life_mark.png` |
| `??` | EXPECTED_PHASE_CHANGE | `mobile/android/app/src/main/res/drawable/product_icon.xml` |
| `??` | EXPECTED_PHASE_CHANGE | `mobile/android/app/src/main/res/drawable/product_splash.xml` |
| `??` | EXPECTED_PHASE_CHANGE | `mobile/android/app/src/main/res/drawable/product_splash_icon.xml` |
| `??` | TEST_INFRASTRUCTURE | `mobile/android/app/src/test/java/com/medicalife/vendingattendance/BetaBrandResourcesTest.java` |
| `??` | TEST_INFRASTRUCTURE | `mobile/android/app/src/test/java/com/medicalife/vendingattendance/FieldDeviceSignatureTest.java` |
| `??` | TEST_INFRASTRUCTURE | `mobile/android/app/src/test/java/com/medicalife/vendingattendance/FieldNetworkSecurityTest.java` |
| `??` | TEST_INFRASTRUCTURE | `mobile/android/app/src/test/java/com/medicalife/vendingattendance/FieldOriginRecoveryPolicyTest.java` |
| `??` | TEST_INFRASTRUCTURE | `mobile/android/app/src/test/java/com/medicalife/vendingattendance/FieldPublicKeyFingerprintTest.java` |
| `??` | EXPECTED_PHASE_CHANGE | `mobile/internal-beta.json` |
| `??` | EXPECTED_PHASE_CHANGE | `mobile/public/medical-life-mark.png` |
| `??` | EXPECTED_PHASE_CHANGE | `mobile/src/app/startup.ts` |
| `??` | EXPECTED_PHASE_CHANGE | `mobile/src/diagnostics/collect.ts` |
| `??` | EXPECTED_PHASE_CHANGE | `mobile/src/diagnostics/presentation.ts` |
| `??` | EXPECTED_PHASE_CHANGE | `mobile/src/fieldIdentity/FieldDeviceKey.ts` |
| `??` | TEST_INFRASTRUCTURE | `mobile/src/fieldIdentity/FieldEnrollment.test.ts` |
| `??` | EXPECTED_PHASE_CHANGE | `mobile/src/fieldIdentity/FieldEnrollment.ts` |
| `??` | TEST_INFRASTRUCTURE | `mobile/src/fieldIdentity/FieldMobileFlow.test.ts` |
| `??` | EXPECTED_PHASE_CHANGE | `mobile/src/fieldIdentity/FieldMobileFlow.ts` |
| `??` | EXPECTED_PHASE_CHANGE | `mobile/src/fieldIdentity/FieldMobilePage.vue` |
| `??` | EXPECTED_PHASE_CHANGE | `mobile/src/fieldIdentity/FieldMobileStore.ts` |
| `??` | EXPECTED_PHASE_CHANGE | `mobile/src/fieldIdentity/FieldMobileTransport.ts` |
| `??` | EXPECTED_PHASE_CHANGE | `mobile/src/fieldSupport/FieldActivitiesPage.vue` |
| `??` | TEST_INFRASTRUCTURE | `mobile/src/fieldSupport/FieldActivityFlow.test.ts` |
| `??` | EXPECTED_PHASE_CHANGE | `mobile/src/fieldSupport/FieldActivityFlow.ts` |
| `??` | EXPECTED_PHASE_CHANGE | `mobile/src/fieldSupport/FieldOfflineStore.ts` |
| `??` | EXPECTED_PHASE_CHANGE | `mobile/src/fieldSupport/FieldOfflineSupport.ts` |
| `??` | EXPECTED_PHASE_CHANGE | `mobile/src/fieldSupport/services.ts` |
| `??` | EXPECTED_PHASE_CHANGE | `mobile/src/views/DiagnosticsPage.vue` |
| `??` | TEST_INFRASTRUCTURE | `mobile/tests/unit/beta-readiness.spec.ts` |
| `??` | TEST_INFRASTRUCTURE | `mobile/tests/unit/diagnostics.spec.ts` |
| `??` | TEST_INFRASTRUCTURE | `mobile/tests/unit/field-support-offline.spec.ts` |
| `??` | EXPECTED_PHASE_CHANGE | `resources/js/Components/SupportActivityList.vue` |
| `??` | EXPECTED_PHASE_CHANGE | `resources/js/Components/SupportActivityPicker.vue` |
| `??` | EXPECTED_PHASE_CHANGE | `resources/js/Components/SupportActivitySummary.vue` |
| `??` | EXPECTED_PHASE_CHANGE | `resources/js/Pages/FieldIdentity/Index.vue` |
| `??` | EXPECTED_PHASE_CHANGE | `resources/js/Pages/Support/Activities/Create.vue` |
| `??` | EXPECTED_PHASE_CHANGE | `resources/js/Pages/Support/Activities/Index.vue` |
| `??` | EXPECTED_PHASE_CHANGE | `resources/js/Pages/Support/Activities/Show.vue` |
| `??` | EXPECTED_PHASE_CHANGE | `resources/js/presentation/supportActivities.js` |
| `??` | EXPECTED_PHASE_CHANGE | `routes/field-identity-api.php` |
| `??` | EXPECTED_PHASE_CHANGE | `routes/field-identity-web.php` |
| `??` | EXPECTED_PHASE_CHANGE | `routes/field-mobile-api.php` |
| `??` | TEST_INFRASTRUCTURE | `tests/Feature/FieldIdentity/DeviceAdminTest.php` |
| `??` | TEST_INFRASTRUCTURE | `tests/Feature/FieldIdentity/DeviceIdentityTest.php` |
| `??` | TEST_INFRASTRUCTURE | `tests/Feature/FieldIdentity/FieldMobileUxTest.php` |
| `??` | TEST_INFRASTRUCTURE | `tests/Feature/Support/FieldSupportActivityTest.php` |
| `??` | TEST_INFRASTRUCTURE | `tests/Feature/Support/SupportActivityNotificationsTest.php` |
| `??` | TEST_INFRASTRUCTURE | `tests/Feature/Support/SupportActivityTest.php` |
| `??` | TEST_INFRASTRUCTURE | `tests/Feature/Support/SupportActivityWebTest.php` |
| `??` | TEST_INFRASTRUCTURE | `tests/Feature/Testing/DatabaseSafetyTest.php` |
| `??` | TEST_INFRASTRUCTURE | `tests/Feature/Vending/UserEmployeeIdentityTest.php` |
| `??` | TEST_INFRASTRUCTURE | `tests/Fixtures/database-safety/2026_09_13_000000_create_safety_probe.php` |
| `??` | TEST_INFRASTRUCTURE | `tests/Frontend/fieldIdentity.test.js` |
| `??` | TEST_INFRASTRUCTURE | `tests/Frontend/supportActivities.test.js` |
| `??` | TEST_INFRASTRUCTURE | `tests/Support/DisposableMysql.php` |
| `??` | TEST_INFRASTRUCTURE | `tests/Support/disposable_mysql.php` |
| `??` | TEST_INFRASTRUCTURE | `tests/Support/field_contributions_mysql.php` |
| `??` | TEST_INFRASTRUCTURE | `tests/Support/field_identity_mysql.php` |
| `??` | TEST_INFRASTRUCTURE | `tests/Support/field_support_mysql.php` |
| `??` | TEST_INFRASTRUCTURE | `tests/Unit/Testing/Incident20260913ConfigCacheSafetyTest.php` |
| `??` | TEST_INFRASTRUCTURE | `tests/Visual/support-activities-fixtures.mjs` |
| `??` | TEST_INFRASTRUCTURE | `tests/Visual/support-activities-review.mjs` |
| `??` | TEST_INFRASTRUCTURE | `tests/Visual/support-activities-server.mjs` |
| `??` | TEST_INFRASTRUCTURE | `tests/bootstrap.php` |

## Visible files excluded: local configuration

| Git | Category | Exact path |
| --- | --- | --- |
| `??` | LOCAL_ONLY | `tests/Support/local_https_transport_probe.php` |
| `??` | LOCAL_ONLY | `tools/local-field-https/httpd-lan-192.168.1.80.conf` |
| `??` | LOCAL_ONLY | `tools/local-field-https/httpd-lan-192.168.1.82.conf` |
| `??` | LOCAL_ONLY | `tools/local-field-https/httpd.conf` |
| `??` | LOCAL_ONLY | `tools/local-field-https/openssl-lan-192.168.1.80.cnf` |
| `??` | LOCAL_ONLY | `tools/local-field-https/openssl-lan-192.168.1.82.cnf` |
| `??` | LOCAL_ONLY | `tools/local-field-https/openssl.cnf` |
| `??` | LOCAL_ONLY | `tools/local-field-https/prepare.ps1` |
| `??` | LOCAL_ONLY | `tools/local-field-https/start-lan-192.168.1.80.ps1` |
| `??` | LOCAL_ONLY | `tools/local-field-https/start-lan-192.168.1.82.ps1` |

## Preserved documentation deferred to Phase 14

| Git | Category | Exact path |
| --- | --- | --- |
| `??` | DOCUMENTATION | `docs/architecture/biometric-dssia-vending-compatibility.md` |
| `??` | DOCUMENTATION | `docs/architecture/biometric-engine-discovery-license-audit.md` |
| `??` | DOCUMENTATION | `docs/architecture/biometric-engine-selection.md` |
| `??` | DOCUMENTATION | `docs/architecture/biometric-engine-spike-plan.md` |
| `??` | DOCUMENTATION | `docs/architecture/biometric-model-license-matrix.md` |
| `??` | DOCUMENTATION | `docs/architecture/biometric-reuse-license-audit.md` |

## Ignored local configuration and runtime exclusions

These are outside the versionable Git inventory and remain excluded. Directory entries exclude every descendant; no secret values are included.

| Scope | Excluded path or pattern |
| --- | --- |
| Local app settings | `.env`, `.env.local`, `mobile/.env.local`, other `.env.*` except the reviewed examples |
| SDK settings | `mobile/android/local.properties` |
| TLS material/runtime | `storage/framework/local-https/**` |
| Backups | `storage/app/backups/**` |
| Private evidence, APKs, manifests, recovery/reset helpers | `storage/app/private/**` |
| Runtime/cache/session/log files | `storage/framework/**` runtime content, `storage/logs/**`, generated `bootstrap/cache/*.php` |
| Uploads/photos | runtime files under `storage/app/**`, `public/storage` |
| Native generated files | `mobile/android/**/build/**`, `.gradle/**`, synchronized assets and generated Capacitor config |
| Web/dependency outputs | `mobile/dist/**`, `public/build/**`, `node_modules/**`, `mobile/node_modules/**`, `vendor/**` |
| Sensitive artifact types | `*.apk`, `*.aab`, `*.db`, `*.sqlite*`, `*.jks`, `*.keystore`, private `*.key`, `*.pem`, `*.p12`, `*.pfx` |

The six Phase 14 documents above remain untouched on disk; their exclusion is scope separation, not deletion. All existing legitimate working-tree changes are preserved.
