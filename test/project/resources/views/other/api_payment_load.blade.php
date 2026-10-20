@if (in_array($gateway->keyword,['authorize']))
<div class="card border-0 mt-5">
    <div class="card-header">
        <h4>@lang('Card Information')</h4>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-12 mb-2">
                <input type="text" name="card_number" class="form-control" placeholder="@lang('Card Number')">
            </div>
            <div class="col-12 mb-2">
                <input type="text" name="cvc" class="form-control" placeholder="@lang('CVC')">
            </div>
            <div class="col-6">
                <input type="text" name="month" class="form-control" placeholder="@lang('Month')">
            </div>
            <div class="col-6">
                <input type="text" name="year" class="form-control" placeholder="@lang('Year')">
            </div>
        </div>
    </div>
</div>
@endif



@if ($gateway->keyword == 'paystack')
<input type="hidden" id="ref_id" name="ref_id" value="">
@endif


@if ($gateway->keyword == 'mercadopago')
<div class="my-2"></div>
<div id="cardNumber"></div>
<div id="expirationDate"></div>
<div id="securityCode"> </div>


<div class="form-group pb-2">
    <input class="form-control" type="text" id="cardholderName" data-checkout="cardholderName"
        placeholder="{{ __('Card Holder Name') }}" required />
</div>
<div class="form-group py-2">
    <input class="form-control" type="text" id="docNumber" data-checkout="docNumber"
        placeholder="{{ __('Document Number') }}" required />
</div>
<div class="form-group py-2">
    <select id="docType" class="form-control" name="docType" data-checkout="docType" type="text"></select>
</div>


@php
$paydata = $gateway->convertAutoData();
@endphp
<script src="{{asset('assets/frontend')}}/js/jquery-3.6.0.min.js"></script>
<script src="https://sdk.mercadopago.com/js/v2"></script>
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



@if ($gateway->type == 'manual')
<div class="row mt-3">
    <div class="form-group col-lg-12">
        <p>
            @php
            echo $gateway->details;
            @endphp
        </p>
    </div>

    <div class="form-group col-lg-12">
        <label class="my-3">@lang('Please provide your transaction details')</label>
        <textarea name="trx_details" class="form-control" id="" cols="30" rows="10"></textarea>
    </div>
    <input type="hidden" name="type" value="manual">

</div>
@endif