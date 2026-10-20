@extends('layouts.user')
@section('title')
@lang('Confirm Deposit')
@endsection

@section('breadcrumb')
@lang('Confirm Deposit')
@endsection

@section('content')

<div class="container-xl">
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
                            @include('other.payment_load')
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
@if(Session::get('deposit_data')['keyword'] == 'mercadopago')


<script>
    const mp = new MercadoPago("{{ $paydata['public_key'] }}");
    
        const cardNumberElement = mp.fields.create('cardNumber', {
            placeholder: "Card Number"
        }).mount('cardNumber');

        const expirationDateElement = mp.fields.create('expirationDate', {
            placeholder: "MM/YY",
        }).mount('expirationDate');

        const securityCodeElement = mp.fields.create('securityCode', {
            placeholder: "Security Code"
        }).mount('securityCode');


        (async function getIdentificationTypes() {
            try {
                const identificationTypes = await mp.getIdentificationTypes();

                const identificationTypeElement = document.getElementById('docType');

                createSelectOptions(identificationTypeElement, identificationTypes);

            } catch (e) {
                return console.error('Error getting identificationTypes: ', e);
            }
        })();

        function createSelectOptions(elem, options, labelsAndKeys = {
            label: "name",
            value: "id"
        }) {

            const {
                label,
                value
            } = labelsAndKeys;

            //heem.options.length = 0;

            const tempOptions = document.createDocumentFragment();

            options.forEach(option => {
                const optValue = option[value];
                const optLabel = option[label];

                const opt = document.createElement('option');
                opt.value = optValue;
                opt.textContent = optLabel;


                tempOptions.appendChild(opt);
            });

            elem.appendChild(tempOptions);
        }
        cardNumberElement.on('binChange', getPaymentMethods);
        async function getPaymentMethods(data) {
            const {
                bin
            } = data
            const {
                results
            } = await mp.getPaymentMethods({
                bin
            });
            console.log(results);
            return results[0];
        }

        async function getIssuers(paymentMethodId, bin) {
            const issuears = await mp.getIssuers({
                paymentMethodId,
                bin
            });
            console.log(issuers)
            return issuers;
        };

        async function getInstallments(paymentMethodId, bin) {
            const installments = await mp.getInstallments({
                amount: document.getElementById('transactionAmount').value,
                bin,
                paymentTypeId: 'credit_card'
            });

        };

        async function createCardToken() {
            const token = await mp.fields.createCardToken({
                cardholderName,
                identificationType,
                identificationNumber,
            });

        }
        let doSubmit = false;
        $(document).on('submit', '#mercadopago', function(e) {
            getCardToken();
            e.preventDefault();
        });
        async function getCardToken() {
            if (!doSubmit) {
                let $form = document.getElementById('mercadopago');
                const token = await mp.fields.createCardToken({
                    cardholderName: document.getElementById('cardholderName').value,
                    identificationType: document.getElementById('docType').value,
                    identificationNumber: document.getElementById('docNumber').value,
                })
                setCardTokenAndPay(token.id)
            }
        };

        function setCardTokenAndPay(token) {
            let form = document.getElementById('mercadopago');
            let card = document.createElement('input');
            card.setAttribute('name', 'token');
            card.setAttribute('type', 'hidden');
            card.setAttribute('value', token);
            form.appendChild(card);
            doSubmit = true;
            form.submit();
        };
</script>

@endif
@endpush