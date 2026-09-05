# Mobile edge architecture

The edge client lives in `mobile/` and is independent from the Laravel/Inertia administration UI.
It uses Ionic Vue 9, TypeScript, Capacitor 7, native SQLite, Capacitor Network and Geolocation.
The application id is `com.medicalife.vendingattendance`; the display name is `Vending Attendance`.

The dependency direction is UI -> application services -> API/storage adapters -> Capacitor plugins.
`DeviceApiClient` owns request signing, `EdgeSyncService` owns foreground synchronization,
`AttendanceCaptureService` owns local capture, and `SqliteEdgeStore` owns atomic persistence.
Laravel remains the authority for device status, machine configuration, manifests, and final receipt.

The client is offline-first: an attendance event is complete once event evidence and its outbox row
commit in one SQLite transaction. Network availability is not a precondition. Synchronization runs at
startup, on foreground resume, when connectivity returns, after capture, or by explicit user action.
No background location or background sync is enabled in this phase.

The browser build exists for type/UI validation only. Provisioning and operational storage fail closed
outside a native Capacitor runtime because browser storage is not an acceptable credential store.
