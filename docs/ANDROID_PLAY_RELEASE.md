# Relatives Android release checks

The Android project lives in `App Files`. This release uses package `za.co.relatives.app`, target SDK 36, version code 7, and Google Play Billing 8.3.0. Compare the version code against the highest code already uploaded in Play Console before signing.

## Build and deploy

- From the Android project, run the Gradle wrapper with Java 21 and Android SDK 36. In the prepared cloud environment, run `RELATIVES_GRADLE_WORKERS=1 bash /workspace/relatives-dev/build-android.sh :app:testDebugUnitTest :app:lintRelease :app:assembleDebug :app:bundleRelease`. If lint and the release task run in the same invocation, use one worker to avoid a lint analysis crash.
- Deploy the PHP changes with the Android update. Apply `migrations/privacy-requests-v1.sql` before exposing the privacy controls or deletion-request form. Serve the web app over HTTPS, and verify Apache honors the access rules for the SQL dumps and native source directory.
- Existing installations need to enable family location sharing again. Pre-upgrade queued fixes lack an account binding and are cleared by the migration. New queued fixes remain bound to the login and consent under which they were collected.
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
4. Tap **Wake** from the second account. Verify one refresh, a visible notice on the recipient phone, and no refresh after consent is revoked. Repeat with the location toggle disabled and with a different account signed in on the same device.
5. Clear sharing from Privacy & Security and verify that family members lose access to the last location and location history. Test the deletion request using a synthetic account and verify the privacy team can complete it under the production retention policy.
6. Use Play license testing to verify purchase, pending purchase, restore, cancellation, renewal, and expiry from Android through to the family entitlement.
