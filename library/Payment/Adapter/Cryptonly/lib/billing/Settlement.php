<?php

namespace Cryptonly\Billing;

class Settlement
{
    /**
     * @param float $balanceDue
     * @param float $invoiceAmount
     * @param float $tolerance
     * @return bool
     */
    public static function invoiceAmountCoversBalanceDue($balanceDue, $invoiceAmount, $tolerance = 0.0001)
    {
        $balanceDue = (float) $balanceDue;
        $invoiceAmount = (float) $invoiceAmount;
        $tolerance = (float) $tolerance;

        if ($balanceDue <= 0) {
            return true;
        }

        return $invoiceAmount + $tolerance >= $balanceDue;
    }

    /**
     * Spread $amount across invoices in order, never above each invoice amount.
     *
     * @param float $amount
     * @param array<int,array{id:int|string,amount:float|int|string}> $invoices
     * @return array{invoices:array<int,array{id:int|string,amount:float}>,amount:float}
     */
    public static function allocate($amount, array $invoices)
    {
        $remaining = round((float) $amount, 2);
        $applied = array();

        foreach ($invoices as $invoice) {
            if ($remaining <= 0) {
                break;
            }

            if (!isset($invoice['id'])) {
                continue;
            }

            $room = round(isset($invoice['amount']) ? (float) $invoice['amount'] : 0.0, 2);

            if ($room <= 0) {
                continue;
            }

            $take = $remaining < $room ? $remaining : $room;
            $take = round($take, 2);

            if ($take <= 0) {
                continue;
            }

            $applied[] = array(
                'id' => $invoice['id'],
                'amount' => $take,
            );
            $remaining = round($remaining - $take, 2);
        }

        $sum = 0.0;

        foreach ($applied as $row) {
            $sum += (float) $row['amount'];
        }

        return array(
            'invoices' => $applied,
            'amount' => round($sum, 2),
        );
    }

    /**
     * @param array<int,array{amount?:float|int|string}> $invoices
     * @return float
     */
    public static function sumAmounts(array $invoices)
    {
        $sum = 0.0;

        foreach ($invoices as $invoice) {
            $sum += isset($invoice['amount']) ? (float) $invoice['amount'] : 0.0;
        }

        return round($sum, 2);
    }
}
