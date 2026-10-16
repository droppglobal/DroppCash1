<?php

namespace App\Http\Controllers\Merchant;

use App\Models\Wallet;
use App\Models\Withdraw;
use App\Models\Withdrawals;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;


use Illuminate\Support\Facades\DB;

class WithdrawalController extends Controller
{
    public function withdrawForm()
    {
        $wallets = Wallet::where('user_id',merchant()->id)->where('user_type',2)->where('balance', '>', 0)->get();
        return view('merchant.withdraw.withdraw_form',compact('wallets'));
    }

    public function methods(Request $request)
    {
        $methods = Withdraw::where('currency_id',$request->currency)->where('status',1)->get();
        if($methods->isEmpty()) return response('empty');
        return response($methods);
    }

    public function withdrawSubmit(Request $request)
    {
        $request->validate([
            'amount'    => 'required|numeric|gt:0',
            'wallet_id' => 'required',
            'method_id' => 'required',
            'user_data' => 'required'
        ]);

        $method = Withdraw::findOrFail($request->method_id);
        if(!$method){
            return back()->with('error','Withdraw method not found');
        }

        $wallet = Wallet::where('id',$request->wallet_id)->where('user_type',2)->first();
        if(!$wallet){
            return back()->with('error','Wallet not found');
        }

        if($request->amount < $method->min_amount || $request->amount > $method->max_amount){
            return back()->with('error','Please follow the limit');
        }

        $charge      = chargeCalc($method,$request->amount);
        $finalAmount = numFormat($request->amount + $charge);

        if($wallet->balance < $finalAmount){
            return back()->with('error','Insufficient balance');
        }

        $txResponse = DB::transaction(function () use (&$charge, &$finalAmount, &$method, &$request, &$wallet, &$withdraw) {
        // atomic check-and-deduct: parallel requests cannot spend the same balance twice
        if(!Wallet::where('id',$wallet->id)->where('balance','>=',$finalAmount)->decrement('balance',$finalAmount)){
            return back()->with('error','Insufficient balance.');
        }
        $wallet->refresh();
        
        $withdraw              = new Withdrawals();
        $withdraw->trx         = str_rand();
        $withdraw->merchant_id = merchant()->id;
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
            ],merchant());
        } catch (\Throwable $th) {
            // never block the withdrawal request because of an email problem
        }

        try {
            $requester = merchant();
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

        return back()->with('success','Withdraw request has been submitted successfully.');
    }

    public function history(Request $request)
    {
        $search = $request->search;
        $withdrawals = Withdrawals::when($search,function($q) use($search) {return $q->where('trx',$search);})->where('merchant_id',merchant()->id)->latest()->paginate(15);
        return view('merchant.withdraw.history',compact('withdrawals','search'));
    }
}
