<?php
namespace App\Http\Controllers\Gateway;

use App\Http\Controllers\User\DepositController;
use App\Models\Deposit;
use App\Models\Generalsetting;
use App\Models\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * Flutterwave (API v3, hosted "Standard" checkout) for wallet deposits.
 *
 * Flow:  initiate()  -> POST /v3/payments           -> user is redirected to Flutterwave's checkout
 *        notify()    -> user comes back (GET)       -> GET /v3/transactions/{id}/verify -> wallet is credited
 *
 * The wallet is only credited after Flutterwave itself confirms the payment AND the reference,
 * currency and amount match what this user started. The amount credited is always the amount
 * stored on our side, never a value taken from the browser.
 */
class Flutterwave
{
    const API = 'https://api.flutterwave.com/v3';

    private static function secretKey()
    {
        $gateway = PaymentGateway::whereKeyword('flutterwave')->first();
        $data    = $gateway ? ($gateway->convertAutoData() ?: []) : [];
        return trim($data['secret_key'] ?? '');
    }

    public static function initiate($request, $payment_data, $type)
    {
        $secret = self::secretKey();
        if ($secret === '') {
            return ['status' => 0, 'message' => __('Flutterwave is not configured yet.')];
        }

        $currency = session('currency');            // set by DepositController::depositSubmit()
        $amount   = isset($payment_data['amount']) ? round((float) $payment_data['amount'], 2) : 0;
        if (!$currency || !auth()->check() || $amount <= 0) {
            return ['status' => 0, 'message' => __('Invalid payment request.')];
        }

        // credit exactly what the customer is charged (2 decimals)
        $deposit_data           = Session::get('deposit_data');
        $deposit_data['amount'] = $amount;
        Session::put('deposit_data', $deposit_data);

        $user  = auth()->user();
        $gs    = Generalsetting::first();
        $txRef = 'DC-' . $user->id . '-' . strtoupper(Str::random(14));

        try {
            $response = Http::withToken($secret)->acceptJson()->timeout(30)->post(self::API . '/payments', [
                'tx_ref'         => $txRef,
                'amount'         => $amount,
                'currency'       => strtoupper($currency->code),
                'redirect_url'   => route('flutterwave.notify'),
                'customer'       => [
                    'email'       => $user->email,
                    'name'        => $user->name,
                    'phonenumber' => $user->phone,
                ],
                'customizations' => [
                    'title'       => $gs->title ?? 'Wallet deposit',
                    'description' => 'Wallet deposit',
                ],
                'meta'           => ['user_id' => $user->id],
                'configurations' => ['session_duration' => 30, 'max_retry_attempt' => 5],
            ]);
            $json = $response->json();
        } catch (\Throwable $e) {
            Log::warning('Flutterwave initiate failed: ' . $e->getMessage());
            return ['status' => 0, 'message' => __('Could not reach Flutterwave. Please try again.')];
        }

        if ($response->successful() && ($json['status'] ?? '') === 'success' && !empty($json['data']['link'])) {
            Session::put('flw_deposit', [
                'tx_ref'   => $txRef,
                'amount'   => $amount,
                'currency' => strtoupper($currency->code),
                'user_id'  => $user->id,
            ]);
            Session::put('type', $type);
            return ['status' => 1, 'message' => '', 'url' => $json['data']['link']];
        }

        Log::warning('Flutterwave initiate rejected: ' . json_encode($json));
        return ['status' => 0, 'message' => 'Flutterwave: ' . ($json['message'] ?? __('Unable to start the payment.'))];
    }

    /**
     * Returns null when the verified Flutterwave transaction is acceptable, otherwise a reason.
     * $verify = decoded JSON of GET /v3/transactions/{id}/verify, $flw = what we stored at initiate().
     */
    public static function checkVerified($verify, $flw)
    {
        if (!is_array($verify) || ($verify['status'] ?? '') !== 'success' || empty($verify['data'])) {
            return 'Payment could not be verified.';
        }
        $d = $verify['data'];
        if (($d['status'] ?? '') !== 'successful') {
            return 'Payment was not successful.';
        }
        if (($d['tx_ref'] ?? '') !== $flw['tx_ref']) {
            return 'Payment reference does not match.';
        }
        if (strtoupper($d['currency'] ?? '') !== strtoupper($flw['currency'])) {
            return 'Payment currency does not match.';
        }
        if (!isset($d['amount']) || (float) $d['amount'] + 0.005 < (float) $flw['amount']) {
            return 'Paid amount is less than the deposit amount.';
        }
        if (empty($d['id'])) {
            return 'Payment could not be verified.';
        }
        return null;
    }

    public function notify(Request $request)
    {
        $flw = Session::get('flw_deposit');

        $fail = function ($message) use ($request) {
            Session::forget('flw_deposit');
            if (session('api_depo')) {
                return redirect(route('payment-init', ['deposit_id' => session('api_depo')]))->with('error', $message);
            }
            return redirect(route('user.deposit.index'))->with('error', $message);
        };

        if (!$flw || !auth()->check() || (int) $flw['user_id'] !== (int) auth()->id()) {
            return $fail(__('Your session expired. If you were charged, please contact support with reference: ') . e($request->tx_ref));
        }

        if (!hash_equals($flw['tx_ref'], (string) $request->tx_ref)) {
            return $fail(__('Payment reference does not match.'));
        }

        if ($request->status !== 'successful' && $request->status !== 'completed') {
            return $fail($request->status === 'cancelled' ? __('Payment was cancelled.') : __('Payment was not successful.'));
        }

        $transactionId = (string) $request->transaction_id;
        if ($transactionId === '' || !ctype_digit($transactionId)) {
            return $fail(__('Payment could not be verified.'));
        }

        // Always confirm the final state with Flutterwave
        try {
            $response = Http::withToken(self::secretKey())->acceptJson()->timeout(30)
                ->get(self::API . '/transactions/' . $transactionId . '/verify');
            $verify = $response->json();
        } catch (\Throwable $e) {
            Log::warning('Flutterwave verify failed: ' . $e->getMessage());
            return $fail(__('Could not verify the payment. If you were charged, please contact support with reference: ') . e($flw['tx_ref']));
        }

        $problem = self::checkVerified($verify, $flw);
        if ($problem) {
            Log::warning('Flutterwave verify rejected (' . $problem . ') ref ' . $flw['tx_ref']);
            return $fail(__($problem));
        }

        // a Flutterwave transaction can only ever be credited once
        $txn = 'FLW-' . $verify['data']['id'];
        if (Deposit::where('txn_id', $txn)->exists() || !Cache::add('flw_credit_' . $verify['data']['id'], 1, 3600)) {
            return $fail(__('This payment has already been credited.'));
        }

        Session::forget('flw_deposit');

        return (new DepositController)->notifyOperation([
            'message' => '',
            'status'  => 1,
            'txn_id'  => $txn,
        ]);
    }
}
