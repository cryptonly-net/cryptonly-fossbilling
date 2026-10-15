<?php

/**
 * Load the FOSSBilling adapter helpers and the bundled billing library.
 *
 * @param string $libDir Directory that contains CreditPolicy.php and billing/
 */
function cryptonly_fossbilling_load($libDir)
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    require_once $libDir . '/CreditPolicy.php';
    require_once $libDir . '/BillingGateway.php';
    require_once $libDir . '/SettlementApplier.php';
    require_once $libDir . '/FossBillingGateway.php';

    $billing = rtrim((string) $libDir, '/\\') . '/billing/bootstrap.php';

    if (!is_file($billing)) {
        throw new RuntimeException(
            'Cryptonly billing library was not found. Install the release zip, which bundles the library.'
        );
    }

    require_once $billing;
    $loaded = true;
}
