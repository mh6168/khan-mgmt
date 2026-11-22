# KHAN PHP Agent Guide

## Shared Firebase reservation status management

PHP jewelers read and update the existing `jewelryReservations` collection in the shared `khan-81cd6` Firestore project. PHP uses `FirestoreAdminClient` with the service account configured by `FIREBASE_ADMIN_SERVICE_ACCOUNT_FILE`; it does not create new reservation records or call Paymob.

### Reservation status values

- `reserved_for_48_hours` - Reserved - Awaiting Pickup
- `handed_to_user` - Collected by Customer
- `refunded_deposit` - Deposit Refunded
- `user_didnt_pick_up` - Not Collected - 48 Hours Expired

The exact Firestore values are preserved. The PHP view maps them to the dashboard display labels.

### Status update flow

The Jeweler Dashboard POST action `reservation_status` receives `_csrf`, `reservationId`, and `reservationStatus`. It does not accept a jeweler UID from the browser.

`FirestoreAdminClient::updateJewelryReservationStatus()`:

1. Reads `jewelryReservations/{reservationId}`.
2. Reads `products/{productId}`.
3. Requires `jewelryReservations/{reservationId}.jewelerId` to equal the verified PHP session UID.
4. Requires `products/{productId}.jewelerId` to equal the same UID.
5. Validates the target status and deadline.
6. Patches only the existing document's `status` field.

No Paymob refund request is made. `refunded_deposit` means the jeweler has confirmed that an actual refund was processed separately.

### Transition restrictions

- `handed_to_user` and `refunded_deposit` require the reservation deadline not to have passed. They may be selected from `reserved_for_48_hours`, `handed_to_user`, or `refunded_deposit` for status corrections.
- `reserved_for_48_hours` may be restored from `handed_to_user` or `refunded_deposit` only before the deadline.
- `user_didnt_pick_up` requires `reservationExpiresAt` or `expiresAt` to be in the past and is allowed only from `reserved_for_48_hours` or `handed_to_user`. It is rejected from `refunded_deposit`.
- `user_didnt_pick_up` is terminal and cannot be corrected to another status.
- Unknown status values are rejected.

The dashboard form uses AJAX with the existing CSRF token. On success it updates the status badge and success message in the current card. Regular form submission remains supported through redirect/status notice handling.

### AJAX white-response fix

The reservation status form has an explicit dashboard `action` and submits through a document-level `submit` listener. JavaScript calls `event.preventDefault()` before `fetch()` and sends the form data with `Accept: application/json`.

The JavaScript reads the success or error JSON, updates the matching reservation badge in place, and renders a success or error message. It does not navigate to or display the JSON endpoint. The form has no JSON-only fallback hidden field, so a native fallback submission redirects back to the reservations dashboard instead of exposing the JSON response as a page.

### Flutter synchronization

The PHP update changes the same `jewelryReservations` document used by Flutter. Flutter's existing `MyOrdersScreen` watches `jewelryReservations` with a customer UID filter and reads `status` from the document. It should therefore display the updated status on the next snapshot. PHP updates only `status`; it does not create a duplicate document or modify Paymob, customer, product, payment, or reservation pricing fields.
