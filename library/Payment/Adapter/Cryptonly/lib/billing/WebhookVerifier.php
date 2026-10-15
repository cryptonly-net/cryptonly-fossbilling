<?php

namespace Cryptonly\Billing;

class WebhookVerifier
{
    const EVENT_INVOICE_STATUS_CHANGED = 'invoice.statusChanged';

    /**
     * @param string $rawBody
     * @param string $signatureHeader
     * @param string $secret
     * @return array{ok:bool,reason?:string,envelope?:array}
     */
    public static function verify($rawBody, $signatureHeader, $secret)
    {
        if (trim((string) $signatureHeader) === '') {
            return array(
                'ok' => false,
                'reason' => 'missing_signature',
            );
        }

        if (trim((string) $secret) === '') {
            return array(
                'ok' => false,
                'reason' => 'missing_secret',
            );
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        if (!hash_equals(strtolower($expected), strtolower(trim((string) $signatureHeader)))) {
            return array(
                'ok' => false,
                'reason' => 'invalid_signature',
            );
        }

        $parsed = json_decode($rawBody, true);

        if (JSON_ERROR_NONE !== json_last_error() || !is_array($parsed)) {
            return array(
                'ok' => false,
                'reason' => 'invalid_json',
            );
        }

        if (!isset($parsed['event']) || self::EVENT_INVOICE_STATUS_CHANGED !== $parsed['event']) {
            return array(
                'ok' => false,
                'reason' => 'wrong_event',
            );
        }

        return array(
            'ok' => true,
            'envelope' => $parsed,
        );
    }

    /**
     * @return string[]
     */
    public static function linkBlockedStatuses()
    {
        return array(
            'paid',
            'partially_paid',
            'overpaid',
            'expired',
            'cancelled',
            'failed',
            'suspended',
        );
    }

    /**
     * @return string[]
     */
    public static function checkoutFinalizedStatuses()
    {
        return array(
            'paid',
            'overpaid',
        );
    }

    /**
     * @param string $status
     * @return bool
     */
    public static function isCheckoutFinalized($status)
    {
        return in_array((string) $status, self::checkoutFinalizedStatuses(), true);
    }

    /**
     * @param string $status
     * @return bool
     */
    public static function isPaymentLinkBlocked($status)
    {
        return in_array((string) $status, self::linkBlockedStatuses(), true);
    }

    /**
     * @param string $status
     * @return bool
     */
    public static function invoiceAllowsPayment($status)
    {
        if ((string) $status === '') {
            return true;
        }

        return !self::isPaymentLinkBlocked($status);
    }

    /**
     * @return string
     */
    public static function checkoutFinalizedMessage()
    {
        return 'This invoice already has a finalized Cryptonly payment. Please contact support if you need assistance.';
    }

    /**
     * @param array $invoice
     * @return float|null
     */
    public static function resolvePartialPaymentAmount(array $invoice)
    {
        if (!empty($invoice['settlement']['amountPaid']) && is_numeric($invoice['settlement']['amountPaid'])) {
            return (float) $invoice['settlement']['amountPaid'];
        }

        $expected = isset($invoice['cryptoAmountExpected']) ? (float) $invoice['cryptoAmountExpected'] : 0.0;
        $received = isset($invoice['cryptoAmountReceived']) ? (float) $invoice['cryptoAmountReceived'] : 0.0;
        $total = isset($invoice['amount']) ? (float) $invoice['amount'] : 0.0;

        if ($expected > 0 && $received > 0 && $total > 0) {
            return round(($received / $expected) * $total, 2);
        }

        return null;
    }

    /**
     * @param array  $invoice
     * @param string $status
     * @return string
     */
    public static function buildTransactionId(array $invoice, $status)
    {
        $invoiceId = !empty($invoice['id']) ? (string) $invoice['id'] : uniqid('cryptonly-', true);

        if ($status === 'partially_paid') {
            if (!empty($invoice['txHash'])) {
                return $invoiceId . '-partial-' . $invoice['txHash'];
            }

            if (isset($invoice['cryptoAmountReceived'])) {
                return $invoiceId . '-partial-' . $invoice['cryptoAmountReceived'];
            }
        }

        return $invoiceId;
    }
}
