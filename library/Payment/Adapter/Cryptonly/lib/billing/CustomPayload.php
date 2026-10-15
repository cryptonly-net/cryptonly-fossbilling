<?php

namespace Cryptonly\Billing;

class CustomPayload
{
    const MAX_LENGTH = 2048;

    /**
     * @param int   $clientId
     * @param int[] $invoiceIds
     * @return string|null
     */
    public static function encode($clientId, array $invoiceIds)
    {
        $ids = array();

        foreach ($invoiceIds as $invoiceId) {
            $ids[] = (int) $invoiceId;
        }

        $json = json_encode(
            array(
                'client_id' => (int) $clientId,
                'invoice_ids' => $ids,
            )
        );

        if (!is_string($json) || strlen($json) > self::MAX_LENGTH) {
            return null;
        }

        return $json;
    }

    /**
     * @param string $json
     * @return array{client_id:int,invoice_ids:int[]}|null
     */
    public static function decode($json)
    {
        $parsed = json_decode((string) $json, true);

        if (!is_array($parsed) || empty($parsed['invoice_ids']) || !is_array($parsed['invoice_ids'])) {
            return null;
        }

        $ids = array();

        foreach ($parsed['invoice_ids'] as $invoiceId) {
            if (!is_numeric($invoiceId) || (int) $invoiceId <= 0) {
                return null;
            }

            $ids[] = (int) $invoiceId;
        }

        if ($ids === array()) {
            return null;
        }

        $clientId = isset($parsed['client_id']) ? (int) $parsed['client_id'] : 0;

        if ($clientId <= 0) {
            return null;
        }

        return array(
            'client_id' => $clientId,
            'invoice_ids' => $ids,
        );
    }
}
