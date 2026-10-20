<?php

namespace App\Http\Controllers\Api\User;

use App\Models\Addon;
use App\Models\Agent;
use App\Models\Wallet;
use App\Models\Currency;
use App\Models\Withdraw;
use App\Models\Transaction;
use App\Models\Withdrawals;
use App\Services\Flutterwave\InstantPayoutLogic;
use App\Services\Flutterwave\InstantWithdrawal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Api\ApiController;

use Illuminate\Support\Facades\DB;

class WithdrawalController extends ApiController
{
    public function withdrawForm()
    {
        $success['wallets'] = Wallet::where('user_id',auth()->id())->where('user_type',1)->where('balance', '>', 0)->with(['currency'])->get();
        return $this->sendResponse($success,__('success'));
    }

    public function methods(Request $request)
    {
        // the instant (Flutterwave) method is only returned when the app asks for it (?instant=1) and Flutterwave is set up,
        // so older versions of the app never see a method they cannot use
        $showInstant = $request->instant && InstantWithdrawal::secret() !== '';
        $success['methods'] = Withdraw::where('currency_id',$request->currency)->where('status',1)
            ->when(!$showInstant, function ($q) { return $q->where('is_instant',0); })->get();
        if($success['methods']->isEmpty()){
            return $this->sendError('Error',['No withdraw methods found']);
        }
        return $this->sendResponse($success,__('Withdraw methods'));
    }

    // instant withdrawal: list of Nigerian banks
    public function instantBanks()
    {
        $banks = InstantWithdrawal::banks();
        if(!$banks){
            return $this->sendError('Error',['Instant withdrawal is temporarily unavailable.']);
        }
        return $this->sendResponse(['banks' => $banks],__('Banks'));
    }

    // instant withdrawal: verify the account, compare the name with the profile and quote the fee
    public function instantResolve(Request $request)
    {
        $validator = Validator::make($request->all(),['bank_code' => 'required','account_number' => 'required']);
        if($validator->fails()){
            return $this->sendError('Validation Error',$validator->errors());
        }
        $result = InstantWithdrawal::resolveAccount(auth()->user(), (string) $request->bank_code, (string) $request->account_number);
        if(!$result['ok']){
            return $this->sendError('Error',[$result['message']]);
        }
        $out = ['account_name' => $result['account_name'], 'name_matches' => $result['match']];
        $method = Withdraw::where('id',$request->method_id)->where('is_instant',1)->where('status',1)->first();
        if($method && InstantPayoutLogic::validAmount($request->amount)){
            $price = InstantWithdrawal::price($method,(float) $request->amount);
            if($price['ok']){
                $out += ['fee' => $price['fee'], 'charge' => $price['charge'], 'total' => $price['total']];
            }
        }
        return $this->sendResponse($out,__('Account verified'));
    }

    public function withdrawSubmit(Request $request)
    {
   
        $validator = Validator::make($request->all(),[
            'amount'    => 'required|numeric|gt:0',
            'wallet_id' => 'required',
            'method_id' => 'required',
            'user_data' => 'nullable'
        ]);

        if($validator->fails()){
            return $this->sendError('Validation Error',$validator->errors());
        }

        // the wallet must belong to the person asking (before, any wallet id was accepted)
        $wallet = Wallet::where('id',$request->wallet_id)->where('user_type',1)->where('user_id',auth()->id())->first();
        if(!$wallet){
            return $this->sendError('Error',['Wallet not found']);
        }

        $method = Withdraw::where('id',$request->method_id)->where('currency_id',$wallet->currency_id)->where('status',1)->first();
        if(!$method){
            return $this->sendError('Error',['Withdraw method not found']);
        }

        // instant Naira withdrawal (paid by Flutterwave)
        if($method->is_instant){
            $result = InstantWithdrawal::create(auth()->user(),$wallet,$method,$request->amount,(string) $request->bank_code,(string) $request->account_number);
            if(!$result['ok']){
                return $this->sendError('Error',[$result['message']]);
            }
            return $this->sendResponse(['state' => $result['state'], 'trx' => $result['withdrawal']->trx],$result['message']);
        }
        if(!$request->filled('user_data')){
            return $this->sendError('Validation Error',['user_data' => ['The user data field is required.']]);
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

        return $this->sendResponse(['success'],__('Withdraw request has been submitted successfully.'));
    }

    public function history()
    {
        $success['withdrawals'] = Withdrawals::where('user_id',auth()->id())->latest()->paginate(15);
        // instant withdrawals still waiting for Flutterwave: ask for the final answer now
        $changed = false;
        foreach($success['withdrawals'] as $item){
            if($item->instant && in_array($item->payout_status,['processing','queued'])){
                try { InstantWithdrawal::reconcile($item); $changed = true; } catch (\Throwable $th) { report($th); }
            }
        }
        if($changed){
            $success['withdrawals'] = Withdrawals::where('user_id',auth()->id())->latest()->paginate(15);
        }
        return $this->sendResponse($success,__('Withdraw history'));
    }
    
    public function cashOutForm()
    {
        $addon = Addon::where('code','agent')->where('status',1)->first();
        if(!$addon){
            return $this->sendError('Error',['Agent addon not active/installed']);
        }
        $success['wallets'] = Wallet::where('user_id',auth()->id())->where('user_type',1)->get();
        $success['charge'] = charge('cashout');
        return $this->sendResponse($success,__('success'));
        
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
        if(!$agent) return $this->sendError('Error',['Agent not found']);
        
        $senderWallet = Wallet::where('id',$request->wallet_id)->where('user_type',1)->where('user_id',auth()->id())->first();
        if(!$senderWallet) return $this->sendError('Error',['Your wallet not found']);

        $currency = Currency::find($senderWallet->currency->id);
        if(!$currency) return $this->sendError('Error',['Currency not found']);

        $charge = charge('cashout');

        if($charge->daily_limit != 0 && auth()->user()->cashOutDailyLimit() >= $charge->daily_limit){
            return $this->sendError('error',['Your daily cashout limit has been reached']);
        }

        if($charge->monthly_limit != 0 && auth()->user()->cashOutMonthlyLimit() >= $charge->monthly_limit){
            return $this->sendError('error',['Your monthly cashout limit has been reached']);
        }

        if(($charge->minimum *  $currency->rate) > $request->amount || ($charge->maximum *  $currency->rate) < $request->amount){
            return $this->sendError('error',['Please follow the limit']);
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
    
        if($senderWallet->balance < $finalAmount) return $this->sendError('error',['Insufficient balance.']);
        
        $txResponse = DB::transaction(function () use (&$agent, &$agentWallet, &$currency, &$finalAmount, &$finalCharge, &$receiverTrnx, &$request, &$senderWallet, &$trnx) {
        // atomic check-and-deduct: parallel requests cannot spend the same balance twice
        if(!Wallet::where('id',$senderWallet->id)->where('balance','>=',$finalAmount)->decrement('balance',$finalAmount)){
            return $this->sendError('Error',['Insufficient balance.']);
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
        $this->sendResponse(['success'],__('Cash out successful'));
    }
}
