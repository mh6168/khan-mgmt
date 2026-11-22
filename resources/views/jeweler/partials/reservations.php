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
        'reserved_for_48_hours' => 'Reserved – Awaiting Pickup',
        'handed_to_user' => 'Collected by Customer',
        'refunded_deposit' => 'Deposit Refunded',
        'user_didnt_pick_up' => 'Not Collected – 48 Hours Expired',
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
    $expiresAt = $reservation['reservationExpiresAt'] ?? $reservation['expiresAt'] ?? '';
    $expiresIso = is_string($expiresAt) ? $expiresAt : '';
    $isTerminal = in_array($status, ['handed_to_user', 'refunded_deposit', 'user_didnt_pick_up'], true);
    $isExpired = false;
    if ($expiresIso !== '') {
        try {
            $isExpired = (new DateTimeImmutable($expiresIso)) <= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        } catch (Exception) {
            $isExpired = false;
        }
    }
?>
                    <article class="reservation-card" data-reservation-card="<?= JewelerSession::escape((string) ($reservation['id'] ?? '')) ?>">
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
                            <form class="reservation-status-form" method="post" data-reservation-status-form>
                                <input type="hidden" name="_csrf" value="<?= JewelerSession::escape($csrfToken) ?>">
                                <input type="hidden" name="action" value="reservation_status">
                                <input type="hidden" name="ajax" value="1">
                                <input type="hidden" name="reservationId" value="<?= JewelerSession::escape((string) ($reservation['id'] ?? '')) ?>">
                                <label for="reservation-status-<?= JewelerSession::escape((string) ($reservation['id'] ?? '')) ?>">Update reservation status</label>
                                <select
                                    id="reservation-status-<?= JewelerSession::escape((string) ($reservation['id'] ?? '')) ?>"
                                    name="reservationStatus"
                                    data-reservation-status
                                    data-expires-at="<?= JewelerSession::escape($expiresIso) ?>"
                                    <?= $isTerminal ? 'disabled' : '' ?>
                                >
                                    <option value="reserved_for_48_hours" <?= $status === 'reserved_for_48_hours' ? 'selected' : '' ?>>Reserved – Awaiting Pickup</option>
                                    <option value="handed_to_user" <?= $status === 'handed_to_user' ? 'selected' : '' ?> <?= $isExpired ? 'disabled' : '' ?>>Collected by Customer</option>
                                    <option value="refunded_deposit" <?= $status === 'refunded_deposit' ? 'selected' : '' ?> <?= $isExpired ? 'disabled' : '' ?>>Deposit Refunded</option>
                                    <option value="user_didnt_pick_up" <?= $status === 'user_didnt_pick_up' ? 'selected' : '' ?> <?= !$isExpired ? 'disabled' : '' ?>>Not Collected – 48 Hours Expired</option>
                                </select>
                                <button class="button small" type="submit" <?= $isTerminal ? 'disabled' : '' ?> data-reservation-status-save>Save status</button>
                                <span class="reservation-status-message" data-reservation-status-message role="status"></span>
                            </form>
                        </div>
                    </article>
<?php endforeach; ?>
                </div>
<?php endif; ?>
