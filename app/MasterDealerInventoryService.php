<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'MasterDealerStore.php';

final class MasterDealerInventoryService
{
    private const ASSETS = ['lira', 'para'];

    public function __construct(
        private readonly MasterDealerStore $store
    ) {
    }

    /**
     * @return array<string, array{quantity: int, reserved: int, available: int}>
     */
    public function summary(): array
    {
        $inventory = [];
        foreach (self::ASSETS as $asset) {
            $document = $this->store->document('masterDealerInventory/' . $asset) ?? [];
            $quantity = max(0, (int) ($document['quantity'] ?? 0));
            $reserved = max(0, min($quantity, (int) ($document['reserved'] ?? 0)));
            $inventory[$asset] = [
                'quantity' => $quantity,
                'reserved' => $reserved,
                'available' => $quantity - $reserved,
            ];
        }

        return $inventory;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function movements(): array
    {
        $transactions = $this->store->collection('masterDealerTransactions');
        $movements = array_values(array_filter(
            $transactions,
            static fn (array $transaction): bool => (int) ($transaction['liraQuantity'] ?? 0) !== 0
                || (int) ($transaction['paraQuantity'] ?? 0) !== 0
        ));
        usort($movements, static fn (array $left, array $right): int =>
            strcmp((string) ($right['createdAt'] ?? ''), (string) ($left['createdAt'] ?? ''))
        );

        return $movements;
    }

    /**
     * @return bool False when this idempotency key was already applied.
     */
    public function adjust(string $asset, int $delta, string $note, string $idempotencyKey): bool
    {
        if (!in_array($asset, self::ASSETS, true)) {
            throw new InvalidArgumentException('Choose Lira or Para inventory.');
        }
        if ($delta === 0 || abs($delta) > 1000000) {
            throw new InvalidArgumentException('Inventory adjustment must be between 1 and 1,000,000 units.');
        }
        $note = trim($note);
        if ($note === '' || strlen($note) > 300) {
            throw new InvalidArgumentException('Enter an adjustment reason of 1 to 300 characters.');
        }
        if (preg_match('/^[a-f0-9]{32}$/', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('Inventory operation key is invalid.');
        }

        $transactionId = 'adjust-' . $idempotencyKey;
        $inventoryPath = 'masterDealerInventory/' . $asset;
        $ledgerPath = 'masterDealerTransactions/' . $transactionId;
        $transaction = $this->store->beginTransaction();
        $existingEntry = $this->store->read($ledgerPath, $transaction);
        if ($existingEntry !== null) {
            $existingDelta = $asset === 'lira'
                ? (int) ($existingEntry['liraQuantity'] ?? 0)
                : (int) ($existingEntry['paraQuantity'] ?? 0);
            if (($existingEntry['asset'] ?? '') !== $asset ||
                $existingDelta !== $delta ||
                ($existingEntry['note'] ?? '') !== $note
            ) {
                throw new InvalidArgumentException('Inventory operation key was already used for different adjustment details.');
            }
            return false;
        }

        $current = $this->store->read($inventoryPath, $transaction);
        $quantity = (int) ($current['quantity'] ?? 0);
        $reserved = (int) ($current['reserved'] ?? 0);
        $updatedQuantity = $quantity + $delta;
        if ($updatedQuantity < $reserved || $updatedQuantity < 0) {
            throw new InvalidArgumentException('Adjustment cannot reduce inventory below reserved customer orders.');
        }

        $now = gmdate('c');
        $inventory = [
            'asset' => $asset,
            'quantity' => $updatedQuantity,
            'reserved' => $reserved,
            'updatedAt' => $now,
        ];
        $entry = [
            'type' => 'inventory_adjustment',
            'status' => 'completed',
            'source' => 'master_dealer',
            'asset' => $asset,
            'liraQuantity' => $asset === 'lira' ? $delta : 0,
            'paraQuantity' => $asset === 'para' ? $delta : 0,
            'amountMinorUnits' => 0,
            'note' => $note,
            'createdAt' => $now,
        ];
        $writes = [
            $current === null
                ? $this->store->createWrite($inventoryPath, $inventory)
                : $this->store->updateWrite($inventoryPath, $inventory),
            $this->store->createWrite($ledgerPath, $entry),
        ];
        $this->store->commit($transaction, $writes);

        return true;
    }
}
