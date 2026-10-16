@extends('layouts.user')

@section('title')
   @lang('Invoice Payment')
@endsection

@section('content')
    <div class="container-xl">
        <div class="row justify-content-center">
            <div class="col-md-7">
                <div class="card">
                    <h2 class="card-header">
                        @lang('Escrow Has Been Made')
                    </h2>

                    <div class="card-body">
                    <p>Escrow has been made for this Invoice, do release the payment once you confirm the order</p>

                    <a href="https://droppcash.app/user" class="btn btn-primary"><i class="fa fa-arrow-left"></i>&nbsp;Go Back</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection