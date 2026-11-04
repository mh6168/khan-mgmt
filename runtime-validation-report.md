# Runtime Validation Report

**Generated**: 2026-11-05  
**Target**: `C:\xampp\htdocs\khan-mgmt\khan-mgmt`

## Summary

| Step | Status | Exit Code | Details |
|---|---|---:|---|
| PHP syntax validation | PASS | 0 | All PHP files passed `php -l`. |
| Apache startup/readiness | PASS | 0 | `/health` returned HTTP 200. |
| Browser signup rendering | PASS | 0 | Signup page rendered in the browser with all required fields. |
| Validation error handling | PASS | 0 | Empty signup submission displayed server-side validation errors. |
| Protected-route access control | PASS | 0 | Application, pending, dashboard, and rejected routes redirected unauthenticated requests to login with HTTP 303. |
| Firebase connectivity | PASS | 0 | Public signing-key check succeeded; invalid login probe returned HTTP 400 `INVALID_LOGIN_CREDENTIALS`. |
| Supabase connectivity | PASS | 0 | Configured private Storage bucket check succeeded. |
| Live onboarding mutation flow | UNVERIFIED | N/A | Not run because no disposable test credentials or cleanup path was supplied; creating a Firebase user and uploading documents would leave persistent external data. |

## Scope verified

The following implementation paths were statically and safely exercised:

- Firebase Authentication registration/login clients.
- Firebase ID-token verification and public-key caching.
- Firestore profile/application REST clients.
- UID-based application ownership checks.
- Application status routing.
- Private Supabase Storage configuration and bucket reachability.
- Protected page redirects.
- Logout route implementation.
- Rejected resubmission helper path compilation.

## Issue fixed during validation

Rejected application resubmission called an undefined `applicationPostedInput()` helper. The route now reads the posted full name, phone, and store address directly, matching the initial application flow.

## End-to-end gaps

The following require a disposable test account and test documents:

1. Registration creating a new Firebase Authentication user.
2. Firestore profile write verification.
3. Duplicate-email rejection through the application form.
4. Login with a real Firebase account.
5. Firestore application creation.
6. Four-document upload and path persistence.
7. Pending, approved, and rejected status transitions.
8. Rejected resubmission and replacement-file deletion.
9. Cross-user access checks using two authenticated accounts.
10. Browser logout/session invalidation after a real login.

These are not marked as passed because they mutate the configured Firebase and Supabase projects.

## Environment

- Docker: unavailable; no container-based infrastructure was required.
- Node.js: available (`v22.14.0`).
- Playwright CLI: available (`v1.64.1`); browser-level signup and route checks were performed through the integrated browser.
- Apache/PHP: available through XAMPP.

## Overall

**NEEDS_SIGNOFF** — all non-mutating and protected-route checks passed, but the complete external-service mutation workflow requires an explicitly disposable test account/data cleanup plan.
