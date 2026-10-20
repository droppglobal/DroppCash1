<?php

namespace App\Http\Controllers\User;

use App\Models\Agent;
use App\Models\Wallet;
use App\Models\Currency;
use App\Models\Withdraw;
use App\Models\Transaction;
use App\Models\Withdrawals;
use App\Services\Flutterwave\InstantPayoutLogic;
use App\Services\Flutterwave\InstantWithdrawal;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;


use Illuminate\Support\Facades\DB;

class WithdrawalController extends Controller
{
    public function withdrawForm()
    {
        $wallets = Wallet::where('user_id',auth()->id())->where('user_type',1)->where('balance', '>', 0)->get();
        return view('user.withdraw.withdraw_form',compact('wallets'));
    }

    public function methods(Request $request)
    {
        // the instant (Flutterwave) method is only offered to screens that can handle it, and only when Flutterwave is set up
        $showInstant = $request->instant && InstantWithdrawal::secret() !== '';
        $methods = Withdraw::where('currency_id',$request->currency)->where('status',1)
            ->when(!$showInstant, function ($q) { return $q->where('is_instant',0); })->get();
        if($methods->isEmpty()){
            return response('empty');
        }
        return response($methods);
    }

    // instant withdrawal: list of Nigerian banks
    public function instantBanks()
    {
        $banks = InstantWithdrawal::banks();
        return response()->json(['ok' => (bool) $banks, 'banks' => $banks ?: []]);
    }

    // instant withdrawal: verify the account, compare the name with the profile and quote the fee
    public function instantResolve(Request $request)
    {
        $request->validate(['bank_code' => 'required', 'account_number' => 'required']);
        $result = InstantWithdrawal::resolveAccount(auth()->user(), (string) $request->bank_code, (string) $request->account_number);
        if(!$result['ok']){
            return response()->json($result);
        }
        $out = ['ok' => true, 'account_name' => $result['account_name'], 'match' => $result['match']];
        $method = Withdraw::where('id',$request->method_id)->where('is_instant',1)->where('status',1)->first();
        if($method && InstantPayoutLogic::validAmount($request->amount)){
            $price = InstantWithdrawal::price($method,(float) $request->amount);
            if($price['ok']){
                $out += ['fee' => $price['fee'], 'charge' => $price['charge'], 'total' => $price['total']];
            }
        }
        return response()->json($out);
    }

    public function withdrawSubmit(Request $request)
    {
        $request->validate([
            'amount'    => 'required|numeric|gt:0',
            'wallet_id' => 'required',
            'method_id' => 'required',
            'user_data' => 'required'
        ]);

        $method = Withdraw::where('id',$request->method_id)->where('status',1)->first();
        if(!$method){
            return back()->with('error','Withdraw method not found');
        }

        // the wallet must belong to the person asking (before, any wallet id was accepted)
        $wallet = Wallet::where('id',$request->wallet_id)->where('user_type',1)->where('user_id',auth()->id())->first();
        if(!$wallet || $wallet->currency_id != $method->currency_id){
            return back()->with('error','Wallet not found');
        }

        // instant Naira withdrawal (paid by Flutterwave)
        if($method->is_instant){
            $result = InstantWithdrawal::create(auth()->user(),$wallet,$method,$request->amount,(string) $request->bank_code,(string) $request->account_number);
            return $result['ok'] ? back()->with('success',$result['message']) : back()->with('error',$result['message']);
        }

        if($request->amount < $method->min_amount || $request->amount > $method->max_amount){
            return back()->with('error','Please follow the limit');
        }

        $charge = chargeCalc($method,$request->amount);
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
        $withdraw->user_id     = auth()->id();
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
            ],auth()->user());
        } catch (\Throwable $th) {
            // never block the withdrawal request because of an email problem
        }

        try {
            $requester = auth()->user();
            $type      = $wallet->currency->type;
            notifyAdmins('withdraw_request',[
                'requester'       => e($requester->name),
                'requester_email' => e($requester->email),
                'requester_type'  => 'User',
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

    public function history()
    {
        $withdrawals = Withdrawals::where('user_id',auth()->id())->latest()->paginate(15);
        // instant withdrawals still waiting for Flutterwave: ask for the final answer now
        $changed = false;
        foreach($withdrawals as $item){
            if($item->instant && in_array($item->payout_status,['processing','queued'])){
                try { InstantWithdrawal::reconcile($item); $changed = true; } catch (\Throwable $th) { report($th); }
            }
        }
        if($changed){
            $withdrawals = Withdrawals::where('user_id',auth()->id())->latest()->paginate(15);
        }
        return view('user.withdraw.history',compact('withdrawals'));
    }

    public function checkAgent(Request $request){
        $receiver['data'] = Agent::where('email',$request->receiver)->first();
        if($receiver['data']){
            return response($receiver);
        }
        return response(null);
    }

    public function cashOutForm()
    {
        $wallets = Wallet::where('user_id',auth()->id())->where('user_type',1)->where('balance', '>', 0)->get();
        $charge = charge('cashout');
        return view('user.withdraw.cashout',compact('wallets','charge'));
    }
    
    public function cashOut(Request $request)
    {
        $request->validate([
            'receiver'  => 'required|email',
            'wallet_id' => 'required|integer',
            'amount'    => 'required|numeric|gt:0' 
        ],
        [
            'wallet_id.required' => 'Wallet is required'
        ]);

      
        $agent = Agent::where('email',$request->receiver)->first();
        if(!$agent) return back()->with('error','Agent not found');
        
        $senderWallet = Wallet::where('id',$request->wallet_id)->where('user_type',1)->where('user_id',auth()->id())->first();
        if(!$senderWallet) return back()->with('error','Your wallet not found');

        $currency = Currency::findOrFail($senderWallet->currency->id);

        $charge = charge('cashout');

        if($charge->daily_limit != 0 && auth()->user()->cashOutDailyLimit() >= $charge->daily_limit){
            return back()->with('error','Your daily cashout limit has been reached');
        }

        if($charge->monthly_limit != 0 && auth()->user()->cashOutMonthlyLimit() >= $charge->monthly_limit){
            return back()->with('error','Your monthly cashout limit has been reached');
        }

        if(($charge->minimum *  $currency->rate) > $request->amount || ($charge->maximum *  $currency->rate) < $request->amount){
            return back()->with('error','Please follow the limit');
        }

        $agentWallet = Wallet::where('currency_id',$currency->id)->where('user_type',3)->where('user_id',$agent->id)->first();

        if(!$agentWallet){
            $agentWallet = Wallet::create([
                'user_id'     => $agent->id,
                'user_type'   => 3,
                'currency_id' => $currency->id,
                'balance'     => 0
            ]);
        }

        $finalCharge = amount(chargeCalc($charge,$request->amount,$currency->rate),$currency->type,2);
        $finalAmount =  amount($request->amount + $finalCharge, $currency->type,2);
    
        if($senderWallet->balance < $finalAmount) return back()->with('error','Insufficient balance.');
        
        $txResponse = DB::transaction(function () use (&$agent, &$agentWallet, &$currency, &$finalAmount, &$finalCharge, &$receiverTrnx, &$request, &$senderWallet, &$trnx) {
        // atomic check-and-deduct: parallel requests cannot spend the same balance twice
        if(!Wallet::where('id',$senderWallet->id)->where('balance','>=',$finalAmount)->decrement('balance',$finalAmount)){
            return back()->with('error','Insufficient balance.');
        }
        $senderWallet->refresh();

        $trnx              = new Transaction();
        $trnx->trnx        = str_rand();
        $trnx->user_id     = auth()->id();
        $trnx->user_type   = 1;
        $trnx->currency_id = $currency->id;
        $trnx->wallet_id   = $senderWallet->id;
        $trnx->amount      = $request->amount;
        $trnx->charge      = $finalCharge;
        $trnx->remark      = 'cash_out';
        $trnx->type        = '-';
        $trnx->details     = trans('Cash out to '). $agent->email;
        $trnx->save();

        $agentWallet->increment('balance',$request->amount);   // atomic
        $agentWallet->refresh();

        $receiverTrnx              = new Transaction();
        $receiverTrnx->trnx        = $trnx->trnx;
        $receiverTrnx->user_id     = $agent->id;
        $receiverTrnx->user_type   = 3;
        $receiverTrnx->currency_id = $currency->id;
        $receiverTrnx->amount      = $request->amount;
        $receiverTrnx->charge      = 0;
        $receiverTrnx->remark      = 'cash_out';
        $receiverTrnx->type        = '+';
        $receiverTrnx->details     = trans('Cash out from '). auth()->user()->email;
        $receiverTrnx->save();
            return null;
        }, 3);
        if($txResponse) return $txResponse;

        try {
            @mailSend('cash_out_user',[
                'amount'    => amount($request->amount,$senderWallet->currency->type,3),             
                'curr'      => $senderWallet->currency->code,
                'agent'     => $agent->email,
                'trnx'      => $trnx->trnx,
                'charge'    => amount($finalCharge,$senderWallet->currency->type,3),
                'date_time' => dateFormat($trnx->created_at),
                'balance'   => amount($senderWallet->balance,$senderWallet->currency->type,3)
            ],auth()->user());
    
            @mailSend('cash_out_agent',[
                'amount'    => amount($request->amount,$senderWallet->currency->type,3),             
                'curr'      => $senderWallet->currency->code,
                'user'      => auth()->user()->email,
                'trnx'      => $trnx->trnx,
                'date_time' => dateFormat($trnx->created_at),
                'balance'   => amount($senderWallet->balance,$senderWallet->currency->type,3)
            ],$agent);
        } catch (\Throwable $th) {
            
        }
  
        return back()->with('success','Cash out successfully');
    
       
    }
}
