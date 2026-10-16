@extends('layouts.payment')
@section('title')
@lang('Confirm Deposit')
@endsection

@section('breadcrumb')
@lang('Confirm Deposit')
@endsection

@section('content')

<div class="container-xl mt-5">
    <div class="row">
        <div class="col-md-12">
            <div class="card shadow-md mb-4 ">
                <div class="card-header text-center">
                    @lang('Payment Details')
                </div>
                <div class="card-body">
                    @if (Session::has('errors'))
                    <div class="col-12 mt-3 pb-0">
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            {{Session::get('errors')}}
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    </div>
                    @endif
                    <form action="{{route('user.deposit.payment')}}" method="POST"
                        id="{{Session::get('deposit_data')['keyword']}}">
                        @csrf
                        <div class="row text-center">
                            <strong>@lang('Total Payment') : {{$currency->symbol.$deposit_data['amount']}}</strong>
                            @if ($charge)
                            <strong>@lang('Total Charge') : {{$currency->symbol.numFormat($charge,2)}}</strong>
                            @endif
                            <strong>@lang('Payment Method') : {{$gateway->name}}</strong>
                            <input type="hidden" name="currency" value="{{$currency->id}}">
                            @include('other.api_payment_load')

                            <div class="text-center my-4">
                                <button type="submit" id="payment__button"
                                    class="btn btn-primary">@lang('Submit')</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@php
$paystack = [];
if(Session::get('deposit_data')['keyword'] == 'paystack'){
$paystack = $gateway->convertAutoData();
}
if(Session::get('deposit_data')['keyword'] == 'mercadopago'){
$paydata = $gateway->convertAutoData();
}
@endphp
@endsection

@push('script')
<script src="https://sdk.mercadopago.com/js/v2"></script>
<script src="https://js.paystack.co/v1/inline.js"></script>
<script>
    'use strict'
   $(document).on('submit','#paystack',function(){
                var total = {{Session::get('deposit_data')['amount']}};
                total = Math.round(total);
                var handler = PaystackPop.setup({
                key: '{{ isset($paystack["key"]) ? $paystack["key"] : '' }}',
                email: '{{ Auth::user()->email }}',
                amount: total * 100,
                currency: "{{ getCurrencyCode() }}",
                ref: ''+Math.floor((Math.random() * 1000000000) + 1),
                    callback: function(response){
                        $('#ref_id').val(response.reference);
                        $('#paystack').attr('id','');
                        $('#payment__button').click();
                    },
                    onClose: function(){
                        window.location.reload();
                    }
                });
                handler.openIframe();
                 return false;
		});
</script>

@endpush