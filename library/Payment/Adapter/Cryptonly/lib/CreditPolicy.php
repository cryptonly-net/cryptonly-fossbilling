<?php

namespace Cryptonly\Fossbilling;

/**
 * How a Cryptonly settlement lands in FOSSBilling.
 *
 * FOSSBilling has no partial invoice status. A short payment becomes client
 * credit. The invoice is marked paid only when that credit covers the total.
 */
class CreditPolicy
{
    /**
     * Fiat still needed after the client's current credit balance.
     */
    public static function amountStillDue($invoiceTotal, $clientBalance)
    {
        $due = round((float) $invoiceTotal - (float) $clientBalance, 2);

        return $due > 0 ? $due : 0.0;
    }

    /**
     * @param array{action?:string,amount?:float|int|string,reason?:string} $decision
     * @return array{credit:float,pay_invoice:bool}
     */
    public static function effect(array $decision)
    {
        if (!isset($decision['action']) || $decision['action'] !== 'approved') {
            return array(
                'credit' => 0.0,
                'pay_invoice' => false,
            );
        }

        $credit = round(isset($decision['amount']) ? (float) $decision['amount'] : 0.0, 2);

        if ($credit <= 0) {
            return array(
                'credit' => 0.0,
                'pay_invoice' => false,
            );
        }

        $reason = isset($decision['reason']) ? (string) $decision['reason'] : '';

        return array(
            'credit' => $credit,
            'pay_invoice' => $reason !== 'partially_paid',
        );
    }
}
