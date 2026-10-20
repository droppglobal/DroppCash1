
@if (in_array($gateway->keyword,['authorize']))
<div class="card border-0 mt-5">
    <div class="card-header">
        <h4>@lang('Card Information')</h4>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-4">
                <input type="text" name="card_number" class="form-control" placeholder="@lang('Card Number')">
            </div>
            <div class="col-4">
                <input type="text" name="cvc" class="form-control" placeholder="@lang('CVC')">
            </div>
            <div class="col-2">
                <input type="text" name="month" class="form-control" placeholder="@lang('Month')">
            </div>
            <div class="col-2">
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



@endif


@if ($gateway->type == 'manual')
<div class="row mt-3">
  <div class="form-group col-lg-12">
    <h3>@lang('Deposit Instruction')</h3>
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
  <input type="hidden" name="type"  value="manual">

</div>
@endif