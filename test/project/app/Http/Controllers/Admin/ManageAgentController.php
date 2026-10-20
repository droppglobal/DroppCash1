<?php

namespace App\Http\Controllers\Admin;

use App\Models\Agent;
use App\Models\Wallet;
use App\Models\Country;
use App\Models\Currency;
use App\Models\LoginLogs;
use App\Models\FundRequest;
use App\Models\Transaction;
use App\Helpers\MediaHelper;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class ManageAgentController extends Controller
{
    public function agentList()
    {
        $search = request('search');
        $status = null;
        if(request('status') == 'active')  $status = 1;
        if(request('status') == 'banned')  $status = 0;
      
        $agents = Agent::whereIn('status',[1,0])->when($status,function($q) use($status) {
            return $q->where('status',$status);
        })->when($search,function($q) use($search) {
            return $q->where('email','like',"%$search%");
        })->latest()->paginate(15);
        $countries =  Country::get();
        return view('admin.agent.agent_list',compact('agents','countries','search'));
    }
    public function agentRequest()
    {
        $agents = Agent::where('status',2)->latest()->paginate(15);
        return view('admin.agent.agent_requests',compact('agents'));
    }

    public function agentDetails($id)
    {
        $user = Agent::findOrFail($id);
        $countries = Country::get(['id','name']);
        $currencies = Currency::get();
        return view('admin.agent.agent_details',compact('user','countries','currencies'));
    }

    public function profileUpdate(Request $request,$id)
    {
        $request->validate([
            'name' => 'required',
            'email' => 'required|email|unique:agents,email,'.$id,
            'phone' => 'required',
            'country' => 'required',
        ]);

        $user          = Agent::findOrFail($id);
        $user->name    = $request->name;
        $user->email   = $request->email;
        $user->phone   = $request->phone;
        $user->country = $request->country;
        $user->city    = $request->city;
        $user->zip     = $request->zip;
        $user->address = $request->address;
        $user->status  = $request->status ? 1 : 0;
        $user->email_verified  = $request->email_verified ? 1 : 0;
        $user->business_name   = $request->business_name;
        $user->business_address   = $request->business_address;
        $user->nid   = $request->nid;
        $user->update();

        return back()->with('success','Agent profile updated');
    }

    public function addAgent(Request $request)
    {
        $data = $request->validate([
            'name'    => 'required',
            'email'   => 'required|email',
            'country' => 'required',
            'phone'   => 'required',
            'password'=> 'required|confirmed',
            'nid'     => 'required',
            'nid_photo' => 'required|image|mimes:jpg,png,jpeg,PNG,JPG',
            'business_name' => 'required',
            'business_address' => 'required'
        ]);

        if($request->nid_photo){
            $data['nid_photo'] = MediaHelper::handleMakeImage($request->nid_photo);
        }

        $data['password'] = bcrypt($request->password);
        $data['email_verified'] = 1;
        Agent::create($data);
        return back()->with('success','New Agent Added.');
    }

    public function addAgentWallet(Request $request)
    {
       $request->validate(['agent_id' => 'required','currency' => 'required']);
       $exist = Wallet::where('user_id',$request->agent_id)->where('user_type',3)->where('currency_id',$request->currency)->first();
      
       if($exist) return back()->with('error','Wallet already exist');
       
       Wallet::create([
           'user_id'  => $request->agent_id,
           'currency_id' => $request->currency,
           'user_type'  => 3
       ]);
       return back()->with('success','Wallet added');
    }

    public function agentLogin($id)
    {
        $agent = Agent::findOrFail($id);
        Auth::guard('agent')->login($agent);
        return redirect(route('agent.dashboard'));
    }

    public function loginInfo($id)
    {
        $user = Agent::findOrFail($id);
        $loginInfo = LoginLogs::where('agent_id',$id)->latest()->paginate(15);
        return view('admin.agent.login_info',compact('loginInfo','user'));
    }


    public function modifyBalance(Request $request)
    {
        $request->validate([
            'wallet_id' => 'required',
            'user_id'   => 'required',
            'amount'    => 'required|gt:0',
            'type'      => 'required|in:1,2'
         ]);
         $user  = Agent::findOrFail($request->user_id);
         $wallet = Wallet::where('id',$request->wallet_id)->where('user_id',$request->user_id)->where('user_type',3)->firstOrFail();

         if($request->type == 1){
            $wallet->balance += $request->amount;
            $wallet->update();

            $trnx              = new Transaction();
            $trnx->trnx        = str_rand();
            $trnx->user_id     = $request->user_id;
            $trnx->user_type   = 3;
            $trnx->currency_id = $wallet->currency->id;
            $trnx->amount      = $request->amount;
            $trnx->charge      = 0;
            $trnx->remark      = 'add_balance';
            $trnx->type        = '+';
            $trnx->details     = trans('Balance added by system');
            $trnx->save();

            $msg = 'Balance has been added';

            @mailSend('add_balance',[
                'amount'=> amount($request->amount,$wallet->currency->type,2),
                'curr'  => $wallet->currency->code,
                'trnx'  => $trnx->trnx,
                'after_balance' => amount($wallet->balance,$wallet->currency->type,2),
                'date_time'  => dateFormat($trnx->created_at)
                ],
            $user);
         }
         if($request->type == 2){
            $wallet->balance -= $request->amount;
            $wallet->update();

            $trnx              = new Transaction();
            $trnx->trnx        = str_rand();
            $trnx->user_id     = $request->user_id;
            $trnx->user_type   = 3;
            $trnx->currency_id = $wallet->currency->id;
            $trnx->amount      = $request->amount;
            $trnx->charge      = 0;
            $trnx->remark      = 'subtract_balance';
            $trnx->type        = '-';
            $trnx->details     = trans('Balance subtracted by system');
            $trnx->save();

            $msg = 'Balance has been subtracted';

            @mailSend('subtract_balance',[
                'amount'=> amount($request->amount,$wallet->currency->type,2),
                'curr'  => $wallet->currency->code,
                'trnx'  => $trnx->trnx,
                'after_balance' => amount($wallet->balance,$wallet->currency->type,2),
                'date_time'  => dateFormat($trnx->created_at)
                ],
            $user);
         }

         return back()->with('success',$msg);
         
    }

    public function fundRequests()
    {
        $search = request('search');
        $requests = FundRequest::when($search,function($q) use($search){
            return $q->where('unique_code',$search);
        })->paginate(15);
        $currencies = Currency::where('status',1)->get();
        return view('admin.agent.fund_requests',compact('requests','currencies'));
    }

    public function fundRequestAccept($id)
    {
        $req = FundRequest::findOrFail($id);
        if($req->status == 1) return back('error','Fund is already accepted');

        $agent  = Agent::findOrFail($req->agent_id);
        $wallet = Wallet::where('user_type',3)->where('user_id',$agent->id)->where('currency_id',$req->currency_id)->first();

        if(!$wallet){
            $wallet = Wallet::create([
                'user_id'  => $agent->id,
                'user_type' => 3,
                'currency_id' => $req->currency_id
            ]);
        }

        $wallet->balance += $req->amount;
        $wallet->update();

        $trnx              = new Transaction();
        $trnx->trnx        = str_rand();
        $trnx->user_id     = $agent->id;
        $trnx->user_type   = 3;
        $trnx->currency_id = $req->currency_id;
        $trnx->amount      = $req->amount;
        $trnx->charge      = 0;
        $trnx->remark      = 'fund_request_accepted';
        $trnx->type        = '+';
        $trnx->details     = trans('Fund request accepted.');
        $trnx->save();

        $req->status = 1;
        $req->update();

        @mailSend('fund_accept',[
            'amount'=> amount($req->amount,$wallet->currency->type,2),
            'curr'  => $wallet->currency->code,
            'trnx'  => $trnx->trnx,
            'u_id'  => $req->unique_code,
            'date_time'  => dateFormat($trnx->created_at)
            ],
        $agent);

        return back()->with('success','Fund request accepted');

    }

    public function fundCollect($id)
    {
        $req = FundRequest::findOrFail($id);
        $req->collect = 1;
        $req->save();
        return back()->with('success','Fund mark as collected.');
    }

    public function fundRequestReject(Request $request,$id)
    {
        $request->validate([
            'reject_reason' => 'required'
        ]);

        $req = FundRequest::findOrFail($id);
        $req->status = 2;
        $req->reject_reason = $request->reject_reason;
        $req->update();

        @mailSend('fund_reject',[
            'amount'=> amount($req->amount,$req->currency->type,2),
            'curr'  => $req->currency->code,
            'u_id'  => $req->unique_code,
            'date_time'  => dateFormat($req->updated_at)
        ],$req->agent);

        return back()->with('success','Fund request rejected.');
    }

    public function agentRequestDetails($agent_id)
    {
        $details = Agent::findOrFail($agent_id);
        return view('admin.agent.details_data',compact('details'));
    }

    public function acceptAgent($agent_id)
    {
        $agent = Agent::findOrFail($agent_id);
        $agent->status = 1;
        $agent->update();

        @email([
            'email'   => $agent->email,
            'name'    => $agent->name,
            'subject' => 'Agent Request Accepted',
            'message' => trans('Your agent request is accepted.')
        ]);

        return back()->with('success','Agent request accepted.');
    }
    public function rejectAgent($agent_id)
    {
        $agent = Agent::findOrFail($agent_id);
        $agent->status = 0;
        $agent->update();

        @email([
            'email'   => $agent->email,
            'name'    => $agent->name,
            'subject' => 'Agent Request Rejected',
            'message' => trans('Your agent request is rejected.')
        ]);

        return back()->with('success','Agent request is rejected.');
    }
}
