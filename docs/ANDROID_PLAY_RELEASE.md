# Relatives Android release checks

The Android project lives in `App Files`. This release uses package `za.co.relatives.app`, target SDK 36, version 1.8, version code 9, and Google Play Billing 8.3.0. Compare the version code against the highest code already uploaded in Play Console before signing.

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
4. Tap **Wake** from the second account. Verify one refresh and a visible informational notice on the recipient phone, with zero extra taps on either phone. Verify that revoking consent prevents refreshes. Repeat with the location toggle disabled and with a different account signed in on the same device.
5. Clear sharing from Privacy & Security and verify that family members lose access to the last location and location history. Test the deletion request using a synthetic account and verify the privacy team can complete it under the production retention policy.
6. Use Play license testing to verify purchase, pending purchase, restore, cancellation, renewal, and expiry from Android through to the family entitlement.

## Tracking and Wake rollout

1. On staging, verify the deployed revision, the installed Play APK version, the Firebase project/package binding, and the database shape using `SHOW CREATE TABLE` for `tracking_current`, `tracking_locations`, `tracking_family_settings`, `fcm_tokens`, and `tracking_place_state`. The shipped SQL snapshots include earlier tracking tables. For a legacy schema, rehearse the V2 conversion on a backup clone. Preserve the old locations and places through cutover and reconcile the copy by family and capture time. Apply `tracking/migrations/003_tracking_reliability.sql` after the V2 and notification tables are ready. The website sync excludes SQL.
2. Enable `TRACKING_ADAPTIVE_CADENCE=1` and `TRACKING_CONFIRMED_WAKE=1` in the staging server environment and reload its environment cache if enabled. Both switches default to zero. Enable `TRACKING_DIAGNOSTICS=1` during verification for aggregate dispatch, receipt, and upload timings. Schedule `tracking/jobs/prune_reliability.php` daily to clear expired Wake metadata and old upload receipts.
3. On Play's internal track use two test accounts on Pixel and Samsung. Include screen-off walking and driving, overnight stationary sharing, Doze, Battery Saver, location/notification changes, network loss, reboot, force-stop and reopen, token rotation, account switch, and two phones on one account. Test the manual refresh and tracking fallback on a phone without Google Play services. Count a Wake success when the receiver commits a fresh fix and the viewer sees it. Check the outcome for offline, permission-blocked, and expired requests.
4. With default settings, require fresh fixes within 60 seconds for at least 95% of connected moving map observations. In at least 100 eligible remote attempts require at least 99% confirmation within 60 seconds and a 95th percentile at or below 45 seconds. Run three matched battery tests per phone against sharing off. The median extra consumption budget is 1 percentage point over eight stationary hours and 2 points per moving hour. Record GPS active time, request counts, and retries with Android Batterystats.
5. Apply the verified compatible server migrations before widening the Play rollout. See the release sequence in `cron/SETUP.md`. Keep the production switches at zero until staging and internal-track checks pass. Setting either switch back to zero restores the legacy Wake dispatch or cadence policy while preserving saved fixes. Keep the legacy maintenance job for older APKs until those installations are phased out.
