<?php

namespace App\Http\Controllers\Agent;

use App\Models\User;
use App\Models\Wallet;
use App\Models\Currency;
use App\Models\FundRequest;
use App\Models\Transaction;
use App\Models\Withdrawals;
use App\Helpers\MediaHelper;
use Illuminate\Http\Request;
use App\Models\Generalsetting;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Intervention\Image\Facades\Image;

class AgentController extends Controller
{

    public function dashboard()
    {
        $agent = agent();
        $wallets            = Wallet::where('user_type',3)->where('user_id',$agent->id)->get();
        $recentWithdraw     = Withdrawals::where('agent_id',$agent->id)->latest()->take(7)->get();
        $recentTransactions = Transaction::where('user_id',$agent->id)->where('user_type',3)->latest()->take(7)->get();
        return view('agent.dashboard',compact('wallets','recentWithdraw','recentTransactions'));
    }

    public function generateQR()
    {
        return view('agent.qr');
    }

    public function profileSetting()
    {
        $user = agent();
        return view('agent.profile_setting',compact('user'));
    }

    public function profileUpdate(Request $request)
    {

        $request->validate([
            'business_name' => 'required',
            'name' => 'required',
            'city' => 'required',
            'zip' => 'required',
            'address' => 'required',
            'photo' => 'image|mimes:png,jpeg,JPG,PNG'
            
        ]);

        $user          = agent();
        $user->business_name    = $request->business_name;
        $user->name    = $request->name;

        $user->city    = $request->city;
        $user->zip     = $request->zip;
        $user->address = $request->address;
     
        if($request->photo){
            $user->photo = MediaHelper::handleMakeImage($request->photo,[300,300]);
        }
        $user->update();
        return back()->with('success','Profile Updated');
    }

    public function changePassword()
    {
        return view('agent.change_password');
    }

    public function updatePassword(Request $request)
    {
        $request->validate(['old_pass'=>'required','password'=>'required|min:6|confirmed']);
        $user = agent();
        if (Hash::check($request->old_pass, $user->password)) {
            $password = bcrypt($request->password);
            $user->password = $password;
            $user->save();
            return back()->with('success', 'Password has been changed');
        } else {
            return back()->with('error', 'The old password doesn\'t match!');
        }
    }

    public function transactions()
    {
        $remark = request('remark');
        $search = request('search');
        $transactions = Transaction::where('user_id',agent()->id)->where('user_type',3)
                        ->when($remark,function($q) use($remark){
                            return $q->where('remark',$remark);
                        })
                        ->when($search,function($q) use($search){
                            return $q->where('trnx',$search);
                        })
                       ->latest()
                       ->paginate(15);

        return view('agent.transactions',compact('transactions'));
    }

    public function trxDetails($id)
    {
        $transaction = Transaction::find($id);
        if(!$transaction) return response('empty');
        return view('agent.trx_details',compact('transaction'));
    }

    public function downloadQR($email)
    {
        $file = generateQR($email);
        $file = file_get_contents($file);
        $image = Image::make($file);
        $extension = str_replace('image/','',$image->mime);
        $filename = 'QRCode_'.$email.'_.'.$extension;
        $qrCode = $image->opacity(100)->fit(350,350);
        $qrCode->encode('jpg');
    
        $headers = [
            'Content-Type' => $image->mime,
            'Content-Disposition' => 'attachment; filename='.$filename,
        ];
        return response()->stream(function() use ($qrCode) {
            echo $qrCode;
        }, 200, $headers);
    }

    public function twoStep()
    {
        return view('agent.twostep.two_step');
    }

    public function twoStepSendCode(Request $request)
    {
        $request->validate(['password'=>'required|confirmed']);
        $user = agent();
        if (Hash::check($request->password, $user->password)) {
            $code = randNum();
            $user->two_fa_code = $code;
            $user->update();
            sendSMS($user->phone,trans('Your two step authentication OTP is : ').$code,Generalsetting::value('contact_no'));
            return redirect(route('agent.two.step.verify'))->with('success','OTP code is sent to your phone.');
        } else {
            return back()->with('error', 'The password doesn\'t match!');
        }

    }
    public function twoStepVerifyForm()
    {
        return view('agent.twostep.verify');
    }

    public function twoStepVerifySubmit(Request $request)
    {
        $request->validate(['code'=>'required']);
        $user = agent();
        if($request->code != $user->two_fa_code){
            return back()->with('error','Invalid OTP');
        }
        if($user->two_fa_status == 1){
            $user->two_fa_status = 0;
            $msg = __('Your two step authentication is de-activated');
        }else{
            $user->two_fa_status = 1;
            $msg = __('Your two step authentication is activated');
        }
        $user->save();
        return redirect(route('agent.two.step'))->with('success',$msg);
    }

    public function fundRequests()
    {   
        $search = request('search');
        $requests = FundRequest::where('agent_id',agent()->id)->when($search,function($q) use($search){
            return $q->where('unique_code',$search);
        })->paginate(15);
        $currencies = Currency::where('status',1)->get();
        return view('agent.fund.requests',compact('requests','currencies'));
    }

    public function fundRequestSubmit(Request $request)
    {
        $request->validate(['amount' => 'required|numeric|gt:0','currency' => 'required|integer']);
        if(agent()->status == 2 || agent()->status == 0){
            return back()->with('error','You are not eligible to perform this action');
        }

        FundRequest::create([
            'agent_id'       => agent()->id,
            'currency_id'    => $request->currency,
            'amount'         => $request->amount,
            'unique_code'    => str_rand(),
        ]);

        return back()->with('success','Your fund request has been submitted.');

    }

    public function checkReceiver(Request $request){
        $receiver['data'] = User::where('email',$request->receiver)->first();
        $user = auth()->user(); 
        if(@$receiver['data'] && $user->email == @$receiver['data']->email){
            return response()->json(['self'=>__('Can\'t transfer or request in self wallet.')]);
        }
        return response($receiver);
    }

    public function cashIn()
    {   
        $wallets = Wallet::where('user_id',agent()->id)->where('user_type',3)->where('balance','>',0)->get();
        $charge = charge('cashin');
        return view('agent.cashin',compact('wallets','charge'));
    }

    public function confirmCashIn(Request $request)
    {
        
        $request->validate([
            'wallet_id' => 'required|integer',
            'amount' => 'required|gt:0',
            'receiver' => 'required',
        ]);
 
        $charge = charge('cashin');
        $agentWallet = Wallet::where([['user_id',agent()->id],['user_type',3]])->find($request->wallet_id);
        if(!$agentWallet){
            return back()->with('error','Your wallet not found');
        }
 
        $user = User::where('email',$request->receiver)->first();
        if(!$user){
            return back()->with('error','Sorry! User Not Found')->withInput();
        }
       $userWallet = Wallet::where([['user_id',$user->id],['user_type',1]])->where('currency_id', $agentWallet->currency->id)->first();
       if(!$userWallet){
        $userWallet = Wallet::create([
                'user_id'  => $user->id,
                'user_type' => 1,
                'currency_id' => $agentWallet->currency->id
            ]);
        }

       $rate = $agentWallet->currency->rate;
    
       if(($charge->minimum *  $rate) > $request->amount || ($charge->maximum *  $rate) < $request->amount){
         return back()->with('error','Please follow the limit');
       }

       //agent commission
       $commission = $request->amount * ($charge->commission/100);

       if($request->amount > $agentWallet->balance){
         return back()->with('error','Insufficient balance.');
       }

        $agentWallet->balance -= $request->amount;
        $agentWallet->save();

        $trnx              = new Transaction();
        $trnx->trnx        = str_rand();
        $trnx->user_id     = agent()->id;
        $trnx->user_type   = 3;
        $trnx->currency_id = $agentWallet->currency_id;
        $trnx->amount      = $request->amount;
        $trnx->charge      = 0;
        $trnx->remark      = 'cash_in';
        $trnx->type        = '-';
        $trnx->details     = trans('Cash In to '). $user->email;
        $trnx->save();
      
        $userWallet->balance += $request->amount;
        $userWallet->save();
        
        $userTrx = new Transaction();
        $userTrx->trnx        = $trnx->trnx;
        $userTrx->user_id     = $user->id;
        $userTrx->user_type   = 1;
        $userTrx->currency_id = $agentWallet->currency_id;
        $userTrx->amount      = $request->amount;
        $userTrx->charge      = 0;
        $userTrx->remark      = 'cash_in';
        $userTrx->type        = '+';
        $userTrx->details     = trans('Cash In from '). agent()->email;
        $userTrx->save();
      
        if ($commission > 0) {
            
            $agentWallet->balance +=  $commission;
            $agentWallet->save();
            
            $commissionTrnx = new Transaction();
            $commissionTrnx->trnx  = $trnx->trnx;
            $commissionTrnx->user_id     = agent()->id;
            $commissionTrnx->user_type   = 3;
            $commissionTrnx->currency_id = $agentWallet->currency_id;
            $commissionTrnx->amount      = $commission;
            $commissionTrnx->charge      = 0;
            $commissionTrnx->remark      = 'cash_in_commission';
            $commissionTrnx->type        = '+';
            $commissionTrnx->details     = trans('Cash In Commission');
            $commissionTrnx->save();
        }
        
        try {
            @mailSend('cash_in_user',[
                'amount'=> amount($request->amount,$userWallet->currency->type,3),             
                'curr' => $userWallet->currency->code,
                'agent' => agent()->email,
                'trnx' => $trnx->trnx,
                'date_time' => dateFormat($trnx->created_at),
                'balance' => amount($userWallet->balance,$userWallet->currency->type,3)
            ],$user);
    
            @mailSend('cash_in_agent',[
                'amount'=> amount($request->amount,$agentWallet->currency->type,3),             
                'curr' => $agentWallet->currency->code,
                'user' => $user->email,
                'trnx' => $trnx->trnx,
                'date_time' => dateFormat($trnx->created_at),
                'balance' => amount($agentWallet->balance,$agentWallet->currency->type,3)
            ],agent());
    
            @mailSend('cash_in_commission',[
                'amount'=> amount($request->amount,$agentWallet->currency->type,3),             
                'curr'  => $agentWallet->currency->code,
                'trnx'  => $trnx->trnx,
                'date_time'  => dateFormat($trnx->created_at),
                'balance' => amount($agentWallet->balance,$agentWallet->currency->type,3)
            ],agent());
            
        } catch (\Throwable $th) {
            
        }
        
        return back()->with('success','Cash in successful');

  }



}
