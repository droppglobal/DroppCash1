@extends('layouts.frontend')

@section('title')
    @lang('API Documentation')
@endsection


@section('content')
      <!-- Documentation -->
      <section class="documentation-section pt-100 pb-100">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <div class="documentation-wrapper">
                        <div class="documentation-item" id="intro">
                            <div class="documentation-header">
                                <h3 class="title">@lang('API Endpoints & Authentication')</h3>
                               
                            </div>
                            <p>
                               @lang(' All requests to the') {{$gs->title}} @lang('API are sent via the HTTP POST method to one of our API endpoint URLs').

                                <ul>
                                    <li>
                                        @lang('HTTP Request Method') : <span class="badge badge--primary">POST</span>
                                    </li>
                                    <li class="mt-2">
                                        @lang('API Endpoint') : <a href="javascript:void(0)">{{url('payment/process')}}</a>
                                    </li>
                                    <li class="my-2">
                                        @lang('JSON Content-Type') : <span class="badge badge--warning">application/json</span>
                                    </li>
                                </ul>

                                @lang('All calls to the') {{$gs->title}} @lang('API require user authentication and user access key.') <a href="{{url('user/register')}}">@lang('Sign up')</a> @lang('for an account to quickly get started').
                                <br>
                                @lang('Sandbox payment can also be initiated when user set the service mode as test in user dashboard. It will be live when the mode is set as active mode.')
                            </p>
                          
                        </div>

                        <div class="documentation-item" id="api">
                            <div class="documentation-header">
                                <h3 class="title">@lang('API Access Key')</h3>
                            </div>
                            <p>
                               @lang('Register as a user in our system. In your user dashboard you will find the option for API access key.') <br><br>

                               @lang('Example access key : 51a4bd18-5bc1-4eaa-97b0-c09323398883')
                            </p>
                            
                        </div>

                        
                        <div class="documentation-item" id="payment">
                            <div class="documentation-header">
                                <h3 class="title">@lang('Payment Transaction Initiate')</h3>
                            </div>
                            <p>
                                @lang('The following example code enables you to initiate a payment,depending on how you structure it. The perameter details are also below.')
                            </p>

                            <table class="table table-bordered text-center bg--section mb-4">
                                <thead class="bg--base">
                                    <tr>
                                        <th class="text--white">@lang('Param Name')</th>
                                        <th class="text--white">@lang('Param Type')</th>
                                        <th class="text--white">@lang('Description')</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>
                                            @lang('custom')
                                        </td>
                                        <td>
                                            @lang('string')
                                        </td>
                                        <td>
                                            @lang('Identification of your end') <span class="badge badge--danger">@lang('Required')</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            @lang('amount')
                                        </td>
                                        <td>
                                            @lang('decimal')
                                        </td>
                                        <td>
                                            @lang('The amount you want to transact') <span class="badge badge--danger">@lang('Required')</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            @lang('details')
                                        </td>
                                        <td>
                                            @lang('string')
                                        </td>
                                        <td>
                                            @lang('Purchase details') <span class="badge badge--danger">@lang('Required')</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            @lang('web_hook')
                                        </td>
                                        <td>
                                            @lang('string')
                                        </td>
                                        <td>
                                            @lang('Instant payment notification url') <span class="badge badge--danger">@lang('Required')</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            @lang('cancel_url')
                                        </td>
                                        <td>
                                            @lang('string')
                                        </td>
                                        <td>
                                            @lang('Payment cancel return url') <span class="badge badge--danger">@lang('Required')</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            @lang('success_url')
                                        </td>
                                        <td>
                                            @lang('string')
                                        </td>
                                        <td>
                                            @lang('Payment success return url') <span class="badge badge--danger">@lang('Required')</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            @lang('customer_email')
                                        </td>
                                        <td>
                                            @lang('string')
                                        </td>
                                        <td>
                                            @lang('Customer email address') <span class="badge badge--danger">@lang('Required')</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            @lang('access_key')
                                        </td>
                                        <td>
                                            @lang('string')
                                        </td>
                                        <td>
                                            @lang('Send access_key as bearer token with header') <span class="badge badge--danger">@lang('Required')</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                            <pre class="mb-0">
                                <button class="copy-btn" data-clipboard-target="#php-code">@lang('Copy')</button>
    <code class="language-php" id="php-code">
            &lt;?php
                $parameters = [
                    'custom' => 'DFU80XZIKS',
                    'currency_code' => 'USD',
                    'amount' => 280.00,
                    'details' => 'Digital Product',
                    'web_hook' => 'http://yoursite.com/web_hook.php',
                    'cancel_url' => 'http://yoursite.com/cancel_url.php',
                    'success_url' => 'http://yoursite.com/success_url.php',
                    'customer_email' => 'customer@mail.com',
                ];
                
                $url = '{{url('/')}}/payment/process';
                
                $headers = [
                    "Accept: application/json",
                    "Authorization: Bearer access_key",
                ];
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_POSTFIELDS,  http_build_query($parameters));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $response = curl_exec($ch);
                curl_close($ch);
            ?&gt;
    </code>
                            </pre>
                            
                        </div>

                        <div class="documentation-item" id="payment">
                            <div class="documentation-header">
                                <h3 class="title">@lang('Example Response after initiating payment')</h3>
                            </div>
                            <pre class="mb-0">
                                
        <code class="language-php" id="php-code">
        
            //Success Response.
            {
                "code": 200,
                "status": "ok",
                "payment_id": "AIYmQIOAz0GlmsjfhgiOeu304",
                "message": "Your payment has been processed. Please follow the URL to complete the payment.",
                "url":"{{url('/')}}/process-checkout?payment_id=AIYmQIOAz0GlmsjfhgiOeu304"
            }

            //Error Response.
            {
                "code": 401,
                "status": "error",
                "message": "Invalid API credentials."
            }
            
            
        </code>
                            </pre>
                            
                        </div>

                        <div class="documentation-item" id="payment">
                            <div class="documentation-header">
                                <h3 class="title">@lang('Response after successful payment')</h3>
                            </div>
                            <pre class="mb-0">
                                
        <code class="language-php" id="php-code">
        
            //Success Response.
            {
                "code": 200,
                "status": "ok",
                "payment_id": "AIYmQIOAz0GlmsjfhgiOeu304",
                "transaction": "AIYmQIOAz0G",
                "amount": 100.00,
                "charge": 5.00,
                "currency": "USD",
                "custom": "BVSUZ545XCS",
                "date"  : "22-05-2022"
            }

            
        </code>
                            </pre>
                            
                        </div>
                       


                        <div class="documentation-item" id="payment">
                            <div class="documentation-header">
                                <h3 class="title">@lang('Verify Payment')</h3>
                            </div>
                            <p>
                                @lang('You can verify the payment whether it is valid or not. After successful payment transaction you will have the response where you find the Payment ID. With this payment id and your access key you need to make a request to our server to verify the payment. Example code is below. ')<br>  <br>
                                <span>@lang('Payment verify end point : ') <a href="javascript:void(0)">{{url('payment/check-validity')}}</a></span>
                            </p> 

                            <pre class="mb-0">
                                <button class="ver-btn copy-btn" data-clipboard-target="#ver-code">@lang('Copy')</button>
    <code class="language-php" id="ver-code">
            &lt;?php
                $parameters = [
                    'payment_id' => 'AIYmQIOAz0GlmsjfhgiOeu304',
                ]
                
                $url = '{{url('payment/check-validity')}}';
                
                $headers = [
                    "Accept: application/json",
                    "Authorization: Bearer access_key",
                ];
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_POSTFIELDS,  $parameters);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $response = curl_exec($ch);
                curl_close($ch);
            ?&gt;
    </code>
                            </pre>
                            
                        </div>

                        <div class="documentation-item" id="payment">
                            <div class="documentation-header">
                                <h3 class="title">@lang('Validity Response')</h3>
                            </div>
                            <pre class="mb-0">
                                
        <code class="language-php" id="php-code">
        
            //Success Response.
            {
                "code": 200,
                "status": "ok",
                "message": "Transaction is valid",
                
            }

            //Error Response.
            {
                "code": 401,
                "status": "error",
                "message": "Invalid API credentials."
            }

            //or
            {
                "code": 404,
                "status": "error",
                "message": "Transaction not found"
            }

            
        </code>
                            </pre>
                            
                        </div>
<!-- Invoice And Escrow Payments -->

<div class="documentation-item" id="payment">
                            <div class="documentation-header">
                                <h3 class="title">@lang('Escrow Payments')</h3>
                            </div>
                            <p>
                                @lang('Escrow Payment is a good option for marketplace websites.The following code enables you to initiate an Escrow Payment,depending on how you structure it. The perameter details are also below.')
                            </p>

                            <table class="table table-bordered text-center bg--section mb-4">
                                <thead class="bg--base">
                                    <tr>
                                        <th class="text--white">@lang('Param Name')</th>
                                        <th class="text--white">@lang('Param Type')</th>
                                        <th class="text--white">@lang('Description')</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>
                                            @lang('currency_code')
                                        </td>
                                        <td>
                                            @lang('string/currency')
                                        </td>
                                        <td>
                                            @lang('Currency Used For Escrow Payment') <span class="badge badge--danger">@lang('Required')</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            @lang('amount')
                                        </td>
                                        <td>
                                            @lang('decimal')
                                        </td>
                                        <td>
                                            @lang('The amount you want to escrow') <span class="badge badge--danger">@lang('Required')</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            @lang('description')
                                        </td>
                                        <td>
                                            @lang('string')
                                        </td>
                                        <td>
                                            @lang('Description For Escrow') <span class="badge badge--danger">@lang('Required')</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            @lang('pay_charge')
                                        </td>
                                        <td>
                                            @lang('Boolean')
                                        </td>
                                        <td>
                                            @lang('True If You Want To Pay the Charge Yourself, False If Not') <span class="badge badge--danger">@lang('Required')</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            @lang('receiver_email')
                                        </td>
                                        <td>
                                            @lang('string/email')
                                        </td>
                                        <td>
                                            @lang('Receiver Email For Escrow') <span class="badge badge--danger">@lang('Required')</span>
                                        </td>
                                    </tr>
             
                                </tbody>
                            </table>

                            <pre class="mb-0">
                                <button class="copy-btn" data-clipboard-target="#php-code">@lang('Copy')</button>
    <code class="language-php" id="php-code">
            &lt;?php
            $parameters = [
                'currency_code' => 'NGN', // currency code
                'amount' => 20, // amount
                'description' => 'Digital Product', // description
                'pay_charge' => true, // true, false
                'receiver_email' => 'reciever_email'
            ];
            
            $url = '{{url('/')}}/payment/make-escrow';
            
            $headers = [
                "Accept: application/json",
                "Authorization: Bearer api_key",
            ];
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POSTFIELDS,  http_build_query($parameters));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            curl_close($ch);

            var_dump($response);
            ?&gt;
    </code>
                            </pre>
                            
                        </div>

                        <div class="documentation-item" id="payment">
                            <div class="documentation-header">
                                <h3 class="title">@lang('Example Response after initiating Escrow')</h3>
                            </div>
                            <pre class="mb-0">
                                
        <code class="language-php" id="php-code">
        
            //Success Response.
          {
            "code":200,
            "status":"OK",
            "message":"An Escrow Has Been Made to [email]"
        }
          
            //Error Response.
            {
                "code": 401,
                "status": "error",
                "message": "Invalid API credentials."
            }
            
            
        </code>
                            </pre>
                            
                        </div>

                <!-- Invoice -->
                <div class="documentation-item" id="payment">
                            <div class="documentation-header">
                                <h3 class="title">@lang('Invoice Payments')</h3>
                            </div>
                            <p>
                                @lang('Invoice Payments is a good option for an Ecommerce website. With DroppCash you will get everything setup with a good clean minimial checkout page, you just only need to tell api which items are in cart, thats it!. User will get an email after an invoice is intiated')
                            </p>

                            <table class="table table-bordered text-center bg--section mb-4">
                                <thead class="bg--base">
                                    <tr>
                                        <th class="text--white">@lang('Param Name')</th>
                                        <th class="text--white">@lang('Param Type')</th>
                                        <th class="text--white">@lang('Description')</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>
                                            @lang('invoice_to')
                                        </td>
                                        <td>
                                            @lang('string')
                                        </td>
                                        <td>
                                            @lang('Name Of The Person To Which Invoice is Being Intiated') <span class="badge badge--danger">@lang('Required')</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            @lang('currency_code')
                                        </td>
                                        <td>
                                            @lang('string/currency')
                                        </td>
                                        <td>
                                            @lang('The Currency In Which Invoice will be Paid (E.g NGN)') <span class="badge badge--danger">@lang('Required')</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            @lang('amount')
                                        </td>
                                        <td>
                                            @lang('decimal')
                                        </td>
                                        <td>
                                            @lang('Amount Per Each Item, Should Be In Array Respective To Item') <span class="badge badge--danger">@lang('Required')</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            @lang('address')
                                        </td>
                                        <td>
                                            @lang('String/Address')
                                        </td>
                                        <td>
                                            @lang('Address Of The User who is doing checkout') <span class="badge badge--info">@lang('Not Required')</span
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            @lang('item')
                                        </td>
                                        <td>
                                            @lang('array')
                                        </td>
                                        <td>
                                            @lang('Items For Checkout') <span class="badge badge--danger">@lang('Required')</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            @lang('email')
                                        </td>
                                        <td>
                                            @lang('string/email')
                                        </td>
                                        <td>
                                            @lang('User Email') <span class="badge badge--danger">@lang('Required')</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            @lang('invoice_status')
                                        </td>
                                        <td>
                                            @lang('string')
                                        </td>
                                        <td>
                                            @lang('publish for Publishing The Invoice, cancel for Cancelling, by default its draft') <span class="badge badge--info">@lang('Not Required')</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            @lang('allow_escrow')
                                        </td>
                                        <td>
                                            @lang('boolean')
                                        </td>
                                        <td>
                                            @lang('For Allowing Escrow Payments') <span class="badge badge--info">@lang('Not Required')</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            @lang('pay_charge')
                                        </td>
                                        <td>
                                            @lang('boolean')
                                        </td>
                                        <td>
                                            @lang('true/false if you want the pay charge given by sender') <span class="badge badge--info">@lang('Not Required')</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            @lang('success_url')
                                        </td>
                                        <td>
                                            @lang('url')
                                        </td>
                                        <td>
                                            @lang('where to redirect after successful payment') <span class="badge badge--info">@lang('Not Required')</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            @lang('cancel_url')
                                        </td>
                                        <td>
                                            @lang('boolean')
                                        </td>
                                        <td>
                                            @lang('where to go after payment is cancelled') <span class="badge badge--info">@lang('Not Required')</span>
                                        </td>
                                    </tr>
             
                                </tbody>
                            </table>

                            <pre class="mb-0">
                                <button class="copy-btn" data-clipboard-target="#php-code">@lang('Copy')</button>
    <code class="language-php" id="php-code">
            &lt;?php
            $parameters = [
                'invoice_to' => 'Test User Name',
                'currency_code' => 'NGN',
                'amount' => [10,20,30],
                'address' => 'Nigeria',
                'item' => ['baby doll','good dooll','thats'],
                'email' => 'email_here',
                'allow_escrow' => true, //false for not allowing escrow Payments
                'pay_charge' => true, // if you want the charge to be paid by sender
                'invoice_status' => 'publish', //publish,cancel, default: unpublished
                'success_url' => 'yoursite.com/success',
                'cancel_url' => 'yoursite.com/cancel'
                ];
            
            $url = '{{url('/')}}/payment/create-invoice';
            
            $headers = [
                "Accept: application/json",
                "Authorization: Bearer api_key",
            ];
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POSTFIELDS,  http_build_query($parameters));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            curl_close($ch);

            $url = json_decode($response)->url;

            header("location: $url");
            ?&gt;
    </code>
                            </pre>
                            
                        </div>

                        <div class="documentation-item" id="payment">
                            <div class="documentation-header">
                                <h3 class="title">@lang('Example of Response after initiating Escrow')</h3>
                            </div>
                            <pre class="mb-0">
                                
        <code class="language-php" id="php-code">
        
            //Success Response.
            {
                "code":200,
                "status":"OK",
                "message":"Invoice Has Been Made",
                "url":"http://{{url('/')}}/view-invoice/eyJpdiI6InhGd1JOVitrYVpDT0kzV1hEc1pxb2c9PSIsInZhbHVlIjoiVTh4elo0dDcrNTMvWHpsNnVwekdLam42WjV0SGt0M3BWNWhXTnlDbzNvTT0iLCJtYWMiOiIxZmI0NDc3MDI2NjAxMWY0M2Y3YjUwZjRkYThiYjlkZjBkNDI3MTIxY2NlYjNhMmU1N2E2Zjc2MmZlYTJkZTRmIiwidGFnIjoiIn0="
            }
          
            //Error Response.
            {
                "code": 401,
                "status": "error",
                "message": "Invalid API credentials."
            }
            
            
        </code>
                            </pre>
                            
                        </div>
                <!-- Invoice -->
                       


<!-- Invoice And Escrow Payments -->
                         <div class="documentation-item" id="currency">
                            <div class="documentation-header">
                                <h3 class="title">@lang('Supported Currencies')</h3>
                            </div>
                            <p>
                                @lang('Following currencies are currently supported in our system. It may update furthur.')
                            </p>
                            <table class="table table-bordered text-center bg--section mb-0">
                                <thead class="bg--base">
                                    <tr>
                                        <th class="text--white">@lang('Currency Name')</th>
                                        <th class="text--white">@lang('Currency Symbol')</th>
                                        <th class="text--white">@lang('Currency Code')</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($currencies as $item)
                                        <tr>
                                            <td>
                                               {{$item->curr_name}}
                                            </td>
                                            <td>
                                                {{$item->symbol}}
                                            </td>
                                            <td>
                                                {{$item->code}}
                                            </td>
                                        </tr>
                                    @endforeach
                                   
                                </tbody>
                            </table>
                        </div>
                       
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- Documentation -->

@endsection

@push('script')
<script src="{{asset('assets/frontend')}}/js/highlight.min.js"></script>
<script>
    hljs.highlightAll();
</script>

<script src="{{asset('assets/frontend')}}/js/clipboard.min.js"></script>
<script>
    new ClipboardJS('.copy-btn');
    new ClipboardJS('.ver-btn');
</script>
@endpush