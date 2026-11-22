<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirestoreClient.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirestoreAdminClient.php';

try {
    $reservationView = file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'jeweler' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'reservations.php');
    $reservationScript = file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'jeweler-dashboard.js');
    if (!is_string($reservationView) || !str_contains($reservationView, 'data-reservation-status-form')) {
        throw new RuntimeException('Reservation status form is missing.');
    }
    if (str_contains($reservationView, 'name="ajax" value="1"')) {
        throw new RuntimeException('Reservation form must not force a JSON-only fallback page.');
    }
    if (!is_string($reservationScript) ||
        !str_contains($reservationScript, "matches('[data-reservation-status-form]')") ||
        !str_contains($reservationScript, 'event.preventDefault()') ||
        !str_contains($reservationScript, "Accept: 'application/json'")
    ) {
        throw new RuntimeException('Reservation status AJAX submit handler is incomplete.');
    }

    $baseReservation = [
        'jewelerId' => 'jeweler_test',
        'status' => 'reserved_for_48_hours',
        'reservationExpiresAt' => '2026-11-22T23:00:00+00:00',
    ];
    $baseProduct = ['jewelerId' => 'jeweler_test'];

    FirestoreAdminClient::validateJewelryReservationStatus(
        $baseReservation,
        $baseProduct,
        'jeweler_test',
        'handed_to_user',
        new DateTimeImmutable('2026-11-22T22:00:00+00:00')
    );
    FirestoreAdminClient::validateJewelryReservationStatus(
        $baseReservation,
        $baseProduct,
        'jeweler_test',
        'refunded_deposit',
        new DateTimeImmutable('2026-11-22T22:00:00+00:00')
    );

    try {
        FirestoreAdminClient::validateJewelryReservationStatus(
            $baseReservation,
            $baseProduct,
            'jeweler_test',
            'user_didnt_pick_up',
            new DateTimeImmutable('2026-11-22T22:00:00+00:00')
        );
        throw new RuntimeException('An unexpired reservation must not be marked not collected.');
    } catch (InvalidArgumentException) {
    }

    FirestoreAdminClient::validateJewelryReservationStatus(
        $baseReservation,
        $baseProduct,
        'jeweler_test',
        'user_didnt_pick_up',
        new DateTimeImmutable('2026-11-23T00:00:00+00:00')
    );

    try {
        FirestoreAdminClient::validateJewelryReservationStatus(
            $baseReservation,
            $baseProduct,
            'another_jeweler',
            'handed_to_user',
            new DateTimeImmutable('2026-11-22T22:00:00+00:00')
        );
        throw new RuntimeException('Cross-jeweler reservation access must be denied.');
    } catch (FirestoreException $exception) {
        if ($exception->httpStatus() !== 403) {
            throw new RuntimeException('Cross-jeweler access should return HTTP 403.');
        }
    }

    FirestoreAdminClient::validateJewelryReservationStatus(
        array_merge($baseReservation, ['status' => 'handed_to_user']),
        $baseProduct,
        'jeweler_test',
        'refunded_deposit',
        new DateTimeImmutable('2026-11-22T22:00:00+00:00')
    );

    FirestoreAdminClient::validateJewelryReservationStatus(
        array_merge($baseReservation, ['status' => 'handed_to_user']),
        $baseProduct,
        'jeweler_test',
        'reserved_for_48_hours',
        new DateTimeImmutable('2026-11-22T22:00:00+00:00')
    );

    FirestoreAdminClient::validateJewelryReservationStatus(
        array_merge($baseReservation, ['status' => 'handed_to_user']),
        $baseProduct,
        'jeweler_test',
        'user_didnt_pick_up',
        new DateTimeImmutable('2026-11-23T00:00:00+00:00')
    );

    FirestoreAdminClient::validateJewelryReservationStatus(
        array_merge($baseReservation, ['status' => 'refunded_deposit']),
        $baseProduct,
        'jeweler_test',
        'handed_to_user',
        new DateTimeImmutable('2026-11-22T22:00:00+00:00')
    );

    try {
        FirestoreAdminClient::validateJewelryReservationStatus(
            array_merge($baseReservation, ['status' => 'handed_to_user']),
            $baseProduct,
            'jeweler_test',
            'refunded_deposit',
            new DateTimeImmutable('2026-11-23T00:00:00+00:00')
        );
        throw new RuntimeException('Collected and refund outcomes must not be selected after expiry.');
    } catch (InvalidArgumentException) {
    }

    try {
        FirestoreAdminClient::validateJewelryReservationStatus(
            array_merge($baseReservation, ['status' => 'refunded_deposit']),
            $baseProduct,
            'jeweler_test',
            'user_didnt_pick_up',
            new DateTimeImmutable('2026-11-23T00:00:00+00:00')
        );
        throw new RuntimeException('A refunded reservation must not be changed to not collected.');
    } catch (InvalidArgumentException) {
    }

    try {
        FirestoreAdminClient::validateJewelryReservationStatus(
            array_merge($baseReservation, ['status' => 'user_didnt_pick_up']),
            $baseProduct,
            'jeweler_test',
            'handed_to_user',
            new DateTimeImmutable('2026-11-23T00:00:00+00:00')
        );
        throw new RuntimeException('A not-collected reservation must not be changed after the deadline.');
    } catch (InvalidArgumentException) {
    }

    $firestore = FirestoreAdminClient::fromEnvironment();
    $reservations = $firestore->collection('jewelryReservations');

    if ($reservations === []) {
        echo json_encode([
            'status' => 'ok',
            'reservationCount' => 0,
            'ownershipCheck' => 'no live records',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    $jewelerUid = (string) ($reservations[0]['jewelerId'] ?? '');
    if ($jewelerUid === '') {
        throw new RuntimeException('A live reservation is missing jewelerId.');
    }

    $filtered = $firestore->listJewelryReservationsByJeweler($jewelerUid);
    foreach ($filtered as $reservation) {
        if (($reservation['jewelerId'] ?? null) !== $jewelerUid) {
            throw new RuntimeException('Reservation filter returned a different jeweler.');
        }
    }

    echo json_encode([
        'status' => 'ok',
        'reservationCount' => count($filtered),
        'ownershipCheck' => 'jewelerId and product owner filtered',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'TEST FAILED: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
