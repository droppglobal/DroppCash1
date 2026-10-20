<?php

namespace App\Services\Flutterwave;

/**
 * Small Flutterwave v3 client for NAIRA PAYOUTS (plain PHP + curl, no Laravel needed, so it can be tested on its own).
 * Every call returns ['http' => status code (0 if no answer), 'json' => decoded body or null, 'error' => curl error or null].
 */
class FlutterwaveClient
{
    private $secret;
    private $base;
    private $timeout;

    public function __construct($secret, $base = 'https://api.flutterwave.com/v3', $timeout = 25)
    {
        $this->secret  = (string) $secret;
        $this->base    = rtrim($base, '/');
        $this->timeout = (int) $timeout;
    }

    private function call($method, $path, array $query = [], array $body = null)
    {
        $url = $this->base . $path . ($query ? '?' . http_build_query($query) : '');
        $ch  = curl_init($url);
        $headers = ['Authorization: Bearer ' . $this->secret, 'Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw  = curl_exec($ch);
        $err  = $raw === false ? curl_error($ch) : null;
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['http' => $err ? 0 : $http, 'json' => $err ? null : json_decode($raw, true), 'error' => $err];
    }

    /** Nigerian banks: [['code' => '044', 'name' => 'Access Bank'], ...] or null when unavailable */
    public function banks()
    {
        $r = $this->call('GET', '/banks/NG');
        if ($r['http'] !== 200 || ($r['json']['status'] ?? '') !== 'success' || !is_array($r['json']['data'] ?? null)) {
            return null;
        }
        $list = [];
        foreach ($r['json']['data'] as $b) {
            if (!empty($b['code']) && !empty($b['name'])) {
                $list[] = ['code' => (string) $b['code'], 'name' => (string) $b['name']];
            }
        }
        usort($list, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
        return $list ?: null;
    }

    /** ['ok' => bool, 'account_name' => string|null, 'message' => string] */
    public function resolve($bankCode, $accountNumber)
    {
        $r = $this->call('POST', '/accounts/resolve', [], ['account_number' => (string) $accountNumber, 'account_bank' => (string) $bankCode]);
        $name = $r['json']['data']['account_name'] ?? null;
        if ($r['http'] === 200 && ($r['json']['status'] ?? '') === 'success' && is_string($name) && trim($name) !== '') {
            return ['ok' => true, 'account_name' => trim($name), 'message' => 'ok'];
        }
        return ['ok' => false, 'account_name' => null, 'message' => $r['json']['message'] ?? 'Could not verify this account'];
    }

    /** Flutterwave's transfer fee in NGN (rounded UP to the kobo), or null when unavailable */
    public function fee($amount)
    {
        $r = $this->call('GET', '/transfers/fee', ['amount' => $amount, 'currency' => 'NGN', 'type' => 'account']);
        $row = $r['json']['data'][0] ?? null;
        if ($r['http'] !== 200 || ($r['json']['status'] ?? '') !== 'success' || !is_array($row) || !isset($row['fee']) || !is_numeric($row['fee'])) {
            return null;
        }
        $fee = (float) $row['fee'];
        if (($row['fee_type'] ?? 'value') === 'percentage') {
            $fee = (float) $amount * $fee / 100;
        }
        return ceil($fee * 100) / 100;
    }

    /** Available NGN balance on the Flutterwave account, or null when unavailable */
    public function balance()
    {
        $r = $this->call('GET', '/balances/NGN');
        $v = $r['json']['data']['available_balance'] ?? null;
        if ($r['http'] !== 200 || ($r['json']['status'] ?? '') !== 'success' || !is_numeric($v)) {
            return null;
        }
        return (float) $v;
    }

    public function createTransfer(array $payload)
    {
        return $this->call('POST', '/transfers', [], $payload);
    }

    public function getTransfer($id)
    {
        return $this->call('GET', '/transfers/' . rawurlencode((string) $id));
    }

    /** Looks a transfer up by OUR reference (exact match only). Returns the transfer array, or null if not found. */
    public function findByReference($reference)
    {
        $r = $this->call('GET', '/transfers', ['reference' => $reference]);
        if ($r['http'] !== 200 || !is_array($r['json']['data'] ?? null)) {
            return null;
        }
        foreach ($r['json']['data'] as $t) {
            if (is_array($t) && ($t['reference'] ?? null) === $reference) {
                return $t;
            }
        }
        return null;
    }
}
