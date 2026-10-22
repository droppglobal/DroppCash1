<?php

namespace App\Http\Controllers\Agent;

use App\Models\Wallet;
use App\Models\Withdraw;
use App\Models\Transaction;
use App\Models\Withdrawals;
use App\Services\Flutterwave\InstantPayoutLogic;
use App\Services\Flutterwave\InstantWithdrawal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;


class WithdrawalController extends Controller
{
 
    public function withdrawForm()
    {
        $wallets = Wallet::where('user_id',agent()->id)->where('user_type',3)->where('balance', '>', 0)->get();
        return view('agent.withdraw.withdraw_form',compact('wallets'));
    }

    public function methods(Request $request)
    {
        // the instant (Flutterwave) method is only offered to screens that can handle it, and only when Flutterwave is set up
        $showInstant = $request->instant && InstantWithdrawal::secret() !== '';
        $methods = Withdraw::where('currency_id',$request->currency)->where('status',1)
            ->when(!$showInstant, function ($q) { return $q->where('is_instant',0); })->get();
        if($methods->isEmpty()) return response('empty');
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
        $result = InstantWithdrawal::resolveAccount(agent(), (string) $request->bank_code, (string) $request->account_number);
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

        $wallet = Wallet::where('id',$request->wallet_id)->where('user_type',3)->where('user_id',agent()->id)->first();   // must be the agent's own wallet
        if(!$wallet){
            return back()->with('error','Wallet not found');
        }

        // instant Naira withdrawal (paid by Flutterwave)
        if($method->is_instant){
            $result = InstantWithdrawal::create(agent(),$wallet,$method,$request->amount,(string) $request->bank_code,(string) $request->account_number);
            return $result['ok'] ? back()->with('success',$result['message']) : back()->with('error',$result['message']);
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
        $withdraw->agent_id    = agent()->id;
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
            ],agent());
        } catch (\Throwable $th) {
            // never block the withdrawal request because of an email problem
        }

        try {
            $requester = agent();
            $type      = $wallet->currency->type;
            notifyAdmins('withdraw_request',[
                'requester'       => e($requester->name),
                'requester_email' => e($requester->email),
                'requester_type'  => 'Agent',
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
        $query = function () use ($search) {
            return Withdrawals::when($search,function($q) use($search) {return $q->where('trx',$search);})->where('agent_id',agent()->id)->latest()->paginate(15);
        };
        $withdrawals = $query();
        // instant withdrawals still waiting for Flutterwave: ask for the final answer now
        $changed = false;
        foreach($withdrawals as $item){
            if($item->instant && in_array($item->payout_status,['processing','queued'])){
                try { InstantWithdrawal::reconcile($item); $changed = true; } catch (\Throwable $th) { report($th); }
            }
        }
        if($changed){
            $withdrawals = $query();
        }
        return view('agent.withdraw.history',compact('withdrawals','search'));
    }
}
