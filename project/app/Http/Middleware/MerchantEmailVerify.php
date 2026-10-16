<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Generalsetting;

class MerchantEmailVerify
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    { 
        $is_verify = Generalsetting::value('is_verify');
        if($is_verify){
            // website: session login on the merchant guard
            // mobile app: Sanctum token that belongs to a merchant (apps have no session)
            $user = auth()->guard('merchant')->user();
            if(!$user && $request->bearerToken()){
                $tokenUser = $request->user('sanctum');
                if($tokenUser instanceof \App\Models\Merchant){
                    $user = $tokenUser;
                }
            }

            if($user){
                if($user->email_verified == 0){
                    if($request->expectsJson()){
                        $response = [
                            'success'    => true,
                            'message'    => 'Please verify your email.',
                            'response'   => ['email_verify' => true],
                        ];

                        return response()->json($response);
                    }
                    return redirect()->route('merchant.verify.email');
                }
                return $next($request);
            }

            if($request->expectsJson()){
                return response()->json([
                    'success'  => false,
                    'message'  => 'Unauthenticated.',
                    'response' => ['unauthenticated' => true],
                ], 401);
            }
            return redirect(route('merchant.login'));
        }
        return $next($request);
    }
}
