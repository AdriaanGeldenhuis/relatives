# App Analysis & iOS Porting Guide

## Android App Analysis

**Overview:**
The "Relatives" app is a hybrid application. It acts as a native wrapper around your web application (`https://www.relatives.co.za`), enhancing it with native Android capabilities that a standard browser cannot provide reliably in the background.

**Key Native Features:**
1.  **Background Location Tracking:**
    -   Uses a Foreground Service (`TrackingLocationService`) to keep the app alive and tracking even when the screen is off or the app is minimized.
    -   Uploads location data to `/tracking/api/update_location.php`.
    -   Smart logic to adjust update intervals based on user activity (viewing the tracking screen vs. background).
2.  **Web Integration:**
    -   Injects a JavaScript Interface (`TrackingBridge`) so the website can talk to the app (e.g., "I am now on the tracking screen, please update location faster").
    -   Custom User-Agent (`RelativesAndroidApp`) allowing the server to identify the app.
    -   Cookie Synchronization between the WebView (UI) and OkHttp (Service) to ensure the background service is authenticated.
3.  **Notifications:**
    -   The service polls `/notifications/api/count.php` every 30 seconds to check for new messages and triggers a local notification if found.

**Code Quality & Improvements:**
-   **Modern UI:** The app uses Jetpack Compose, which is the modern standard for Android UI.
-   **Permissions:** Handling of runtime permissions (Location, Audio) is implemented correctly, including the complex "Background Location" permission flow for Android 10+.
-   **Battery Usage:** Polling every 30 seconds for notifications is battery-intensive.
    -   *Recommendation:* Switch to Firebase Cloud Messaging (FCM) for push notifications instead of polling. This saves battery and data.

---

## iOS Porting Guide

You cannot "export" this Android code to iOS directly because the underlying technologies (Java/Kotlin vs Swift/Objective-C) and System APIs (Services vs Background Modes) are completely different. However, the logic is straightforward to replicate.

Here is the blueprint for your iOS developer (or for you, if you use Xcode):

### 1. Project Setup
-   **Language:** Swift.
-   **Framework:** UIKit or SwiftUI (SwiftUI is closer to Jetpack Compose).
-   **Main Component:** `WKWebView`.

### 2. Core Features Translation

| Android Feature | iOS Equivalent | Notes |
| :--- | :--- | :--- |
| `WebView` | `WKWebView` | Standard web view component in iOS. |
| `addJavascriptInterface` | `WKScriptMessageHandler` | You need to register a message handler (e.g., "TrackingBridge") and the JS calls `window.webkit.messageHandlers.TrackingBridge.postMessage(...)`. |
| `TrackingLocationService` | `CLLocationManager` | iOS does **not** have "Services" like Android. You must enable "Location updates" and "Background fetch" in `Info.plist`. You request "Always" location permission. |
| **Notification Polling** | **Push Notifications** | **Crucial Difference:** iOS will likely kill your app if you try to poll an API every 30 seconds in the background. You **must** implement Apple Push Notification Service (APNs) for reliable message alerts. |
| `User-Agent` | `customUserAgent` property | Set this on the `WKWebView` config. |

### 3. Step-by-Step iOS Implementation Plan

1.  **Create a Single View App** in Xcode.
2.  **Add `WKWebView`** to the main view controller. Load `https://www.relatives.co.za`.
3.  **Configure `Info.plist`**:
    -   Add `NSLocationAlwaysAndWhenInUseUsageDescription`.
    -   Add `NSLocationWhenInUseUsageDescription`.
    -   Enable "Background Modes": Check "Location updates".
4.  **Implement Location Logic**:
    -   Create a `LocationManager` class using `CoreLocation`.
    -   When `TrackingBridge` receives a message, update `distanceFilter` or `desiredAccuracy`.
    -   Send HTTP POST requests using `URLSession` (equivalent to OkHttp).
5.  **Handle Cookies**:
    -   `WKWebView` and `URLSession` share the `HTTPCookieStorage.shared` automatically in most cases, but you may need to manually sync if they diverge.

### 4. Cross-Platform Alternatives
If you don't want to write two separate apps, consider:
-   **Flutter:** You can write the logic once in Dart. Plugins exist for Background Location (`flutter_background_geolocation`) and WebView (`webview_flutter`).
-   **Kotlin Multiplatform (KMP):** You could share the "Network" and "Logic" code, but you'd still need to write the UI (SwiftUI) and specific Background Location code for iOS natively.

**Summary:**
To get this on iPhone, you need to build a native iOS app that mirrors the logic of `MainActivity.kt` and `TrackingLocationService.kt` using Swift and iOS APIs. The web part remains exactly the same.
