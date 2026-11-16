<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'MasterDealerStore.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'MasterDealerPricingService.php';

final class MasterDealerTransactionService
{
    public function __construct(
        private readonly MasterDealerStore $store,
        private readonly MasterDealerPricingService $pricing
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function createPurchaseOrder(
        string $customerUid,
        int $liraQuantity,
        int $paraQuantity,
        ?string $idempotencyKey = null
    ): array
    {
        if ($customerUid === '' || strlen($customerUid) > 128) {
            throw new InvalidArgumentException('Customer identity is invalid.');
        }
        if ($liraQuantity < 0 || $paraQuantity < 0 || $liraQuantity > 10000 || $paraQuantity > 10000 ||
            ($liraQuantity === 0 && $paraQuantity === 0)
        ) {
            throw new InvalidArgumentException('Enter a positive quantity of Liras or Paras.');
        }
        if ($idempotencyKey !== null && preg_match('/^[A-Za-z0-9._-]{16,128}$/', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('Purchase idempotency key is invalid.');
        }

        $this->expirePendingOrders();
        $id = $idempotencyKey === null
            ? $this->store->newId()
            : substr(hash('sha256', $customerUid . ':' . $idempotencyKey), 0, 32);
        $transaction = $this->store->beginTransaction();
        $orderPath = 'masterDealerPurchaseOrders/' . $id;
        $existingOrder = $this->store->read($orderPath, $transaction);
        if ($existingOrder !== null) {
            if (($existingOrder['customerUid'] ?? '') !== $customerUid ||
                (int) ($existingOrder['liraQuantity'] ?? -1) !== $liraQuantity ||
                (int) ($existingOrder['paraQuantity'] ?? -1) !== $paraQuantity
            ) {
                throw new InvalidArgumentException('Purchase idempotency key was already used for different order details.');
            }
            if (($existingOrder['status'] ?? '') === 'expired') {
                throw new InvalidArgumentException('Purchase order expired; use a new idempotency key to retry.');
            }
            $existingOrder['id'] = $id;
            $existingOrder['amountEgp'] = ((int) ($existingOrder['amountMinorUnits'] ?? 0)) / 100;
            $existingOrder['amountPiasters'] = (int) ($existingOrder['amountMinorUnits'] ?? 0);
            return $existingOrder;
        }

        $prices = $this->pricing->latest();
        $liraPriceMinor = $liraQuantity > 0 ? $this->toMinorUnits($prices['lira']['selling']) : 0;
        $paraPriceMinor = $paraQuantity > 0 ? $this->toMinorUnits($prices['para']['selling']) : 0;
        $amount = $liraQuantity * $liraPriceMinor + $paraQuantity * $paraPriceMinor;
        if ($amount <= 0) {
            throw new RuntimeException('Live purchase pricing is unavailable.');
        }

        $liraPath = 'masterDealerInventory/lira';
        $paraPath = 'masterDealerInventory/para';
        $lira = $this->store->read($liraPath, $transaction);
        $para = $this->store->read($paraPath, $transaction);
        $liraQuantityInStock = (int) ($lira['quantity'] ?? 0);
        $liraReserved = (int) ($lira['reserved'] ?? 0);
        $paraQuantityInStock = (int) ($para['quantity'] ?? 0);
        $paraReserved = (int) ($para['reserved'] ?? 0);

        if ($liraQuantity > $liraQuantityInStock - $liraReserved ||
            $paraQuantity > $paraQuantityInStock - $paraReserved
        ) {
            throw new InvalidArgumentException('Requested quantities are not currently available.');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $now->modify('+15 minutes');
        $order = [
            'customerUid' => $customerUid,
            'liraQuantity' => $liraQuantity,
            'paraQuantity' => $paraQuantity,
            'liraUnitPriceMinor' => $liraPriceMinor,
            'paraUnitPriceMinor' => $paraPriceMinor,
            'amountMinorUnits' => $amount,
            'currency' => 'EGP',
            'status' => 'pending',
            'createdAt' => $now->format(DateTimeInterface::ATOM),
            'expiresAt' => $expiresAt->format(DateTimeInterface::ATOM),
            'merchantOrderId' => 'khan-md-' . $id,
        ];
        $writes = [
            $this->store->createWrite($orderPath, $order),
        ];

        if ($liraQuantity > 0) {
            $writes[] = $this->inventoryWrite($liraPath, $lira, $liraQuantityInStock, $liraReserved + $liraQuantity);
        }
        if ($paraQuantity > 0) {
            $writes[] = $this->inventoryWrite($paraPath, $para, $paraQuantityInStock, $paraReserved + $paraQuantity);
        }
        $this->store->commit($transaction, $writes);

        $order['id'] = $id;
        $order['amountEgp'] = $amount / 100;
        $order['amountPiasters'] = $amount;
        return $order;
    }

    /**
     * Process an order only after Paymob has independently confirmed it.
     */
    public function settleVerifiedPurchase(string $orderId, string $paymentTransactionId, int $verifiedAmountMinor): bool
    {
        if (preg_match('/^[a-f0-9]{32}$/', $orderId) !== 1 ||
            preg_match('/^[0-9]{1,30}$/', $paymentTransactionId) !== 1
        ) {
            throw new InvalidArgumentException('Purchase or payment identifier is invalid.');
        }

        $orderPath = 'masterDealerPurchaseOrders/' . $orderId;
        $paymentPath = 'masterDealerTransactions/payment-' . $paymentTransactionId;
        $liraPath = 'masterDealerInventory/lira';
        $paraPath = 'masterDealerInventory/para';
        $financePath = 'masterDealerFinance/account';
        $transaction = $this->store->beginTransaction();
        $order = $this->store->read($orderPath, $transaction);
        $existingPayment = $this->store->read($paymentPath, $transaction);
        if ($existingPayment !== null) {
            return false;
        }
        if ($order === null || ($order['status'] ?? '') !== 'pending') {
            throw new InvalidArgumentException('Purchase order is not pending.');
        }
        if ((int) ($order['amountMinorUnits'] ?? 0) !== $verifiedAmountMinor) {
            throw new InvalidArgumentException('Verified payment amount does not match the purchase order.');
        }
        if (new DateTimeImmutable((string) ($order['expiresAt'] ?? '1970-01-01')) <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
            throw new InvalidArgumentException('Purchase order has expired.');
        }

        $lira = $this->store->read($liraPath, $transaction);
        $para = $this->store->read($paraPath, $transaction);
        $finance = $this->store->read($financePath, $transaction);
        $liraQuantity = (int) ($order['liraQuantity'] ?? 0);
        $paraQuantity = (int) ($order['paraQuantity'] ?? 0);
        $liraOnHand = (int) ($lira['quantity'] ?? 0);
        $liraReserved = (int) ($lira['reserved'] ?? 0);
        $paraOnHand = (int) ($para['quantity'] ?? 0);
        $paraReserved = (int) ($para['reserved'] ?? 0);

        if ($liraOnHand < $liraQuantity || $liraReserved < $liraQuantity ||
            $paraOnHand < $paraQuantity || $paraReserved < $paraQuantity
        ) {
            throw new RuntimeException('Reserved purchase inventory is inconsistent.');
        }

        $now = gmdate('c');
        $order['status'] = 'completed';
        $order['paymentTransactionId'] = $paymentTransactionId;
        $order['paidAt'] = $now;
        $balance = (int) ($finance['balanceMinorUnits'] ?? 0) + $verifiedAmountMinor;
        $entry = [
            'type' => 'customer_purchase',
            'status' => 'completed',
            'source' => 'paymob',
            'customerUid' => (string) ($order['customerUid'] ?? ''),
            'purchaseOrderId' => $orderId,
            'paymentTransactionId' => $paymentTransactionId,
            'liraQuantity' => $liraQuantity,
            'paraQuantity' => $paraQuantity,
            'liraUnitPriceMinor' => (int) ($order['liraUnitPriceMinor'] ?? 0),
            'paraUnitPriceMinor' => (int) ($order['paraUnitPriceMinor'] ?? 0),
            'amountMinorUnits' => $verifiedAmountMinor,
            'createdAt' => $now,
        ];
        $writes = [
            $this->inventoryWrite($liraPath, $lira, $liraOnHand - $liraQuantity, $liraReserved - $liraQuantity),
            $this->inventoryWrite($paraPath, $para, $paraOnHand - $paraQuantity, $paraReserved - $paraQuantity),
            $finance === null
                ? $this->store->createWrite($financePath, ['balanceMinorUnits' => $balance, 'updatedAt' => $now])
                : $this->store->updateWrite($financePath, ['balanceMinorUnits' => $balance, 'updatedAt' => $now]),
            $this->store->updateWrite($orderPath, $order),
            $this->store->createWrite($paymentPath, $entry),
        ];
        $this->store->commit($transaction, $writes);

        return true;
    }

    public function expirePendingOrders(): int
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiredCount = 0;
        foreach ($this->store->collection('masterDealerPurchaseOrders') as $order) {
            if (($order['status'] ?? '') !== 'pending' || !is_string($order['expiresAt'] ?? null)) {
                continue;
            }
            try {
                $expiresAt = new DateTimeImmutable($order['expiresAt']);
            } catch (Exception) {
                error_log('Master Dealer purchase order has an invalid expiry timestamp.');
                continue;
            }

            if ($expiresAt < $now && $this->expireOrder((string) ($order['id'] ?? ''))) {
                $expiredCount++;
            }
        }

        return $expiredCount;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(): array
    {
        $transactions = $this->store->collection('masterDealerTransactions');
        usort($transactions, static fn (array $left, array $right): int =>
            strcmp((string) ($right['createdAt'] ?? ''), (string) ($left['createdAt'] ?? ''))
        );

        return $transactions;
    }

    private function expireOrder(string $orderId): bool
    {
        if (preg_match('/^[a-f0-9]{32}$/', $orderId) !== 1) {
            return false;
        }

        $orderPath = 'masterDealerPurchaseOrders/' . $orderId;
        $liraPath = 'masterDealerInventory/lira';
        $paraPath = 'masterDealerInventory/para';
        $transaction = $this->store->beginTransaction();
        $order = $this->store->read($orderPath, $transaction);
        if ($order === null || ($order['status'] ?? '') !== 'pending') {
            return false;
        }

        $expiresAt = new DateTimeImmutable((string) ($order['expiresAt'] ?? '1970-01-01'));
        if ($expiresAt > new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
            return false;
        }

        $liraQuantity = (int) ($order['liraQuantity'] ?? 0);
        $paraQuantity = (int) ($order['paraQuantity'] ?? 0);
        $lira = $this->store->read($liraPath, $transaction);
        $para = $this->store->read($paraPath, $transaction);
        $writes = [];
        if ($liraQuantity > 0) {
            $writes[] = $this->inventoryWrite(
                $liraPath,
                $lira,
                (int) ($lira['quantity'] ?? 0),
                max(0, (int) ($lira['reserved'] ?? 0) - $liraQuantity)
            );
        }
        if ($paraQuantity > 0) {
            $writes[] = $this->inventoryWrite(
                $paraPath,
                $para,
                (int) ($para['quantity'] ?? 0),
                max(0, (int) ($para['reserved'] ?? 0) - $paraQuantity)
            );
        }
        $order['status'] = 'expired';
        $order['expiredAt'] = gmdate('c');
        $writes[] = $this->store->updateWrite($orderPath, $order);
        $this->store->commit($transaction, $writes);

        return true;
    }

    /**
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function inventoryWrite(string $path, ?array $existing, int $quantity, int $reserved): array
    {
        $data = [
            'asset' => str_ends_with($path, '/lira') ? 'lira' : 'para',
            'quantity' => $quantity,
            'reserved' => $reserved,
            'updatedAt' => gmdate('c'),
        ];

        return $existing === null
            ? $this->store->createWrite($path, $data)
            : $this->store->updateWrite($path, $data);
    }

    private function toMinorUnits(?float $price): int
    {
        if ($price === null || !is_finite($price) || $price <= 0) {
            throw new RuntimeException('Live purchase pricing is unavailable.');
        }

        return (int) round($price * 100, 0, PHP_ROUND_HALF_UP);
    }
}
