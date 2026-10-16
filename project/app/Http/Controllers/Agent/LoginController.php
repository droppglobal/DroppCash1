<?php

namespace App\Http\Controllers\Agent;

use App\Models\Agent;
use App\Models\Wallet;
use App\Models\Country;
use App\Models\LoginLogs;
use App\Helpers\MediaHelper;
use Illuminate\Http\Request;
use App\Models\Generalsetting;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
  
   

    public function registerForm()
    {
      $gs = Generalsetting::first();
      if($gs->registration == 0){
        return back()->with('error','Registration is currently off.');
      } 
      $countries = Country::get();
      $info = @loginIp();
      return view('agent.auth.register',compact('countries','info'));
    }

    public function register(Request $request)
    {
      $gs = Generalsetting::first();
      if($gs->registration == 0){
        return back()->with('error','Registration is currently off.');
      } 

      $countries = Country::query(); 
      $name = $countries->pluck('name')->toArray();
      $data = $request->validate([
        'business_name' => 'required',
        'business_address' => 'required',
        'nid' => 'required',
        'nid_photo' => 'required|mimes:jpg,jpeg,png,PNG',
        'name' => 'required',
        'email' => ['required','email','unique:agents',$gs->allowed_email != null ? 'email_domain:'.$request->email:''],
        'phone' => 'required',
        'country' => 'required|in:'.implode(',',$name),
        'address' => 'required',
        'password' => 'required|min:4|confirmed'
      ],['email.email_domain'=>'Allowed emails are only within : '.$gs->allowed_email]);
      
      $currency = $countries->where('name',$request->country)->value('currency_id');
      if($request->nid_photo){
        $data['nid_photo'] = MediaHelper::handleMakeImage($request->nid_photo);
      }
      $data['phone'] = $request->dial_code.$request->phone;
      $data['password'] = bcrypt($request->password);
      $data['email_verified'] = $gs->is_verify == 1 ? 0:1;
      $data['status'] = 2;
      $user = Agent::create($data);
   
      Wallet::create([
        'user_id' => $user->id,
        'user_type' => 3,
        'currency_id' => $currency,
        'balance' => 0
      ]);
      
      session()->flush('success','Registration successful');
      Auth::guard('agent')->attempt(['email' => $request->email, 'password' => $request->password]);

      if($gs->is_verify == 1){
        $user->verify_code = randNum();
        $user->save();
        
        @email([
          'email'   => $user->email,
          'name'    => $user->name,
          'subject' => __('Email Verification Code'),
          'message' => __('Email Verification Code is : '). $user->verify_code,
        ]);

      }
      return redirect(route('agent.dashboard'));
    }
 

    public function showLoginForm()
    {
       return view('agent.auth.login');
    }

    public function login(Request $request)
    {
      $request->validate( [
        'email'   => 'required|email',
        'password' => 'required'
      ]);

      if (Auth::guard('agent')->attempt(['email' => $request->email, 'password' => $request->password], $request->remember)) {
        if(agent()->status == 2){
          Auth::guard('agent')->logout();
          return redirect(route('agent.login'))->with('error','Sorry your agent request is still pendng');
        }
        LoginLogs::create([
          'agent_id' => agent()->id,
          'ip' => @loginIp()->geoplugin_request,
          'country' => @loginIp()->geoplugin_countryName,
          'city' => @loginIp()->geoplugin_city,
        ]);
        return redirect(route('agent.dashboard'));
      }
      return back()->with('error','Sorry! Credentials Mismatch.');
    }

    public function forgotPasswordForm()
    {
      return view('agent.auth.forgot_passowrd');
    }

    public function forgotPasswordSubmit(Request $request)
    {
       $request->validate(['email'=>'required|email']);
       $existAgent = Agent::where('email',$request->email)->first();
       if(!$existAgent){
         return back()->with('error','Sorry! Email doesn\'t exist');
       }

       $existAgent->verify_code = randNum();
       $existAgent->save();

      @email([
        'email'   => $existAgent->email,
        'name'    => $existAgent->name,
        'subject' => __('Password Reset Code'),
        'message' => __('Password reset code is : ').$existAgent->verify_code,
      ]);
      session()->put('email',$existAgent->email);
      return redirect(route('agent.verify.code'))->with('success','A password reset code has been sent to your email.');
    }

    public function verifyCode()
    {
       return view('agent.auth.verify_code');
    }

    public function verifyCodeSubmit(Request $request)
    {
       $request->validate(['code' => 'required|integer']);
       $user = Agent::where('email',session('email'))->first();
       if(!$user){
          return back()->with('error','User doesn\'t exist');
       }

       if($user->verify_code != $request->code){
          return back()->with('error','Invalid verification code.');
       }
       return redirect(route('agent.reset.password'));
    }

    public function resetPassword()
    {
      return view('agent.auth.reset_password');
    }

    public function resetPasswordSubmit(Request $request)
    {
       $request->validate(['password' => 'required|confirmed|min:6']);
       $agent = Agent::where('email',session('email'))->first();
       $agent->password = bcrypt($request->password);
       $agent->update();
       return redirect(route('agent.login'))->with('success','Password reset successful.');
    }

    public function logout()
    {
      $auth = Auth::guard('agent');
      if($auth->user()->two_fa_status == 1){
        $auth->user()->two_fa = 1;
        $auth->user()->save();
      }
      $auth->logout();
      return redirect('/agent/login');
    }

   
}
