# KHAN Management

PHP-based management website for the KHAN gold and jewelry marketplace.

## Stage 6 status

This repository contains the dependency-free project bootstrap and Firebase Authentication foundation:

- A public Apache document root and front controller.
- A development/production-aware error-handling bootstrap.
- A `/` welcome route and `/health` health route.
- Firebase email/password signup and login through the Firebase Authentication REST API.
- A minimal protected `GET /auth/me` endpoint using verified Firebase ID tokens.
- A read-only Firestore user-profile lookup at `users/{uid}` for `/auth/me`.
- Branded responsive signup and login pages with server-side validation.
- Session-backed logout and a placeholder jeweler application route.
- Dependency-free server-side Firebase ID-token verification using Google’s published secure-token certificates.
- Placeholders for application, configuration, routes, views, storage, and tests.
- No protected dashboards, Supabase Storage, uploads, applications, or admin workflows yet.

## Technology stack

- PHP 8.2
- Apache through XAMPP
- Firebase Authentication through REST APIs
- Cloud Firestore through the REST API
- Supabase Storage REST client for a private `jeweler-documents` bucket

## Prerequisites

- Windows with XAMPP installed.
- PHP 8.2 available at `C:\xampp\php\php.exe`.
- Apache enabled in the XAMPP Control Panel.
- Composer is not required for the current stages and has not been installed. Firebase Admin SDK is therefore not added; the existing Firebase REST integration avoids introducing a dependency and does not require a service-account key.

The current XAMPP PHP installation includes the baseline extensions needed by the bootstrap and planned HTTP integrations, including `curl`, `fileinfo`, `json`, `mbstring`, `openssl`, and `session`.

## Run locally with XAMPP

1. Start Apache from the XAMPP Control Panel.
2. The recommended document root is:

   `C:\xampp\htdocs\khan-mgmt\khan-mgmt\public`

   Apache has not been reconfigured by this project. With the default XAMPP document root (`C:\xampp\htdocs`), use:

   `http://localhost/khan-mgmt/khan-mgmt/public/`

   The web server should expose `public/`, not the repository root. A virtual host or document-root change may be configured separately later, but none is assumed here.
3. Ensure Apache has `mod_rewrite` enabled and permits `.htaccess` overrides so `public/.htaccess` can route non-file requests to `public/index.php`.
4. Copy `.env.example` to `.env` and fill in the Firebase and Supabase values. Keep `.env` local; it is ignored by Git.
5. Set `FIREBASE_WEB_API_KEY` and `FIREBASE_PROJECT_ID` to the existing Khan Firebase project values. Set `SUPABASE_URL`, `SUPABASE_STORAGE_BUCKET`, and `SUPABASE_SERVICE_ROLE_KEY` for the existing Supabase project. Do not put credentials in the repository or frontend.
6. Run the read-only service connection check:

   ```powershell
   & 'C:\xampp\php\php.exe' tests\service_connection_test.php
   ```

7. Open the configured local URL in a browser:

   - `/` — welcome response
   - `/health` — health response
   - `/signup` — jeweler registration form for full name, phone, email, password, and confirmation
   - `/login` — login form
   - `/public/signup.php` — dedicated signup page entry point
   - `/public/login.php` — dedicated login page entry point
   - `POST /auth/signup` — JSON email/password Firebase account creation
   - `POST /auth/login` — JSON email/password sign-in
   - `GET /auth/me` — returns the UID from a successfully verified Firebase ID token
   - `/jeweler/application` — authenticated placeholder for the next onboarding stage
   - `/auth/logout` — destroys the PHP session and redirects to login

The authentication endpoints require a JSON body containing `email` and `password`. They return Firebase's ID token, refresh token, expiry, local UID, and email on success. A missing `FIREBASE_WEB_API_KEY` returns a configuration error without attempting a request.

## Environment configuration

`.env.example` documents the application settings and Firebase Authentication placeholders. At startup, the application reads the optional root `.env` file at `C:\xampp\htdocs\khan-mgmt\khan-mgmt\.env` using a minimal `KEY=value` loader. Existing non-empty Apache/PHP environment variables take precedence. Keep the real `.env` file local; it is ignored by Git.

Supabase configuration is backend-only. The service-role key is sent only in server-side REST requests to the configured Storage bucket. It must never be placed in HTML, JavaScript, or any file under `public/`.

The reusable `FirebaseIdTokenVerifier` validates the bearer token signature with Google’s published certificates and checks the `RS256`, `kid`, `exp`, `iat`, `aud`, `iss`, `sub`, and `auth_time` requirements. It caches certificates using the upstream `Cache-Control: max-age` value and rejects duplicate JSON object keys in tokens and key-cache responses instead of relying on PHP’s last-key-wins JSON behavior. `GET /auth/me` uses only the verified `sub` claim as the UID, reads `users/{uid}` from Firestore with the verified Firebase ID token, and returns only `displayName` and `photoUrl` from the profile document plus a valid verified email claim.

Firestore REST access uses the verified Firebase ID token, not a service-account key. Enable Firestore for the Firebase project and configure Security Rules so a signed-in user can read only their own profile document, for example `users/{request.auth.uid}`. Profiles are not created automatically in this stage. A missing profile returns `404`; denied access returns `403`; missing configuration or upstream failures return safe `503` responses.

Signup creates the following Firestore document at `users/{uid}`, where `{uid}` is returned by Firebase Authentication and verified server-side:

```text
fullName: string
phoneNumber: string
email: string
onboardingStatus: "not_started"
```

Login reads this profile using the verified ID token. Supported temporary onboarding statuses are `not_started`, `draft`, `submitted`, `under_review`, `approved`, and `rejected`; they currently route to the placeholder application page. Roles are not accepted from the browser and are not assigned by signup.

Never put Firebase service-account credentials, private keys, Supabase service-role keys, uploaded documents, or production secrets in the repository or under `public/`. Store them in protected server environment configuration or an approved secret manager.

## Tests and checks

Composer and a test runner are not configured. Run PHP syntax checks from PowerShell with:

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object {
    & 'C:\xampp\php\php.exe' -l $_.FullName
}
```

The application can also be checked by requesting `/health` through Apache. Firebase signup/login are not considered live-tested unless a configured Firebase project is actually used.

## Remaining setup

Before the next implementation stage:

- Confirm the Apache virtual host/document root points to `public/`.
- Provide Firebase and Supabase values through protected server configuration. The Supabase project must have a private Storage bucket matching `SUPABASE_STORAGE_BUCKET`.
- Enable Cloud Firestore and create the required `users/{uid}` documents and user-scoped Security Rules.
- Run `tests\service_connection_test.php` after entering credentials. It does not create users, write Firestore data, upload files, or change either project.
- Expand protected functionality only after reviewing the `GET /auth/me` verification boundary and its fail-closed behavior.
