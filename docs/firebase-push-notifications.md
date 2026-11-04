# Firebase push notifications

Firebase Cloud Messaging (FCM) web push for:

- Order and parcel status updates
- Marketing campaigns to opted-in subscribers

## 1. Enable FCM in Firebase

1. Firebase Console → **Project settings → Cloud Messaging**.
2. Under **Web Push certificates**, generate a key pair.
3. Copy the **VAPID public key**.
4. **Project settings → Service accounts → Generate new private key**.
5. Download the JSON file.

## 2. Configure the admin channel

1. Sign in as platform admin.
2. Go to **Admin → Communications → Firebase Phone Auth**.
3. Paste:
   - **Web Push VAPID key**
   - **Service account JSON** (the whole JSON, minified or one line)
4. Keep the channel **Active**.
5. Save.

No `.env` change is required. Environment fallback:

```dotenv
FIREBASE_VAPID_KEY=
FIREBASE_SERVICE_ACCOUNT_JSON=
FIREBASE_SERVER_KEY=
```

## 3. How users enable push

The **Push notifications** card appears in:

- Customer portal
- Business settings

Users click **Enable push notifications**, grant browser permission, and may
tick **Send me marketing messages**.

The browser registers:

```text
/firebase-messaging-sw.js
```

and the FCM token is stored in `push_tokens` against the signed-in user.

## 4. Order status updates

`notification_send()` now sends one FCM push per order event, deduplicated
across SMS/email channels. The notification opens:

```text
/track-mzigo?num=TRK-...
```

## 5. Marketing messages

Campaigns push only to users who:

- have a saved FCM token with `marketing_opt_in = 1`
- still have marketing enabled in their notification preferences

The push opens the campaign CTA or unsubscribe link.

## 6. Database

Migration `044_push_tokens.sql` adds:

```text
push_tokens
```

## 7. Service worker

The web app config is public and currently embedded in:

```text
/firebase-messaging-sw.js
```

If the Firebase project changes, update the channel and this file together.

## 8. Security

- Push token API requires a signed-in user and CSRF
- One token per browser, refreshed on each enable
- Invalid/unregistered FCM tokens are removed automatically
- Marketing pushes are filtered by opt-in
- Service-account credentials are stored encrypted in `communication_channels`
- `/api/push-config.php` exposes only the public Firebase web config and VAPID key
