<?php

namespace Cryptonly\Billing;

class AccountValidator
{
    /**
     * @param string $apiKey
     * @param string $accountId
     * @param bool   $sandbox
     * @param bool   $debugLog
     * @param ApiClient|null $client
     * @return string|null
     */
    public static function validate($apiKey, $accountId, $sandbox = false, $debugLog = false, $client = null)
    {
        $apiKey = trim((string) $apiKey);
        $accountId = trim((string) $accountId);

        if ($apiKey === '' && $accountId === '') {
            return null;
        }

        if ($apiKey !== '' && $accountId === '') {
            return 'Account ID is required when an API key is configured.';
        }

        if ($apiKey === '') {
            return 'API Key is required when an Account ID is configured.';
        }

        if (!self::isUuid($accountId)) {
            return 'Account ID must be a valid UUID.';
        }

        if ($client === null) {
            $client = new ApiClient($apiKey, $sandbox, null, $debugLog);
        }

        return self::validateListAccountsResponse($client->listAccounts(), $accountId);
    }

    /**
     * @param array  $response
     * @param string $accountId
     * @return string|null
     */
    public static function validateListAccountsResponse(array $response, $accountId)
    {
        if (!$response['ok']) {
            return self::mapApiError($response);
        }

        $accounts = isset($response['data']) && is_array($response['data']) ? $response['data'] : array();

        if (!self::accountIdExists($accounts, $accountId)) {
            return 'Account ID not found for this API key.';
        }

        return null;
    }

    /**
     * @param string $value
     * @return bool
     */
    public static function isUuid($value)
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            (string) $value
        );
    }

    /**
     * @param array  $accounts
     * @param string $accountId
     * @return bool
     */
    public static function accountIdExists(array $accounts, $accountId)
    {
        $needle = strtolower((string) $accountId);

        foreach ($accounts as $account) {
            if (!is_array($account) || empty($account['id'])) {
                continue;
            }

            if (strtolower((string) $account['id']) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{ok:bool,error?:string,status?:int} $response
     * @return string
     */
    public static function mapApiError(array $response)
    {
        $status = isset($response['status']) ? (int) $response['status'] : 0;

        if ($status === 401 || $status === 403) {
            return 'Invalid Cryptonly API key.';
        }

        if ($status === 0 || $status >= 500) {
            return 'Could not reach Cryptonly API. Check sandbox mode and server outbound HTTPS.';
        }

        if (!empty($response['error']) && is_string($response['error'])) {
            return $response['error'];
        }

        return 'Cryptonly API request failed (HTTP ' . $status . ').';
    }
}
