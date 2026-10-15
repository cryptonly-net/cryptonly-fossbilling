<?php

namespace Cryptonly\Fossbilling;

/**
 * The slice of FOSSBilling this gateway needs.
 * The host implementation talks to Doctrine. Tests use an in-memory stand-in.
 */
interface BillingGateway
{
    /**
     * @return array{id:int,client_id:int,total:float,currency:string,status:string,due_at:?string,buyer:array}
     */
    public function invoice($invoiceId);

    /**
     * @param int $clientId
     * @return float
     */
    public function balance($clientId);

    /**
     * @param int    $clientId
     * @param float  $amount
     * @param string $description
     * @param string $reference Cryptonly transaction id. A repeat of the same reference must not credit twice.
     */
    public function addCredit($clientId, $amount, $description, $reference);

    /**
     * @param int $invoiceId
     * @return bool True when the invoice is now paid.
     */
    public function payWithCredits($invoiceId);

    /**
     * @param int $transactionId
     * @return array{invoice_id:int,status:string,txn_status:string,txn_id:string}
     */
    public function transaction($transactionId);

    /**
     * @param int   $transactionId
     * @param array $fields
     */
    public function updateTransaction($transactionId, array $fields);

    /**
     * @param string $message
     */
    public function log($message);
}
