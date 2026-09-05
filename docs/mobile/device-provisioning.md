# Device provisioning

The operator obtains a 64-character, single-use provisioning token from the existing web admin and
enters it once in the mobile client. The client calls `POST /api/v1/device/provision` with the token
and device metadata. The server-provided `device_id` and individual HMAC credential are accepted only
from that response.

The credential is written to Android Keystore-backed secure storage or iOS Keychain with
`whenUnlockedThisDeviceOnly`; it is never written to SQLite, preferences, localStorage, logs, or UI.
The provisioning token is cleared from the input after success. If SQLite cannot record the public
device identity, secure storage is cleared so provisioning does not leave a split local state.

Provisioning cannot be validated in a browser. Re-provisioning, credential rotation and lost-device
recovery remain administrative procedures controlled by the Laravel Device Registry.
