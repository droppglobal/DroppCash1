@extends('layouts.auth3')

@section('title')
    @lang('Confirm Payment')
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
                <h3 class="text-center mt-3 mb-4 text-dark fw-bold">
                    @if($payment->is_escrow) @lang('Review your escrow payment') @else @lang('Review your payment') @endif
                </h3>

                @if(!$isLive)
                <div class="alert alert-warning text-center" role="alert">@lang('TEST MODE - nothing will be charged.')</div>
                @endif

                <div class="mb-3 bg--body border p-2 rounded">
                    <div class="d-flex justify-content-between p-2"><span>@lang('Paying')</span><strong>{{ $merchant->name }}</strong></div>
                    <div class="d-flex justify-content-between p-2"><span>@lang('For')</span><strong>{{ $payment->details }}</strong></div>
                    <div class="d-flex justify-content-between p-2"><span>@lang('Amount')</span><strong>{{ $currency->symbol.amount($payment->amount,$currency->type,2) }}</strong></div>
                    @if($payment->is_escrow && $payment->pay_charge && $fee > 0)
                    <div class="d-flex justify-content-between p-2"><span>@lang('Escrow fee')</span><strong>{{ $currency->symbol.amount($fee,$currency->type,2) }}</strong></div>
                    @endif
                    <div class="d-flex justify-content-between p-2 border-top"><span>@lang('Total to pay')</span><strong class="text--base">{{ $currency->symbol.amount($total,$currency->type,2) }}</strong></div>
                    @if($isLive)
                    <div class="d-flex justify-content-between p-2"><span>@lang('Your wallet balance')</span><strong>{{ $currency->symbol.amount($balance,$currency->type,2) }}</strong></div>
                    @endif
                </div>

                @if($payment->is_escrow)
                <p class="small text-center">@lang('Your money is held safely in escrow. It is only paid to the seller when you release it from your DroppCash account (My Escrows). If something goes wrong you can open a dispute.')</p>
                @endif

                @if(!$enough)
                <div class="alert alert-danger text-center" role="alert">@lang('You do not have enough balance in this currency, or this payment cannot be made from your account.')</div>
                @endif

                <form action="{{route('payment.confirm.submit')}}" method="post" onsubmit="this.querySelector('button').disabled = true;">
                    @csrf
                    <button type="submit" class="cmn--btn w-100" @if(!$enough) disabled @endif>@lang('Confirm payment')</button>
                </form>
                <a href="{{$payment->cancel_url}}" class="text--base mt-3 d-block text-center"><u>@lang('Cancel Payment')</u></a>
            </div>
        </div>
    </div>
</section>
@endsection
