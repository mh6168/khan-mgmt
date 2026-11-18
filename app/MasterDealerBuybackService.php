<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'MasterDealerStore.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'MasterDealerPricingService.php';

final class MasterDealerBuybackService
{
    public function __construct(
        private readonly MasterDealerStore $store,
        private readonly MasterDealerPricingService $pricing
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function submit(
        string $customerUid,
        string $customerEmail,
        int $liraQuantity,
        int $paraQuantity,
        string $instaPayPhone,
        ?string $idempotencyKey = null,
        string $customerName = '',
        string $customerPhone = ''
    ): array {
        if ($customerUid === '' || strlen($customerUid) > 128) {
            throw new InvalidArgumentException('Customer identity is invalid.');
        }
        if ($liraQuantity < 0 || $paraQuantity < 0 || $liraQuantity > 10000 || $paraQuantity > 10000 ||
            ($liraQuantity === 0 && $paraQuantity === 0)
        ) {
            throw new InvalidArgumentException('Enter a positive quantity of Liras or Paras.');
        }
        $instaPayPhone = trim($instaPayPhone);
        $phoneDigits = preg_replace('/\D/', '', $instaPayPhone) ?? '';
        if (preg_match('/^[0-9+ ()-]{7,24}$/', $instaPayPhone) !== 1 || strlen($phoneDigits) < 7 || strlen($phoneDigits) > 20) {
            throw new InvalidArgumentException('Enter a valid InstaPay phone number.');
        }
        if ($idempotencyKey !== null && preg_match('/^[A-Za-z0-9._-]{16,128}$/', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('Buyback idempotency key is invalid.');
        }

        $id = $idempotencyKey === null
            ? $this->store->newId()
            : substr(hash('sha256', $customerUid . ':' . $idempotencyKey), 0, 32);
        $path = 'masterDealerSellRequests/' . $id;
        $transaction = $this->store->beginTransaction();
        $existingRequest = $this->store->read($path, $transaction);
        if ($existingRequest !== null) {
            if (($existingRequest['customerUid'] ?? '') !== $customerUid ||
                (int) ($existingRequest['liraQuantity'] ?? -1) !== $liraQuantity ||
                (int) ($existingRequest['paraQuantity'] ?? -1) !== $paraQuantity ||
                ($existingRequest['instapayPhone'] ?? '') !== $instaPayPhone
            ) {
                throw new InvalidArgumentException('Buyback idempotency key was already used for different request details.');
            }
            $existingRequest['id'] = $id;
            $existingRequest['totalAmountEgp'] = ((int) ($existingRequest['totalPayoutMinor'] ?? 0)) / 100;
            return $existingRequest;
        }

        $prices = $this->pricing->latest();
        $liraUnitPriceMinor = $liraQuantity > 0 ? $this->toMinorUnits($prices['lira']['buyback']) : 0;
        $paraUnitPriceMinor = $paraQuantity > 0 ? $this->toMinorUnits($prices['para']['buyback']) : 0;
        $totalAmountMinor = $liraQuantity * $liraUnitPriceMinor + $paraQuantity * $paraUnitPriceMinor;
        if ($totalAmountMinor <= 0) {
            throw new RuntimeException('Live buyback pricing is unavailable.');
        }

        $request = [
            'customerUid' => $customerUid,
            'customerName' => trim($customerName),
            'customerEmail' => filter_var($customerEmail, FILTER_VALIDATE_EMAIL) ? strtolower($customerEmail) : '',
            'customerPhone' => trim($customerPhone),
            'instapayPhone' => $instaPayPhone,
            'liraQuantity' => $liraQuantity,
            'paraQuantity' => $paraQuantity,
            'liraUnitPriceMinor' => $liraUnitPriceMinor,
            'paraUnitPriceMinor' => $paraUnitPriceMinor,
            'totalPayoutMinor' => $totalAmountMinor,
            'currency' => 'EGP',
            'status' => 'processing',
            'createdAt' => gmdate('c'),
        ];
        $portfolioPath = 'customerPortfolios/' . $customerUid;
        $portfolio = $this->store->read($portfolioPath, $transaction);
        $liraHeld = (int) ($portfolio['liraQuantity'] ?? 0);
        $paraHeld = (int) ($portfolio['paraQuantity'] ?? 0);
        $liraReserved = (int) ($portfolio['reservedLiraQuantity'] ?? 0);
        $paraReserved = (int) ($portfolio['reservedParaQuantity'] ?? 0);
        if ($portfolio === null ||
            $liraQuantity > $liraHeld - $liraReserved ||
            $paraQuantity > $paraHeld - $paraReserved
        ) {
            throw new InvalidArgumentException('Requested quantities exceed the customer available holdings.');
        }
        $this->store->commit($transaction, [
            $this->store->createWrite($path, $request),
            $this->store->updateWrite($portfolioPath, [
                'liraQuantity' => $liraHeld,
                'paraQuantity' => $paraHeld,
                'reservedLiraQuantity' => $liraReserved + $liraQuantity,
                'reservedParaQuantity' => $paraReserved + $paraQuantity,
                'updatedAt' => gmdate('c'),
            ]),
        ]);
        $request['id'] = $id;
        $request['totalAmountEgp'] = $totalAmountMinor / 100;

        return $request;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function requests(): array
    {
        $requests = $this->store->collection('masterDealerSellRequests');
        usort($requests, static fn (array $left, array $right): int =>
            strcmp((string) ($right['createdAt'] ?? ''), (string) ($left['createdAt'] ?? ''))
        );

        return $requests;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function request(string $id): ?array
    {
        if ($id === '' || strlen($id) > 128 || str_contains($id, '/')) {
            return null;
        }

        return $this->store->document('masterDealerSellRequests/' . $id);
    }

    public function startProcessing(string $id): bool
    {
        if ($id === '' || strlen($id) > 128 || str_contains($id, '/')) {
            throw new InvalidArgumentException('Buyback request ID is invalid.');
        }

        return false;
    }

    public function complete(string $id): bool
    {
        if ($id === '' || strlen($id) > 128 || str_contains($id, '/')) {
            throw new InvalidArgumentException('Buyback request ID is invalid.');
        }

        $requestPath = 'masterDealerSellRequests/' . $id;
        $ledgerPath = 'masterDealerTransactions/buyback-' . $id;
        $liraPath = 'masterDealerInventory/lira';
        $paraPath = 'masterDealerInventory/para';
        $transaction = $this->store->beginTransaction();
        $request = $this->store->read($requestPath, $transaction);
        $existingEntry = $this->store->read($ledgerPath, $transaction);
        if ($existingEntry !== null || ($request['status'] ?? '') === 'completed') {
            return false;
        }
        if ($request === null) {
            throw new InvalidArgumentException('Buyback request was not found.');
        }
        if (($request['status'] ?? '') === 'money_transferred') {
            return false;
        }
        if (($request['status'] ?? '') !== 'processing') {
            throw new InvalidArgumentException('Buyback request is not awaiting transfer.');
        }

        $lira = $this->store->read($liraPath, $transaction);
        $para = $this->store->read($paraPath, $transaction);
        $liraQuantity = (int) ($request['liraQuantity'] ?? 0);
        $paraQuantity = (int) ($request['paraQuantity'] ?? 0);
        $liraUnitPriceMinor = (int) ($request['liraUnitPriceMinor'] ?? 0);
        $paraUnitPriceMinor = (int) ($request['paraUnitPriceMinor'] ?? 0);
        $amount = (int) ($request['totalPayoutMinor'] ?? 0);
        $customerUid = (string) ($request['customerUid'] ?? '');
        if ($customerUid === '' ||
            $liraQuantity < 0 ||
            $paraQuantity < 0 ||
            ($liraQuantity === 0 && $paraQuantity === 0) ||
            $liraQuantity > 10000 ||
            $paraQuantity > 10000 ||
            $liraUnitPriceMinor <= 0 ||
            $paraUnitPriceMinor <= 0 ||
            ($request['currency'] ?? '') !== 'EGP' ||
            $amount !== $liraQuantity * $liraUnitPriceMinor + $paraQuantity * $paraUnitPriceMinor
        ) {
            throw new InvalidArgumentException('Buyback request data is invalid.');
        }
        $portfolioPath = 'customerPortfolios/' . $customerUid;
        $portfolio = $this->store->read($portfolioPath, $transaction);
        if ($portfolio === null ||
            ($liraQuantity > 0 && $lira === null) ||
            ($paraQuantity > 0 && $para === null)
        ) {
            throw new RuntimeException('Portfolio or Master Dealer inventory is unavailable.');
        }
        $liraHeld = (int) ($portfolio['liraQuantity'] ?? 0);
        $paraHeld = (int) ($portfolio['paraQuantity'] ?? 0);
        $liraReserved = (int) ($portfolio['reservedLiraQuantity'] ?? 0);
        $paraReserved = (int) ($portfolio['reservedParaQuantity'] ?? 0);
        if ($liraHeld < $liraQuantity ||
            $paraHeld < $paraQuantity ||
            $liraReserved < $liraQuantity ||
            $paraReserved < $paraQuantity
        ) {
            throw new RuntimeException('Reserved customer holdings do not match this request.');
        }

        $now = gmdate('c');
        $request['status'] = 'money_transferred';
        $request['transferredAt'] = new FirestoreTimestampValue($now);
        $request['transferredBy'] = 'master@master.com';
        $ledgerEntry = [
            'type' => 'customer_buyback',
            'status' => 'completed',
            'source' => 'instapay',
            'buybackRequestId' => $id,
            'customerUid' => $customerUid,
            'liraQuantity' => $liraQuantity,
            'paraQuantity' => $paraQuantity,
            'liraUnitPriceMinor' => $liraUnitPriceMinor,
            'paraUnitPriceMinor' => $paraUnitPriceMinor,
            'amountMinorUnits' => $amount,
            'createdAt' => new FirestoreTimestampValue($now),
            'confirmedAt' => new FirestoreTimestampValue($now),
        ];

        $writes = [
            $this->store->updateWrite($portfolioPath, [
                'liraQuantity' => $liraHeld - $liraQuantity,
                'paraQuantity' => $paraHeld - $paraQuantity,
                'reservedLiraQuantity' => $liraReserved - $liraQuantity,
                'reservedParaQuantity' => $paraReserved - $paraQuantity,
                'updatedAt' => $now,
            ]),
            $this->store->updateWrite($requestPath, $request),
            $this->store->createWrite($ledgerPath, $ledgerEntry),
        ];
        if ($liraQuantity > 0) {
            $writes[] = $this->inventoryWrite($liraPath, $lira, (int) ($lira['quantity'] ?? 0) + $liraQuantity, (int) ($lira['reserved'] ?? 0));
        }
        if ($paraQuantity > 0) {
            $writes[] = $this->inventoryWrite($paraPath, $para, (int) ($para['quantity'] ?? 0) + $paraQuantity, (int) ($para['reserved'] ?? 0));
        }
        $this->store->commit($transaction, $writes);

        return true;
    }

    private function toMinorUnits(?float $price): int
    {
        if ($price === null || !is_finite($price) || $price <= 0) {
            throw new RuntimeException('Live buyback pricing is unavailable.');
        }

        return (int) round($price * 100, 0, PHP_ROUND_HALF_UP);
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
}
