<?php

namespace Cryptonly\Billing;

class CheckoutResolver
{
    const MAX_ATTEMPTS = 20;

    /**
     * @param callable $orderIdForAttempt function(int $attempt): string|null
     * @param callable $fetchByOrderId    function(string $orderId): array
     * @return array{action:string,orderId?:string,invoice?:array,message?:string}
     */
    public static function resolve(callable $orderIdForAttempt, callable $fetchByOrderId)
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $orderId = $orderIdForAttempt($attempt);

            if (!is_string($orderId) || $orderId === '') {
                return array(
                    'action' => 'error',
                    'message' => 'Cryptonly order id is too long for these invoices.',
                );
            }

            $response = $fetchByOrderId($orderId);

            if (!empty($response['ok']) && !empty($response['data']) && is_array($response['data'])) {
                $status = isset($response['data']['status']) ? (string) $response['data']['status'] : '';

                if (WebhookVerifier::invoiceAllowsPayment($status)) {
                    return array(
                        'action' => 'redirect',
                        'orderId' => $orderId,
                        'invoice' => $response['data'],
                    );
                }

                if (WebhookVerifier::isCheckoutFinalized($status)) {
                    return array(
                        'action' => 'blocked',
                        'message' => WebhookVerifier::checkoutFinalizedMessage(),
                    );
                }

                continue;
            }

            if (self::isNotFound($response)) {
                return array(
                    'action' => 'create',
                    'orderId' => $orderId,
                );
            }

            $message = !empty($response['error']) && is_string($response['error'])
                ? $response['error']
                : 'Unable to load Cryptonly payment status. Please try again later.';

            return array(
                'action' => 'error',
                'message' => $message,
            );
        }

        return array(
            'action' => 'error',
            'message' => 'Too many Cryptonly payment attempts for this invoice. Please contact support.',
        );
    }

    /**
     * @param array{ok?:bool,status?:int} $response
     * @return bool
     */
    public static function isNotFound(array $response)
    {
        if (!empty($response['ok'])) {
            return false;
        }

        return isset($response['status']) && (int) $response['status'] === 404;
    }
}
