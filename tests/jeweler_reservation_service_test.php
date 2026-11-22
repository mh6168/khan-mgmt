<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirestoreClient.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirestoreAdminClient.php';

try {
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

    try {
        FirestoreAdminClient::validateJewelryReservationStatus(
            array_merge($baseReservation, ['status' => 'handed_to_user']),
            $baseProduct,
            'jeweler_test',
            'refunded_deposit',
            new DateTimeImmutable('2026-11-22T22:00:00+00:00')
        );
        throw new RuntimeException('Terminal reservation statuses must not be changed.');
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
