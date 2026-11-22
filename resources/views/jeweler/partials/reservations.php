                <div class="section-heading">
                    <div><h2>Reservations</h2><p>Reservation activity for your product listings</p></div>
                </div>
<?php if ($reservations === []): ?>
                <div class="empty-state"><h2>No reservation records yet</h2><p>Reservations for your products will appear here after a customer completes payment.</p></div>
<?php else: ?>
                <div class="reservation-list">
<?php foreach ($reservations as $reservation): ?>
<?php
    $status = (string) ($reservation['status'] ?? '');
    $statusLabels = [
        'reserved_for_48_hours' => 'Reserved for 48 hours',
        'handed_to_user' => 'Handed to customer',
        'refunded_deposit' => 'Deposit refunded',
        'user_didnt_pick_up' => 'Not collected',
    ];
    $statusLabel = $statusLabels[$status] ?? 'Status unavailable';
    $statusClass = match ($status) {
        'reserved_for_48_hours' => 'reserved',
        'handed_to_user' => 'handed',
        'refunded_deposit' => 'refunded',
        'user_didnt_pick_up' => 'expired',
        default => 'unknown',
    };
    $minorAmount = static function (array $reservation, string $minorField, string $egpField): float {
        if (is_numeric($reservation[$minorField] ?? null)) {
            return (float) $reservation[$minorField] / 100;
        }
        return is_numeric($reservation[$egpField] ?? null) ? (float) $reservation[$egpField] : 0.0;
    };
    $formatAmount = static fn (float $amount): string => number_format($amount, 2) . ' EGP';
    $formatReservationDate = static function (mixed $value): string {
        if (!is_string($value) || $value === '') {
            return 'Date unavailable';
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('M j, Y g:i A') . ' UTC';
        } catch (Exception) {
            return 'Date unavailable';
        }
    };
    $total = $minorAmount($reservation, 'originalTotalPriceMinorUnits', 'originalTotalPriceEgp');
    $deposit = $minorAmount($reservation, 'depositAmountMinorUnits', 'depositAmountEgp');
    $remaining = $minorAmount($reservation, 'remainingBalanceMinorUnits', 'remainingBalanceEgp');
?>
                    <article class="reservation-card">
                        <div class="reservation-image">
<?php if (is_string($reservation['productImageUrl'] ?? null) && $reservation['productImageUrl'] !== ''): ?>
                            <img src="<?= JewelerSession::escape($reservation['productImageUrl']) ?>" alt="<?= JewelerSession::escape((string) ($reservation['productName'] ?? 'Reserved product')) ?>">
<?php else: ?>
                            <span class="product-image-placeholder">No image</span>
<?php endif; ?>
                        </div>
                        <div class="reservation-body">
                            <div class="reservation-title-row">
                                <div>
                                    <h3><?= JewelerSession::escape((string) ($reservation['productName'] ?? 'Jewelry piece')) ?></h3>
                                    <p><?= JewelerSession::escape((string) ($reservation['jewelerStoreName'] ?? 'KHAN jeweler')) ?></p>
                                </div>
                                <span class="reservation-status <?= $statusClass ?>"><?= JewelerSession::escape($statusLabel) ?></span>
                            </div>
                            <div class="reservation-details">
                                <div><span>Customer</span><strong><?= JewelerSession::escape((string) ($reservation['customerName'] ?? 'KHAN customer')) ?></strong></div>
                                <div><span>Customer email</span><strong><?= JewelerSession::escape((string) ($reservation['customerEmail'] ?? 'Not provided')) ?></strong></div>
                                <div><span>Customer phone</span><strong><?= JewelerSession::escape((string) ($reservation['customerPhone'] ?? 'Not provided')) ?></strong></div>
                                <div><span>Reservation date</span><strong><?= JewelerSession::escape($formatReservationDate($reservation['reservationCreatedAt'] ?? $reservation['createdAt'] ?? null)) ?></strong></div>
                                <div><span>Pickup deadline</span><strong><?= JewelerSession::escape($formatReservationDate($reservation['reservationExpiresAt'] ?? $reservation['expiresAt'] ?? null)) ?></strong></div>
                                <div><span>Total price</span><strong><?= JewelerSession::escape($formatAmount($total)) ?></strong></div>
                                <div><span>Deposit paid</span><strong><?= JewelerSession::escape($formatAmount($deposit)) ?></strong></div>
                                <div><span>Balance at pickup</span><strong><?= JewelerSession::escape($formatAmount($remaining)) ?></strong></div>
                            </div>
                        </div>
                    </article>
<?php endforeach; ?>
                </div>
<?php endif; ?>
