<?php

namespace App\Services\Flutterwave;

use App\Models\PaymentGateway;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\Withdrawals;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Instant NAIRA withdrawals paid out through Flutterwave Transfers.
 *
 * payout_status of a withdrawal:
 *   review      waiting for an admin (name did not match, Flutterwave balance low, or something needs a human look)
 *   queued      created, about to be sent
 *   processing  sent to Flutterwave, waiting for the final answer (webhook / reconcile)
 *   completed   paid  (status = 1)
 *   failed      not paid, money returned to the wallet (status = 2)
 *
 * Money rules (same pattern as the rest of the app):
 *   - the wallet is debited atomically, together with the withdrawal row, in one database transaction
 *   - we NEVER refund and NEVER resend because of a timeout or a 5xx: only Flutterwave's clear answer can do that
 *   - every final step first CLAIMS the row (UPDATE ... WHERE payout_status IN (...)), so it can only happen once
 */
class InstantWithdrawal
{
    /* ---------------------------------------------------------------- setup */

    public static function secret()
    {
        $gateway = PaymentGateway::whereKeyword('flutterwave')->first();
        $data    = $gateway ? ($gateway->convertAutoData() ?: []) : [];
        return trim($data['secret_key'] ?? '');
    }

    /** overridable for tests only */
    public static $apiBase = 'https://api.flutterwave.com/v3';
    public static $timeout = 25;

    public static function client()
    {
        $secret = self::secret();
        return $secret === '' ? null : new FlutterwaveClient($secret, self::$apiBase, self::$timeout);
    }

    private static function fail_($message)
    {
        return ['ok' => false, 'message' => $message];
    }

    public static function banks()
    {
        $cached = Cache::get('flw_ng_banks');
        if (is_array($cached) && $cached) {
            return $cached;
        }
        $client = self::client();
        $banks  = $client ? $client->banks() : null;
        if ($banks) {
            Cache::put('flw_ng_banks', $banks, 43200);
        }
        return $banks;
    }

    /* ---------------------------------------------------------------- quote */

    /** Verify bank + account number with Flutterwave and compare the holder's name with the profile name. */
    public static function resolveAccount($user, $bankCode, $accountNumber)
    {
        $client = self::client();
        if (!$client) {
            return self::fail_(__('Instant withdrawal is temporarily unavailable.'));
        }
        if (!InstantPayoutLogic::validAccountNumber((string) $accountNumber)) {
            return self::fail_(__('Enter a valid 10-digit account number.'));
        }
        $bank = null;
        foreach ((self::banks() ?: []) as $b) {
            if ($b['code'] === (string) $bankCode) {
                $bank = $b;
                break;
            }
        }
        if (!$bank) {
            return self::fail_(__('Please choose a valid bank.'));
        }
        $r = $client->resolve($bank['code'], (string) $accountNumber);
        if (!$r['ok']) {
            return self::fail_(__('We could not verify this account. Check the bank and the account number.'));
        }
        return [
            'ok'           => true,
            'bank'         => $bank,
            'account_name' => $r['account_name'],
            'match'        => InstantPayoutLogic::namesMatch($user->name, $r['account_name']),
        ];
    }

    /** Charge = the method's own charge + Flutterwave's transfer fee (passed on to the user). */
    public static function price($method, $amount)
    {
        $client = self::client();
        $fee    = $client ? $client->fee((float) $amount) : null;
        if ($fee === null) {
            return self::fail_(__('Instant withdrawal is temporarily unavailable.'));
        }
        $methodCharge = (float) chargeCalc($method, (float) $amount);
        return ['ok' => true, 'fee' => $fee, 'method_charge' => $methodCharge] + InstantPayoutLogic::price($amount, $methodCharge, $fee);
    }

    /** Same limits as a manual withdrawal (the method's min / max), plus an optional daily cap. Returns an error text or null. */
    public static function checkRequest($user, $wallet, $method, $amount)
    {
        if (!$method || !$method->is_instant || !$method->status) {
            return __('Instant withdrawal is not available.');
        }
        if (!$wallet || $wallet->user_id != $user->id || $wallet->user_type != 1 || $wallet->currency_id != $method->currency_id) {
            return __('Wallet not found');
        }
        if (optional($wallet->currency)->code !== 'NGN') {
            return __('Instant withdrawal is only available for Naira.');
        }
        if (!InstantPayoutLogic::validAmount($amount)) {
            return __('Enter a valid amount (up to 2 decimal places).');
        }
        if ($amount < $method->min_amount || $amount > $method->max_amount) {
            return __('Please follow the limit');
        }
        if ((float) $method->daily_limit > 0) {
            $today = Withdrawals::where('user_id', $user->id)->where('instant', 1)->where('payout_status', '!=', 'failed')
                ->where('created_at', '>=', now()->startOfDay())->sum('amount');
            if ($today + $amount > (float) $method->daily_limit) {
                return __('You have reached the daily limit for instant withdrawals.');
            }
        }
        return null;
    }

    /* --------------------------------------------------------------- create */

    /**
     * The one entry point for a user's instant withdrawal (website AND app).
     * Everything is re-checked here: nothing sent by the browser is trusted.
     * @return array ['ok' => bool, 'message' => text, 'state' => review|processing|completed|failed, 'withdrawal' => Withdrawals]
     */
    public static function create($user, $wallet, $method, $amount, $bankCode, $accountNumber)
    {
        // validate exactly what was typed (3 decimal places are refused, not silently rounded), then work in kobo
        if ($error = self::checkRequest($user, $wallet, $method, $amount)) {
            return self::fail_($error);
        }
        $amount = round((float) $amount, 2);
        $account = self::resolveAccount($user, $bankCode, $accountNumber);
        if (!$account['ok']) {
            return $account;
        }
        $price = self::price($method, $amount);
        if (!$price['ok']) {
            return $price;
        }
        if ($wallet->balance < $price['total']) {
            return self::fail_(__('Insufficient balance'));
        }

        $state  = $account['match'] ? 'queued' : 'review';
        $reason = $account['match'] ? null : 'Account name (' . $account['account_name'] . ') does not match the profile name (' . $user->name . ')';

        $withdraw = null;
        $refused  = DB::transaction(function () use (&$withdraw, &$wallet, &$method, &$user, &$amount, &$account, &$price, &$state, &$reason, &$accountNumber) {
            // atomic check-and-deduct, together with the withdrawal row
            if (!Wallet::where('id', $wallet->id)->where('balance', '>=', $price['total'])->decrement('balance', $price['total'])) {
                return self::fail_(__('Insufficient balance'));
            }
            $wallet->refresh();

            $withdraw                  = new Withdrawals();
            $withdraw->trx             = str_rand();
            $withdraw->user_id         = $user->id;
            $withdraw->method_id       = $method->id;
            $withdraw->currency_id     = $wallet->currency_id;
            $withdraw->amount          = $amount;
            $withdraw->charge          = $price['charge'];
            $withdraw->total_amount    = $price['total'];
            $withdraw->user_data       = InstantPayoutLogic::summary($account['bank']['name'], $accountNumber, $account['account_name']);
            $withdraw->instant         = 1;
            $withdraw->payout_status   = $state;
            $withdraw->bank_code       = $account['bank']['code'];
            $withdraw->bank_name       = $account['bank']['name'];
            $withdraw->account_number  = $accountNumber;
            $withdraw->account_name    = $account['account_name'];
            $withdraw->flw_fee         = $price['fee'];
            $withdraw->payout_message  = $reason;
            $withdraw->payout_at       = now();
            $withdraw->save();
            return null;
        }, 3);
        if ($refused) {
            return $refused;
        }

        if ($state === 'review') {
            self::mailPending($withdraw, $user, $wallet);
            self::alertAdmins($withdraw, $reason);
            return ['ok' => true, 'state' => 'review', 'withdrawal' => $withdraw, 'message' => __('Your withdrawal was received. Because the account name does not match the name on your profile, our team will review it and pay it out shortly. We will email you.')];
        }

        $final = self::send($withdraw);
        $withdraw = Withdrawals::find($withdraw->id);
        if ($final === 'failed') {
            return ['ok' => false, 'state' => 'failed', 'withdrawal' => $withdraw, 'message' => __('The transfer could not be completed. Your money has been returned to your wallet.')];
        }
        if ($final === 'review') {
            self::mailPending($withdraw, $user, $wallet);
            return ['ok' => true, 'state' => 'review', 'withdrawal' => $withdraw, 'message' => __('Your withdrawal was received and is being processed by our team. We will email you.')];
        }
        return ['ok' => true, 'state' => $final, 'withdrawal' => $withdraw, 'message' => __('Your withdrawal is on its way to your bank account. We will email you when it is done.')];
    }

    /* ----------------------------------------------------------------- send */

    /** Ask Flutterwave to pay a queued (or admin-approved) withdrawal. Returns the new payout_status. */
    public static function send(Withdrawals $w)
    {
        // claim: exactly one process may send a given withdrawal
        $claimed = Withdrawals::where('id', $w->id)->where('status', 0)->whereIn('payout_status', ['queued', 'review'])
            ->update(['payout_status' => 'processing', 'payout_message' => 'Sending to Flutterwave', 'payout_at' => now()]);
        $w->refresh();
        if (!$claimed) {
            return (string) $w->payout_status;
        }

        $client = self::client();
        if (!$client) {
            self::hold($w, 'Flutterwave is not configured (secret key missing).');
            return 'review';
        }

        $balance = $client->balance();
        if ($balance !== null && $balance < (float) $w->amount + (float) $w->flw_fee) {
            self::hold($w, 'The Flutterwave NGN balance is too low (' . number_format($balance, 2) . ') to pay this withdrawal. Fund it, then approve.');
            return 'review';
        }

        $result = $client->createTransfer([
            'account_bank'   => $w->bank_code,
            'account_number' => $w->account_number,
            'amount'         => round((float) $w->amount, 2),
            'currency'       => 'NGN',
            'debit_currency' => 'NGN',
            'narration'      => 'DroppCash withdrawal ' . $w->trx,
            'reference'      => $w->trx,
            'callback_url'   => route('flutterwave.payout.notify'),
        ]);
        $decision = InstantPayoutLogic::interpretCreate($result);
        Log::info('Instant withdrawal ' . $w->trx . ' create -> ' . $decision['state'] . ' ' . $decision['message']);

        switch ($decision['state']) {
            case 'failed':
                if (!empty($decision['config'])) {
                    self::alertAdmins($w, 'Flutterwave refused the request (' . $decision['message'] . '). Check the secret key and the allowed IP addresses in your Flutterwave dashboard.');
                }
                self::fail($w, $decision['message'], $decision['transfer_id']);
                return 'failed';
            case 'low_balance':
                self::hold($w, 'Flutterwave: ' . $decision['message']);
                return 'review';
            case 'processing':
            case 'completed':
                Withdrawals::where('id', $w->id)->update(['flw_transfer_id' => $decision['transfer_id'], 'payout_message' => 'Sent to Flutterwave']);
                // never trust the first answer alone: confirm with a fresh lookup
                return self::reconcile($w, true);
        }
        // unknown: we cannot tell whether it went through. Stay "processing"; the webhook / reconcile will find out by our reference.
        Withdrawals::where('id', $w->id)->update(['payout_message' => 'Waiting for confirmation from Flutterwave']);
        return 'processing';
    }

    /* ------------------------------------------------------------ reconcile */

    /** Ask Flutterwave what really happened to a processing withdrawal, and settle it. Safe to call many times. */
    public static function reconcile(Withdrawals $w, $force = false)
    {
        $w->refresh();
        if (!$w->instant || $w->status != 0 || !in_array($w->payout_status, ['processing', 'queued'], true)) {
            return (string) $w->payout_status;
        }
        if (!$force && $w->payout_at && $w->payout_at->gt(now()->subSeconds(8))) {
            return (string) $w->payout_status;       // do not hammer Flutterwave
        }
        $client = self::client();
        if (!$client) {
            return (string) $w->payout_status;
        }
        Withdrawals::where('id', $w->id)->update(['payout_at' => now()]);

        $transfer = null;
        if ($w->flw_transfer_id) {
            $r = $client->getTransfer($w->flw_transfer_id);
            if ($r['http'] === 200 && is_array($r['json']['data'] ?? null)) {
                $transfer = $r['json']['data'];
            }
        } else {
            $transfer = $client->findByReference($w->trx);
        }

        if (!$transfer) {
            if (!$w->flw_transfer_id && $w->created_at->lt(now()->subMinutes(15))) {
                self::hold($w, 'Flutterwave has no record of this transfer after 15 minutes. Check your Flutterwave dashboard before approving, so it is not paid twice.');
                return 'review';
            }
            return (string) $w->payout_status;
        }

        $verdict = InstantPayoutLogic::interpretTransfer($transfer, ['trx' => $w->trx, 'amount' => $w->amount]);
        switch ($verdict['verdict']) {
            case 'success':
                self::complete($w, $verdict['message'], $verdict['transfer_id']);
                return 'completed';
            case 'failed':
                self::fail($w, $verdict['message'], $verdict['transfer_id']);
                return 'failed';
            case 'mismatch':
                self::hold($w, 'Flutterwave data does not match this withdrawal (' . $verdict['message'] . '). Check your Flutterwave dashboard.');
                return 'review';
        }
        if ($verdict['transfer_id'] && !$w->flw_transfer_id) {
            Withdrawals::where('id', $w->id)->update(['flw_transfer_id' => $verdict['transfer_id']]);
        }
        return 'processing';
    }

    /** Admin approves a withdrawal that was waiting in review. */
    public static function approve(Withdrawals $w)
    {
        $w->refresh();
        if (!$w->instant || $w->status != 0 || $w->payout_status !== 'review') {
            return self::fail_(__('This withdrawal can not be approved.'));
        }
        // an earlier attempt may have reached Flutterwave: never pay twice
        $client   = self::client();
        $existing = $client ? $client->findByReference($w->trx) : null;
        if ($existing) {
            Withdrawals::where('id', $w->id)->where('payout_status', 'review')->update(['payout_status' => 'processing', 'flw_transfer_id' => $existing['id'] ?? null]);
            $state = self::reconcile($w, true);
            return ['ok' => true, 'message' => __('A transfer for this withdrawal already exists at Flutterwave. Its status was refreshed: ') . $state];
        }
        $state = self::send($w);
        return ['ok' => $state !== 'failed', 'message' => __('Sent to Flutterwave. Status: ') . $state];
    }

    /* ---------------------------------------------------------- final steps */

    /** Paid. Happens once. */
    public static function complete(Withdrawals $w, $message = '', $transferId = null)
    {
        $trnx = null;
        $done = DB::transaction(function () use (&$w, &$trnx, &$message, &$transferId) {
            $claimed = Withdrawals::where('id', $w->id)->where('status', 0)->whereIn('payout_status', ['processing', 'queued', 'review'])
                ->update(['status' => 1, 'payout_status' => 'completed', 'payout_message' => substr((string) $message ?: 'Paid', 0, 250), 'flw_transfer_id' => $transferId ?: $w->flw_transfer_id]);
            if (!$claimed) {
                return false;
            }
            $w->refresh();
            $trnx              = new Transaction();
            $trnx->trnx        = str_rand();
            $trnx->user_id     = $w->user_id;
            $trnx->user_type   = 1;
            $trnx->currency_id = $w->currency_id;
            $trnx->amount      = $w->amount;
            $trnx->charge      = $w->charge;
            $trnx->remark      = 'withdraw_money';
            $trnx->type        = '-';
            $trnx->details     = trans('Withdraw money');
            $trnx->save();
            return true;
        }, 3);

        if ($done) {
            try {
                @mailSend('accept_withdraw', ['amount' => amount($w->amount, $w->currency->type, 2), 'method' => $w->method->name, 'trnx' => $trnx->trnx, 'curr' => $w->currency->code,
                    'charge' => amount($w->charge, $w->currency->type, 2), 'date_time' => dateFormat($trnx->created_at)], $w->user);
            } catch (\Throwable $th) {
                // an email problem must never undo a payout
            }
        }
        return (bool) $done;
    }

    /** Not paid: return the money to the wallet. Happens once. */
    public static function fail(Withdrawals $w, $reason = '', $transferId = null)
    {
        $trnx = null;
        $done = DB::transaction(function () use (&$w, &$trnx, &$reason, &$transferId) {
            $claimed = Withdrawals::where('id', $w->id)->where('status', 0)->whereIn('payout_status', ['processing', 'queued', 'review'])
                ->update(['status' => 2, 'payout_status' => 'failed', 'reject_reason' => substr('Instant transfer failed: ' . ($reason ?: 'not completed'), 0, 250),
                          'payout_message' => substr((string) $reason, 0, 250), 'flw_transfer_id' => $transferId ?: $w->flw_transfer_id]);
            if (!$claimed) {
                return false;
            }
            $w->refresh();
            $wallet = Wallet::where('user_id', $w->user_id)->where('user_type', 1)->where('currency_id', $w->currency_id)->firstOrFail();
            $wallet->increment('balance', $w->total_amount);   // atomic
            $wallet->refresh();

            $trnx              = new Transaction();
            $trnx->trnx        = str_rand();
            $trnx->user_id     = $w->user_id;
            $trnx->user_type   = 1;
            $trnx->currency_id = $w->currency_id;
            $trnx->amount      = $w->amount;
            $trnx->charge      = 0;
            $trnx->remark      = 'withdraw_reject';
            $trnx->type        = '+';
            $trnx->details     = trans('Withdraw request rejected');
            $trnx->save();
            return true;
        }, 3);

        if ($done) {
            try {
                @mailSend('reject_withdraw', ['amount' => amount($w->amount, $w->currency->type, 2), 'method' => $w->method->name, 'trnx' => $trnx->trnx, 'curr' => $w->currency->code,
                    'reason' => $w->reject_reason, 'date_time' => dateFormat($trnx->created_at)], $w->user);
            } catch (\Throwable $th) {
                // an email problem must never undo a refund
            }
        }
        return (bool) $done;
    }

    /** Needs a human: park it in review (no money moves) and tell the admins. */
    public static function hold(Withdrawals $w, $reason)
    {
        $held = Withdrawals::where('id', $w->id)->where('status', 0)->whereIn('payout_status', ['processing', 'queued'])
            ->update(['payout_status' => 'review', 'payout_message' => substr($reason, 0, 250)]);
        if ($held) {
            self::alertAdmins($w, $reason);
        }
        return (bool) $held;
    }

    /* ---------------------------------------------------------------- email */

    private static function mailPending(Withdrawals $w, $user, $wallet)
    {
        try {
            $type = $wallet->currency->type;
            @mailSend('withdraw_request_user', [
                'amount'    => plainAmount($w->amount, $type),
                'charge'    => plainAmount($w->charge, $type),
                'total'     => plainAmount($w->total_amount, $type),
                'curr'      => $wallet->currency->code,
                'method'    => $w->method->name,
                'trnx'      => $w->trx,
                'balance'   => plainAmount($wallet->balance, $type),
                'date_time' => dateFormat($w->created_at),
            ], $user);
        } catch (\Throwable $th) {
            // never block the withdrawal because of an email problem
        }
    }

    public static function alertAdmins(Withdrawals $w, $reason)
    {
        try {
            $user = $w->user;
            notifyAdmins('instant_payout_alert', [
                'reason'          => e($reason),
                'trnx'            => $w->trx,
                'amount'          => plainAmount($w->amount, 1),
                'requester'       => e(optional($user)->name),
                'requester_email' => e(optional($user)->email),
                'bank'            => e($w->bank_name),
                'account'         => e($w->account_number),
                'account_name'    => e($w->account_name),
                'date_time'       => dateFormat($w->created_at),
                'link'            => route('admin.withdraw.pending'),
            ]);
        } catch (\Throwable $th) {
            // never block the withdrawal because of an email problem
        }
    }
}
