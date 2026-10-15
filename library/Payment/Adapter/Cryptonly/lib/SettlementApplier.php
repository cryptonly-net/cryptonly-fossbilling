<?php

namespace Cryptonly\Fossbilling;

use Cryptonly\Billing\CustomPayload;
use Cryptonly\Billing\PaymentDecision;

/**
 * Applies one verified Cryptonly invoice to a FOSSBilling invoice.
 *
 * A partial payment is client credit. paid and overpaid credit only the amount
 * still due, then pay the invoice from that credit. The overpay surplus is not stored.
 */
class SettlementApplier
{
    /** @var BillingGateway */
    private $billing;

    public function __construct(BillingGateway $billing)
    {
        $this->billing = $billing;
    }

    /**
     * @param array $cryptonlyInvoice
     * @param int   $boundInvoiceId Invoice id from the callback URL
     * @param int   $transactionId
     */
    public function apply(array $cryptonlyInvoice, $boundInvoiceId, $transactionId)
    {
        $transactionId = (int) $transactionId;
        $tx = $this->billing->transaction($transactionId);

        if ($tx['status'] === 'processed') {
            return;
        }

        $invoiceId = (int) $tx['invoice_id'];

        if ($invoiceId <= 0) {
            $invoiceId = (int) $boundInvoiceId;
        }

        if ($invoiceId <= 0) {
            throw new \Payment_Exception('Cryptonly webhook is missing the FOSSBilling invoice.');
        }

        if ((int) $boundInvoiceId > 0 && $invoiceId !== (int) $boundInvoiceId) {
            throw new \Payment_Exception('Cryptonly webhook invoice does not match this callback.');
        }

        $invoice = $this->billing->invoice($invoiceId);
        $this->assertPayload($cryptonlyInvoice, $invoice);

        if ($invoice['status'] === 'paid') {
            $this->finish($transactionId, 'already_paid', 0.0, $invoice['currency'], null);

            return;
        }

        if ($invoice['status'] !== 'unpaid') {
            $this->finish($transactionId, 'invoice_' . $invoice['status'], 0.0, $invoice['currency'], null);

            return;
        }

        $balance = $this->billing->balance((int) $invoice['client_id']);
        $stillDue = CreditPolicy::amountStillDue($invoice['total'], $balance);

        if ($stillDue <= 0) {
            $this->payOrFail($invoiceId, 'Account credit covers this invoice, but it could not be marked paid.');
            $this->finish($transactionId, 'paid_from_credit', 0.0, $invoice['currency'], null);

            return;
        }

        $decision = PaymentDecision::decide(
            $cryptonlyInvoice,
            array(
                array(
                    'id' => $invoiceId,
                    'amount' => $stillDue,
                    'currency' => $invoice['currency'],
                ),
            ),
            $invoice['currency']
        );

        if (!isset($decision['action']) || $decision['action'] !== 'approved') {
            $reason = isset($decision['reason']) ? (string) $decision['reason'] : 'ignored';

            if ($reason === 'settlement_shortfall' || $reason === 'partial_amount_unavailable') {
                throw new \Payment_Exception('Cryptonly payment could not be applied (' . $reason . ').');
            }

            $this->finish($transactionId, $reason, 0.0, $invoice['currency'], null);
            $this->billing->log('Cryptonly webhook ignored: ' . $reason . ' invoice=' . $invoiceId);

            return;
        }

        $effect = CreditPolicy::effect($decision);
        $reference = isset($decision['transaction_id']) ? (string) $decision['transaction_id'] : '';
        $alreadyCredited = $tx['txn_status'] === 'credited'
            && $reference !== ''
            && $tx['txn_id'] === $reference;

        if ($effect['credit'] > 0 && !$alreadyCredited) {
            $this->billing->addCredit(
                (int) $invoice['client_id'],
                $effect['credit'],
                $this->creditDescription($invoiceId, $decision),
                $reference
            );
            $this->billing->updateTransaction($transactionId, array(
                'txn_status' => 'credited',
                'txn_id' => $reference,
                'amount' => $effect['credit'],
                'currency' => isset($decision['currency']) ? $decision['currency'] : $invoice['currency'],
            ));
        }

        if ($effect['pay_invoice']) {
            $this->payOrFail($invoiceId, 'Cryptonly credited the client, but the invoice could not be marked paid.');
        }

        $reason = isset($decision['reason']) ? (string) $decision['reason'] : 'approved';
        $this->finish($transactionId, $reason, $effect['credit'], $invoice['currency'], $reference);
        $this->billing->log(
            'Cryptonly ' . $reason
            . ' invoice=' . $invoiceId
            . ' credit=' . $effect['credit']
            . ($effect['pay_invoice'] ? ' paid' : ' open')
        );
    }

    /**
     * @param array $cryptonlyInvoice
     * @param array $invoice
     */
    private function assertPayload(array $cryptonlyInvoice, array $invoice)
    {
        $raw = isset($cryptonlyInvoice['customPayload']) ? (string) $cryptonlyInvoice['customPayload'] : '';
        $payload = CustomPayload::decode($raw);

        if ($payload === null) {
            throw new \Payment_Exception('Cryptonly webhook is missing the invoice reference.');
        }

        if ((int) $payload['client_id'] !== (int) $invoice['client_id']) {
            throw new \Payment_Exception('Cryptonly webhook client does not match this invoice.');
        }

        $invoiceIds = array();

        foreach ($payload['invoice_ids'] as $invoiceId) {
            $invoiceIds[] = (int) $invoiceId;
        }

        if (!in_array((int) $invoice['id'], $invoiceIds, true)) {
            throw new \Payment_Exception('Cryptonly webhook does not match this invoice.');
        }
    }

    /**
     * @param int    $invoiceId
     * @param string $failure
     */
    private function payOrFail($invoiceId, $failure)
    {
        if (!$this->billing->payWithCredits($invoiceId)) {
            throw new \Payment_Exception($failure);
        }
    }

    /**
     * @param int   $invoiceId
     * @param array $decision
     * @return string
     */
    private function creditDescription($invoiceId, array $decision)
    {
        $reason = isset($decision['reason']) ? (string) $decision['reason'] : 'payment';

        return 'Cryptonly ' . $reason . ' for invoice #' . (int) $invoiceId;
    }

    /**
     * @param int         $transactionId
     * @param string      $note
     * @param float       $amount
     * @param string      $currency
     * @param string|null $reference
     */
    private function finish($transactionId, $note, $amount, $currency, $reference)
    {
        $fields = array(
            'status' => 'processed',
            'note' => $note,
            'amount' => $amount,
            'currency' => $currency,
        );

        if ($reference !== null && $reference !== '') {
            $fields['txn_id'] = $reference;
        }

        $this->billing->updateTransaction($transactionId, $fields);
    }
}
