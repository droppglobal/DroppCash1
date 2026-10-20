<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use App\Models\ApiDeposit;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantPayment;
use App\Models\Transaction;
use App\Models\Wallet;

class ManageApiDepositController extends Controller
{
    public function deposits()
    {
        $search = request('search');
        $status = request('status');
        if ($status == '0') {
            $status = 'pending';
        }

        $deposits = ApiDeposit::when($status, function ($q) use ($status) {
            if ($status == 'pending') {
                return $q->where('status', 0);
            }
            return $q->where('status', $status);
        })
            ->when($search, function ($q) use ($search) {
                return $q->where('txn_id', 'like', "%$search%");
            })
            ->latest()->paginate(15);


        return view('admin.api_deposit.index', compact('deposits', 'search'));
    }

    public function approve(Request $request)
    {
        $deposit = ApiDeposit::findOrFail($request->id);
        $payment = MerchantPayment::where('payment_id', $deposit->payment_id)->first();
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
        $charge = $charge = charge('merchant-payment');
        $finalCharge = chargeCalc($charge, $payment->amount, $payment->currency->rate);
        $finalAmount =  numFormat($payment->amount);
        $merchantWallet->balance += $finalAmount;
        $merchantWallet->update();

        $receiverTrnx              = new Transaction();
        $receiverTrnx->trnx        = $deposit->txn_id;
        $receiverTrnx->user_id     = $merchant->id;
        $receiverTrnx->user_type   = 2;
        $receiverTrnx->currency_id = $payment->currency_id;
        $receiverTrnx->amount      = $finalAmount;
        $receiverTrnx->charge      = $finalCharge;
        $receiverTrnx->type        = '+';
        $receiverTrnx->remark      = 'merchant_api_payment';
        $receiverTrnx->details     = trans('Payment received from : ') . $payment->customer_email;
        $receiverTrnx->save();

        $this->curlRequest($payment, $deposit->txn_id, 'OK', 200, 'Payment has been approved');

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

        $deposit->status = 1;
        $deposit->save();
        return back()->with('success', 'Deposit has been approved');
    }


    public function reject(Request $request)
    {
        $deposit = ApiDeposit::findOrFail($request->id);
        $payment = MerchantPayment::where('payment_id', $deposit->payment_id)->first();
        $deposit->status = 2;
        $deposit->save();
        $this->curlRequest($payment, $deposit->txn_id, 'REJECT', 501, $request->reject_reason);
        return back()->with('success', 'Deposit has been rejected');
    }


    public function curlRequest($payment, $trnx, $type, $code, $message = null)
    {

        $params = [
            'code'             =>  $code,
            'status'           =>  $type,
            'payment_id'       =>  $payment->payment_id,
            'transaction'      =>  $trnx,
            'amount'           =>  amount($payment->amount, $payment->currency->type, 3),
            'currency'         =>  $payment->currency->code,
            'custom'           =>  $payment->custom,
            'date'             =>  dateFormat($payment->updated_at, 'd-m-Y'),
            'message'          =>  $message,
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
}
