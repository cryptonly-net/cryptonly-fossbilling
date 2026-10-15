<?php

namespace Cryptonly\Billing;

class ApiClient
{
    const API_PRODUCTION = 'https://api-merchant.cryptonly.net';
    const API_SANDBOX = 'https://sandbox-api-merchant.cryptonly.net';

    /** @var string */
    private $apiKey;

    /** @var string */
    private $baseUrl;

    /** @var callable|null */
    private $logger;

    /** @var bool */
    private $includeBody;

    /**
     * @param string        $apiKey
     * @param bool          $sandbox
     * @param callable|null $logger function(string $url, string $data, string $direction, bool $success): void
     * @param bool          $includeBody Include the response body in log detail. The API key is never logged.
     */
    public function __construct($apiKey, $sandbox = false, $logger = null, $includeBody = false)
    {
        $this->apiKey = (string) $apiKey;
        $this->baseUrl = $sandbox ? self::API_SANDBOX : self::API_PRODUCTION;
        $this->logger = is_callable($logger) ? $logger : null;
        $this->includeBody = (bool) $includeBody;
    }

    /**
     * @param array $payload
     * @return array{ok:bool,data?:array,error?:string,status?:int}
     */
    public function createInvoice(array $payload)
    {
        return $this->request('POST', '/invoice', $payload);
    }

    /**
     * @param string      $accountId
     * @param string|null $orderId
     * @param string|null $invoiceId
     * @return array{ok:bool,data?:array,error?:string,status?:int}
     */
    public function getInvoice($accountId, $orderId = null, $invoiceId = null)
    {
        $query = array('accountId' => $accountId);

        if ($invoiceId) {
            $query['id'] = $invoiceId;
        }

        if ($orderId) {
            $query['orderId'] = $orderId;
        }

        return $this->request('GET', '/invoice', null, $query);
    }

    /**
     * @return array{ok:bool,data?:array,error?:string,status?:int}
     */
    public function listAccounts()
    {
        return $this->request('GET', '/accounts');
    }

    /**
     * @param string     $method
     * @param string     $path
     * @param array|null $body
     * @param array      $query
     * @return array{ok:bool,data?:array,error?:string,status?:int}
     */
    private function request($method, $path, $body = null, array $query = array())
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $headers = array(
            'Content-Type: application/json',
            'x-tenant-api-key: ' . $this->apiKey,
        );

        $requestDetail = $method . ' ' . $path;

        if ($body !== null) {
            $encoded = json_encode($body);
            $requestDetail .= $this->includeBody && is_string($encoded) ? "\n" . $encoded : '';
        }

        $this->log($url, $requestDetail, 'input', true);

        $curl = curl_init($url);

        if ($curl === false) {
            $this->log($url, 'Unable to initialize HTTP client.', 'output', false);

            return array(
                'ok' => false,
                'error' => 'Unable to initialize HTTP client.',
            );
        }

        $options = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        );

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
        }

        curl_setopt_array($curl, $options);

        $rawBody = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($rawBody === false) {
            $message = $curlError !== '' ? $curlError : 'HTTP request failed.';
            $this->log($url, $message, 'output', false);

            return array(
                'ok' => false,
                'error' => $message,
                'status' => $statusCode,
            );
        }

        $detail = $method . ' ' . $path . ' HTTP ' . $statusCode;

        if ($this->includeBody) {
            $detail .= "\n" . $rawBody;
        }

        $decoded = json_decode($rawBody, true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            $this->log($url, $detail, 'output', false);

            return array(
                'ok' => false,
                'error' => 'Invalid JSON response from Cryptonly API.',
                'status' => $statusCode,
            );
        }

        if ($statusCode >= 400) {
            $message = $this->extractErrorMessage($decoded, $statusCode);
            $this->log($url, $detail . "\n" . $message, 'output', false);

            return array(
                'ok' => false,
                'error' => $message,
                'status' => $statusCode,
                'data' => is_array($decoded) ? $decoded : array(),
            );
        }

        $this->log($url, $detail, 'output', true);

        if (is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])) {
            return array(
                'ok' => true,
                'data' => $decoded['data'],
                'status' => $statusCode,
            );
        }

        return array(
            'ok' => true,
            'data' => is_array($decoded) ? $decoded : array(),
            'status' => $statusCode,
        );
    }

    /**
     * @param array|null $decoded
     * @param int        $statusCode
     * @return string
     */
    private function extractErrorMessage($decoded, $statusCode)
    {
        if (is_array($decoded)) {
            if (!empty($decoded['message']) && is_string($decoded['message'])) {
                return $decoded['message'];
            }

            if (!empty($decoded['error']['message']) && is_string($decoded['error']['message'])) {
                return $decoded['error']['message'];
            }
        }

        return 'Cryptonly API request failed (HTTP ' . $statusCode . ').';
    }

    /**
     * @param string $url
     * @param string $data
     * @param string $direction
     * @param bool   $success
     */
    private function log($url, $data, $direction, $success)
    {
        if ($this->logger === null) {
            return;
        }

        call_user_func($this->logger, $url, $data, $direction, $success);
    }
}
