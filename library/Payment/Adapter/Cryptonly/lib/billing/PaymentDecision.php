<?php

namespace Cryptonly\Billing;

/**
 * Maps a verified Cryptonly invoice webhook onto a billing transaction.
 * Open invoice rows are the amounts still due.
 */
class PaymentDecision
{
    /**
     * @param array $cryptonlyInvoice
     * @param array<int,array{id:int|string,amount:float|int|string,currency?:string}> $openInvoices
     * @param string|null $gatewayCurrency
     * @return array{action:string,status?:string,amount?:float,currency?:string,invoices?:array,transaction_id?:string,reason:string}
     */
    public static function decide(array $cryptonlyInvoice, array $openInvoices, $gatewayCurrency = null)
    {
        $status = isset($cryptonlyInvoice['status']) ? (string) $cryptonlyInvoice['status'] : '';
        $currency = isset($cryptonlyInvoice['fiatCurrencyCode'])
            ? strtoupper((string) $cryptonlyInvoice['fiatCurrencyCode'])
            : '';

        if ($currency === '') {
            return self::ignore('missing_currency');
        }

        if ($gatewayCurrency !== null && $gatewayCurrency !== '' && strtoupper((string) $gatewayCurrency) !== $currency) {
            return self::ignore('currency_mismatch');
        }

        foreach ($openInvoices as $invoice) {
            if (!empty($invoice['currency']) && strtoupper((string) $invoice['currency']) !== $currency) {
                return self::ignore('currency_mismatch');
            }
        }

        if ($status === 'suspended') {
            return array(
                'action' => 'pending',
                'status' => 'pending',
                'reason' => 'suspended',
            );
        }

        if ($status !== 'paid' && $status !== 'overpaid' && $status !== 'partially_paid') {
            return self::ignore($status !== '' ? $status : 'unknown');
        }

        $due = Settlement::sumAmounts($openInvoices);

        if ($due <= 0) {
            return self::ignore('already_paid');
        }

        if ($status === 'partially_paid') {
            $partial = WebhookVerifier::resolvePartialPaymentAmount($cryptonlyInvoice);

            if ($partial === null || $partial <= 0) {
                return self::ignore('partial_amount_unavailable');
            }

            $allocated = Settlement::allocate($partial, $openInvoices);

            if ($allocated['amount'] <= 0) {
                return self::ignore('partial_amount_unavailable');
            }

            return array(
                'action' => 'approved',
                'status' => 'approved',
                'amount' => $allocated['amount'],
                'currency' => $currency,
                'invoices' => $allocated['invoices'],
                'transaction_id' => WebhookVerifier::buildTransactionId($cryptonlyInvoice, $status),
                'reason' => 'partially_paid',
            );
        }

        $cryptonlyAmount = isset($cryptonlyInvoice['amount']) ? (float) $cryptonlyInvoice['amount'] : 0.0;

        if (!Settlement::invoiceAmountCoversBalanceDue($due, $cryptonlyAmount)) {
            return self::ignore('settlement_shortfall');
        }

        $allocated = Settlement::allocate($due, $openInvoices);

        return array(
            'action' => 'approved',
            'status' => 'approved',
            'amount' => $allocated['amount'],
            'currency' => $currency,
            'invoices' => $allocated['invoices'],
            'transaction_id' => WebhookVerifier::buildTransactionId($cryptonlyInvoice, $status),
            'reason' => $status,
        );
    }

    /**
     * @param string $reason
     * @return array{action:string,reason:string}
     */
    private static function ignore($reason)
    {
        return array(
            'action' => 'ignore',
            'reason' => $reason,
        );
    }
}
