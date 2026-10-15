<?php

namespace Cryptonly\Billing;

class Text
{
    const DESCRIPTION_MAX = 512;

    /**
     * @param string $value
     * @param int    $max
     * @return string
     */
    public static function truncate($value, $max = self::DESCRIPTION_MAX)
    {
        $value = (string) $value;
        $max = (int) $max;

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }

        return substr($value, 0, $max);
    }

    /**
     * Company name, otherwise first and last name.
     *
     * @param string $company
     * @param string $firstName
     * @param string $lastName
     * @return string
     */
    public static function clientDisplayName($company, $firstName, $lastName)
    {
        $company = trim((string) $company);

        if ($company !== '') {
            return $company;
        }

        return trim(trim((string) $firstName) . ' ' . trim((string) $lastName));
    }

    /**
     * @param string   $clientName
     * @param string[] $invoiceLabels
     * @param string   $fallback
     * @return string
     */
    public static function invoiceDescription($clientName, array $invoiceLabels, $fallback)
    {
        $labels = array();

        foreach ($invoiceLabels as $label) {
            $label = trim((string) $label);

            if ($label !== '') {
                $labels[] = $label;
            }
        }

        $invoiceText = $labels !== array() ? 'Invoice #' . implode(', ', $labels) : '';
        $clientName = trim((string) $clientName);

        if ($clientName !== '' && $invoiceText !== '') {
            return self::truncate($clientName . ' - ' . $invoiceText);
        }

        if ($invoiceText !== '') {
            return self::truncate($invoiceText);
        }

        return self::truncate((string) $fallback);
    }
}
