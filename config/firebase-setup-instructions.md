\# 🔥 Firebase Cloud Messaging Setup



\## Step 1: Get Service Account JSON



1\. Go to \[Firebase Console](https://console.firebase.google.com/)

2\. Select your project (or create one)

3\. Click the gear icon ⚙️ → \*\*Project Settings\*\*

4\. Go to \*\*Service Accounts\*\* tab

5\. Click \*\*Generate New Private Key\*\*

6\. Save the JSON file as: `/config/firebase-service-account.json`



\## Step 2: Add to .env



Add these to your `.env` file:



```env

FCM\_PROJECT\_ID=your-project-id

FCM\_SERVER\_KEY=your-legacy-server-key (optional - for fallback)

