<?php

namespace Cryptonly\Fossbilling;

use Box\Mod\Client\Entity\Client;
use Box\Mod\Invoice\Entity\Invoice;
use Box\Mod\Invoice\Entity\Transaction;

/**
 * BillingGateway backed by a FOSSBilling installation.
 */
class FossBillingGateway implements BillingGateway
{
    /** @var \Pimple\Container */
    private $di;

    public function __construct(\Pimple\Container $di)
    {
        $this->di = $di;
    }

    public function invoice($invoiceId)
    {
        $invoice = $this->di['em']->getRepository(Invoice::class)->find((int) $invoiceId);

        if (!$invoice instanceof Invoice) {
            throw new \Payment_Exception('Invoice not found.');
        }

        $row = $this->di['mod_service']('Invoice')->toApiArray($invoice, true);
        $buyer = isset($row['buyer']) && is_array($row['buyer']) ? $row['buyer'] : array();

        return array(
            'id' => (int) $row['id'],
            'client_id' => (int) $row['client_id'],
            'total' => (float) $row['total'],
            'currency' => (string) $row['currency'],
            'status' => (string) $row['status'],
            'due_at' => isset($row['due_at']) ? (string) $row['due_at'] : null,
            'buyer' => $buyer,
        );
    }

    public function balance($clientId)
    {
        $client = $this->di['em']->getRepository(Client::class)->find((int) $clientId);

        if (!$client instanceof Client) {
            throw new \Payment_Exception('Client not found.');
        }

        return (float) $this->di['mod_service']('Client')->getClientBalance($client);
    }

    public function addCredit($clientId, $amount, $description, $reference)
    {
        $client = $this->di['em']->getRepository(Client::class)->find((int) $clientId);

        if (!$client instanceof Client) {
            throw new \Payment_Exception('Client not found.');
        }

        $this->di['mod_service']('Client')->addFunds($client, $amount, $description, array(
            'type' => 'transaction',
            'rel_id' => (string) $reference,
        ));
    }

    public function payWithCredits($invoiceId)
    {
        $invoice = $this->di['em']->getRepository(Invoice::class)->find((int) $invoiceId);

        if (!$invoice instanceof Invoice) {
            throw new \Payment_Exception('Invoice not found.');
        }

        return (bool) $this->di['mod_service']('Invoice')->payInvoiceWithCredits($invoice);
    }

    public function transaction($transactionId)
    {
        $tx = $this->di['em']->getRepository(Transaction::class)->find((int) $transactionId);

        if (!$tx instanceof Transaction) {
            throw new \Payment_Exception('Transaction not found.');
        }

        $invoice = $tx->getInvoice();

        return array(
            'invoice_id' => $invoice instanceof Invoice ? (int) $invoice->getId() : 0,
            'status' => $tx->getStatus(),
            'txn_status' => $tx->getTxnStatus() ?? '',
            'txn_id' => $tx->getTxnId() ?? '',
        );
    }

    public function updateTransaction($transactionId, array $fields)
    {
        $tx = $this->di['em']->getRepository(Transaction::class)->find((int) $transactionId);

        if (!$tx instanceof Transaction) {
            throw new \Payment_Exception('Transaction not found.');
        }

        if (isset($fields['status'])) {
            $tx->setStatus((string) $fields['status']);
        }

        if (isset($fields['txn_status'])) {
            $tx->setTxnStatus((string) $fields['txn_status']);
        }

        if (isset($fields['txn_id'])) {
            $tx->setTxnId((string) $fields['txn_id']);
        }

        if (isset($fields['amount'])) {
            $tx->setAmount((string) $fields['amount']);
        }

        if (isset($fields['currency'])) {
            $tx->setCurrency((string) $fields['currency']);
        }

        if (isset($fields['note'])) {
            $tx->setNote((string) $fields['note']);
        }

        $tx->setUpdatedAt(new \DateTime());
        $this->di['em']->flush();
    }

    public function log($message)
    {
        if (isset($this->di['logger'])) {
            $this->di['logger']->info($message);
        }
    }
}
