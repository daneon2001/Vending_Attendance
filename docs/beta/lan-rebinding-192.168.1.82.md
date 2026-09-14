# LAN change to 192.168.1.82

The operator reported a new PC address during physical recovery. `ipconfig` confirmed Wi-Fi IPv4 `192.168.1.82`. Build 4 still targets the previous host and cannot complete login at the new address.

Changes are limited to local configuration and the internal beta artifact:

- Local APP_URL and the mobile API/identity URLs now use `https://192.168.1.82:8443`.
- Dedicated Apache configuration and start script bind only the new address. Previous configurations and certificates remain available.
- A new server certificate contains SAN IP `192.168.1.82`, signed by the existing local CA using the existing server public/private key. No Android or CA key generation, no system trust-store changes.
- DEBUG user-CA trust is scoped to the new host. Native recovery permits only previous `192.168.101.15` or `192.168.1.80` HTTPS origins to the new `.82` HTTPS origin. RELEASE rejects both transitions; reverse, other hosts and HTTP remain denied.
- Internal beta versionCode advances to 5, versionName stays `1.0.1-beta.1`. The beta metadata test is updated accordingly.

Validation: OpenSSL chain and IP verification PASS; Apache syntax PASS. Python's default validating TLS context with the existing CA confirmed the live server certificate and hostname, and an unauthenticated GET to the POST-only profile endpoint returned HTTP 405. An initial request timed out during FastCGI startup; the subsequent response succeeded. Windows curl reported local-CA revocation-status unknown; no TLS bypass was used.

Mobile tests: 329/330 initially passed; the only failure was the expected versionCode 4 fixture. After updating it to 5, all 9 tests in that file passed. Web build and Capacitor sync passed. Native/build evidence and artifact SHA256 are recorded in the private build 5 manifest.

Real database comparison against the pre-install checkpoint: **14/14 exact count/hash MATCH**, saved in `storage/app/private/phase-13.7.2.1-incident/lan82-baseline.json`. No OTP, attendance, activity, enrollment, device binding or phone verification changes. ASISTENCIAS_FORTIA was not accessed. No phone interactions or credential captures in this LAN adjustment.

Build 5 requires a separate installation authorization under the operator's physical artifact gate; the existing authorization named build 4. No build 5 installation, commit, tag, push or deploy is included in this step.
