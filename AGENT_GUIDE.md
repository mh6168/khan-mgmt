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

- `handed_to_user` and `refunded_deposit` require the current status to be `reserved_for_48_hours` and require the reservation deadline not to have passed.
- `user_didnt_pick_up` requires the current status to be `reserved_for_48_hours` and requires `reservationExpiresAt` or `expiresAt` to be in the past.
- A reservation already in `handed_to_user`, `refunded_deposit`, or `user_didnt_pick_up` is terminal and cannot be changed.
- Unknown status values are rejected.

The dashboard form uses AJAX with the existing CSRF token. On success it updates the status badge and success message in the current card. Regular form submission remains supported through redirect/status notice handling.

### Flutter synchronization

The PHP update changes the same `jewelryReservations` document used by Flutter. Flutter's existing `MyOrdersScreen` watches `jewelryReservations` with a customer UID filter and reads `status` from the document. It should therefore display the updated status on the next snapshot. PHP updates only `status`; it does not create a duplicate document or modify Paymob, customer, product, payment, or reservation pricing fields.
