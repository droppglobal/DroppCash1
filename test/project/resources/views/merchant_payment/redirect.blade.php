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

                <div class="col-12">
                    <div class="text-center">
                        <h3>Payment Successfull</h3>

                        <strong>
                            <p class="timeCoundownShow py-5">

                            </p>
                        </strong>

                    </div>
                </div>



                <div class="or">
                    <span>@lang('OR')</span>
                </div>

                <a href="{{$payment->success_url}}" class="text--base text-center mt-3"><u>@lang('Go Success
                        Url')</u></a>
            </div>
        </div>
    </div>
</section>
@endsection

@push('script')

<script>
    let redirect_url = '{{$redirect_url}}';
    
    var delay = 10000; 
     setTimeout(function(){ window.location = redirect_url; }, delay);  
     $('.timeCoundownShow').html('You will be redirected to the payment page in <span id="countdown">10</span> seconds');

        var seconds = document.getElementById('countdown').textContent;
        var countdown = setInterval(function() {
            seconds--;
            document.getElementById('countdown').textContent = seconds;
            if (seconds <= 0) clearInterval(countdown);
        }, 1000);

</script>


@endpush