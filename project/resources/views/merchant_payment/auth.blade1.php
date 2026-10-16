@extends('layouts.auth3')

@section('title')
    @lang('Payment Checkout')
@endsection

@section('content')
<section class="checkout-section-wrapper">
    <div class="container p-0">
        <div class="checkout-wrapper">
            <div class="checkout-logo">
                <a href="{{url('/')}}">
                    <img src="{{getPhoto($gs->header_logo)}}" alt="logo">
                </a>
            </div>
            <div class="checkout-body">
                <form class="row gy-3" action="{{route('process.payment.authenticate')}}" method="post">
                    @csrf
                    <div class="col-12">
                    <h3 class="text-center mt-3 mb-4 text-dark fw-bold">Pay Via Your DroppCash Wallet</h3>    
                    <div
                            class="d-flex flex-wrap justify-content-evenly justify-content-sm-between mb-3 bg--body border p-2 rounded">
                            <h4 class="m-0 p-2">Amount</h4>
                            <h4 class="m-0 text--base p-2">{{$payment->currency->symbol.amount($payment->amount,$payment->currency->type,2)}}</h4>
                        </div>
                    </div>
               
@auth
<input type="hidden" id="email" name="email" value="{{Auth::user()->email}}"/>

<div class="col-sm-12 form-group">
                        <label for="password" class="form--label mb-1">@lang('Password')</label>
                        <input type="password" name="password" id="password" class="form-control form--control"
                            placeholder="@lang('Enter Your Password For Confirmation')" required>
                    </div>
@endauth
@guest()
<div class="col-sm-12 form-group">
                        <label for="email" class="form--label mb-1">@lang('Email')</label>
                        <input type="text" id="email" name="email" class="form-control form--control"
                            placeholder="@lang('Email')" required>
                    </div>
                    <div class="col-sm-12 form-group">
                        <label for="password" class="form--label mb-1">@lang('Password')</label>
                        <input type="password" name="password" id="password" class="form-control form--control"
                            placeholder="@lang('Password')" required>
                    </div>
@endguest


                    <div class="col-12">
                        <button type="submit" class="cmn--btn w-100">@lang('Confirm Payment')</button>
                    </div>
                </form>
                <div class="or">
                    <span>@lang('OR')</span>
                </div>

@guest
                <a href="{{route('user.register')}}" class="cmn--btn bg--section text--title w-100">@lang('Create Account')</a>

@endguest
                <a href="{{$payment->cancel_url}}" class="text--base mt-3"><u>@lang('Cancel Payment')</u></a>
            </div>
        </div>
    </div>
</section>
@endsection
