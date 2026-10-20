<?php

namespace App\Services\Flutterwave;

/**
 * The DECISIONS of instant Naira withdrawal, kept free of Laravel so every rule can be tested on its own.
 */
class InstantPayoutLogic
{
    /* ---------------------------------------------------------------- names */

    public static function normalizeName($name)
    {
        $s = (string) $name;
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($t !== false && $t !== '') {
                $s = $t;
            }
        }
        $s = strtoupper($s);
        $s = preg_replace('/[^A-Z0-9 ]+/', ' ', $s);        // hyphens, dots and apostrophes act as spaces
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    public static function nameTokens($name)
    {
        $titles = ['MR', 'MRS', 'MISS', 'MS', 'DR', 'PROF', 'CHIEF', 'ALHAJI', 'ALHAJA', 'ENGR', 'PST', 'MALLAM', 'MAL'];
        $out = [];
        foreach (explode(' ', self::normalizeName($name)) as $t) {
            if (strlen($t) >= 2 && !in_array($t, $titles, true)) {
                $out[$t] = $t;
            }
        }
        return array_values($out);
    }

    /**
     * Does the bank account holder match the profile name?
     * Order and case do not matter. At least TWO name parts must match (a spelling slip of one letter is tolerated
     * on longer names). A profile name with only one word never matches, so it goes to manual review.
     */
    public static function namesMatch($profileName, $accountName)
    {
        $a = self::nameTokens($profileName);
        $b = self::nameTokens($accountName);
        if (count($a) < 2 || !$b) {
            return false;
        }
        $hits = 0;
        $used = [];
        foreach ($a as $ta) {
            foreach ($b as $i => $tb) {
                if (isset($used[$i])) {
                    continue;
                }
                if ($ta === $tb || (strlen($ta) >= 5 && strlen($tb) >= 5 && levenshtein($ta, $tb) <= 1)) {
                    $hits++;
                    $used[$i] = true;
                    break;
                }
            }
        }
        return $hits >= 2;
    }

    /* ---------------------------------------------------------------- input */

    public static function validAccountNumber($n)
    {
        return is_string($n) && preg_match('/^\d{10}$/', $n) === 1;
    }

    public static function validAmount($amount)
    {
        return is_numeric($amount) && (float) $amount > 0 && abs(round((float) $amount, 2) - (float) $amount) < 0.0000001;
    }

    /** @return array [charge, total] charge = the method's own charge + Flutterwave's fee (passed on to the user) */
    public static function price($amount, $methodCharge, $flwFee)
    {
        $charge = round((float) $methodCharge + (float) $flwFee, 2);
        return ['charge' => $charge, 'total' => round((float) $amount + $charge, 2)];
    }

    public static function maskAccount($n)
    {
        $n = (string) $n;
        return strlen($n) > 4 ? str_repeat('*', strlen($n) - 4) . substr($n, -4) : $n;
    }

    public static function summary($bankName, $accountNumber, $accountName)
    {
        return substr('Instant bank transfer | ' . $bankName . ' | ' . $accountNumber . ' | ' . $accountName, 0, 250);
    }

    /* ---------------------------------------------- Flutterwave's answers */

    /**
     * What did Flutterwave answer when we asked it to CREATE a transfer?
     * state: completed | processing | failed | low_balance | unknown
     *   unknown   = we cannot tell if the transfer exists (timeout, 5xx...) -> NEVER refund or resend blindly
     *   failed    = Flutterwave clearly refused / failed it -> safe to refund
     *   low_balance = our Flutterwave balance is too low -> hold for an admin
     */
    public static function interpretCreate(array $r)
    {
        $http = (int) ($r['http'] ?? 0);
        $j    = $r['json'] ?? null;
        $msg  = is_array($j) ? (string) ($j['message'] ?? '') : '';

        if ($http === 0 || !is_array($j) || $http >= 500 || $http === 429) {
            return ['state' => 'unknown', 'message' => 'No clear answer from Flutterwave', 'transfer_id' => null];
        }

        $d      = is_array($j['data'] ?? null) ? $j['data'] : [];
        $status = strtoupper((string) ($d['status'] ?? ''));
        $id     = isset($d['id']) ? (string) $d['id'] : null;
        $done   = (string) ($d['complete_message'] ?? '');

        if ($http >= 200 && $http < 300 && ($j['status'] ?? '') === 'success' && $id) {
            if ($status === 'SUCCESSFUL') return ['state' => 'completed', 'message' => $done, 'transfer_id' => $id];
            if ($status === 'FAILED')     return ['state' => 'failed', 'message' => $done ?: 'Transfer failed', 'transfer_id' => $id];
            return ['state' => 'processing', 'message' => $done, 'transfer_id' => $id];
        }

        if ($status === 'FAILED' && $id) {
            return ['state' => 'failed', 'message' => $done ?: ($msg ?: 'Transfer failed'), 'transfer_id' => $id];
        }
        if (stripos($msg, 'insufficient') !== false || stripos($msg, 'balance') !== false) {
            return ['state' => 'low_balance', 'message' => $msg, 'transfer_id' => null];
        }
        if (stripos($msg, 'reference') !== false && (stripos($msg, 'exist') !== false || stripos($msg, 'duplicate') !== false || stripos($msg, 'already') !== false)) {
            return ['state' => 'unknown', 'message' => $msg, 'transfer_id' => null];      // it may have gone through the first time
        }
        if ($http >= 400 && $http < 500) {
            return ['state' => 'failed', 'message' => $msg ?: 'Transfer refused', 'transfer_id' => null, 'config' => in_array($http, [401, 403], true)];
        }
        return ['state' => 'unknown', 'message' => $msg ?: 'Unexpected answer from Flutterwave', 'transfer_id' => null];
    }

    /**
     * Judge a transfer fetched from Flutterwave against OUR withdrawal ($w = ['trx' =>, 'amount' =>]).
     * verdict: success | failed | pending | mismatch | error.  Only 'success' and 'failed' may change money.
     */
    public static function interpretTransfer($t, array $w)
    {
        if (!is_array($t) || empty($t)) {
            return ['verdict' => 'error', 'message' => 'No transfer data', 'transfer_id' => null];
        }
        $id = isset($t['id']) ? (string) $t['id'] : null;
        if (($t['reference'] ?? null) !== $w['trx']) {
            return ['verdict' => 'mismatch', 'message' => 'Reference does not match', 'transfer_id' => $id];
        }
        if (strtoupper((string) ($t['currency'] ?? '')) !== 'NGN') {
            return ['verdict' => 'mismatch', 'message' => 'Currency does not match', 'transfer_id' => $id];
        }
        if (!isset($t['amount']) || !is_numeric($t['amount']) || abs((float) $t['amount'] - (float) $w['amount']) > 0.005) {
            return ['verdict' => 'mismatch', 'message' => 'Amount does not match', 'transfer_id' => $id];
        }
        $message = (string) ($t['complete_message'] ?? '');
        switch (strtoupper((string) ($t['status'] ?? ''))) {
            case 'SUCCESSFUL': return ['verdict' => 'success', 'message' => $message, 'transfer_id' => $id];
            case 'FAILED':     return ['verdict' => 'failed', 'message' => $message ?: 'Transfer failed', 'transfer_id' => $id];
        }
        return ['verdict' => 'pending', 'message' => $message, 'transfer_id' => $id];
    }
}
