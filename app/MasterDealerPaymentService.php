<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'PaymobClient.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'MasterDealerTransactionService.php';

final class MasterDealerPaymentService
{
    public function __construct(
        private readonly MasterDealerTransactionService $transactions,
        private readonly ?PaymobClient $paymob = null
    ) {
    }

    public static function fromEnvironment(MasterDealerTransactionService $transactions): self
    {
        return new self($transactions, PaymobClient::fromEnvironment());
    }

    public function confirm(string $orderId, string $transactionId): bool
    {
        if ($this->paymob === null) {
            throw new InvalidArgumentException('Paymob payment verification is not configured.');
        }

        $payment = $this->paymob->verifyTransaction($transactionId);
        if (($payment['success'] ?? false) !== true ||
            ($payment['pending'] ?? false) === true ||
            ($payment['is_refunded'] ?? false) === true ||
            ($payment['is_voided'] ?? false) === true
        ) {
            throw new PaymobException('Paymob did not confirm this payment as successful.');
        }
        if (strtoupper((string) ($payment['currency'] ?? '')) !== 'EGP' ||
            !is_numeric($payment['amount_cents'] ?? null)
        ) {
            throw new PaymobException('Verified payment details are invalid.');
        }

        $verifiedTransactionId = (string) ($payment['id'] ?? '');
        if ($verifiedTransactionId !== $transactionId) {
            throw new PaymobException('Paymob returned a different transaction.');
        }

        $order = $payment['order'] ?? null;
        if (!is_array($order)) {
            $paymobOrderId = $payment['order_id'] ?? null;
            if (!is_int($paymobOrderId) && !is_string($paymobOrderId)) {
                throw new PaymobException('Paymob returned no order reference.');
            }
            $order = $this->paymob->getOrder($paymobOrderId);
        }

        if (($order['merchant_order_id'] ?? null) !== 'khan-md-' . $orderId) {
            throw new PaymobException('Verified payment does not match the purchase order.');
        }

        return $this->transactions->settleVerifiedPurchase(
            $orderId,
            $transactionId,
            (int) $payment['amount_cents']
        );
    }
}
