<?php

namespace Cryptonly\Billing;

class HttpsUrl
{
    /**
     * Public HTTPS URL with a dotted host. Cryptonly webhook URLs must match this.
     *
     * @param string $url
     * @return bool
     */
    public static function isPublicHttps($url)
    {
        $parts = parse_url(trim((string) $url));

        if (!is_array($parts)) {
            return false;
        }

        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';

        if ($scheme !== 'https' || $host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        if (strpos($host, '.') === false) {
            return false;
        }

        return true;
    }
}
