<?php

namespace Cryptonly\Billing;

class Expiry
{
    const MIN_MINUTES = 15;
    const MAX_MINUTES = 43200;

    /**
     * @param string   $dueDate YYYY-MM-DD
     * @param int|null $now
     * @return array{ok:bool,minutes?:int|null,message?:string}
     */
    public static function fromDueDate($dueDate, $now = null)
    {
        $dueDate = trim((string) $dueDate);

        if ($dueDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            return array(
                'ok' => true,
                'minutes' => null,
            );
        }

        $dueEnd = strtotime($dueDate . ' 23:59:59');

        if ($dueEnd === false) {
            return array(
                'ok' => true,
                'minutes' => null,
            );
        }

        $nowTs = $now !== null ? (int) $now : time();

        if ($dueEnd < $nowTs) {
            return array(
                'ok' => false,
                'message' => 'This invoice is past its due date.',
            );
        }

        $minutes = (int) ceil(($dueEnd - $nowTs) / 60);
        $minutes = max(self::MIN_MINUTES, min(self::MAX_MINUTES, $minutes));

        return array(
            'ok' => true,
            'minutes' => $minutes,
        );
    }

    /**
     * @param string[] $dueDates
     * @param int|null $now
     * @return array{ok:bool,minutes?:int|null,message?:string}
     */
    public static function fromEarliestDueDate(array $dueDates, $now = null)
    {
        $earliest = null;

        foreach ($dueDates as $dueDate) {
            $dueDate = trim((string) $dueDate);

            if ($dueDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
                continue;
            }

            if ($earliest === null || $dueDate < $earliest) {
                $earliest = $dueDate;
            }
        }

        if ($earliest === null) {
            return array(
                'ok' => true,
                'minutes' => null,
            );
        }

        return self::fromDueDate($earliest, $now);
    }
}
