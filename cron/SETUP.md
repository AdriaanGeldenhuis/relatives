# Relatives release and scheduled jobs

## Release sequence

1. On staging, verify the deployed revision, PHP 8.2 or newer, the web and CLI PHP extensions, the Firebase project and signing package, and the active crontab. Match the Play credentials to the APK under test.
2. Rehearse the database upgrade on a backup clone. Apply the privacy, notification, calendar sync, Holiday, and tracking migrations required by the installed schema. Convert a legacy tracking schema with a preserved copy and reconcile history and places by family and capture time. Apply `tracking/migrations/003_tracking_reliability.sql` and then `migrations/app-reliability-v1.sql` with the MariaDB or MySQL CLI. The latter contains chat revision triggers and widens the message attachment column.
3. Configure `OPENWEATHER_API_KEY` in the site environment using a rotated key. Configure the protected Firebase HTTP v1 service account and Mapbox as described by the hosting setup. Generate a VAPID key pair with the installed PHP Web Push library and set `WEB_PUSH_PUBLIC_KEY`, `WEB_PUSH_PRIVATE_KEY`, and `WEB_PUSH_SUBJECT`. Enable browser registration with `WEB_PUSH_ENABLED=1` after testing opt-in and display. Run `php maintenance/release-check.php /path/to/relatives` on the host.
4. Deploy PHP, then send the Android build to the Play internal track. Test Wake with both phones, including a locked recipient and account revocation. Enable `TRACKING_CONFIRMED_WAKE=1` and `TRACKING_ADAPTIVE_CADENCE=1` on staging before benchmarking. Test browser push on Chromium, Firefox, Safari, and an iPhone or iPad Home Screen install. Widen rollout when the device, latency, and battery checks pass. The site workflow checks schema and dependencies before syncing the website.
5. To pause new Web Push registrations and sends, set `WEB_PUSH_ENABLED=0`. Use `PUSH_OUTBOX_ENABLED=0` for legacy Android delivery if queue rollback is needed. Location reliability switches can be reset independently. Keep the compatible database additions during rollback.

## Cron

Use the production PHP CLI binary with an absolute site path. Route output to a private rotating log outside the web root. This example uses a server clock set to Africa/Johannesburg.

```crontab
* * * * * /usr/bin/php /path/to/relatives/cron/push-deliveries.php
* * * * * /usr/bin/php /path/to/relatives/cron/notification-reminders.php
* * * * * /usr/bin/php /path/to/relatives/cron/weather-notifications.php
*/15 * * * * /usr/bin/php /path/to/relatives/tracking/jobs/recompute_geofence_states.php
7 * * * * /usr/bin/php /path/to/relatives/cron/reconcile-google-play.php
0 3 * * * /usr/bin/php /path/to/relatives/cron/notification-cleanup.php
5 3 * * * /usr/bin/php /path/to/relatives/maintenance/cleanup.php
10 3 * * * /usr/bin/php /path/to/relatives/tracking/jobs/prune_reliability.php
15 3 * * * /usr/bin/php /path/to/relatives/tracking/jobs/prune_events.php
20 3 * * * /usr/bin/php /path/to/relatives/tracking/jobs/prune_history.php
```

Use the single Calendar and Schedule reminder entry. It includes birthdays, anniversaries, and calendar sync. Remove the old duplicate reminder entries during cutover. The existing 10-minute Wake maintenance job applies only to older Android installations that support it.

Check `scheduled_job_status` for the push, weather, reminder, geofence, and maintenance jobs. The push job logs the age of the oldest due delivery. Review failed delivery states and distinguish provider acceptance from a device receipt.
