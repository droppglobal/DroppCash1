<?php

namespace App\Http\Controllers\Gateway;

use App\Http\Controllers\PaymentApiController;
use App\Http\Controllers\User\DepositController;
use App\Models\Generalsetting;
use App\Models\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use App\Models\Deposit;
use App\Models\User;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\Currency;
use Illuminate\Support\Facades\Log;

class Coingate
{
    public static function initiate($request, $payment_data, $type)
    {
    
        $status = 0;
        $message = '';
        $url     = '';


        $gs =  Generalsetting::findOrFail(1);
        $item_number = Str::random(4) . time();
        $blockchain    = PaymentGateway::whereKeyword('coingate')->first();
        $blockchain = $blockchain->convertAutoData();
        $coingateAuth = $blockchain['secret_string'];
        $item_name = $gs->title . " Invest";

        $cancel_url = '';
        switch ($type) {
            case 'deposit':
                Session::put('type', $type);
                $cancel_url = route('user.deposit.index');
                break;

            case 'api':
                Session::put('type', $type);
                $cancel_url = $payment_data['cancel_url'];
                $success_url = $payment_data['success_url'];
                break;

            case 'merchent_api':
                Session::put('type', $type);
                $cancel_url = $payment_data['cancel_url'];
                $success_url = $payment_data['success_url'];
                break;

            default:
                # code...
                break;
        }
        
        
        Session::put('user_id', auth()->id());

        $my_callback_url = route('notify.coingate');
        // $my_callback_url = 'https://webhook.site/7dccfcb5-9ce8-4fdd-8b5e-66b5edd24f2e';
        if ($type == 'api') {
            $currency = Currency::findOrFail($payment_data['currency_id'])->code;
        } else {
            $currency = getCurrencyCode();
        }


        $client = new \CoinGate\Client($coingateAuth, true);

        $post_params = array(
            'order_id'          => $type == 'merchent_api' ? $payment_data['payment_id'].'_api' : $item_number,
            'price_amount'      => $payment_data['amount'],
            'price_currency'    => $currency,
            'receive_currency'  => 'BTC',
            'callback_url'      => $my_callback_url,
            'cancel_url'        => $cancel_url,
            'success_url'       => $payment_data['api'] == true ? route('user.res.api') : $cancel_url,
            'title'             => $item_name,
            'description'       => 'Deposit'
        );
        

        try {
            $order = $client->order->create($post_params);
        } catch (\CoinGate\Exception\ApiErrorException $e) {
            dd($e->getMessage());
        }

        if ($type == 'api') {
            $status = 1;
            $url = $order->payment_url;
            Session::put('typees', $type);
            return ['status' => $status, 'url' => $url];
        }



        if ($order) {
            $status = 1;
            $url = $order->payment_url;
            $operation = new DepositController();
            $user = auth()->user();
            $deposit_data = Session::get('deposit_data');
            if ($operation->invoice()) {
                $operation->invoicePaymentUpdate($deposit_data, $user);
            } else {
                $data = new Deposit();
                $data->user_info = json_encode($user);
                $data->user_id  = $user->id;
                $data->currency_id  = session('currency')->id;
                $data->user_type  = 1;
                $data->amount   = $deposit_data['amount'];
                $data->method   = $deposit_data['gateway'];
                $data->currency_info  = sessionCurrency();
                $data->status  = 'pending';
                $data->txn_id  = $item_number;
                $data->save();
            }

            Session::forget('deposit_data');
        }
        return ['status' => $status, 'message' => $message, 'url' => $url];
    }





    public function notify(Request $request)
    {

        $check = Str::contains($request->order_id, '_api');

        if ($check) {
            Log::info($request->all());
            Log::info('Merchent');
            $myfile = fopen("response.txt", "w") or die("Unable to open file!");
            $txt = serialize($request->all());
            fwrite($myfile, $txt);
            fwrite($myfile, $txt);
            fclose($myfile);


            if ($request->status == 'paid' && $request->token) {
                $status = 1;
                $txn_id = $request->token;
                $payment_id = Str::before($request->order_id, '_api');
                $message = 'Payment has been completed';
                return (new PaymentApiController)->coinGateNotify(['status' => $status, 'message' => $message, 'txn_id' => $txn_id, 'payment_id' => $payment_id]);
            }

            return true;
        }


        if ($request->status == 'paid') {


            try {
                if ($request->status == 'paid' && $request->token) {
                    $deposit = Deposit::where('txn_id', $request->order_id)->first();
                    $deposit->txn_id = $request->token;
                    $deposit->status  = 'completed';
                    $deposit->update();
                    $user = User::findOrFail($deposit->user_id);

                    if ($deposit->invoice != null) {
                        $invoice = Invoice::findOrFail($deposit->invoice);

                        $trnx              = new Transaction();
                        $trnx->trnx        = $deposit->txn_id;
                        $trnx->user_id     = $user;
                        $trnx->user_type   = 1;
                        $trnx->currency_id = $invoice->currency_id;
                        $trnx->amount      = $invoice->final_amount;
                        $trnx->charge      = 0;
                        $trnx->remark      = 'invoice_payment';
                        $trnx->invoice_num = $invoice->number;

                        $trnx->details     = trans('Payemnt to invoice : ') . $invoice->number;
                        $trnx->save();

                        $rcvWallet = Wallet::where('user_id', $invoice->user_id)->where('user_type', 1)->where('currency_id', $invoice->currency_id)->first();

                        if (!$rcvWallet) {
                            $rcvWallet =  Wallet::create([
                                'user_id'     => $invoice->user_id,
                                'user_type'   => 1,
                                'currency_id' => $invoice->currency_id,
                                'balance'     => 0
                            ]);
                        }

                        $rcvWallet->balance += $invoice->get_amount;
                        $rcvWallet->update();

                        $rcvTrnx              = new Transaction();
                        $rcvTrnx->trnx        = $trnx->trnx;
                        $rcvTrnx->user_id     = $invoice->user_id;
                        $rcvTrnx->user_type   = 1;
                        $rcvTrnx->currency_id = $invoice->currency_id;
                        $rcvTrnx->amount      = $invoice->get_amount;
                        $rcvTrnx->charge      = $invoice->charge;
                        $rcvTrnx->remark      = 'invoice_payment';
                        $rcvTrnx->invoice_num = $invoice->number;
                        $rcvTrnx->type        = '+';
                        $rcvTrnx->details     = trans('Receive Payemnt from invoice : ') . $invoice->number;
                        $rcvTrnx->save();

                        $invoice->payment_status = 1;
                        $invoice->update();


                        @mailSend('received_invoice_payment', [
                            'amount' => amount($invoice->get_amount, $invoice->currency->type, 2),
                            'curr'   => $invoice->currency->code,
                            'trnx'   => $rcvTrnx->trnx,
                            'from_user' => $invoice->email,
                            'inv_num'  => $invoice->number,
                            'after_balance' => amount($rcvWallet->balance, $invoice->currency->type, 2),
                            'charge' => amount($invoice->charge, $invoice->currency->type, 2),
                            'date_time' => dateFormat($rcvTrnx->created_at)
                        ], $invoice->user);
                    } else {

                        $wallet = Wallet::where([['user_id', $user->id], ['user_type', 1], ['currency_id', $deposit->currency_id]])->first();
                        if (!$wallet) {
                            $wallet =  Wallet::create([
                                'user_id'     => $user->id,
                                'user_type'   => 1,
                                'currency_id' => $deposit->currency_id,
                                'balance'     => 0
                            ]);
                        }
                        $wallet->balance += ($deposit->amount);
                        $wallet->save();

                        $trnx              = new Transaction();
                        $trnx->trnx        = str_rand();
                        $trnx->user_id     = $user->id;
                        $trnx->user_type   = 1;
                        $trnx->currency_id = $deposit->currency_id;
                        $trnx->wallet_id   = $wallet->id;
                        $trnx->amount      = ($deposit->amount);
                        $trnx->charge      = 0;
                        $trnx->remark      = 'deposit';
                        $trnx->type        = '+';
                        $trnx->details     = trans('Deposit balance to wallet ') . $deposit->currency->code;
                        $trnx->save();

                        @mailSend('deposit_approve', [
                            'amount' => amount($deposit->amount, $deposit->currency->type, 2),
                            'curr'   => $deposit->currency->code,
                            'trnx'   => $trnx->trnx,
                            'method' => $deposit->gateway->name,
                            'charge' => 0,
                            'new_balance' => amount($wallet->balance, $wallet->currency->type, 2),
                            'data_time' => dateFormat($trnx->created_at)
                        ], $user);
                    }
                }
                
                Log::info($request->all());
                Log::info('OK');
                
            } catch (\Throwable $e) {
                $fpbt = fopen('coingate-error' . time() . '.txt', 'w');
                fwrite($fpbt, json_encode($e->getMessage(), true));
                fclose($fpbt);
            }
        }
    }
}
