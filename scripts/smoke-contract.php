<?php

/**
 * Exercises the FOSSBilling adapter against an in-memory billing stand-in.
 * Does not boot FOSSBilling.
 */

use Cryptonly\Billing\ApiClient;
use Cryptonly\Billing\CustomPayload;
use Cryptonly\Fossbilling\BillingGateway;
use Cryptonly\Fossbilling\CreditPolicy;

require __DIR__ . '/fossbilling-stubs.php';

if (!class_exists('Payment_Exception', false)) {
    class Payment_Exception extends Exception
    {
    }
}

require dirname(__DIR__) . '/library/Payment/Adapter/Cryptonly/Cryptonly.php';

$failures = 0;

    function check($condition, $message)
    {
        global $failures;

        if ($condition) {
            echo "ok  $message\n";

            return;
        }

        $failures++;
        echo "FAIL $message\n";
    }

    const ACCOUNT_ID = '019e2730-5075-73dd-abc6-f7db06336664';
    const SECRET = 'test-secret';

    class MemoryBilling implements BillingGateway
    {
        /** @var array<string,mixed> */
        public $invoice;

        /** @var float */
        public $balance = 0.0;

        /** @var array<int,array<string,mixed>> */
        public $credits = array();

        /** @var int */
        public $payCalls = 0;

        /** @var bool */
        public $payResult = true;

        /** @var array<string,mixed> */
        public $transaction = array(
            'invoice_id' => 12,
            'status' => 'received',
            'txn_status' => '',
            'txn_id' => '',
        );

        /** @var array<int,string> */
        public $logs = array();

        public function invoice($invoiceId)
        {
            return $this->invoice;
        }

        public function balance($clientId)
        {
            return $this->balance;
        }

        public function addCredit($clientId, $amount, $description, $reference)
        {
            $this->credits[] = array(
                'client_id' => (int) $clientId,
                'amount' => (float) $amount,
                'description' => (string) $description,
                'reference' => (string) $reference,
            );
            $this->balance = round($this->balance + (float) $amount, 2);
        }

        public function payWithCredits($invoiceId)
        {
            $this->payCalls++;

            if (!$this->payResult) {
                return false;
            }

            if (round($this->balance, 2) + 0.001 < round((float) $this->invoice['total'], 2)) {
                return false;
            }

            $this->invoice['status'] = 'paid';

            return true;
        }

        public function transaction($transactionId)
        {
            return $this->transaction;
        }

        public function updateTransaction($transactionId, array $fields)
        {
            foreach ($fields as $key => $value) {
                if ($key === 'note' || $key === 'amount' || $key === 'currency') {
                    $this->transaction[$key] = $value;
                    continue;
                }

                $this->transaction[$key] = $value;
            }
        }

        public function log($message)
        {
            $this->logs[] = (string) $message;
        }
    }

    class FakeCryptonlyApi extends ApiClient
    {
        /** @var array<int,array> */
        public $creates = array();

        public function __construct()
        {
            parent::__construct('test-key', true);
        }

        public function listAccounts()
        {
            return array(
                'ok' => true,
                'data' => array(
                    array('id' => ACCOUNT_ID),
                ),
            );
        }

        public function getInvoice($accountId, $orderId = null, $invoiceId = null)
        {
            return array(
                'ok' => false,
                'status' => 404,
                'error' => 'not found',
            );
        }

        public function createInvoice(array $payload)
        {
            $this->creates[] = $payload;

            return array(
                'ok' => true,
                'data' => array(
                    'paymentPageUrl' => 'https://pay.example/invoice',
                    'status' => 'created',
                ),
            );
        }
    }

    function config()
    {
        return array(
            'api_key' => 'test-key',
            'account_id' => ACCOUNT_ID,
            'webhook_secret' => SECRET,
            'test_mode' => true,
            'notify_url' => 'https://billing.example.com/ipn.php?gateway_id=3&invoice_id=12',
            'return_url' => 'https://billing.example.com/invoice/payment-return?status=ok',
        );
    }

    function adapter(FakeCryptonlyApi $api, MemoryBilling $billing)
    {
        $adapter = new Payment_Adapter_Cryptonly(config(), $api);
        $adapter->setBilling($billing);

        return $adapter;
    }

    function invoiceRow($total, $status = 'unpaid')
    {
        return array(
            'id' => 12,
            'client_id' => 7,
            'total' => (float) $total,
            'currency' => 'USD',
            'status' => $status,
            'due_at' => '2099-01-01 00:00:00',
            'buyer' => array(
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'company' => '',
            ),
        );
    }

    function signedWebhook($status, $amount, array $extra = array())
    {
        $data = array_merge(array(
            'id' => 'c-1',
            'status' => $status,
            'fiatCurrencyCode' => 'USD',
            'amount' => $amount,
            'orderId' => 'FOSS-12',
            'customPayload' => CustomPayload::encode(7, array(12)),
        ), $extra);
        $body = json_encode(array(
            'event' => 'invoice.statusChanged',
            'data' => $data,
        ));
        $signature = hash_hmac('sha256', $body, SECRET);

        return array(
            'invoice_id' => 12,
            'http_raw_post_data' => $body,
            'server' => array(
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            ),
        );
    }

    check(CreditPolicy::amountStillDue(100, 40) === 60.0, 'amount still due subtracts credit');
    check(CreditPolicy::amountStillDue(100, 100) === 0.0, 'covered balance has nothing due');
    check(CreditPolicy::effect(array('action' => 'approved', 'amount' => 40, 'reason' => 'partially_paid'))['pay_invoice'] === false, 'partial does not pay the invoice');
    check(CreditPolicy::effect(array('action' => 'approved', 'amount' => 100, 'reason' => 'paid'))['pay_invoice'] === true, 'paid pays the invoice');
    check(CreditPolicy::effect(array('action' => 'ignore', 'reason' => 'expired'))['credit'] === 0.0, 'ignored status credits nothing');

    $missing = null;

    try {
        new Payment_Adapter_Cryptonly(array(
            'account_id' => ACCOUNT_ID,
            'webhook_secret' => SECRET,
        ));
    } catch (Payment_Exception $e) {
        $missing = $e->getMessage();
    }

    check(is_string($missing) && strpos($missing, 'API Key') !== false, 'missing API key is rejected before any HTTP call');

    $api = new FakeCryptonlyApi();
    $billing = new MemoryBilling();
    $billing->invoice = invoiceRow(100);
    $billing->balance = 40;
    $html = adapter($api, $billing)->getHtml(null, 12, false);
    check(strpos($html, 'https://pay.example/invoice') !== false, 'checkout links to the hosted page');
    check(count($api->creates) === 1, 'checkout creates one Cryptonly invoice');
    check($api->creates[0]['amount'] === 60.0, 'checkout charges only the amount still due');
    check($api->creates[0]['orderId'] === 'FOSS-12', 'order id uses the FOSS prefix');
    check($api->creates[0]['webhookUrl'] === config()['notify_url'], 'webhook URL is the FOSSBilling callback');
    check(isset($api->creates[0]['expiresInMinutes']), 'due date sets an expiry');

    $covered = new MemoryBilling();
    $covered->invoice = invoiceRow(100);
    $covered->balance = 100;
    $coveredApi = new FakeCryptonlyApi();
    $coveredHtml = adapter($coveredApi, $covered)->getHtml(null, 12, false);
    check($coveredApi->creates === array(), 'enough credit does not open a new checkout');
    check($covered->payCalls === 1 && $covered->invoice['status'] === 'paid', 'enough credit pays the invoice');
    check(strpos($coveredHtml, 'account credit') !== false, 'enough credit tells the client the invoice was paid');

    $partial = new MemoryBilling();
    $partial->invoice = invoiceRow(100);
    $partial->balance = 0;
    $partialAdapter = adapter(new FakeCryptonlyApi(), $partial);
    $partialAdapter->processTransaction(null, 4, signedWebhook('partially_paid', 100, array(
        'txHash' => '0xabc',
        'settlement' => array('amountPaid' => 40),
    )), 3);
    check(count($partial->credits) === 1 && $partial->credits[0]['amount'] === 40.0, 'partial credits the settled amount');
    check($partial->payCalls === 0 && $partial->invoice['status'] === 'unpaid', 'partial leaves the invoice unpaid');
    check($partial->transaction['status'] === 'processed', 'partial webhook is marked processed');

    $partialAdapter->processTransaction(null, 4, signedWebhook('partially_paid', 100, array(
        'txHash' => '0xabc',
        'settlement' => array('amountPaid' => 40),
    )), 3);
    check(count($partial->credits) === 1, 'replaying a processed partial does not credit again');

    $rest = new MemoryBilling();
    $rest->invoice = invoiceRow(100);
    $rest->balance = 40;
    adapter(new FakeCryptonlyApi(), $rest)->processTransaction(null, 5, signedWebhook('paid', 60, array(
        'id' => 'c-2',
    )), 3);
    check(count($rest->credits) === 1 && $rest->credits[0]['amount'] === 60.0, 'remaining payment credits the amount still due');
    check($rest->invoice['status'] === 'paid', 'remaining payment marks the invoice paid');

    $over = new MemoryBilling();
    $over->invoice = invoiceRow(100);
    adapter(new FakeCryptonlyApi(), $over)->processTransaction(null, 6, signedWebhook('overpaid', 130, array(
        'id' => 'c-3',
    )), 3);
    check(count($over->credits) === 1 && $over->credits[0]['amount'] === 100.0, 'overpay credits the amount still due, not the surplus');
    check($over->invoice['status'] === 'paid', 'overpay marks the invoice paid');

    $again = new MemoryBilling();
    $again->invoice = invoiceRow(100);
    $again->balance = 100;
    $again->transaction = array(
        'invoice_id' => 12,
        'status' => 'received',
        'txn_status' => 'credited',
        'txn_id' => 'c-4',
    );
    adapter(new FakeCryptonlyApi(), $again)->processTransaction(null, 7, signedWebhook('paid', 100, array(
        'id' => 'c-4',
    )), 3);
    check($again->credits === array() && $again->invoice['status'] === 'paid', 'a credited retry pays the invoice without adding credit again');

    $partialRetry = new MemoryBilling();
    $partialRetry->invoice = invoiceRow(100);
    $partialRetry->balance = 40;
    $partialRetry->transaction = array(
        'invoice_id' => 12,
        'status' => 'received',
        'txn_status' => 'credited',
        'txn_id' => 'c-1-partial-0xabc',
    );
    adapter(new FakeCryptonlyApi(), $partialRetry)->processTransaction(null, 11, signedWebhook('partially_paid', 100, array(
        'txHash' => '0xabc',
        'settlement' => array('amountPaid' => 40),
    )), 3);
    check($partialRetry->credits === array() && $partialRetry->invoice['status'] === 'unpaid', 'a credited partial retry does not credit the same settlement again');
    check($partialRetry->transaction['status'] === 'processed', 'a credited partial retry is marked processed');

    $expired = new MemoryBilling();
    $expired->invoice = invoiceRow(100);
    adapter(new FakeCryptonlyApi(), $expired)->processTransaction(null, 8, signedWebhook('expired', 100, array(
        'id' => 'c-5',
    )), 3);
    check($expired->credits === array() && $expired->payCalls === 0, 'expired adds no credit');
    check($expired->transaction['status'] === 'processed', 'expired is recorded without money');

    $bad = new MemoryBilling();
    $bad->invoice = invoiceRow(100);
    $badPayload = signedWebhook('paid', 100);
    $badPayload['server']['HTTP_X_WEBHOOK_SIGNATURE'] = 'deadbeef';
    $rejected = null;

    try {
        adapter(new FakeCryptonlyApi(), $bad)->authenticateJsonWebhook($badPayload);
    } catch (Payment_Exception $e) {
        $rejected = $e->getMessage();
    }

    check(is_string($rejected) && $bad->credits === array(), 'a bad signature is rejected before any credit');

    $mismatch = new MemoryBilling();
    $mismatch->invoice = invoiceRow(100);
    $foreign = signedWebhook('paid', 100, array(
        'customPayload' => CustomPayload::encode(7, array(99)),
    ));
    $foreignError = null;

    try {
        adapter(new FakeCryptonlyApi(), $mismatch)->processTransaction(null, 9, $foreign, 3);
    } catch (Payment_Exception $e) {
        $foreignError = $e->getMessage();
    }

    check(is_string($foreignError) && $mismatch->credits === array(), 'a webhook for another invoice adds no credit');

if ($failures > 0) {
    fwrite(STDERR, $failures . " failed\n");
    exit(1);
}

echo "fossbilling contract ok\n";
