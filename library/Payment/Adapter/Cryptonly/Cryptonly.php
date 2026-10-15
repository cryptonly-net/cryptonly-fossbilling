<?php

require_once __DIR__ . '/lib/load.php';

cryptonly_fossbilling_load(__DIR__ . '/lib');

use Cryptonly\Billing\AccountValidator;
use Cryptonly\Billing\ApiClient;
use Cryptonly\Billing\Checkout;
use Cryptonly\Billing\OrderId;
use Cryptonly\Billing\WebhookVerifier;
use Cryptonly\Fossbilling\BillingGateway;
use Cryptonly\Fossbilling\CreditPolicy;
use Cryptonly\Fossbilling\FossBillingGateway;
use Cryptonly\Fossbilling\SettlementApplier;

/**
 * Hosted Cryptonly checkout for FOSSBilling.
 *
 * Money is recorded only from a signed invoice.statusChanged webhook.
 * A partial payment is client credit. The invoice is marked paid when that
 * credit covers the invoice total. An overpay is capped at the amount still due.
 */
class Payment_Adapter_Cryptonly implements FOSSBilling\InjectionAwareInterface, FOSSBilling\Payment\JsonWebhookAuthenticatorInterface
{
    const DEFAULT_PREFIX = 'FOSS';

    /** @var array<string,mixed> */
    private $config;

    /** @var \Pimple\Container|null */
    private $di;

    /** @var ApiClient */
    private $apiClient;

    /** @var BillingGateway|null */
    private $billing;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct($config, ApiClient $apiClient = null)
    {
        $this->config = is_array($config) ? $config : array();
        $this->requireCredentials();
        $this->apiClient = $apiClient !== null
            ? $apiClient
            : new ApiClient($this->apiKey(), $this->testMode());

        $error = AccountValidator::validate(
            $this->apiKey(),
            $this->accountId(),
            $this->testMode(),
            false,
            $this->apiClient
        );

        if ($error !== null) {
            throw new Payment_Exception($error);
        }
    }

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    /**
     * Test seam. FOSSBilling uses FossBillingGateway from setDi().
     */
    public function setBilling(BillingGateway $billing)
    {
        $this->billing = $billing;
    }

    /**
     * @return string[]
     */
    public static function getSecretFields()
    {
        return array('api_key', 'webhook_secret');
    }

    /**
     * @return array<string,mixed>
     */
    public static function getConfig()
    {
        return array(
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'can_load_in_iframe' => false,
            'description' => 'Accept Bitcoin, stablecoins, and other cryptocurrencies on a Cryptonly hosted page. '
                . 'A partial payment is added as account credit. The invoice is marked paid when the credit covers the total.',
            'form' => array(
                'api_key' => array(
                    'text',
                    array(
                        'label' => 'API Key',
                        'secret' => true,
                    ),
                ),
                'account_id' => array(
                    'text',
                    array(
                        'label' => 'Account ID',
                    ),
                ),
                'webhook_secret' => array(
                    'text',
                    array(
                        'label' => 'Webhook Signing Key',
                        'secret' => true,
                    ),
                ),
                'order_id_prefix' => array(
                    'text',
                    array(
                        'label' => 'Order ID Prefix',
                        'required' => false,
                        'description' => 'Optional. Default FOSS produces FOSS-12, then FOSS-12-2 on a later attempt.',
                    ),
                ),
            ),
        );
    }

    /**
     * @param object $api_admin
     * @param int    $invoice_id
     * @param bool   $subscription
     */
    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        if ($subscription) {
            return $this->message('Cryptonly does not charge renewals by itself. Pay the renewal invoice when it is issued.');
        }

        $invoice = $this->billing()->invoice((int) $invoice_id);

        if ($invoice['status'] !== 'unpaid') {
            return $this->message('This invoice is not awaiting payment.');
        }

        $balance = $this->billing()->balance((int) $invoice['client_id']);
        $due = CreditPolicy::amountStillDue($invoice['total'], $balance);

        if ($due <= 0) {
            if (!$this->billing()->payWithCredits((int) $invoice['id'])) {
                return $this->message('Account credit covers this invoice, but it could not be marked paid.');
            }

            return $this->message('This invoice was paid with account credit.');
        }

        $buyer = $invoice['buyer'];
        $result = Checkout::start($this->apiClient, array(
            'accountId' => $this->accountId(),
            'prefix' => $this->prefix(),
            'defaultPrefix' => self::DEFAULT_PREFIX,
            'amount' => $due,
            'currency' => $invoice['currency'],
            'invoiceAmounts' => array(
                array(
                    'id' => (int) $invoice['id'],
                    'amount' => $due,
                ),
            ),
            'clientId' => (int) $invoice['client_id'],
            'company' => isset($buyer['company']) ? (string) $buyer['company'] : '',
            'firstName' => isset($buyer['first_name']) ? (string) $buyer['first_name'] : '',
            'lastName' => isset($buyer['last_name']) ? (string) $buyer['last_name'] : '',
            'returnUrl' => $this->configValue('return_url'),
            'dueDates' => $this->dueDates(isset($invoice['due_at']) ? $invoice['due_at'] : null),
            'callbackUrl' => $this->configValue('notify_url'),
        ));

        if (!$result['ok']) {
            return $this->message($this->checkoutMessage($result));
        }

        return $this->payLink($result['paymentPageUrl']);
    }

    public function authenticateJsonWebhook(array $data): void
    {
        $verified = $this->verify($data);

        if (!$verified['ok']) {
            throw new Payment_Exception($this->rejectedMessage($verified));
        }
    }

    /**
     * @param object               $api_admin
     * @param int                  $id
     * @param array<string,mixed>  $data
     * @param int                  $gateway_id
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        $payload = is_array($data) ? $data : array();
        $verified = $this->verify($payload);

        if (!$verified['ok']) {
            throw new Payment_Exception($this->rejectedMessage($verified));
        }

        $envelope = $verified['envelope'];
        $cryptonlyInvoice = isset($envelope['data']) && is_array($envelope['data']) ? $envelope['data'] : array();
        $boundInvoiceId = isset($payload['invoice_id']) ? (int) $payload['invoice_id'] : 0;

        (new SettlementApplier($this->billing()))->apply($cryptonlyInvoice, $boundInvoiceId, (int) $id);
    }

    private function requireCredentials()
    {
        $missing = array();

        if ($this->apiKey() === '') {
            $missing[] = 'API Key';
        }

        if ($this->accountId() === '') {
            $missing[] = 'Account ID';
        }

        if ($this->webhookSecret() === '') {
            $missing[] = 'Webhook Signing Key';
        }

        if ($missing !== array()) {
            throw new Payment_Exception('Cryptonly is not fully configured. Missing: ' . implode(', ', $missing) . '.');
        }

        if (!AccountValidator::isUuid($this->accountId())) {
            throw new Payment_Exception('Account ID must be a valid UUID.');
        }

        $prefixError = OrderId::validatePrefix($this->configValue('order_id_prefix'));

        if ($prefixError !== null) {
            throw new Payment_Exception($prefixError);
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return array{ok:bool,reason?:string,envelope?:array}
     */
    private function verify(array $data)
    {
        $raw = isset($data['http_raw_post_data']) ? (string) $data['http_raw_post_data'] : '';

        return WebhookVerifier::verify($raw, $this->signature($data), $this->webhookSecret());
    }

    /**
     * @param array<string,mixed> $data
     */
    private function signature(array $data)
    {
        $server = isset($data['server']) && is_array($data['server']) ? $data['server'] : array();

        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }

            $name = strtoupper($key);

            if ($name === 'HTTP_X_WEBHOOK_SIGNATURE' || $name === 'REDIRECT_HTTP_X_WEBHOOK_SIGNATURE') {
                return trim((string) $value);
            }
        }

        return '';
    }

    /**
     * @param array{ok:bool,reason?:string} $verified
     */
    private function rejectedMessage(array $verified)
    {
        $reason = isset($verified['reason']) ? (string) $verified['reason'] : 'unknown';

        return 'Cryptonly webhook was rejected (' . $reason . ').';
    }

    /**
     * @return BillingGateway
     */
    private function billing()
    {
        if ($this->billing instanceof BillingGateway) {
            return $this->billing;
        }

        if ($this->di === null) {
            throw new Payment_Exception('Cryptonly is not connected to FOSSBilling.');
        }

        $this->billing = new FossBillingGateway($this->di);

        return $this->billing;
    }

    /**
     * @param string|null $dueAt
     * @return string[]
     */
    private function dueDates($dueAt)
    {
        $dueAt = trim((string) $dueAt);

        if (!preg_match('/^(\d{4}-\d{2}-\d{2})/', $dueAt, $matches)) {
            return array();
        }

        return array($matches[1]);
    }

    /**
     * @param array{ok:bool,error?:string,detail?:string} $result
     */
    private function checkoutMessage(array $result)
    {
        $code = isset($result['error']) ? (string) $result['error'] : '';
        $messages = array(
            'amount_positive' => 'Payment amount must be greater than zero.',
            'invoices_required' => 'Select an invoice to pay with Cryptonly.',
            'amount_mismatch' => 'Invoice amounts do not match the payment total.',
            'callback_https' => 'Cryptonly webhooks require a public HTTPS address for this FOSSBilling site.',
            'client_required' => 'Cryptonly could not identify the client for this payment.',
            'payload_too_long' => 'Too many invoices for one Cryptonly payment.',
            'past_due' => 'This invoice is past its due date.',
            'already_paid' => 'This invoice already has a finalized Cryptonly payment. Contact support if you need assistance.',
            'order_id_too_long' => 'Cryptonly order id is too long for this invoice.',
            'too_many_attempts' => 'Too many Cryptonly payment attempts for this invoice. Contact support.',
            'payment_page_missing' => 'Cryptonly did not return a payment page URL.',
            'checkout_failed' => 'Unable to start Cryptonly payment. Try again or choose another payment method.',
        );

        if ($code === 'gateway' && !empty($result['detail'])) {
            return 'Cryptonly could not start the payment. ' . (string) $result['detail'];
        }

        if (isset($messages[$code])) {
            return $messages[$code];
        }

        return 'Unable to start Cryptonly payment. Try again or choose another payment method.';
    }

    /**
     * @param string $url
     */
    private function payLink($url)
    {
        $safe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $html = '<p>You pay on the Cryptonly page. A partial payment is added as account credit. '
            . 'The invoice is marked paid when that credit covers the total.</p>';
        $html .= '<p><a class="btn btn-primary" href="' . $safe . '">Pay with Cryptonly</a></p>';

        if ($this->flag('auto_redirect')) {
            $html .= '<script>window.location.href=' . json_encode($url) . ';</script>';
        }

        return $html;
    }

    /**
     * @param string $text
     */
    private function message($text)
    {
        return '<p>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    private function apiKey()
    {
        return trim($this->configValue('api_key'));
    }

    private function accountId()
    {
        return trim($this->configValue('account_id'));
    }

    private function webhookSecret()
    {
        return trim($this->configValue('webhook_secret'));
    }

    private function prefix()
    {
        return OrderId::normalizePrefix($this->configValue('order_id_prefix'), self::DEFAULT_PREFIX);
    }

    private function testMode()
    {
        return $this->flag('test_mode');
    }

    /**
     * @param string $key
     */
    private function flag($key)
    {
        if (!isset($this->config[$key])) {
            return false;
        }

        $value = $this->config[$key];

        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /**
     * @param string $key
     */
    private function configValue($key)
    {
        if (!isset($this->config[$key]) || !is_scalar($this->config[$key])) {
            return '';
        }

        return (string) $this->config[$key];
    }
}
