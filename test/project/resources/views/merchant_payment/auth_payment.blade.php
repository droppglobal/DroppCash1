@extends('layouts.payment')

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
                        <div class="d-flex">
                            <strong>Payment To</strong>
                            <strong class="ms-auto">{{$payment->merchant->business_name}}</strong>
                        </div>
                        <div class="d-flex">
                            <strong>Amount</strong>
                            <strong class="ms-auto">
                                {{$payment->currency->symbol.amount($payment->amount,$payment->currency->type,2)}}
                            </strong>
                        </div>
                        <div class="d-flex  justify-content-between ">

                            <strong>Details : </strong>
                            <strong>
                                {{Str::limit($payment->details,30, '...')}}
                            </strong>

                        </div>
                    </div>

                </form>
           
                <a href="{{route('payment.confirm')}}" class="cmn--btn bg--section text--title w-100">@lang('Payment
                    Confirm')</a>
                <a href="{{$payment->cancel_url}}" class="text--base mt-3"><u>@lang('Cancel Payment')</u></a>
            </div>
        </div>
    </div>
</section>
@endsection
