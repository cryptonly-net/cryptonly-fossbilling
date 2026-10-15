<?php

namespace Cryptonly\Billing;

class OrderId
{
    const MAX_LENGTH = 256;

    /**
     * @param string $raw
     * @param string $defaultPrefix
     * @return string
     */
    public static function normalizePrefix($raw, $defaultPrefix)
    {
        $prefix = trim((string) $raw);

        if ($prefix === '') {
            return (string) $defaultPrefix;
        }

        return $prefix;
    }

    /**
     * @param string $prefix
     * @return bool
     */
    public static function isValidPrefix($prefix)
    {
        $prefix = trim((string) $prefix);

        if ($prefix === '') {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z0-9_-]{1,32}$/', $prefix);
    }

    /**
     * @param string $raw
     * @return string|null
     */
    public static function validatePrefix($raw)
    {
        $prefix = trim((string) $raw);

        if ($prefix === '') {
            return null;
        }

        if (!self::isValidPrefix($prefix)) {
            return 'Order ID Prefix must be 1–32 characters (letters, numbers, underscores, hyphens only).';
        }

        return null;
    }

    /**
     * One invoice: {prefix}-{id}. Attempt 2+ appends -{attempt}.
     *
     * @param int[]  $invoiceIds
     * @param int    $attempt
     * @param string $prefix
     * @param string $defaultPrefix
     * @return string|null Null when the id would exceed the API limit.
     */
    public static function forInvoices(array $invoiceIds, $attempt, $prefix, $defaultPrefix)
    {
        $ids = array();

        foreach ($invoiceIds as $invoiceId) {
            $ids[] = (int) $invoiceId;
        }

        sort($ids, SORT_NUMERIC);
        $prefix = self::normalizePrefix($prefix, $defaultPrefix);
        $attempt = (int) $attempt;
        $key = implode('x', $ids);

        if ($attempt <= 1) {
            $orderId = $prefix . '-' . $key;
        } else {
            $orderId = $prefix . '-' . $key . '-' . $attempt;
        }

        if (strlen($orderId) > self::MAX_LENGTH) {
            return null;
        }

        return $orderId;
    }
}
