<?php

namespace App\Http\Controllers\Admin;

use App\Models\Transaction;
use App\Models\Withdrawals;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Services\Flutterwave\InstantWithdrawal;

use Illuminate\Support\Facades\DB;

class WithdrawalController extends Controller
{
    public function accepted()
    {
        $withdrawlogs = Withdrawals::where('status', 1)->latest()->with(['method','user','currency','merchant','agent'])->paginate(15);
        return view('admin.withdraw.withdraw_all',compact('withdrawlogs'));
    }
    public function pending()
    {
        $withdrawlogs = Withdrawals::where('status', 0)->latest()->with(['method','user','currency','merchant','agent'])->paginate(15);
        return view('admin.withdraw.withdraw_all',compact('withdrawlogs'));
    }
    public function rejected()
    {
        $withdrawlogs = Withdrawals::where('status', 2)->latest()->with(['method','user','currency','merchant','agent'])->paginate(15);
        return view('admin.withdraw.withdraw_all',compact('withdrawlogs'));
    }

    public function withdrawAccept(Withdrawals $withdraw)
    {
        // an instant Naira withdrawal is never "marked paid" by hand: approving it SENDS it through Flutterwave
        if($withdraw->instant){
            $result = InstantWithdrawal::approve($withdraw);
            return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
        }
        $txResponse = DB::transaction(function () use (&$trnx, &$user, &$withdraw) {
        // claim first: a withdrawal can be accepted only once
        if(!Withdrawals::where('id',$withdraw->id)->where('status',0)->update(['status'=>1])){
            return back()->with('error','This withdrawal has already been processed');
        }
        $withdraw->refresh();
       
        if($withdraw->user_id){
            $user = $withdraw->user;
           
            $trnx              = new Transaction();
            $trnx->trnx        = str_rand();
            $trnx->user_id     = $withdraw->user_id;
            $trnx->user_type   = 1;
            $trnx->currency_id = $withdraw->currency->id;
            $trnx->amount      = $withdraw->amount;
            $trnx->charge      = $withdraw->charge;
            $trnx->remark      = 'withdraw_money';
            $trnx->type        = '-';
            $trnx->details     = trans('Withdraw money');
            $trnx->save();
        }
        elseif ($withdraw->merchant_id){
            $user = $withdraw->merchant;
         
            $trnx              = new Transaction();
            $trnx->trnx        = str_rand();
            $trnx->user_id     = $withdraw->merchant_id;
            $trnx->user_type   = 2;
            $trnx->currency_id = $withdraw->currency->id;
            $trnx->amount      = $withdraw->amount;
            $trnx->charge      = $withdraw->charge;
            $trnx->remark      = 'withdraw_money';
            $trnx->type        = '-';
            $trnx->details     = trans('Withdraw money');
            $trnx->save();
        }
        else {
            $user = $withdraw->agent;
          
            $trnx              = new Transaction();
            $trnx->trnx        = str_rand();
            $trnx->user_id     = $withdraw->agent_id;
            $trnx->user_type   = 3;
            $trnx->currency_id = $withdraw->currency->id;
            $trnx->amount      = $withdraw->amount;
            $trnx->charge      = $withdraw->charge;
            $trnx->remark      = 'withdraw_money';
            $trnx->type        = '-';
            $trnx->details     = trans('Withdraw money');
            $trnx->save();
        }
            return null;
        }, 3);
        if($txResponse) return $txResponse;

        @mailSend('accept_withdraw',['amount'=>amount($withdraw->amount,$withdraw->currency->type,2), 'method' => $withdraw->withdraw->name,'trnx'=> $trnx->trnx,'curr' => $withdraw->currency->code,'method'=>$withdraw->method->name,'charge'=> amount($withdraw->charge,$withdraw->currency->type,2),'date_time'=> dateFormat($trnx->created_at)], $user);

        return back()->with('success','Withdraw Accepted Successfully');
    }


    // refresh an instant withdrawal that is waiting for Flutterwave
    public function instantCheck(Withdrawals $withdraw)
    {
        if(!$withdraw->instant){
            return back()->with('error','This is not an instant withdrawal');
        }
        $state = InstantWithdrawal::reconcile($withdraw,true);
        return back()->with('success',__('Status refreshed: ').$state);
    }

    public function withdrawReject(Request $request, Withdrawals $withdraw)
    {
        $request->validate(['reason_of_reject' => 'required']);
        // an instant withdrawal that is already with Flutterwave (or finished) can not be rejected by hand: the money may be gone
        if($withdraw->instant && $withdraw->payout_status !== 'review'){
            return back()->with('error','This instant withdrawal is already with Flutterwave or finished. Use "Check status" instead.');
        }
    
        $txResponse = DB::transaction(function () use (&$request, &$trnx, &$user, &$wallet, &$withdraw) {
        // claim first: a withdrawal can be rejected (and refunded) only once, and never after it was accepted
        if(!Withdrawals::where('id',$withdraw->id)->where('status',0)->update(array_merge(['status'=>2,'reject_reason'=>$request->reason_of_reject], $withdraw->instant ? ['payout_status'=>'failed','payout_message'=>'Rejected by admin'] : []))){
            return back()->with('error','This withdrawal has already been processed');
        }
        $withdraw->refresh();

        if($withdraw->user_id){
            $user = $withdraw->user;
            $wallet = Wallet::where('user_id',$withdraw->user_id)->where('user_type',1)->where('currency_id',$withdraw->currency_id)->firstOrFail();

            $wallet->increment('balance',$withdraw->total_amount);   // atomic
            $wallet->refresh();

            $trnx              = new Transaction();
            $trnx->trnx        = str_rand();
            $trnx->user_id     = $withdraw->user_id;
            $trnx->user_type   = 1;
            $trnx->currency_id = $withdraw->currency->id;
            $trnx->amount      = $withdraw->amount;
            $trnx->charge      = 0;
            $trnx->remark      = 'withdraw_reject';
            $trnx->type        = '+';
            $trnx->details     = trans('Withdraw request rejected');
            $trnx->save();

        } elseif ($withdraw->merchant_id){
            $user = $withdraw->merchant;
            $wallet = Wallet::where('user_id',$withdraw->merchant_id)->where('user_type',2)->where('currency_id',$withdraw->currency_id)->firstOrFail();

            $wallet->increment('balance',$withdraw->total_amount);   // atomic
            $wallet->refresh();

            $trnx              = new Transaction();
            $trnx->trnx        = str_rand();
            $trnx->user_id     = $withdraw->merchant_id;
            $trnx->user_type   = 2;
            $trnx->currency_id = $withdraw->currency->id;
            $trnx->amount      = $withdraw->amount;
            $trnx->charge      = 0;
            $trnx->remark      = 'withdraw_reject';
            $trnx->type        = '+';
            $trnx->details     = trans('Withdraw request rejected');
            $trnx->save();

        } else {
            $user = $withdraw->agent;
            $wallet = Wallet::where('user_id',$withdraw->agent_id)->where('user_type',3)->where('currency_id',$withdraw->currency_id)->firstOrFail();

            $wallet->increment('balance',$withdraw->total_amount);   // atomic
            $wallet->refresh();

            $trnx              = new Transaction();
            $trnx->trnx        = str_rand();
            $trnx->user_id     = $withdraw->agent_id;
            $trnx->user_type   = 3;
            $trnx->currency_id = $withdraw->currency->id;
            $trnx->amount      = $withdraw->amount;
            $trnx->charge      = 0;
            $trnx->remark      = 'withdraw_reject';
            $trnx->type        = '+';
            $trnx->details     = trans('Withdraw request rejected');
            $trnx->save();

        }
            return null;
        }, 3);
        if($txResponse) return $txResponse;

        @mailSend('reject_withdraw',['amount'=> amount($withdraw->amount,$withdraw->currency->type,2), 'method' => $withdraw->withdraw->name,'trnx'=> $trnx->trnx,'curr' => $withdraw->currency->code,'method'=>$withdraw->method->name,'reason'=>$withdraw->reject_reason,'date_time'=> dateFormat($trnx->created_at)],$user);

        return back()->with('success','Withdraw Rejected Successfully');
    }
}
