# KHAN Management

PHP-based management website for the KHAN gold and jewelry marketplace.

## Stage 2 status

This repository contains the dependency-free project bootstrap and Firebase Authentication foundation:

- A public Apache document root and front controller.
- A development/production-aware error-handling bootstrap.
- A `/` welcome route and `/health` health route.
- Firebase email/password signup and login through the Firebase Authentication REST API.
- Placeholders for application, configuration, routes, views, storage, and tests.
- No token verification, protected dashboards, Firestore, Supabase Storage, uploads, applications, or admin workflows yet.

## Technology stack

- PHP 8.2
- Apache through XAMPP
- Firebase Authentication through REST APIs
- Cloud Firestore (planned)
- Supabase Storage with a private `jeweler-documents` bucket (planned)

## Prerequisites

- Windows with XAMPP installed.
- PHP 8.2 available at `C:\xampp\php\php.exe`.
- Apache enabled in the XAMPP Control Panel.
- Composer is not required for the current stages and has not been installed.

The current XAMPP PHP installation includes the baseline extensions needed by the bootstrap and planned HTTP integrations, including `curl`, `fileinfo`, `json`, `mbstring`, `openssl`, and `session`.

## Run locally with XAMPP

1. Start Apache from the XAMPP Control Panel.
2. The recommended document root is:

   `C:\xampp\htdocs\khan-mgmt\khan-mgmt\public`

   Apache has not been reconfigured by this project. With the default XAMPP document root (`C:\xampp\htdocs`), use:

   `http://localhost/khan-mgmt/khan-mgmt/public/`

   The web server should expose `public/`, not the repository root. A virtual host or document-root change may be configured separately later, but none is assumed here.
3. Ensure Apache has `mod_rewrite` enabled and permits `.htaccess` overrides so `public/.htaccess` can route non-file requests to `public/index.php`.
4. Copy `.env.example` to `.env` only when local environment configuration is needed. Keep `.env` local; it is ignored by Git.
5. Set `FIREBASE_WEB_API_KEY` in the server environment to the Web API key for the Firebase project when authentication testing is needed. Do not put credentials in the repository.
6. Open the configured local URL in a browser:

   - `/` — welcome response
   - `/health` — health response
   - `POST /auth/signup` — creates an email/password Firebase account
   - `POST /auth/login` — signs in an email/password Firebase account

The authentication endpoints require a JSON body containing `email` and `password`. They return Firebase's ID token, refresh token, expiry, local UID, and email on success. A missing `FIREBASE_WEB_API_KEY` returns a configuration error without attempting a request.

## Environment configuration

`.env.example` documents the application settings and Firebase Authentication placeholders. The application does not parse `.env` files; Apache/PHP environment variables are the supported runtime configuration mechanism. If using a local `.env` file with a separate development tool, keep it ignored by Git.

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
- Provide `FIREBASE_WEB_API_KEY` through protected server configuration for a real Firebase project.
- Decide the secure server-side ID-token verification approach before adding protected routes. Do not decode JWTs without cryptographic verification.
