<?php

namespace Cryptonly\Billing;

class Checkout
{
    /**
     * @param ApiClient $client
     * @param array     $input
     * @return array{ok:bool,error?:string,detail?:string,paymentPageUrl?:string}
     */
    public static function start(ApiClient $client, array $input)
    {
        $amount = isset($input['amount']) ? (float) $input['amount'] : 0.0;
        $invoiceAmounts = isset($input['invoiceAmounts']) && is_array($input['invoiceAmounts'])
            ? $input['invoiceAmounts']
            : array();

        if ($amount <= 0) {
            return self::error('amount_positive');
        }

        if ($invoiceAmounts === array()) {
            return self::error('invoices_required');
        }

        if (abs(Settlement::sumAmounts($invoiceAmounts) - round($amount, 2)) > 0.0001) {
            return self::error('amount_mismatch');
        }

        $callbackUrl = isset($input['callbackUrl']) ? (string) $input['callbackUrl'] : '';

        if (!HttpsUrl::isPublicHttps($callbackUrl)) {
            return self::error('callback_https');
        }

        $accountId = isset($input['accountId']) ? trim((string) $input['accountId']) : '';
        $prefix = isset($input['prefix']) ? (string) $input['prefix'] : '';
        $defaultPrefix = isset($input['defaultPrefix']) ? (string) $input['defaultPrefix'] : 'FOSS';
        $invoiceIds = array();

        foreach ($invoiceAmounts as $row) {
            if (!isset($row['id']) || (int) $row['id'] <= 0) {
                return self::error('invoices_required');
            }

            $invoiceIds[] = (int) $row['id'];
        }

        $clientId = isset($input['clientId']) ? (int) $input['clientId'] : 0;

        if ($clientId <= 0) {
            return self::error('client_required');
        }

        $customPayload = CustomPayload::encode($clientId, $invoiceIds);

        if ($customPayload === null) {
            return self::error('payload_too_long');
        }

        $dueDates = isset($input['dueDates']) && is_array($input['dueDates']) ? $input['dueDates'] : array();
        $expiry = Expiry::fromEarliestDueDate($dueDates);

        if (!$expiry['ok']) {
            return self::error('past_due');
        }

        $fetch = function ($orderId) use ($client, $accountId) {
            return $client->getInvoice($accountId, $orderId);
        };

        $checkout = CheckoutResolver::resolve(
            function ($attempt) use ($invoiceIds, $prefix, $defaultPrefix) {
                return OrderId::forInvoices($invoiceIds, $attempt, $prefix, $defaultPrefix);
            },
            $fetch
        );

        if ($checkout['action'] === 'redirect') {
            $url = self::paymentPageUrl(isset($checkout['invoice']) ? $checkout['invoice'] : array());

            if ($url === null) {
                return self::error('payment_page_missing');
            }

            return array(
                'ok' => true,
                'paymentPageUrl' => $url,
            );
        }

        if ($checkout['action'] === 'blocked') {
            return self::error('already_paid');
        }

        if ($checkout['action'] === 'error') {
            return self::resolverError(isset($checkout['message']) ? (string) $checkout['message'] : '');
        }

        $orderId = isset($checkout['orderId']) ? (string) $checkout['orderId'] : '';
        $created = $client->createInvoice(self::payload($input, $orderId, $callbackUrl, $customPayload, $expiry));

        if (!$created['ok'] && isset($created['status']) && (int) $created['status'] === 409) {
            $again = CheckoutResolver::resolve(
                function ($attempt) use ($invoiceIds, $prefix, $defaultPrefix) {
                    return OrderId::forInvoices($invoiceIds, $attempt, $prefix, $defaultPrefix);
                },
                $fetch
            );

            if ($again['action'] === 'redirect') {
                $url = self::paymentPageUrl(isset($again['invoice']) ? $again['invoice'] : array());

                if ($url === null) {
                    return self::error('payment_page_missing');
                }

                return array(
                    'ok' => true,
                    'paymentPageUrl' => $url,
                );
            }

            if ($again['action'] === 'blocked') {
                return self::error('already_paid');
            }

            if ($again['action'] === 'error') {
                return self::resolverError(isset($again['message']) ? (string) $again['message'] : '');
            }

            if ($again['action'] === 'create' && !empty($again['orderId']) && $again['orderId'] !== $orderId) {
                $created = $client->createInvoice(
                    self::payload($input, (string) $again['orderId'], $callbackUrl, $customPayload, $expiry)
                );
            }
        }

        if (!$created['ok']) {
            $message = !empty($created['error']) ? (string) $created['error'] : '';

            return $message === '' ? self::error('checkout_failed') : self::error('gateway', $message);
        }

        $url = self::paymentPageUrl(isset($created['data']) && is_array($created['data']) ? $created['data'] : array());

        if ($url === null) {
            return self::error('payment_page_missing');
        }

        return array(
            'ok' => true,
            'paymentPageUrl' => $url,
        );
    }

    /**
     * @param array  $input
     * @param string $orderId
     * @param string $callbackUrl
     * @param string $customPayload
     * @param array  $expiry
     * @return array
     */
    private static function payload(array $input, $orderId, $callbackUrl, $customPayload, array $expiry)
    {
        $labels = array();

        foreach ($input['invoiceAmounts'] as $row) {
            $labels[] = (string) $row['id'];
        }

        $clientName = Text::clientDisplayName(
            isset($input['company']) ? (string) $input['company'] : '',
            isset($input['firstName']) ? (string) $input['firstName'] : '',
            isset($input['lastName']) ? (string) $input['lastName'] : ''
        );

        $payload = array(
            'accountId' => $input['accountId'],
            'fiatCurrencyCode' => strtoupper((string) $input['currency']),
            'orderId' => $orderId,
            'number' => $orderId,
            'description' => Text::invoiceDescription($clientName, $labels, 'Invoice #' . implode(', ', $labels)),
            'webhookUrl' => $callbackUrl,
            'customPayload' => $customPayload,
            'customerId' => substr((string) $input['clientId'], 0, 52),
            'amount' => round((float) $input['amount'], 2),
        );

        $returnUrl = isset($input['returnUrl']) ? trim((string) $input['returnUrl']) : '';

        if ($returnUrl !== '') {
            $payload['returnUrl'] = $returnUrl;
            $payload['successUrl'] = $returnUrl;
            $payload['failedUrl'] = $returnUrl;
        }

        if (isset($expiry['minutes']) && $expiry['minutes'] !== null) {
            $payload['expiresInMinutes'] = (int) $expiry['minutes'];
        }

        return $payload;
    }

    /**
     * @param array $invoice
     * @return string|null
     */
    private static function paymentPageUrl(array $invoice)
    {
        if (!isset($invoice['paymentPageUrl']) || $invoice['paymentPageUrl'] === '') {
            return null;
        }

        return (string) $invoice['paymentPageUrl'];
    }

    /**
     * @param string $message
     * @return array{ok:bool,error:string,detail?:string}
     */
    private static function resolverError($message)
    {
        if ($message === 'Cryptonly order id is too long for these invoices.') {
            return self::error('order_id_too_long');
        }

        if ($message === 'Too many Cryptonly payment attempts for this invoice. Please contact support.') {
            return self::error('too_many_attempts');
        }

        if ($message === 'Unable to load Cryptonly payment status. Please try again later.') {
            return self::error('checkout_failed');
        }

        if ($message === '') {
            return self::error('checkout_failed');
        }

        return self::error('gateway', $message);
    }

    /**
     * @param string      $code
     * @param string|null $detail
     * @return array{ok:bool,error:string,detail?:string}
     */
    private static function error($code, $detail = null)
    {
        $result = array(
            'ok' => false,
            'error' => $code,
        );

        if ($detail !== null && $detail !== '') {
            $result['detail'] = $detail;
        }

        return $result;
    }
}
