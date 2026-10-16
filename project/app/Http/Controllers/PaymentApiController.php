<?php

namespace App\Http\Controllers;

use App\Models\Wallet;
use App\Models\ApiCreds;
use App\Models\User;
use App\Models\Currency;
use App\Models\Merchant;
use App\Models\Transaction;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\MerchantPayment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Models\Dispute;
use App\Models\Escrow;
use App\Models\InvItem;
use App\Models\Invoice;

use Illuminate\Support\Facades\DB;

class PaymentApiController extends Controller
{
    public function paymentProcess(Request $request)
    {
        return $this->startPayment($request,false);
    }

    // buyer pays INTO ESCROW; the API key owner is the seller
    public function escrowProcess(Request $request)
    {
        return $this->startPayment($request,true);
    }

    private function startPayment(Request $request, $escrow = false)
    {
        $validator = Validator::make($request->all(),[
            'currency_code'   => 'required|string|max:4',
            'amount'          => 'required|gt:0',
            'details'         => 'required|string|max:255',
            'web_hook'        => 'required|url',
            'custom'          => 'required|string|max:20',
            'cancel_url'      => 'required|url',  
            'success_url'     => 'required|url',  
            'customer_email'  => 'required|email|max:30'
        ]);

        if($validator->fails()) {
        	return [
                'code'=> 422,
                'status' => 'error',
                'message' => $validator->errors()->all()
            ] ;
        }

        $currency = Currency::where('code',$request->currency_code)->first();
        if(!$currency){
            return [
                'code'=> 404,
                'status' => 'error',
                'message' => 'Requested currency not found.'
            ] ;
        }

        $cred = ApiCreds::where('access_key',$request->bearerToken())->first();
        if(!$cred){
            return [
                'code'=> 401,
                'status' => 'error',
                'message' => 'Invalid API credentials.'
            ] ;
        }

        $data = $request->only('amount','details','web_hook','custom','cancel_url','success_url','customer_email');
        $data['payment_id'] = Str::random(32);
        $data['currency_id'] = $currency->id;
        $data['merchant_id'] = @$cred->merchant->id;
        

        if($cred->mode == 1)  $data['mode'] = 1;
        else                  $data['mode'] = 0;

        $data['is_escrow']  = $escrow ? 1 : 0;
        $data['pay_charge'] = ($escrow && filter_var($request->pay_charge, FILTER_VALIDATE_BOOLEAN)) ? 1 : 0;
      
        try {
            MerchantPayment::create($data);
            $url = route('process.payment.auth',['payment_id' => $data['payment_id']]);
        } catch (\Exception $e) {
            return [
                "code" => 500,
                "status" => "error",
                "message"=> "Server is not responding.",
            ] ;
        }
        
        return [
            "code" => 200,
            "status" => "OK",
            'payment_id' => $data['payment_id'] ,
            "message"=> $escrow ? "Your escrow payment has been created. Please send the buyer to the URL to fund the escrow." : "Your payment has been processed. Please follow the URL to complete the payment.",
            "url" => $url,
        ] ;

    }

    public function processCheckOut()
    {  
        

        $payment = MerchantPayment::where('payment_id',request('payment_id'))->where('status','!=',1)->firstOrFail();
    
        session()->put('payment',encrypt($payment));
        return view('merchant_payment.auth',compact('payment'));
    }

    public function authenticate(Request $request)
    {
        $request->validate(['email'=>'required|email','password'=>'required']);
        if (Auth::guard('web')->attempt(['email' => $request->email, 'password' => $request->password])) {
           $request->session()->regenerate();      // new session id after login; the payment stays in the session
           return redirect(route('payment.confirm'));
        }else{
            return back()->with('error','Wrong credentials');   
        }
    }

    // Step 2 of checkout: show the buyer exactly what will happen. Nothing is charged on this page.
    public function reviewPayment()
    {
        try {
            $payment = decrypt(session('payment'));
        } catch (\Throwable $th) {
            return redirect('/')->with('error','Your payment session has expired. Please start again from the shop.');
        }
        if(!auth()->check()){
            return redirect(route('process.payment.auth',['payment_id' => $payment->payment_id]));
        }
        // the copy in the session can be old: check the real state
        $fresh = MerchantPayment::where('id',$payment->id)->first();
        if(!$fresh || $fresh->status != 0){
            return redirect('/')->with('error','This payment has already been completed or is no longer available.');
        }
        $payment  = $fresh;
        $currency = Currency::find($payment->currency_id);
        $merchant = User::find($payment->merchant_id);
        if(!$currency || !$merchant) abort(404);

        $wallet = Wallet::where('user_type',1)->where('user_id',auth()->id())->where('currency_id',$currency->id)->first();
        $fee    = 0;
        $total  = $payment->amount;
        if($payment->is_escrow){
            $fee = amount(chargeCalc(charge('make-escrow'),$payment->amount,$currency->rate),$currency->type,2);
            if($payment->pay_charge) $total = amount($payment->amount + $fee,$currency->type,2);
        }
        $balance = $wallet ? $wallet->balance : 0;
        $isLive  = $payment->mode == 1;
        $enough  = !$isLive || ($wallet && $balance >= $total && $merchant->id != auth()->id());

        return view('merchant_payment.confirm',compact('payment','currency','merchant','balance','fee','total','enough','isLive'));
    }

    // Step 3: the buyer pressed "Confirm payment" (POST + CSRF token). This is the only place that moves money.
    public function confirmPayment()
    {
        if(!auth()->check()){
            return redirect('/')->with('error','Please sign in to confirm the payment.');
        }

        try {
            $payment = decrypt(session('payment'));
            $payment->user_id = auth()->id();
            $payment->update();
     

        } catch (\Throwable $th) {
            return back()->with('error','Something went wrong');
        }
    

        if($payment->status == 1) return back()->with('error','This transaction is already successfully completed');
    
        if($payment->is_escrow){
            $res = $payment->mode == 1 ? $this->livePaymentEscrow($payment) : $this->testPaymentEscrow($payment);
        }else{
            $charge = charge('merchant-payment');
            if($payment->mode == 1) $res = $this->livePayment($payment,$charge);
            else $res = $this->curlRequest($payment,$charge,str_rand());
        }

        if(isset($res['error']))  return back()->with('error',$res['error']);
        
        $payment->status = 1;
        $payment->save();

        session()->forget('payment');
        // Auth::logout(auth()->user());
        $params = $res['success'];

        $separator = strpos($payment->success_url,'?') === false ? '?' : '&';
        return redirect($payment->success_url.$separator.$params);

    }


    public function livePayment($payment,$charge)
    {
        
        $userWallet = Wallet::where('user_type',1)->where('user_id',auth()->id())->where('currency_id',$payment->currency_id)->first();
      
        if(!$userWallet) return ['error' => 'Wallet not found'];
        

        // $merchant = Merchant::find($payment->merchant_id);
        $merchant = User::find($payment->merchant_id);
        if(!$merchant) return ['error' => 'Invalid merchant'];
        
        $merchantWallet = Wallet::where('user_type',1)->where('user_id',$merchant->id)->where('currency_id',$payment->currency_id)->first();
       
        if(!$merchantWallet){
            $merchantWallet = Wallet::create([
                'user_id'     => $merchant->id,
                'user_type'   => 1,
                'currency_id' => $payment->currency_id,
                'balance'     => 0
            ]);
        }

        if($payment->amount  > $userWallet->balance) return ['error' => 'Sorry! insufficient balance'];
    
        $finalCharge = chargeCalc($charge,$payment->amount,$payment->currency->rate);
        if($finalCharge > $payment->amount) $finalCharge = 0;
        $finalAmount =  numFormat($payment->amount - $finalCharge);

        $txResponse = DB::transaction(function () use (&$finalAmount, &$finalCharge, &$merchant, &$merchantWallet, &$payment, &$receiverTrnx, &$trnx, &$userWallet) {
        // atomic check-and-deduct: parallel requests cannot spend the same balance twice
        if(!Wallet::where('id',$userWallet->id)->where('balance','>=',$payment->amount)->decrement('balance',$payment->amount)){
            return ['error' => 'Sorry! insufficient balance'];
        }
        $userWallet->refresh();

        $trnx              = new Transaction();
        $trnx->trnx        = str_rand();
        $trnx->user_id     = auth()->id();
        $trnx->user_type   = 1;
        $trnx->currency_id = $payment->currency_id;
        $trnx->amount      = $payment->amount;
        $trnx->charge      = 0;
        $trnx->remark      = 'merchant_api_payment';
        $trnx->type        = '-';
        $trnx->details     = trans('Payment to merchant : ').  $merchant->email;
        $trnx->save();

        $merchantWallet->increment('balance',$finalAmount);   // atomic
        $merchantWallet->refresh();

        $receiverTrnx              = new Transaction();
        $receiverTrnx->trnx        = $trnx->trnx;
        $receiverTrnx->user_id     = $merchant->id;
        $receiverTrnx->user_type   = 2;
        $receiverTrnx->currency_id = $payment->currency_id;
        $receiverTrnx->amount      = $finalAmount;
        $receiverTrnx->charge      = $finalCharge;
        $receiverTrnx->type        = '+';
        $receiverTrnx->remark      = 'merchant_api_payment';
        $receiverTrnx->details     = trans('Payment received from : '). auth()->user()->email;
        $receiverTrnx->save();
            return null;
        }, 3);
        if($txResponse) return $txResponse;

        try {
            @mailSend('api_payment_user',[
                'amount'    => amount($payment->amount,$payment->currency->type,2),
                'curr'      => $payment->currency->code,
                'merchant'  => $merchant->business_name,
                'trnx'      => $receiverTrnx->trnx,
                'date_time' => dateFormat($receiverTrnx->created_at)
            ],auth()->user());

            @mailSend('api_payment_merchant',[
                'amount'    => amount($finalAmount,$payment->currency->type,2),
                'curr'      => $payment->currency->code,
                'user'      => auth()->user(),
                'trnx'      => $receiverTrnx->trnx,
                'charge'    => amount($finalCharge,$payment->currency->type,2),
                'date_time' => dateFormat($receiverTrnx->created_at)
            ], $merchant);

        } catch (\Throwable $th) {
           
        }

       $res = $this->curlRequest($payment,$charge,$trnx->trnx);
       return $res;
    }


    public function curlRequest($payment,$charge,$trnx)
    {
        $params = [
            'code'             =>  200,
            'status'           =>  'OK',
            'payment_id'       =>  $payment->payment_id,
            'transaction'      =>  $trnx,
            'amount'           =>  amount($payment->amount,$payment->currency->type,3),
            'charge'           =>  chargeCalc($charge,$payment->amount,$payment->currency->rate),
            'currency'         =>  $payment->currency->code,
            'custom'           =>  $payment->custom,
            'date'             =>  dateFormat($payment->updated_at,'d-m-Y')
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

    // ====================================================================================
    //  ESCROW CHECKOUT  (buyer pays into escrow, the API key owner is the seller)
    // ====================================================================================

    public function livePaymentEscrow($payment)
    {
        $buyer  = auth()->user();
        $seller = User::find($payment->merchant_id);
        if(!$seller) return ['error' => 'Invalid merchant'];
        if($seller->id == $buyer->id) return ['error' => 'You can not pay yourself'];

        $currency = Currency::find($payment->currency_id);
        if(!$currency) return ['error' => 'Currency not found'];

        $buyerWallet = Wallet::where('user_type',1)->where('user_id',$buyer->id)->where('currency_id',$currency->id)->first();
        if(!$buyerWallet) return ['error' => 'Wallet not found'];

        $amount      = $payment->amount;
        $payCharge   = $payment->pay_charge ? 1 : 0;
        $finalCharge = amount(chargeCalc(charge('make-escrow'),$amount,$currency->rate),$currency->type,2);
        $finalAmount = $payCharge ? amount($amount + $finalCharge,$currency->type,2) : amount($amount,$currency->type,2);

        if($buyerWallet->balance < $finalAmount) return ['error' => 'Sorry! insufficient balance'];

        try {
            DB::transaction(function () use (&$payment, &$buyer, &$seller, &$currency, &$buyerWallet, &$amount, &$payCharge, &$finalCharge, &$finalAmount, &$escrow, &$trnx) {
                // claim the payment first: it can fund one escrow only, even with parallel requests
                if(!MerchantPayment::where('id',$payment->id)->where('status',0)->update(['status'=>1])){
                    throw new \RuntimeException('This transaction is already successfully completed');
                }
                // atomic check-and-deduct
                if(!Wallet::where('id',$buyerWallet->id)->where('balance','>=',$finalAmount)->decrement('balance',$finalAmount)){
                    throw new \RuntimeException('Sorry! insufficient balance');
                }
                $buyerWallet->refresh();

                $escrow               = new Escrow();
                $escrow->trnx         = str_rand();
                $escrow->user_id      = $buyer->id;
                $escrow->recipient_id = $seller->id;
                $escrow->description  = $payment->details;
                $escrow->amount       = $amount;
                $escrow->pay_charge   = $payCharge;
                $escrow->charge       = $finalCharge;
                $escrow->currency_id  = $currency->id;
                $escrow->save();

                $trnx              = new Transaction();
                $trnx->trnx        = $escrow->trnx;
                $trnx->user_id     = $buyer->id;
                $trnx->user_type   = 1;
                $trnx->currency_id = $currency->id;
                $trnx->wallet_id   = $buyerWallet->id;
                $trnx->amount      = $finalAmount;
                $trnx->charge      = $finalCharge;
                $trnx->remark      = 'make_escrow';
                $trnx->type        = '-';
                $trnx->details     = trans('Made escrow to '). $seller->email;
                $trnx->save();

                MerchantPayment::where('id',$payment->id)->update(['escrow_id' => (string) $escrow->id]);
            }, 3);
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }

        $payment->escrow_id = (string) $escrow->id;

        try {
            $type = $currency->type;
            @mailSend('make_escrow',[
                'amount'        => plainAmount($amount,$type),
                'charge'        => plainAmount($finalCharge,$type),
                'curr'          => $currency->code,
                'to_user'       => $seller->email,
                'description'   => e($payment->details),
                'trnx'          => $escrow->trnx,
                'after_balance' => plainAmount($buyerWallet->balance,$type),
                'date_time'     => dateFormat($escrow->created_at)
            ],$buyer);

            @mailSend('receive_escrow',[
                'amount'      => plainAmount($amount,$type),
                'curr'        => $currency->code,
                'from_user'   => $buyer->email,
                'description' => e($payment->details),
                'trnx'        => $escrow->trnx,
                'date_time'   => dateFormat($escrow->created_at)
            ],$seller);
        } catch (\Throwable $th) {
            // never block the escrow because of an email problem
        }

        sendEscrowEvent($payment,'escrow.funded','held',['transaction' => $escrow->trnx, 'charge' => plainAmount($finalCharge,$currency->type)]);

        return ['success' => http_build_query([
            'code'        => 200,
            'event'       => 'escrow.funded',
            'status'      => 'held',
            'payment_id'  => $payment->payment_id,
            'escrow_id'   => $payment->escrow_id,
            'transaction' => $escrow->trnx,
            'amount'      => plainAmount($amount,$currency->type),
            'currency'    => $currency->code,
            'custom'      => $payment->custom,
            'date'        => date('d-m-Y'),
        ])];
    }

    // test mode: nothing is charged and no real escrow exists, but the whole flow (redirect, webhook, status, refund) works
    public function testPaymentEscrow($payment)
    {
        if(!MerchantPayment::where('id',$payment->id)->where('status',0)->update(['status'=>1])){
            return ['error' => 'This transaction is already successfully completed'];
        }
        $ref = 'TEST-' . strtoupper(Str::random(10));
        MerchantPayment::where('id',$payment->id)->update(['escrow_id' => $ref]);
        $payment->escrow_id = $ref;
        $currency = Currency::find($payment->currency_id);

        sendEscrowEvent($payment,'escrow.funded','held',['transaction' => $ref]);

        return ['success' => http_build_query([
            'code'        => 200,
            'event'       => 'escrow.funded',
            'status'      => 'held',
            'payment_id'  => $payment->payment_id,
            'escrow_id'   => $ref,
            'transaction' => $ref,
            'amount'      => plainAmount($payment->amount,$currency ? $currency->type : 1),
            'currency'    => $currency ? $currency->code : '',
            'custom'      => $payment->custom,
            'test'        => 1,
            'date'        => date('d-m-Y'),
        ])];
    }

    // finds an escrow payment that belongs to the API key in the request
    private function escrowPaymentFor(Request $request, &$error)
    {
        $cred = ApiCreds::where('access_key',$request->bearerToken())->first();
        if(!$cred){
            $error = ['code'=> 401, 'status' => 'error', 'message' => 'Invalid API credentials.'];
            return null;
        }
        $payment = MerchantPayment::where('payment_id',$request->payment_id)->where('merchant_id',$cred->merchant_id)->where('is_escrow',1)->first();
        if(!$payment){
            $error = ['code'=> 404, 'status' => 'error', 'message' => 'Escrow payment not found.'];
            return null;
        }
        return $payment;
    }

    private function escrowStatusData($payment)
    {
        $currency = Currency::find($payment->currency_id);
        $base = [
            'payment_id' => $payment->payment_id,
            'custom'     => $payment->custom,
            'amount'     => plainAmount($payment->amount,$currency ? $currency->type : 1),
            'currency'   => $currency ? $currency->code : '',
            'test'       => $payment->mode == 1 ? 0 : 1,
        ];
        if($payment->status == 0 || !$payment->escrow_id){
            return $base + ['escrow_id' => null, 'escrow_status' => 'awaiting_payment'];
        }
        if($payment->mode != 1){        // test mode: state is simulated on the payment row
            $map = [1 => 'held', 2 => 'refunded', 3 => 'released', 4 => 'disputed'];
            return $base + ['escrow_id' => $payment->escrow_id, 'escrow_status' => $map[$payment->status] ?? 'held'];
        }
        $escrow = Escrow::find($payment->escrow_id);
        if(!$escrow) return $base + ['escrow_id' => $payment->escrow_id, 'escrow_status' => 'unknown'];
        return $base + [
            'escrow_id'     => (string) $escrow->id,
            'escrow_status' => escrowApiStatus($escrow),
            'transaction'   => $escrow->trnx,
            'charge'        => plainAmount($escrow->charge,$escrow->currency->type),
            'funded_at'     => optional($escrow->created_at)->toIso8601String(),
        ];
    }

    public function escrowStatus(Request $request)
    {
        $payment = $this->escrowPaymentFor($request,$error);
        if(!$payment) return $error;

        return [
            'code'   => 200,
            'status' => 'OK',
            'escrow' => $this->escrowStatusData($payment),
        ];
    }

    // the seller (API key owner) returns the held money to the buyer
    public function escrowRefund(Request $request)
    {
        $payment = $this->escrowPaymentFor($request,$error);
        if(!$payment) return $error;

        if($payment->status == 0 || !$payment->escrow_id){
            return ['code'=> 422, 'status' => 'error', 'message' => 'This escrow has not been funded yet.'];
        }

        // ---- test mode (simulated) ----
        if($payment->mode != 1){
            if(!MerchantPayment::where('id',$payment->id)->where('status',1)->update(['status'=>2])){
                return ['code'=> 422, 'status' => 'error', 'message' => 'Escrow can only be refunded while it is held.'];
            }
            sendEscrowEvent($payment,'escrow.refunded','refunded');
            return ['code'=> 200, 'status' => 'OK', 'message' => 'Escrow refunded to the buyer (test mode).'];
        }

        // ---- live ----
        $escrow = Escrow::find($payment->escrow_id);
        if(!$escrow || $escrow->recipient_id != $payment->merchant_id){
            return ['code'=> 404, 'status' => 'error', 'message' => 'Escrow payment not found.'];
        }
        $buyer = User::find($escrow->user_id);
        if(!$buyer) return ['code'=> 404, 'status' => 'error', 'message' => 'Buyer not found.'];

        try {
            $refundAmount = escrowPaidByBuyer($escrow);   // the amount, plus the escrow fee if the buyer paid it
            DB::transaction(function () use (&$escrow, &$buyer, &$trnx, &$refundAmount) {
                // claim first: only an escrow that is still held can be refunded, and only once
                if(!Escrow::where('id',$escrow->id)->where('status',0)->update(['status'=>4,'returned_to'=>$buyer->email])){
                    throw new \RuntimeException('Escrow can only be refunded while it is held.');
                }
                $wallet = Wallet::where('user_id',$buyer->id)->where('user_type',1)->where('currency_id',$escrow->currency_id)->first();
                if(!$wallet){
                    $wallet = Wallet::create(['user_id' => $buyer->id, 'user_type' => 1, 'currency_id' => $escrow->currency_id, 'balance' => 0]);
                }
                $wallet->increment('balance',$refundAmount);   // atomic
                $wallet->refresh();

                $trnx              = new Transaction();
                $trnx->trnx        = str_rand();
                $trnx->user_id     = $buyer->id;
                $trnx->user_type   = 1;
                $trnx->currency_id = $escrow->currency_id;
                $trnx->wallet_id   = $wallet->id;
                $trnx->amount      = $refundAmount;
                $trnx->charge      = 0;
                $trnx->type        = '+';
                $trnx->remark      = 'escrow_return';
                $trnx->details     = trans('Escrow fund returned');
                $trnx->save();
            }, 3);
        } catch (\RuntimeException $e) {
            return ['code'=> 422, 'status' => 'error', 'message' => $e->getMessage()];
        }

        try {
            @mailSend('escrow_return',['amount'=>amount($refundAmount,$escrow->currency->type,2), 'trnx'=> $trnx->trnx,'curr' => $escrow->currency->code,'date_time'=> dateFormat($trnx->created_at)], $buyer);
        } catch (\Throwable $th) {
            // never block the refund because of an email problem
        }
        notifyEscrowWebhook($escrow,'escrow.refunded');

        return ['code'=> 200, 'status' => 'OK', 'message' => 'Escrow refunded to the buyer.', 'refunded' => plainAmount($refundAmount,$escrow->currency->type)];
    }

    // TEST MODE ONLY: lets a developer simulate what the buyer would do (release or dispute)
    public function escrowSimulate(Request $request)
    {
        $payment = $this->escrowPaymentFor($request,$error);
        if(!$payment) return $error;

        if($payment->mode == 1){
            return ['code'=> 403, 'status' => 'error', 'message' => 'Simulation is only available for test-mode payments.'];
        }
        $events = ['released' => [3,'escrow.released'], 'disputed' => [4,'escrow.disputed']];
        if(!isset($events[$request->action])){
            return ['code'=> 422, 'status' => 'error', 'message' => ['action must be released or disputed']];
        }
        [$newStatus,$event] = $events[$request->action];
        if(!MerchantPayment::where('id',$payment->id)->where('status',1)->update(['status'=>$newStatus])){
            return ['code'=> 422, 'status' => 'error', 'message' => 'Only a held escrow can be simulated.'];
        }
        sendEscrowEvent($payment,$event,$request->action);
        return ['code'=> 200, 'status' => 'OK', 'message' => 'Simulated: escrow '.$request->action.'.'];
    }

    public function checkValidity(Request $request)
    {
        $cred = ApiCreds::where('access_key',$request->bearerToken())->first();
        if(!$cred){
            return [
                'code'=> 401,
                'status' => 'error',
                'message' => 'Invalid API credentials.'
            ] ;
        }

        $payment = MerchantPayment::where('payment_id',$request->payment_id)->first();
        if($payment && $payment->is_escrow){
            return [
                'code'=> 409,
                'status' => 'error',
                'message' => 'This is an escrow payment. Use /payment/escrow/status instead.'
            ];
        }
        if($payment && $payment->status == 1){
            return [
                'code'=> 200,
                'status' => 'OK',
                'message' => 'Transaction is valid'
            ];
        }
        return [
            'code'=> 404,
            'status' => 'error',
            'message' => 'Transaction Not Found.'
        ];
    }


// make escrow through api 

public function makeEscrow(Request $request)
{

    $cred = ApiCreds::where('access_key',$request->bearerToken())->first();
    if(!$cred){
        return [
            'code'=> 401,
            'status' => 'error',
            'message' => 'Invalid API credentials.'
        ] ;
    }

    $validator = Validator::make($request->all(),[
        'receiver_email'   => 'required|email|exists:users,email',
        'pay_charge'          => 'required|boolean',
        'amount'  => 'required|gt:0',
        'currency_code' => 'required|string',
        'description' => 'required|string'
    ]);

    if($validator->fails()) {
        return [
            'code'=> 422,
            'status' => 'error',
            'message' => $validator->errors()->all()
        ] ;
    }

// currency code
$currency_info = Currency::where('code',$request->currency_code)->first();
if(!$currency_info){
    return [
        'code'=> 404,
        'status' => 'error',
        'message' => 'Requested currency not found.'
    ] ;
}

// currency code

// sender user 
$sender_user_id = User::where('id',$cred->merchant_id)->first();
$sender_id = $sender_user_id->id;    
// sender user     

// getting wallet
$currency_id = $currency_info->id;
$wallet_info = Wallet::where('user_id',$sender_id)->where('currency_id',$currency_id)->where('user_type',1)->first();
// getting wallet

    if(!$wallet_info){
        return [
            'code'=> 404,
            'status' => 'error',
            'message' => 'You do not have a wallet in this currency.'
        ] ;
    }

    $receiver_email = $request->receiver_email; // reciver email
    $wallet_id = $wallet_info->id;
    $user_id = $sender_id;
    $amount = $request->amount; // amount
    $description = $request->description; // description
    $pay_charge = $request->pay_charge ? 1 : 0; // true or false pay charge



    $receiver = User::where('email',$receiver_email)->first();
    if(!$receiver) return back()->with('error','Recipient not found');
   

    $senderWallet = Wallet::where('id',$wallet_id)->where('user_type',1)->where('user_id',$user_id)->first();
    if(!$senderWallet) return back()->with('error','Your wallet not found');


    $currency = Currency::findOrFail($senderWallet->currency_id);
    $charge = charge('make-escrow');  

    $finalCharge = amount(chargeCalc($charge,$amount,$currency->rate),$currency->type,2);
    if($request->pay_charge) $finalAmount =  amount($amount + $finalCharge, $currency->type,2);
    else  $finalAmount =  amount($amount, $currency->type,2);
    
    if($senderWallet->balance < $finalAmount){
        return [
            'code'=> 422,
            'status' => 'error',
            'message' => "Insufficent Balance In Your Wallet"
        ] ;
    }
    
    $txResponse = DB::transaction(function () use (&$amount, &$currency, &$description, &$escrow, &$finalAmount, &$finalCharge, &$pay_charge, &$receiver, &$senderWallet, &$trnx, &$user_id) {
    // atomic check-and-deduct: parallel requests cannot spend the same balance twice
    if(!Wallet::where('id',$senderWallet->id)->where('balance','>=',$finalAmount)->decrement('balance',$finalAmount)){
        return [
            'code'=> 422,
            'status' => 'error',
            'message' => "Insufficent Balance In Your Wallet"
        ];
    }
    $senderWallet->refresh();

    $escrow               = new Escrow();
    $escrow->trnx         = str_rand();
    $escrow->user_id      = $user_id;
    $escrow->recipient_id = $receiver->id;
    $escrow->description  = $description;
    $escrow->amount       = $amount;
    $escrow->pay_charge   = $pay_charge ? 1 : 0;
    $escrow->charge       = $finalCharge;
    $escrow->currency_id  = $currency->id;
    $escrow->save();

    $trnx              = new Transaction();
    $trnx->trnx        = $escrow->trnx;
    $trnx->user_id     = $user_id;
    $trnx->user_type   = 1;
    $trnx->currency_id = $currency->id;
    $trnx->wallet_id   = $senderWallet->id;
    $trnx->amount      = $finalAmount;
    $trnx->charge      = $finalCharge;
    $trnx->remark      = 'make_escrow';
    $trnx->type        = '-';
    $trnx->details     = trans('Made escrow to '). $receiver->email;
    $trnx->save();
        return null;
    }, 3);
    if($txResponse) return $txResponse;

    try {
        $type = $currency->type;
        @mailSend('make_escrow',[
            'amount'        => plainAmount($amount,$type),
            'charge'        => plainAmount($finalCharge,$type),
            'curr'          => $currency->code,
            'to_user'       => $receiver->email,
            'description'   => e($description),
            'trnx'          => $escrow->trnx,
            'after_balance' => plainAmount($senderWallet->balance,$type),
            'date_time'     => dateFormat($escrow->created_at)
        ],$sender_user_id);

        @mailSend('receive_escrow',[
            'amount'      => plainAmount($amount,$type),
            'curr'        => $currency->code,
            'from_user'   => $sender_user_id->email,
            'description' => e($description),
            'trnx'        => $escrow->trnx,
            'date_time'   => dateFormat($escrow->created_at)
        ],$receiver);
    } catch (\Throwable $th) {
        // never block the escrow because of an email problem
    }


    return [
        "code" => 200,
        "status" => "OK",
        "message"=> "An Escrow Has Been Made to $receiver_email",
    ];

}

// make escrow through api 

// make invoice thorugh api 
public function MakeInvoice(Request $request)
{
    $cred = ApiCreds::where('access_key',$request->bearerToken())->first();
    if(!$cred){
        return [
            'code'=> 401,
            'status' => 'error',
            'message' => 'Invalid API credentials.'
        ] ;
    }

    // api credentials
    if(count(User::where('email',$request->email)->get()) == 0){
        return [
            "code" => 500,
            "status" => "OK",
            "message"=> "User Not Registerred On Dropppay",
            "url" => url('/') . '/user/register'
        ];
    }
   $validator = Validator::make($request->all(),[
        'invoice_to' => 'required|string',
        'email'      => 'required|email|exists:users,email',
        'address'    => 'nullable',
        'currency_code'   => 'required',
        'item'       => 'required',
        'item.*'     => 'required',
        'amount'     => 'required',
        'amount.*'   => 'required|numeric|gt:0' 
    ]);



    if($validator->fails()) {
        return [
            'code'=> 422,
            'status' => 'error',
            'message' => $validator->errors()->all()
        ] ;
    }

// currency code
$currency_info = Currency::where('code',$request->currency_code)->first();
if(!$currency_info){
    return [
        'code'=> 404,
        'status' => 'error',
        'message' => 'Requested currency not found.'
    ] ;
}

// currency code

// sender user 
$sender_user_id = User::where('id',$cred->merchant_id)->first();
$sender_id = $sender_user_id->id;    
// sender user     

//invoice status 
$invoice_code = $request->invoice_status;
$invoice_status = 0;

if($invoice_code == 'publish'){
    $invoice_status = 1;
}
if($invoice_code == 'cancel'){
    $invoice_status = 2;
}

//invoice status 

// getting wallet
$currency_id = $currency_info->id;
$wallet_info = Wallet::where('user_id',$sender_id)->where('currency_id',$currency_id)->where('user_type',1)->first();
// getting wallet

    $charge = charge('create-invoice');
    $currency = Currency::findOrFail($currency_id);

    $amount = array_sum($request->amount);
    $finalCharge = chargeCalc($charge,$amount,$currency->rate);
    $willGetAmount = numFormat($amount - $finalCharge);

    $allow_escrow = $request->allow_escrow ?? false;
    $pay_charge = $request->pay_charge ?? false;


    $invoice = new Invoice();
    $invoice->user_id      = $sender_id;
    $invoice->number       = 'INV-'.randNum(8);
    $invoice->invoice_to   = $request->invoice_to;
    $invoice->email        = $request->email;
    $invoice->address      = $request->address;
    $invoice->currency_id  = $currency->id;
    $invoice->charge       = $finalCharge;
    $invoice->allow_escrow = $allow_escrow;
    $invoice->pay_charge = $pay_charge;
    $invoice->success_url = $request->success_url ?? "";
    $invoice->cancel_url = $request->cancel_url ?? "";
    $invoice->final_amount = $amount;
    $invoice->get_amount   = $willGetAmount;
    $invoice->status = $invoice_status;
    $invoice->save();

    try{
        $items = array_combine($request->item,$request->amount);
    }
    catch (\Exception $e) {
        return [
            "code" => 500,
            "status" => "error",
            "message"=> "An Error In Your API Parameters Caused This.",
        ] ;
    }
    
  
    foreach($items as $item => $itemAmount){
        $invItem             = new InvItem();
        $invItem->invoice_id = $invoice->id;
        $invItem->name       = $item;
        $invItem->amount	 = $itemAmount;
        $invItem->save();
    }
 
    $route = route('invoice.view',encrypt($invoice->number));

    @email([

        'email'   => $invoice->email,
        "subject" => trans('Invoice Payment'),
        'message' => trans('Hello')." $invoice->invoice_to,<br/></br>".

            trans('You have pending payment of invoice')." <b>$invoice->number</b>.".trans('Please click the link below to complete your payment') .".<br/></br>".
            
            trans('Invoice details').": <br/></br>".
            
            trans('Amount')  .":  $amount $currency->code <br/>".
            trans('Payment Link')." :  <a href='$route' target='_blank'>".trans('Click To Payment')."</a><br/>".
            trans('Time')." : $invoice->created_at,

        " 
    ]);

    return [
        "code" => 200,
        "status" => "OK",
        "message"=> "Invoice Has Been Made",
        "url" => $route
    ];
}


// make invoice thorugh api 



}


