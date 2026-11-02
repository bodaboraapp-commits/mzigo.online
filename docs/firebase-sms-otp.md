# Firebase SMS OTP login

Password-less phone sign-in using **Firebase Phone Authentication**. Firebase
sends the SMS and verifies the code in the browser; Mzigo verifies the Firebase
ID token on the server before creating a session.

## 1. Create the Firebase project

1. Firebase Console → **Authentication** → **Sign-in method** → enable **Phone**.
2. **Project settings → Your apps** → add a **Web app**.
3. Copy the web config values:
   - `apiKey`
   - `authDomain`
   - `projectId`
   - `appId`

## 2. Add the channel in admin

1. Sign in as platform admin.
2. Go to **Admin → Communications**.
3. Open **Firebase Phone Auth (SMS OTP)**.
4. Paste the four web config values, enable the channel and save.

The channel is created by migration `043_firebase_channel.sql` in an inactive
state. No `.env` change is required when the channel is active.

## 3. Optional `.env` fallback

For CLI/automated environments you can configure it without the admin panel:

```dotenv
FIREBASE_ENABLED=1
FIREBASE_API_KEY=AIza...
FIREBASE_AUTH_DOMAIN=mzigo-xxxx.firebaseapp.com
FIREBASE_PROJECT_ID=mzigo-xxxx
FIREBASE_APP_ID=1:123456789:web:abc123
```

The admin channel takes priority when both are present.

## 4. Authorised domains

Firebase Console → Authentication → Settings → **Authorised domains** must
include:

- `localhost` for local testing
- `mzigo.online` for production

## 5. How it works

```text
Login page → Firebase signInWithPhoneNumber()
           → SMS code entered in browser
           → Firebase returns ID token
           → POST /api/firebase-otp-login.php
           → server verifies RS256 signature, aud, iss, exp
           → phone number is taken from the verified token
           → Mzigo session created
```

The client never sends a trusted phone number; only the signed Firebase token
is accepted.

## 6. Roles

The **SMS code** tab supports:

- **Business / employee** — existing phone account only
- **Customer** — existing customer or a new customer account
- **Freelancer / runner** — existing or new runner account

## 7. Security

- Token signature checked against Google's public certificates
- `aud`, `iss`, `exp`, `iat` and `sub` checked
- Rate limited per IP
- CSRF protected
- Every attempt audited as `auth.firebase_otp_login` / `auth.firebase_otp_failed`
- Channel must be active before the login tab appears
