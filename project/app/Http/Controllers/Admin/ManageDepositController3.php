<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use App\Models\Deposit;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\Invoice;

class ManageDepositController extends Controller
{
    public function deposits()
    {
        $search = request('search');
        $status = request('status');
        $deposits = Deposit::when($status,function($q) use($status){
            return $q->where('status',$status);
        })
        ->when($search,function($q) use($search){
            return $q->where('txn_id','like',"%$search%");
        })
        ->latest()->paginate(15);
        return view('admin.deposit.index',compact('deposits','search'));
    }

    public function approve(Request $request)
    {
        $deposit = Deposit::findOrFail($request->id);
        // claim first: a deposit can be approved (and credited) only once
        if(!Deposit::where('id',$deposit->id)->where('status','pending')->update(['status'=>'completed'])){
            return back()->with('error','This deposit has already been processed');
        }
        $deposit->refresh();
        $user = User::findOrFail($deposit->user_id);

        if($deposit->invoice != null){
            $invoice = Invoice::findOrFail($deposit->invoice);
            
            $trnx              = new Transaction();
            $trnx->trnx        = $deposit->txn_id;
            $trnx->user_id     = $user->id;
            $trnx->user_type   = 1;
            $trnx->currency_id = $invoice->currency_id;
            $trnx->amount      = $invoice->final_amount;
            $trnx->charge      = 0;
            $trnx->remark      = 'invoice_payment';
            $trnx->invoice_num = $invoice->number;
            
            $trnx->details     = trans('Payment to invoice : '). $invoice->number;
            $trnx->save();

            $rcvWallet = Wallet::where('user_id',$invoice->user_id)->where('user_type',1)->where('currency_id',$invoice->currency_id)->first();
            
            if(!$rcvWallet){
                $rcvWallet =  Wallet::create([
                    'user_id'     => $invoice->user_id,
                    'user_type'   => 1,
                    'currency_id' => $invoice->currency_id,
                    'balance'     => 0
                ]);
            }

            $rcvWallet->increment('balance',$invoice->get_amount);   // atomic
            $rcvWallet->refresh();

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
            $rcvTrnx->details     = trans('Receive Payment from invoice : '). $invoice->number;
            $rcvTrnx->save();

            $invoice->payment_status = 1;
            $invoice->update();


            @mailSend('received_invoice_payment',[
                'amount' => amount($invoice->get_amount,$invoice->currency->type,2),
                'curr'   => $invoice->currency->code,
                'trnx'   => $rcvTrnx->trnx,
                'from_user' => $invoice->email,
                'inv_num'  => $invoice->number,
                'after_balance' => amount($rcvWallet->balance,$invoice->currency->type,2),
                'charge' => amount($invoice->charge,$invoice->currency->type,2),
                'date_time' => dateFormat($rcvTrnx->created_at)
            ],$invoice->user);
          return back()->with('success','Deposit has been approved');
        }


        else{

            $wallet = Wallet::where([['user_id',$user->id],['user_type',1],['currency_id',$deposit->currency_id]])->first();
            if(!$wallet){
                    $wallet =  Wallet::create([
                        'user_id'     => $user->id,
                        'user_type'   => 1,
                        'currency_id' => $deposit->currency_id,
                        'balance'     => 0
                    ]);
                }
                $wallet->increment('balance',($deposit->amount - $deposit->charge));   // atomic
                $wallet->refresh();

                $trnx              = new Transaction();
                $trnx->trnx        = str_rand();
                $trnx->user_id     = $user->id;
                $trnx->user_type   = 1;
                $trnx->currency_id = $deposit->currency_id;
                $trnx->wallet_id   = $wallet->id;
                $trnx->amount      = ($deposit->amount - $deposit->charge);
                $trnx->charge      = $deposit->charge;
                $trnx->remark      = 'deposit_approve';
                $trnx->type        = '+';
                $trnx->details     = trans('Approve deposit');
                $trnx->save();

                @mailSend('deposit_approve',[
                    'amount' => amount($deposit->amount,$deposit->currency->type,2),
                    'curr'   => $deposit->currency->code,
                    'trnx'   => $trnx->trnx,
                    'method' => $deposit->gateway->name,
                    'charge' => amount($deposit->charge,$deposit->currency->type,2),
                    'new_balance' => amount($wallet->balance,$wallet->currency->type,2),
                    'date_time' => dateFormat($trnx->created_at)
                ],$user);

                return back()->with('success','Deposit has been approved');
        }
       
    }


    public function reject(Request $request)
    {
        $deposit = Deposit::findOrFail($request->id);
        if(!Deposit::where('id',$deposit->id)->where('status','pending')->update(['status'=>'rejected'])){
            return back()->with('error','This deposit has already been processed');
        }
        $deposit->refresh();
    
        $user = User::findOrFail($deposit->user_id);

        @mailSend('deposit_reject',[
            'amount' => amount($deposit->amount,$deposit->currency->type,2),
            'curr'   => $deposit->currency->code,
            'method' => $deposit->gateway->name,
            'charge' => amount($deposit->charge,$deposit->currency->type,2),
            'reject_reason' => $request->reject_reason,
            'date_time' => dateFormat(now())
        ],$user);

        return back()->with('success','Deposit has been rejected');
    }
}
