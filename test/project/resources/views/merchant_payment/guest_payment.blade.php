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
                <form class="row gy-3" action="{{route('process.payment.guest.sumbit')}}" method="post">
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

                        
                        <div class="row mt-3">
                            <div class="form-group">
                                <div class="form-label">@lang('Select Gateway')</div>
                                <select class="form-control" name="gateway" id="gateway">
                                    <option value="" disabled selected>@lang('Select')</option>
                                    @foreach ($methods as $method)
                                    <option value="{{$method->id}}" data-keyword="{{$method->keyword}}">
                                        {{$method->name}}</option>
                                    @endforeach
                                </select>
                            </div>

                            

                            <div id="load_payment">
                            </div>
                        </div>
                    </div>
                    <button type="submit" id="payment__button"
                        class="cmn--btn bg--section text--title w-100">@lang('Payment
                        Confirm')</button>
                </form>

                <div class="or">
                    <span>@lang('OR')</span>
                </div>

                <a href="{{$payment->cancel_url}}" class="text--base mt-3"><u>@lang('Cancel Payment')</u></a>
            </div>
        </div>
    </div>
</section>
@endsection
@php
$paystack = App\Models\PaymentGateway::where('keyword','paystack')->first()->convertAutoData();
@endphp
@push('script')
<script src="https://sdk.mercadopago.com/js/v2"></script>
<script src="https://js.paystack.co/v1/inline.js"></script>
<script>
   $(document).on('submit','#paystack',function(){
                var total = {{amount($payment->amount,$payment->currency->type,2)}};
                total = Math.round(total);
                var handler = PaystackPop.setup({
                key: '{{ isset($paystack["key"]) ? $paystack["key"] : '' }}',
                email: '{{ $payment->customer_email }}',
                amount: total * 100,
                currency: "{{ $payment->currency->code }}",
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



<script>
    $(document).on('change','#gateway',function(){
        let gateway = $(this).val();
        let keyword = $(this).find(':selected').data('keyword');
       $('form').attr('id',keyword);

        $()
        $.ajax({
            url: "{{route('load.payment')}}",
            type: "GET",
            data: {
                _token: "{{csrf_token()}}",
                payment_id: gateway
            },
            success: function(response) {
                $('#load_payment').html(response);
            }
        });
    })
</script>


@endpush