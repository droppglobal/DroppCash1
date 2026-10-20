<?php

namespace App\Http\Controllers\Agent;

use Illuminate\Http\Request;
use App\Models\Generalsetting;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class AuthorizationController extends Controller
{
  
    public function twoStep()
    {
      $user = agent();
      if($user->two_fa != 1){
        return redirect(route('agent.dashboard'));
      }
      return view('agent.twostep.otp_code');
    }

    public function twoStepVerify(Request $request)
    {
        $request->validate(['code'=>'required']);
        $user = agent();
        if($request->code != $user->two_fa_code){
            return back()->with('error','Invalid OTP');
        }
        $user->two_fa = 0;
        $user->save();
        return redirect(route('agent.dashboard'));
    }

    public function twoStepResendCode()
    {
        $code = randNum();
        $user = agent();
        $user->two_fa_code = $code;
        $user->update();
        sendSMS($user->phone,'Your two step authentication OTP is : '.$code,Generalsetting::value('contact_no'));
        return back()->with('success','OTP code is sent to your phone.');
    }


    public function verifyEmail()
    {
      $user = agent();
      if($user->email_verified == 1){
        return redirect(route('agent.dashboard'));
      }
      return view('agent.auth.email_verify_code');
    }

    public function verifyEmailSubmit(Request $request)
    {
        $request->validate(['code' => 'required|integer']);
        $user = agent();
        if(!$user){
          return back()->with('error','User doesn\'t exist');
        }

        if($user->verify_code != $request->code){
          return back()->with('error','Invalid verification code.');
        }

        $user->verify_code = null;
        $user->email_verified = 1;
        $user->save();
        return redirect(route('agent.dashboard'));

    }
    
    public function verifyEmailResendCode()
    {
        $code = randNum();
        $user = agent();
        $user->verify_code = $code;
        $user->update();
       
        @email([
          'email'   => $user->email,
          'name'    => $user->name,
          'subject' => __('Email Verification Code'),
          'message' => __('Email Verification Code is : '). $user->verify_code,
        ]);

        return back()->with('success','Verify code resent to your email.');
    }
}
