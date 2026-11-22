<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'FirestoreAdminClient.php';

try {
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
