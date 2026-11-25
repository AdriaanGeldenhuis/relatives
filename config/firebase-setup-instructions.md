# 🔥 Firebase Cloud Messaging Setup

Push notifications are sent through the FCM HTTP v1 API using a service
account. **Without the service-account file on the server, no push
notification can be delivered** — the old "legacy server key" fallback no
longer exists (Google shut that API down in June 2024).

## Step 1: Get Service Account JSON

1. Go to [Firebase Console](https://console.firebase.google.com/)
2. Select your project (or create one)
3. Click the gear icon ⚙️ → **Project Settings**
4. Go to **Service Accounts** tab
5. Click **Generate New Private Key**
6. Through SFTP or the hosting file manager, save the key as `firebase-service-account.json`
   in the account folder that contains `public_html`. Give the website's PHP user read
   access. Keep the key out of Git.

Relatives reads the project ID from the key. The legacy location
`public_html/config/firebase-service-account.json` is supported for existing setups
and blocked from web access by the root Apache rules.

## Optional: .env override

```env
# Only needed to override the project_id inside the service-account JSON:
FCM_PROJECT_ID=your-project-id
```

`FCM_SERVER_KEY` is obsolete and ignored — remove it from `.env` if present.

## Verifying

- `notifications → Settings → send test notification` exercises the full
  pipeline including FCM.
- Delivery attempts are logged in the `notification_delivery_log` table:
  `status = 'sent'` means FCM accepted the message; `failed` rows include the
  error in `error_message`.
- The Wake button reports the cause when Firebase setup blocks delivery. The
  `wake_devices.php` response includes a `reason` code. Check the PHP error log for
  the corresponding `FCM:` line, which gives the OAuth HTTP status and cURL error
  number without logging the key or token.

## Database

Run `migrations/notifications-fix-v2.sql` once against the live database. It
drops the `user_device` unique key on `fcm_tokens` that broke token
re-registration (HTTP 500) whenever a device's FCM token rotated.
