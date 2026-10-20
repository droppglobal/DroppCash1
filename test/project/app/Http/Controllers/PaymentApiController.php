<?php

namespace App\Http\Controllers;

use App\Models\Wallet;
use App\Models\ApiCreds;
use App\Models\ApiDeposit;
use App\Models\Currency;

use App\Models\Merchant;
use App\Models\Transaction;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\MerchantPayment;
use App\Models\PaymentGateway;
use Illuminate\Support\Facades\Auth;

use Illuminate\Support\Facades\Validator;

class PaymentApiController extends Controller
{
    public function paymentProcess(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'currency_code'   => 'required|string|max:4',
            'amount'          => 'required|gt:0',
            'details'         => 'string|max:255',
            'web_hook'        => 'required|url',
            'custom'          => 'required|string|max:20',
            'cancel_url'      => 'required|url',
            'success_url'     => 'required|url',
            'customer_email'  => 'required|email|max:30'
        ]);

        if ($validator->fails()) {
            return [
                'code' => 422,
                'status' => 'error',
                'message' => $validator->errors()->all()
            ];
        }

        $currency = Currency::where('code', $request->currency_code)->first();
        if (!$currency) {
            return [
                'code' => 404,
                'status' => 'error',
                'message' => 'Requested currency not found.'
            ];
        }

        $cred = ApiCreds::where('access_key', $request->bearerToken())->first();
        if (!$cred) {
            return [
                'code' => 401,
                'status' => 'error',
                'message' => 'Invalid API credentials.'
            ];
        }

        $data = $request->only('amount', 'details', 'web_hook', 'custom', 'cancel_url', 'success_url', 'customer_email');
        $data['payment_id'] = Str::random(32);
        $data['currency_id'] = $currency->id;
        $data['merchant_id'] = @$cred->merchant->id;


        if ($cred->mode == 1)  $data['mode'] = 1;
        else                  $data['mode'] = 0;

        try {
            MerchantPayment::create($data);
            $url = route('process.payment.auth', ['payment_id' => $data['payment_id']]);
        } catch (\Exception $e) {
            return [
                "code" => 500,
                "status" => "error",
                "message" => "Server is not responding.",
            ];
        }

        return [
            "code" => 200,
            "status" => "OK",
            'payment_id' => $data['payment_id'],
            "message" => "Your payment has been processed. Please follow the URL to complete the payment.",
            "url" => $url,
        ];
    }

    public function processCheckOut()
    {
        $payment = MerchantPayment::where('payment_id', request('payment_id'))->where('status', '!=', 1)->firstOrFail();
        session()->put('payment', encrypt($payment));
        return view('merchant_payment.auth', compact('payment'));
    }

    public function authenticate(Request $request)
    {
        $request->validate(['email' => 'required|email', 'password' => 'required']);
        if (Auth::guard('web')->attempt(['email' => $request->email, 'password' => $request->password])) {
            return redirect()->route('process.payment.check');
        } else {
            return back()->with('error', 'Wrong credentials');
        }
    }

    public function processPaymentCheck()
    {
        $payment = decrypt(session('payment'));

        return view('merchant_payment.auth_payment', compact('payment'));
    }

    public function confirmPayment()
    {
        try {
            $payment = decrypt(session('payment'));
            $payment->user_id = auth()->id();
            $payment->update();
        } catch (\Throwable $th) {
            return back()->with('error', 'Something went wrong');
        }

        if ($payment->status == 1) return back()->with('error', 'This transaction is already successfully completed');

        $charge = charge('merchant-payment');
        if ($payment->mode == 1) $res = $this->livePayment($payment, $charge);
        else $res = $this->curlRequest($payment, $charge, str_rand());

        if (isset($res['error']))  return back()->with('error', $res['error']);

        $payment->status = 1;
        $payment->save();

        session()->forget('payment');
        Auth::logout(auth()->user());
        $params = $res['success'];

        return redirect($payment->success_url . "?$params");
    }


    public function processGuestPaymentCheck()
    {

        $payment = decrypt(session('payment'));
        $methods = PaymentGateway::whereJsonContains('currency_id', "$payment->currency_id")->where('status', 1)->get();
        if ($methods->isEmpty()) {
            return back()->with('error', 'No gateways found associate with this currency.');
        }
        return view('merchant_payment.guest_payment', compact('payment', 'methods'));
    }



    public function depositPayment(Request $request)
    {
        $request->validate([
            'gateway' => 'required',
        ]);

        $gateway = PaymentGateway::where('status', 1)->whereId($request->gateway)->first();
        $charge = charge('merchant-payment');
        $payment = decrypt(session('payment'))->first();
        $finalCharge = chargeCalc($charge, $payment['amount'], $payment->currency->rate);
        $finalAmount =  numFormat($payment->amount + $finalCharge);

        $send_payment = $payment;
        $send_payment['amount'] = $finalAmount;

        if ($gateway->type == 'manual') {
            $payment_keyword = 'manual';
            $request->validate([
                'trx_details' => 'required|string|max:255',
            ]);
        } else {
            $payment_keyword = $gateway->keyword;
        }

        $request_data = $request;

        $service =  __NAMESPACE__ . '\Gateway' . '\\' . ucwords($payment_keyword);

        $deposit = $service::initiate($request_data, $send_payment, 'api');

        if ($deposit['status'] == 1 && in_array($payment_keyword, ['paytm', 'razorpay'])) {
            return view($deposit['view'], $deposit['prams']);
        }

        if ($deposit['status'] == 1 && isset($deposit['url'])) {
            return redirect($deposit['url']);
        }

        try {

            if ($deposit['status'] == 1) {
                if ($gateway->type == 'manual') {
                    $data = new ApiDeposit();
                    $data->user_id      = $send_payment['merchant_id'];
                    $data->amount       = $payment['amount'];
                    $data->method       = $gateway->name;
                    $data->txn_id       = $deposit['txn_id'];
                    $data->txn_details  = $request['trx_details'];
                    $data->charge       = $deposit_data['charge'] ?? null;
                    $data->payment_id   = $send_payment['payment_id'];
                    $data->currency_id  = $send_payment['currency_id'];
                    $data->status       = 0;
                    $data->save();
                }

                $payment = decrypt(session('payment'));
                $payment->user_id = 0;
                $payment->update();
                $charge = charge('merchant-payment');
                if ($payment->mode == 1) $res = $this->livePaymentGuest($payment, $charge, $deposit['txn_id'], $gateway->type == 'manual' ? 'manual' : null);
                else $res = $this->curlRequest($payment, $charge, str_rand());
                if (isset($res['error']))  return back()->with('error', $res['error']);
                session()->forget('payment');
                $params = $res['success'];
                $redirect_url = $payment->success_url . "?$params";
                return view('merchant_payment.redirect', compact('redirect_url','payment'));

                
            }
        } catch (\Throwable $th) {
            dd($th->getMessage());
        }
    }


    public function paytmNotify(Request $request)
    {

        $status = 0;
        $message = '';
        $txn_id = '';
        if ('TXN_SUCCESS' === $request['STATUS']) {
            $transaction_id = $request['TXNID'];
            $status = 1;
            $txn_id = $transaction_id;
        } else if ('TXN_FAILURE' === $request['STATUS']) {
            $message = __('Payment Field Please Try again');
        }

        return $this->notifyOperation(['message' => $message, 'status' => $status, 'txn_id' => $txn_id]);
    }

    public function notifyOperation($deposit)
    {
        if ($deposit['status'] == 0) {
            return redirect()->route('process.payment.guest')->with('error', $deposit['message']);
        }
        $payment = decrypt(session('payment'));
       
        
        $payment->user_id = 0;
        $payment->update();
        $charge = charge('merchant-payment');
        if ($payment->mode == 1) $res = $this->livePaymentGuest($payment, $charge, $deposit['txn_id']);
        else $res = $this->curlRequest($payment, $charge, str_rand());

        if (isset($res['error']))  return back()->with('error', $res['error']);

        $payment->status = 1;
        $payment->save();

        session()->forget('payment');
        $params = $res['success'];
        $redirect_url = $payment->success_url . "?$params";
        return view('merchant_payment.redirect', compact('redirect_url','payment'));
    }
    
    public function coinGateNotify($deposit)
    {
        if ($deposit['status'] == 0) {
            return redirect()->route('process.payment.guest')->with('error', $deposit['message']);
        }

   
        $payment = MerchantPayment::wherePaymentId($deposit['payment_id'])->first();
      
        $payment->user_id = 0;
        $payment->update();
        $charge = charge('merchant-payment');
        if ($payment->mode == 1) $res = $this->livePaymentGuest($payment, $charge, $deposit['txn_id']);
        else $res = $this->curlRequest($payment, $charge, str_rand());

        if (isset($res['error']))  return back()->with('error', $res['error']);

        $payment->status = 1;
        $payment->save();

        session()->forget('payment');
        $params = $res['success'];
        $redirect_url = $payment->success_url . "?$params";
        return view('merchant_payment.redirect', compact('redirect_url','payment'));
    }



    public function livePaymentGuest($payment, $charge, $trnx, $type = null)
    {
        if ($type == 'manual') {
            $res = $this->curlRequest($payment, $charge, $trnx, 'manual');
            return $res;
        }

        $merchant = Merchant::find($payment->merchant_id);

        if (!$merchant) return ['error' => 'Invalid merchant'];
        $merchantWallet = Wallet::where('user_type', 2)->where('user_id', $merchant->id)->where('currency_id', $payment['currency_id'])->first();

        if (!$merchantWallet) {
            $merchantWallet = Wallet::create([
                'user_id'     => $merchant->id,
                'user_type'   => 2,
                'currency_id' => $payment->currency_id,
                'balance'     => 0
            ]);
        }

        $finalCharge = chargeCalc($charge, $payment->amount, $payment->currency->rate);
        $finalAmount =  numFormat($payment->amount + $finalCharge);

        $merchantWallet->balance += $finalAmount;
        $merchantWallet->update();

        $receiverTrnx              = new Transaction();
        $receiverTrnx->trnx        = $trnx;
        $receiverTrnx->user_id     = $merchant->id;
        $receiverTrnx->user_type   = 2;
        $receiverTrnx->currency_id = $payment->currency_id;
        $receiverTrnx->amount      = $finalAmount;
        $receiverTrnx->charge      = $finalCharge;
        $receiverTrnx->type        = '+';
        $receiverTrnx->remark      = 'merchant_api_payment';
        $receiverTrnx->details     = trans('Payment received from : ') . $payment->customer_email;
        $receiverTrnx->save();
        try {
            @mailSend('api_payment_merchant', [
                'amount'    => amount($finalAmount, $payment->currency->type, 2),
                'curr'      => $payment->currency->code,
                'user'      => $payment->customer_email,
                'trnx'      => $receiverTrnx->trnx,
                'charge'    => amount($finalCharge, $payment->currency->type, 2),
                'date_time' => dateFormat($receiverTrnx->created_at)
            ], $merchant);
        } catch (\Throwable $th) {
            dd($th->getMessage());
        }

        $res = $this->curlRequest($payment, $charge, $trnx);
        return $res;
    }



    public function livePayment($payment, $charge)
    {

        $userWallet = Wallet::where('user_type', 1)->where('user_id', auth()->id())->where('currency_id', $payment->currency_id)->first();

        if (!$userWallet) return ['error' => 'Wallet not found'];

        $merchant = Merchant::find($payment->merchant_id);
        if (!$merchant) return ['error' => 'Invalid merchant'];

        $merchantWallet = Wallet::where('user_type', 2)->where('user_id', $merchant->id)->where('currency_id', $payment->currency_id)->first();

        if (!$merchantWallet) {
            $merchantWallet = Wallet::create([
                'user_id'     => $merchant->id,
                'user_type'   => 2,
                'currency_id' => $payment->currency_id,
                'balance'     => 0
            ]);
        }

        if ($payment->amount  > $userWallet->balance) return ['error' => 'Sorry! insufficient balance'];

        $finalCharge = chargeCalc($charge, $payment->amount, $payment->currency->rate);
        $finalAmount =  numFormat($payment->amount + $finalCharge);

        $userWallet->balance -= $payment->amount;
        $userWallet->update();

        $trnx              = new Transaction();
        $trnx->trnx        = str_rand();
        $trnx->user_id     = auth()->id();
        $trnx->user_type   = 1;
        $trnx->currency_id = $payment->currency_id;
        $trnx->amount      = $payment->amount;
        $trnx->charge      = 0;
        $trnx->remark      = 'merchant_api_payment';
        $trnx->type        = '-';
        $trnx->details     = trans('Payment to merchant : ') .  $merchant->email;
        $trnx->save();

        $merchantWallet->balance += $finalAmount;
        $merchantWallet->update();

        $receiverTrnx              = new Transaction();
        $receiverTrnx->trnx        = $trnx->trnx;
        $receiverTrnx->user_id     = $merchant->id;
        $receiverTrnx->user_type   = 2;
        $receiverTrnx->currency_id = $payment->currency_id;
        $receiverTrnx->amount      = $finalAmount;
        $receiverTrnx->charge      = $finalCharge;
        $receiverTrnx->type        = '+';
        $receiverTrnx->remark      = 'merchant_api_payment';
        $receiverTrnx->details     = trans('Payment received from : ') . auth()->user()->email;
        $receiverTrnx->save();

        try {
            @mailSend('api_payment_user', [
                'amount'    => amount($payment->amount, $payment->currency->type, 2),
                'curr'      => $payment->currency->code,
                'merchant'  => $merchant->business_name,
                'trnx'      => $receiverTrnx->trnx,
                'date_time' => dateFormat($receiverTrnx->created_at)
            ], auth()->user());

            @mailSend('api_payment_merchant', [
                'amount'    => amount($finalAmount, $payment->currency->type, 2),
                'curr'      => $payment->currency->code,
                'user'      => auth()->user(),
                'trnx'      => $receiverTrnx->trnx,
                'charge'    => amount($finalCharge, $payment->currency->type, 2),
                'date_time' => dateFormat($receiverTrnx->created_at)
            ], $merchant);
        } catch (\Throwable $th) {
        }

        $res = $this->curlRequest($payment, $charge, $trnx->trnx);
        return $res;
    }


    public function curlRequest($payment, $charge, $trnx, $type = null)
    {
        $params = [
            'code'             =>  200,
            'status'           =>  $type == 'manual' ? 'PENDING' : 'OK',
            'payment_id'       =>  $payment->payment_id,
            'transaction'      =>  $trnx,
            'amount'           =>  amount($payment->amount, $payment->currency->type, 3),
            'charge'           =>  chargeCalc($charge, $payment->amount, $payment->currency->rate),
            'currency'         =>  $payment->currency->code,
            'custom'           =>  $payment->custom,
            'date'             =>  dateFormat($payment->updated_at, 'd-m-Y')
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_URL, $payment->web_hook);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);
        return ['success' => http_build_query($params)];
    }

    public function checkValidity(Request $request)
    {
        $cred = ApiCreds::where('access_key', $request->bearerToken())->first();
        if (!$cred) {
            return [
                'code' => 401,
                'status' => 'error',
                'message' => 'Invalid API credentials.'
            ];
        }

        $payment = MerchantPayment::where('payment_id', $request->payment_id)->first();
        if ($payment && $payment->status == 1) {
            return [
                'code' => 200,
                'status' => 'OK',
                'message' => 'Transaction is valid'
            ];
        }
        return [
            'code' => 404,
            'status' => 'error',
            'message' => 'Transaction Not Found.'
        ];
    }
}
