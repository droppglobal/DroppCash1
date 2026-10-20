<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Models\Wallet;
use App\Models\Withdraw;
use App\Models\Withdrawals;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\ApiController;
use Illuminate\Support\Facades\Validator;

use Illuminate\Support\Facades\DB;

class WithdrawalController extends ApiController
{
    public function withdrawForm()
    {
        $success['wallets'] = Wallet::where('user_id',request()->user()->id)->where('user_type',2)->where('balance', '>', 0)->get();
        return $this->sendResponse($success,__('success'));
    }

    public function methods(Request $request)
    {
        $success['methods'] = Withdraw::where('currency_id',$request->currency)->where('status',1)->where('is_instant',0)->get();   // instant Naira withdrawal is for users only
        if($success['methods']->isEmpty()){
            return $this->sendError('Error',['No withdraw methods found']);
        }
        return $this->sendResponse($success,__('Withdraw methods'));
    }

    public function withdrawSubmit(Request $request)
    {
        $validator = Validator::make($request->all(),[
            'amount'    => 'required|numeric|gt:0',
            'wallet_id' => 'required',
            'method_id' => 'required',
            'user_data' => 'required'
        ]);

        if($validator->fails()){
            return $this->sendError('Validation Error',$validator->errors());
        }

        $wallet = Wallet::where('id',$request->wallet_id)->where('user_type',2)->where('user_id',request()->user()->id)->first();   // must be the merchant's own wallet
        if(!$wallet){
            return $this->sendError('Error',['Wallet not found']);
        }

        $method = Withdraw::where('id',$request->method_id)->where('currency_id',$wallet->currency_id)->where('status',1)->where('is_instant',0)->first();
        if(!$method){
            return $this->sendError('Error',['Withdraw method not found']);
        }

        if($request->amount < $method->min_amount || $request->amount > $method->max_amount){
            return $this->sendError('Error',['Please follow the limit']);
        }

        $charge = chargeCalc($method,$request->amount);
        $finalAmount = numFormat($request->amount + $charge);

        if($wallet->balance < $finalAmount){
            return $this->sendError('Error',['Insufficient balance']);
        }

        $txResponse = DB::transaction(function () use (&$charge, &$finalAmount, &$method, &$request, &$wallet, &$withdraw) {
        // atomic check-and-deduct: parallel requests cannot spend the same balance twice
        if(!Wallet::where('id',$wallet->id)->where('balance','>=',$finalAmount)->decrement('balance',$finalAmount)){
            return $this->sendError('Error',['Insufficient balance.']);
        }
        $wallet->refresh();
        
        $withdraw              = new Withdrawals();
        $withdraw->trx         = str_rand();
        $withdraw->merchant_id = request()->user()->id;
        $withdraw->method_id   = $method->id;
        $withdraw->currency_id = $wallet->currency_id;
        $withdraw->amount      = $request->amount;
        $withdraw->charge      = $charge;
        $withdraw->total_amount= $finalAmount;
        $withdraw->user_data   = $request->user_data;
        $withdraw->save();
            return null;
        }, 3);
        if($txResponse) return $txResponse;

        try {
            $type = $wallet->currency->type;
            @mailSend('withdraw_request_user',[
                'amount'    => plainAmount($request->amount,$type),
                'charge'    => plainAmount($charge,$type),
                'total'     => plainAmount($finalAmount,$type),
                'curr'      => $wallet->currency->code,
                'method'    => $method->name,
                'trnx'      => $withdraw->trx,
                'balance'   => plainAmount($wallet->balance,$type),
                'date_time' => dateFormat($withdraw->created_at)
            ],request()->user());
        } catch (\Throwable $th) {
            // never block the withdrawal request because of an email problem
        }

        try {
            $requester = request()->user();
            $type      = $wallet->currency->type;
            notifyAdmins('withdraw_request',[
                'requester'       => e($requester->name),
                'requester_email' => e($requester->email),
                'requester_type'  => 'Merchant',
                'amount'          => plainAmount($request->amount,$type),
                'charge'          => plainAmount($charge,$type),
                'total'           => plainAmount($finalAmount,$type),
                'curr'            => $wallet->currency->code,
                'method'          => $method->name,
                'trnx'            => $withdraw->trx,
                'date_time'       => dateFormat($withdraw->created_at),
                'link'            => route('admin.withdraw.pending')
            ]);
        } catch (\Throwable $th) {
            // never block the withdrawal request because of an email problem
        }

        return $this->sendResponse(['success'],__('Withdraw request has been submitted successfully.'));
    }

    public function history()
    {
        $success['withdrawals'] = Withdrawals::where('merchant_id',request()->user()->id)->latest()->paginate(15);
        return $this->sendResponse($success,__('Withdraw history'));
    }
}
