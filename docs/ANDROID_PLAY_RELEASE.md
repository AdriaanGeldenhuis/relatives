# Relatives Android release checks

The Android project lives in `App Files`. This release uses package `za.co.relatives.app`, target SDK 36, version 1.9.2, version code 12, and Google Play Billing 8.3.0. Compare the version code against the highest code already uploaded in Play Console before signing.

## Build and deploy

- From the Android project, use Java 21 and Android SDK 36 and run `./gradlew --max-workers=1 :app:testDebugUnitTest :app:lintRelease :app:assembleDebug :app:bundleRelease`.
- Deploy the compatible PHP changes before distributing the Android update. Follow the tracking rollout below. Apply `migrations/privacy-requests-v1.sql` before exposing the privacy controls or deletion-request form. Serve the web app over HTTPS, and verify Apache honors the access rules for the SQL dumps and native source directory.
- Upgrading from Room schema 2 preserves the existing account-bound queue and sharing consent. An upgrade from schema 1 still requires sharing opt-in and clears fixes without a verifiable account binding.
- Configure the production Play Publisher service account, the matching package name, and the active monthly products in Play Console and the server's plan table. Then schedule `cron/reconcile-google-play.php` for renewal and entitlement checks. It uses the existing production service account configuration. Open the guarded deletion-request queue at `/admin/account-deletion.php` and assign a privacy operator to process requests and retained data.

## Play Console

- Use `https://www.relatives.co.za/legal/privacy.php` for the privacy-policy URL and `https://www.relatives.co.za/legal/delete-account.php` for the external account-deletion URL once those pages are deployed. The in-app entry is **Profile → Delete account**. Requests require account authentication and a password confirmation. The Relatives privacy team fulfills the request through the production data systems.
- Declare the location foreground service and background location. Describe the core feature as opt-in family location sharing. Show the disclosure, an explicit opt-in, the persistent notification, a device with the app closed, and **Stop sharing** in the review video. For the background permission, show the separate choice to grant Android's **Allow all the time** or share during an active tracking session.
- Complete Data safety using the deployed app and enabled integrations. Check precise and approximate location, account and device identifiers, user content and media, push messaging, payment verification, the map and weather providers, and optional AI features. Declare target audience and provide reviewer credentials for an active test family with the relevant screens unlocked.
- Sign the release bundle with the Play upload key. Use an internal testing track before submitting for review. Confirm the publisher account, licensing test accounts, store products, and subscription management links there.

Official references

- [Target API requirements](https://support.google.com/googleplay/android-developer/answer/11926878)
- [Background location policy](https://support.google.com/googleplay/android-developer/answer/9799150)
- [Foreground service requirements](https://support.google.com/googleplay/android-developer/answer/13392821)
- [Account deletion requirements](https://support.google.com/googleplay/android-developer/answer/13327111)
- [Play Billing version requirements](https://developer.android.com/google/play/billing/deprecation-faq)
- [16 KB page-size support](https://developer.android.com/guide/practices/page-sizes)

## Physical-phone rollout checks

Use two accounts and at least two Android phones. Include a Huawei or Honor device if the rollout depends on a device without Google Play services.

1. Grant location for directions and confirm it leaves family sharing off. Deny the tracking disclosure, Android location, or the notification permission on separate runs, and verify the app still opens the map without uploading fixes.
2. Enable live location and confirm the ongoing notification and an updated position on the second phone. Test walking and driving with the screen off. Compare an overnight stationary run with the same phone and network on the old build. Record battery use, fix age, and whether the process remained active.
3. Test Battery Saver, loss of network and recovery, the settings round-trip, reboot, force-stop, and return to the app. Android can defer background work or refuse a restart. Record these separately from a failure while the active tracking notification is present.
4. Tap **Wake** from the second account. Verify a fresh location on the map without a toast, recipient notice, or new inbox entry on either phone. Open the map and verify that stale locations also refresh automatically. Verify that revoking consent prevents refreshes. Repeat with the location toggle disabled and with a different account signed in on the same device.
5. Clear sharing from Privacy & Security and verify that family members lose access to the last location and location history. Test the deletion request using a synthetic account and verify the privacy team can complete it under the production retention policy.
6. Use Play license testing to verify purchase, pending purchase, restore, cancellation, renewal, and expiry from Android through to the family entitlement.

## Tracking and Wake rollout

1. On staging, verify the deployed revision, the APK version, the Firebase project and package, and the installed schema. Apply migration 003 and the shared push outbox migration, followed by `tracking/migrations/004_tracking_evidence.sql`. The normal website deployment applies the additive 004 migration during its database preflight. Rehearse upgrades against a backup clone and preserve locations and places through cutover.
2. Set `TRACKING_DIAGNOSTICS=1` for aggregate dispatch, device, capture, and upload timings. Run the updated geofence engine with `TRACKING_GEOFENCE_DELIVERY=0` to verify recorded crossings before sending alerts to the staging family. The automatic map refresh and each current snapshot are scoped to active family members. An older APK keeps its regular location updates and must upgrade to receive silent remote refreshes.
3. On Play's internal track, test on Samsung and a second Android brand. Include screen-off walking and driving, overnight idle, Doze, both coarse and precise grants, Battery Saver, offline recovery, reboot, force-stop and reopen, account switch, and two phones signed into one account. Test manual refresh and the platform provider on a device without Google Play services. Log blocked or unreachable phones separately from connected phones actively tracking.
4. Try at least 100 refreshes with connected phones actively sharing. Require at least 95% of fixes visible on the viewer's map within 30 seconds, with zero new refresh notices or badges. On a same-car route with three or four phones, compare samples captured within one second and require at least 95% of precise open-road samples to be within 50 m of one another. Record actual on-screen pin distances and capture-age spread independently.
5. Replay at least 100 known circle and polygon crossings and repeat representative routes with phones locked. For supported, well-observed crossings require 99% detection and zero duplicates. For phones online and actively tracking, aim for 95% of alerts within 45 seconds. Record OS-only delays separately. Verify quick re-entry, boundary jitter, small zones, edits, delayed uploads and muted preferences. Tap a notification to open the exact event.
6. Run three matched battery tests per phone against sharing off and the previous build. Use a median budget of one additional percentage point over eight stationary hours and two points per moving hour. Record GPS active time and retries. Widen the Android rollout once these phone checks pass.
7. To pause specific changes, set `TRACKING_SILENT_WAKE=0`, `TRACKING_LOCATION_QUALITY=0`, `TRACKING_DRIVING_ACCURACY=0`, or `TRACKING_GEOFENCE_DELIVERY=0` independently. The silent Wake switch pauses remote refreshes. Leave the additive columns in place.
