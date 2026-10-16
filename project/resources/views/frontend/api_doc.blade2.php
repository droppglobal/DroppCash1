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

<div class="documentation-item"><div class="documentation-header"><h3 class="title">@lang('On this page')</h3></div><p><a href="#overview">@lang('Overview')</a> &middot; <a href="#authentication">@lang('Authentication & Requests')</a> &middot; <a href="#test-mode">@lang('Test & Live Mode')</a> &middot; <a href="#payment">@lang('Start a Payment')</a> &middot; <a href="#result">@lang('Payment Result')</a> &middot; <a href="#verify">@lang('Confirm a Payment')</a> &middot; <a href="#escrow">@lang('Create an Escrow')</a> &middot; <a href="#invoice">@lang('Create an Invoice')</a> &middot; <a href="#errors">@lang('Errors')</a> &middot; <a href="#security">@lang('Security')</a> &middot; <a href="#currencies">@lang('Currencies')</a></p></div>

<div class="documentation-item" id="overview">
<div class="documentation-header"><h3 class="title">@lang('Overview')</h3></div>

<p>The {{$gs->title}} API lets your website or app accept payments, create escrows and send invoices. Everything below is a plain HTTPS request, so you can use any language.</p>
<p><strong>How a payment works</strong></p>
<ol>
<li>Your server calls <code>POST /payment/process</code> with the amount, currency and your own order reference. You get back a <code>url</code>.</li>
<li>You send the buyer to that <code>url</code>. The buyer signs in with their {{$gs->title}} account and confirms the payment.</li>
<li>{{$gs->title}} sends the result to your <code>web_hook</code> and redirects the buyer to your <code>success_url</code>.</li>
<li>Your server calls <code>GET /payment/check-validity</code> to confirm the payment is real, then delivers the goods.</li>
</ol>
<div class="alert alert-warning mb-4" role="alert"><strong>Step 4 is not optional.</strong> The webhook and the redirect are not signed, so anyone could fake them. Always confirm with check-validity before you deliver anything.</div>

</div>

<div class="documentation-item" id="authentication">
<div class="documentation-header"><h3 class="title">@lang('Authentication & Requests')</h3></div>

<p><strong>1. Get your API access key.</strong> <a href="{{url('user/register')}}">Sign up</a> or log in, then open the API key page in your user dashboard. Use the key only on your server and never put it in a web page or mobile app.</p>
<p><strong>2. Send it as a Bearer token</strong> in the <code>Authorization</code> header of every request:</p>
<pre class="mb-3"><button class="copy-btn" data-clipboard-target="#code-1">@lang('Copy')</button><code class="language-http" id="code-1">Authorization: Bearer YOUR_ACCESS_KEY
Accept: application/json
Content-Type: application/json
</code></pre>

<p><strong>3. Send your data as JSON</strong> (recommended) or as normal form fields. Both work.</p>
<div style="overflow-x:auto"><table class="table table-bordered text-center bg--section mb-4"><thead class="bg--base"><tr><th class="text--white">@lang('Item')</th><th class="text--white">@lang('Value')</th></tr></thead><tbody>
<tr><td>Base URL</td><td><code>{{ url('/') }}</code></td></tr>
<tr><td>Start a payment</td><td><span class="badge badge--primary">POST</span> <code>/payment/process</code></td></tr>
<tr><td>Confirm a payment</td><td><span class="badge badge--warning">GET</span> <code>/payment/check-validity</code></td></tr>
<tr><td>Create an escrow</td><td><span class="badge badge--primary">POST</span> <code>/payment/make-escrow</code></td></tr>
<tr><td>Create an invoice</td><td><span class="badge badge--primary">POST</span> <code>/payment/create-invoice</code></td></tr>
</tbody></table></div>

<div class="alert alert-info mb-4" role="alert"><strong>Always read the <code>code</code> field in the response body.</strong> The HTTP status is 200 even when the request fails. A success has <code>"code": 200</code>; anything else is an error (see <a href="#errors">Errors</a>).</div>

</div>

<div class="documentation-item" id="test-mode">
<div class="documentation-header"><h3 class="title">@lang('Test Mode & Live Mode')</h3></div>

<p>In your user dashboard you can set the API service mode to <strong>test</strong> or <strong>active</strong> (live).</p>
<ul>
<li><strong>Test mode:</strong> no money moves and no wallet balance is needed. The checkout, webhook and redirect all work exactly as in live mode, so you can test your whole integration.</li>
<li><strong>Active (live) mode:</strong> the buyer's wallet is charged and your wallet is credited.</li>
</ul>
<p>In both modes the buyer signs in with a {{$gs->title}} account during checkout, so create a second test account to play the buyer. In live mode that account also needs a wallet in the payment currency with enough balance.</p>
</div>

<div class="documentation-item" id="payment">
<div class="documentation-header"><h3 class="title">@lang('Start a Payment')</h3></div>

<p><span class="badge badge--primary">POST</span> <code>{{ url('payment/process') }}</code></p>
<div style="overflow-x:auto"><table class="table table-bordered text-center bg--section mb-4"><thead class="bg--base"><tr><th class="text--white">@lang('Param')</th><th class="text--white">@lang('Type')</th><th class="text--white">@lang('Description')</th></tr></thead><tbody>
<tr><td><code>currency_code</code></td><td>string, max 4</td><td>Currency to charge, e.g. <code>USD</code>, <code>NGN</code>, <code>BTC</code>. Must be one of the <a href="#currencies">supported currencies</a>. <span class="badge badge--danger">Required</span></td></tr>
<tr><td><code>amount</code></td><td>number</td><td>Amount to charge, greater than 0, with a decimal point (e.g. <code>0.02</code>). Crypto currencies allow up to 8 decimal places; other currencies use 2. <span class="badge badge--danger">Required</span></td></tr>
<tr><td><code>custom</code></td><td>string, max 20</td><td>Your own order or customer reference. It is returned to you after payment so you can match it to your order. <span class="badge badge--danger">Required</span></td></tr>
<tr><td><code>details</code></td><td>string, max 255</td><td>What the buyer is paying for. <span class="badge badge--danger">Required</span></td></tr>
<tr><td><code>customer_email</code></td><td>email, max 30</td><td>Buyer email address. <span class="badge badge--danger">Required</span></td></tr>
<tr><td><code>web_hook</code></td><td>URL</td><td>Your server URL that receives the payment result (server to server). <span class="badge badge--danger">Required</span></td></tr>
<tr><td><code>success_url</code></td><td>URL</td><td>Where the buyer is sent after paying. <span class="badge badge--danger">Required</span></td></tr>
<tr><td><code>cancel_url</code></td><td>URL</td><td>Where the buyer is sent if they cancel. <span class="badge badge--danger">Required</span></td></tr>
</tbody></table></div>

<p>The access key is not a body parameter. Send it in the <code>Authorization: Bearer</code> header.</p>
<p class="mb-1 mt-3"><strong>cURL</strong></p>
<pre class="mb-3"><button class="copy-btn" data-clipboard-target="#code-2">@lang('Copy')</button><code class="language-bash" id="code-2">curl -X POST {{ url('payment/process') }} \
  -H "Authorization: Bearer YOUR_ACCESS_KEY" \
  -H "Content-Type: application/json" \
  -d '{
  "custom": "ORDER-1001",
  "currency_code": "USD",
  "amount": 280.00,
  "details": "Digital Product",
  "web_hook": "https://yoursite.com/web_hook.php",
  "success_url": "https://yoursite.com/success",
  "cancel_url": "https://yoursite.com/cancel",
  "customer_email": "customer@mail.com"
}'
</code></pre>

<p class="mb-1 mt-3"><strong>PHP</strong></p>
<pre class="mb-3"><button class="copy-btn" data-clipboard-target="#code-3">@lang('Copy')</button><code class="language-php" id="code-3">&lt;?php
$parameters = [
    'custom'         =&gt; 'ORDER-1001',
    'currency_code'  =&gt; 'USD',
    'amount'         =&gt; 280.00,
    'details'        =&gt; 'Digital Product',
    'web_hook'       =&gt; 'https://yoursite.com/web_hook.php',
    'success_url'    =&gt; 'https://yoursite.com/success',
    'cancel_url'     =&gt; 'https://yoursite.com/cancel',
    'customer_email' =&gt; 'customer@mail.com',
];

$ch = curl_init('{{ url('payment/process') }}');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Content-Type: application/json',
    'Authorization: Bearer YOUR_ACCESS_KEY',
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = json_decode(curl_exec($ch), true);
curl_close($ch);

if (($response['code'] ?? 0) === 200) {
    header('Location: ' . $response['url']);   // send the buyer to checkout
    exit;
}
echo 'Error: ' . json_encode($response['message'] ?? 'Unknown error');
</code></pre>

<p class="mb-1 mt-3"><strong>Node.js</strong></p>
<pre class="mb-3"><button class="copy-btn" data-clipboard-target="#code-4">@lang('Copy')</button><code class="language-javascript" id="code-4">// Node.js 18+ (run this on your server, never in the browser)
const res = await fetch('{{ url('payment/process') }}', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_ACCESS_KEY',
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
  body: JSON.stringify({
    custom: 'ORDER-1001',
    currency_code: 'USD',
    amount: 280.0,
    details: 'Digital Product',
    web_hook: 'https://yoursite.com/web_hook',
    success_url: 'https://yoursite.com/success',
    cancel_url: 'https://yoursite.com/cancel',
    customer_email: 'customer@mail.com',
  }),
});
const data = await res.json();
if (data.code === 200) {
  // redirect the buyer to data.url
} else {
  console.error(data.message);
}
</code></pre>

<p class="mb-1 mt-3"><strong>Python</strong></p>
<pre class="mb-3"><button class="copy-btn" data-clipboard-target="#code-5">@lang('Copy')</button><code class="language-python" id="code-5">import requests

r = requests.post(
    "{{ url('payment/process') }}",
    headers={"Authorization": "Bearer YOUR_ACCESS_KEY"},
    json={
        "custom": "ORDER-1001",
        "currency_code": "USD",
        "amount": 280.00,
        "details": "Digital Product",
        "web_hook": "https://yoursite.com/web_hook",
        "success_url": "https://yoursite.com/success",
        "cancel_url": "https://yoursite.com/cancel",
        "customer_email": "customer@mail.com",
    },
    timeout=30,
)
data = r.json()
if data["code"] == 200:
    checkout_url = data["url"]   # redirect the buyer here
else:
    print(data["message"])
</code></pre>

<p><strong>Success response</strong></p>
<pre class="mb-3"><button class="copy-btn" data-clipboard-target="#code-6">@lang('Copy')</button><code class="language-json" id="code-6">{
  "code": 200,
  "status": "OK",
  "payment_id": "AIYmQIOAz0GlmsjfhgiOeu304",
  "message": "Your payment has been processed. Please follow the URL to complete the payment.",
  "url": "https://droppcash.app/process-checkout?payment_id=AIYmQIOAz0GlmsjfhgiOeu304"
}
</code></pre>

<p>Save the <code>payment_id</code> with your order and redirect the buyer to <code>url</code>.</p>
</div>

<div class="documentation-item" id="result">
<div class="documentation-header"><h3 class="title">@lang('Payment Result (Webhook & Redirect)')</h3></div>

<p>When the buyer pays, {{$gs->title}} does two things with the same data:</p>
<ul>
<li><strong>Webhook:</strong> a form-encoded <code>POST</code> to your <code>web_hook</code> URL. Respond with any 2xx status. The reply is not read and the call is <strong>not retried</strong>.</li>
<li><strong>Redirect:</strong> the buyer is sent to your <code>success_url</code> with the same fields added as a query string (<code>?code=200&amp;status=OK&amp;payment_id=...</code>).</li>
</ul>
<div style="overflow-x:auto"><table class="table table-bordered text-center bg--section mb-4"><thead class="bg--base"><tr><th class="text--white">@lang('Field')</th><th class="text--white">@lang('Description')</th></tr></thead><tbody>
<tr><td><code>code</code>, <code>status</code></td><td><code>200</code> and <code>OK</code> for a completed payment</td></tr>
<tr><td><code>payment_id</code></td><td>The ID returned when you started the payment</td></tr>
<tr><td><code>transaction</code></td><td>The DroppCash transaction reference</td></tr>
<tr><td><code>amount</code></td><td>Amount paid (up to 3 decimal places)</td></tr>
<tr><td><code>charge</code></td><td>The fee applied to this payment</td></tr>
<tr><td><code>currency</code></td><td>Currency code</td></tr>
<tr><td><code>custom</code></td><td>Your reference from the request</td></tr>
<tr><td><code>date</code></td><td>Payment date, <code>DD-MM-YYYY</code></td></tr>
</tbody></table></div>

<p class="mb-1 mt-3"><strong>PHP webhook example</strong></p>
<pre class="mb-3"><button class="copy-btn" data-clipboard-target="#code-7">@lang('Copy')</button><code class="language-php" id="code-7">&lt;?php
// web_hook.php - receives the payment result from DroppCash
$paymentId = $_POST['payment_id'] ?? '';
$custom    = $_POST['custom'] ?? '';

// 1. Never trust this request on its own. Ask DroppCash if the payment is real
//    (see "Confirm a Payment" below).
// 2. Match $custom and the amount with YOUR order record.
// 3. Make sure you only fulfil each order once - the webhook can arrive
//    together with the buyer's redirect.

http_response_code(200);
</code></pre>

<p class="mb-1 mt-3"><strong>Node.js webhook example</strong></p>
<pre class="mb-3"><button class="copy-btn" data-clipboard-target="#code-8">@lang('Copy')</button><code class="language-javascript" id="code-8">// Node.js (Express) webhook example
app.use(express.urlencoded({ extended: false }));

app.post('/web_hook', async (req, res) =&gt; {
  const { payment_id, custom } = req.body;
  // 1. confirm with check-validity (next section)
  // 2. match custom and amount with your own order
  // 3. fulfil the order once only
  res.sendStatus(200);
});
</code></pre>

<div class="alert alert-info mb-4" role="alert">The webhook is sent once and the reply is ignored. If your server is down at that moment you will not get it again. For that reason also run the check-validity call when the buyer lands on your <code>success_url</code>.</div>

</div>

<div class="documentation-item" id="verify">
<div class="documentation-header"><h3 class="title">@lang('Confirm a Payment')</h3></div>

<p><span class="badge badge--warning">GET</span> <code>{{ url('payment/check-validity') }}?payment_id=PAYMENT_ID</code></p>
<p>Send the <code>payment_id</code> as a query parameter and your access key in the <code>Authorization</code> header. This must be a <strong>GET</strong> request.</p>
<p class="mb-1 mt-3"><strong>cURL</strong></p>
<pre class="mb-3"><button class="copy-btn" data-clipboard-target="#code-9">@lang('Copy')</button><code class="language-bash" id="code-9">curl -G {{ url('payment/check-validity') }} \
  -H "Authorization: Bearer YOUR_ACCESS_KEY" \
  --data-urlencode "payment_id=AIYmQIOAz0GlmsjfhgiOeu304"
</code></pre>

<p class="mb-1 mt-3"><strong>PHP</strong></p>
<pre class="mb-3"><button class="copy-btn" data-clipboard-target="#code-10">@lang('Copy')</button><code class="language-php" id="code-10">&lt;?php
$paymentId = 'AIYmQIOAz0GlmsjfhgiOeu304';

$ch = curl_init('{{ url('payment/check-validity') }}?payment_id=' . urlencode($paymentId));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Authorization: Bearer YOUR_ACCESS_KEY',
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = json_decode(curl_exec($ch), true);
curl_close($ch);

if (($response['code'] ?? 0) === 200) {
    // payment is completed - safe to deliver the order
}
</code></pre>

<p class="mb-1 mt-3"><strong>Node.js</strong></p>
<pre class="mb-3"><button class="copy-btn" data-clipboard-target="#code-11">@lang('Copy')</button><code class="language-javascript" id="code-11">const url = new URL('{{ url('payment/check-validity') }}');
url.searchParams.set('payment_id', 'AIYmQIOAz0GlmsjfhgiOeu304');

const res = await fetch(url, {
  headers: { 'Authorization': 'Bearer YOUR_ACCESS_KEY', 'Accept': 'application/json' },
});
const data = await res.json();
if (data.code === 200) {
  // payment is completed - safe to deliver the order
}
</code></pre>

<p class="mb-1 mt-3"><strong>Python</strong></p>
<pre class="mb-3"><button class="copy-btn" data-clipboard-target="#code-12">@lang('Copy')</button><code class="language-python" id="code-12">import requests

r = requests.get(
    "{{ url('payment/check-validity') }}",
    params={"payment_id": "AIYmQIOAz0GlmsjfhgiOeu304"},
    headers={"Authorization": "Bearer YOUR_ACCESS_KEY"},
    timeout=30,
)
if r.json()["code"] == 200:
    pass  # payment is completed - safe to deliver the order
</code></pre>

<p><strong>Responses</strong></p>
<pre class="mb-3"><button class="copy-btn" data-clipboard-target="#code-13">@lang('Copy')</button><code class="language-json" id="code-13">// Payment completed
{ "code": 200, "status": "OK", "message": "Transaction is valid" }

// Payment not found or not completed yet
{ "code": 404, "status": "error", "message": "Transaction Not Found." }

// Wrong or missing access key
{ "code": 401, "status": "error", "message": "Invalid API credentials." }
</code></pre>

<div class="alert alert-warning mb-4" role="alert">This call only tells you the payment is completed. It does not return the amount, so also compare the <code>custom</code> reference and the amount from the webhook with your own order record.</div>

</div>

<div class="documentation-item" id="escrow">
<div class="documentation-header"><h3 class="title">@lang('Create an Escrow')</h3></div>

<p>Escrow holds money safely until it is released to the receiver. It suits marketplaces. <strong>The money comes from the wallet of the account that owns the access key</strong>, in the currency you choose, and is taken immediately.</p>
<p><span class="badge badge--primary">POST</span> <code>{{ url('payment/make-escrow') }}</code></p>
<div style="overflow-x:auto"><table class="table table-bordered text-center bg--section mb-4"><thead class="bg--base"><tr><th class="text--white">@lang('Param')</th><th class="text--white">@lang('Type')</th><th class="text--white">@lang('Description')</th></tr></thead><tbody>
<tr><td><code>receiver_email</code></td><td>email</td><td>Email of the person who will receive the escrow. They must already have a {{$gs->title}} account. <span class="badge badge--danger">Required</span></td></tr>
<tr><td><code>currency_code</code></td><td>string</td><td>Currency of the escrow, e.g. <code>NGN</code>. You need a wallet in this currency. <span class="badge badge--danger">Required</span></td></tr>
<tr><td><code>amount</code></td><td>number</td><td>Amount to hold, greater than 0. <span class="badge badge--danger">Required</span></td></tr>
<tr><td><code>description</code></td><td>string</td><td>What the escrow is for. <span class="badge badge--danger">Required</span></td></tr>
<tr><td><code>pay_charge</code></td><td>boolean</td><td><code>true</code>: you pay the escrow charge on top of the amount. <code>false</code>: the charge is taken from the amount when it is released. <span class="badge badge--danger">Required</span></td></tr>
</tbody></table></div>

<p class="mb-1 mt-3"><strong>cURL</strong></p>
<pre class="mb-3"><button class="copy-btn" data-clipboard-target="#code-14">@lang('Copy')</button><code class="language-bash" id="code-14">curl -X POST {{ url('payment/make-escrow') }} \
  -H "Authorization: Bearer YOUR_ACCESS_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "receiver_email": "seller@mail.com",
    "currency_code": "NGN",
    "amount": 20,
    "description": "Digital Product",
    "pay_charge": true
  }'
</code></pre>

<p class="mb-1 mt-3"><strong>PHP</strong></p>
<pre class="mb-3"><button class="copy-btn" data-clipboard-target="#code-15">@lang('Copy')</button><code class="language-php" id="code-15">&lt;?php
$parameters = [
    'receiver_email' =&gt; 'seller@mail.com',
    'currency_code'  =&gt; 'NGN',
    'amount'         =&gt; 20,
    'description'    =&gt; 'Digital Product',
    'pay_charge'     =&gt; true,
];

$ch = curl_init('{{ url('payment/make-escrow') }}');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Content-Type: application/json',
    'Authorization: Bearer YOUR_ACCESS_KEY',
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = json_decode(curl_exec($ch), true);
curl_close($ch);
</code></pre>

<p><strong>Responses</strong></p>
<pre class="mb-3"><button class="copy-btn" data-clipboard-target="#code-16">@lang('Copy')</button><code class="language-json" id="code-16">// Success
{ "code": 200, "status": "OK", "message": "An Escrow Has Been Made to seller@mail.com" }

// Validation problem (message is a list)
{ "code": 422, "status": "error", "message": ["The selected receiver email is invalid."] }

// Not enough money in your wallet
{ "code": 422, "status": "error", "message": "Insufficent Balance In Your Wallet" }
</code></pre>

</div>

<div class="documentation-item" id="invoice">
<div class="documentation-header"><h3 class="title">@lang('Create an Invoice')</h3></div>

<p>Create an invoice for your customer. {{$gs->title}} emails them a payment link and also returns it to you so you can redirect them straight to a clean checkout page.</p>
<p><span class="badge badge--primary">POST</span> <code>{{ url('payment/create-invoice') }}</code></p>
<div style="overflow-x:auto"><table class="table table-bordered text-center bg--section mb-4"><thead class="bg--base"><tr><th class="text--white">@lang('Param')</th><th class="text--white">@lang('Type')</th><th class="text--white">@lang('Description')</th></tr></thead><tbody>
<tr><td><code>invoice_to</code></td><td>string</td><td>Name of the person being invoiced. <span class="badge badge--danger">Required</span></td></tr>
<tr><td><code>email</code></td><td>email</td><td>Customer email. The customer must already have a {{$gs->title}} account. <span class="badge badge--danger">Required</span></td></tr>
<tr><td><code>currency_code</code></td><td>string</td><td>Currency the invoice is paid in, e.g. <code>NGN</code>. <span class="badge badge--danger">Required</span></td></tr>
<tr><td><code>item</code></td><td>array of strings</td><td>Names of the items. Each name must be unique. <span class="badge badge--danger">Required</span></td></tr>
<tr><td><code>amount</code></td><td>array of numbers</td><td>Price of each item, in the same order as <code>item</code>. Both arrays must be the same length and every amount greater than 0. The invoice total is the sum. <span class="badge badge--danger">Required</span></td></tr>
<tr><td><code>address</code></td><td>string</td><td>Customer address. <span class="badge badge--primary">Optional</span></td></tr>
<tr><td><code>invoice_status</code></td><td>string</td><td><code>publish</code> makes the invoice payable, <code>cancel</code> cancels it. If left out the invoice is saved as a draft. <span class="badge badge--primary">Optional</span></td></tr>
<tr><td><code>allow_escrow</code></td><td>boolean</td><td><code>true</code> lets the customer pay through escrow. <span class="badge badge--primary">Optional</span></td></tr>
<tr><td><code>pay_charge</code></td><td>boolean</td><td>Only matters when the customer pays through escrow: <code>true</code> means the escrow charge is paid on top of the invoice total. <span class="badge badge--primary">Optional</span></td></tr>
<tr><td><code>success_url</code></td><td>URL</td><td>Where to send the customer after paying. <span class="badge badge--primary">Optional</span></td></tr>
<tr><td><code>cancel_url</code></td><td>URL</td><td>Where to send the customer if they cancel. <span class="badge badge--primary">Optional</span></td></tr>
</tbody></table></div>

<p class="mb-1 mt-3"><strong>cURL</strong></p>
<pre class="mb-3"><button class="copy-btn" data-clipboard-target="#code-17">@lang('Copy')</button><code class="language-bash" id="code-17">curl -X POST {{ url('payment/create-invoice') }} \
  -H "Authorization: Bearer YOUR_ACCESS_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "invoice_to": "Test User",
    "email": "customer@mail.com",
    "currency_code": "NGN",
    "item": ["Baby doll", "Toy car", "Gift wrap"],
    "amount": [10, 20, 30],
    "address": "Lagos, Nigeria",
    "allow_escrow": true,
    "pay_charge": true,
    "invoice_status": "publish",
    "success_url": "https://yoursite.com/success",
    "cancel_url": "https://yoursite.com/cancel"
  }'
</code></pre>

<p class="mb-1 mt-3"><strong>PHP</strong></p>
<pre class="mb-3"><button class="copy-btn" data-clipboard-target="#code-18">@lang('Copy')</button><code class="language-php" id="code-18">&lt;?php
$parameters = [
    'invoice_to'     =&gt; 'Test User',
    'email'          =&gt; 'customer@mail.com',
    'currency_code'  =&gt; 'NGN',
    'item'           =&gt; ['Baby doll', 'Toy car', 'Gift wrap'],
    'amount'         =&gt; [10, 20, 30],
    'address'        =&gt; 'Lagos, Nigeria',
    'allow_escrow'   =&gt; true,
    'pay_charge'     =&gt; true,
    'invoice_status' =&gt; 'publish',
    'success_url'    =&gt; 'https://yoursite.com/success',
    'cancel_url'     =&gt; 'https://yoursite.com/cancel',
];

$ch = curl_init('{{ url('payment/create-invoice') }}');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Content-Type: application/json',
    'Authorization: Bearer YOUR_ACCESS_KEY',
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = json_decode(curl_exec($ch), true);
curl_close($ch);

if (($response['code'] ?? 0) === 200) {
    header('Location: ' . $response['url']);   // customer pays on the invoice page
    exit;
}
</code></pre>

<p><strong>Responses</strong></p>
<pre class="mb-3"><button class="copy-btn" data-clipboard-target="#code-19">@lang('Copy')</button><code class="language-json" id="code-19">// Success
{
  "code": 200,
  "status": "OK",
  "message": "Invoice Has Been Made",
  "url": "https://droppcash.app/view-invoice/eyJpdiI6..."
}

// Customer email has no account (a link to register is returned)
{ "code": 500, "status": "OK", "message": "User Not Registerred On Dropppay", "url": "https://droppcash.app/user/register" }

// item and amount lists do not match
{ "code": 500, "status": "error", "message": "An Error In Your API Parameters Caused This." }
</code></pre>

</div>

<div class="documentation-item" id="errors">
<div class="documentation-header"><h3 class="title">@lang('Errors')</h3></div>

<p>Remember: the HTTP status is always 200. Check the <code>code</code> field in the JSON body.</p>
<div style="overflow-x:auto"><table class="table table-bordered text-center bg--section mb-4"><thead class="bg--base"><tr><th class="text--white">@lang('code')</th><th class="text--white">@lang('Meaning')</th><th class="text--white">@lang('What to do')</th></tr></thead><tbody>
<tr><td><code>200</code></td><td>Success</td><td>Continue.</td></tr>
<tr><td><code>401</code></td><td><code>Invalid API credentials.</code></td><td>The access key is missing or wrong. Check the <code>Authorization: Bearer</code> header.</td></tr>
<tr><td><code>404</code></td><td><code>Requested currency not found.</code> or <code>Transaction Not Found.</code></td><td>Check <code>currency_code</code> against the supported list, or the <code>payment_id</code>. A payment that is not completed yet also returns 404 on check-validity. For escrow, <code>You do not have a wallet in this currency.</code> means the account that owns the key needs a wallet in that currency.</td></tr>
<tr><td><code>422</code></td><td>Validation failed, or <code>Insufficent Balance In Your Wallet</code> (escrow)</td><td>For validation, <code>message</code> is a list of what to fix. For balance, top up your wallet.</td></tr>
<tr><td><code>500</code></td><td>Server problem, or invoice parameters/customer account problem</td><td>Check the invoice <code>item</code> and <code>amount</code> lists and that the customer is registered. If it keeps happening, contact support.</td></tr>
</tbody></table></div>

<p>Example validation error:</p>
<pre class="mb-3"><button class="copy-btn" data-clipboard-target="#code-20">@lang('Copy')</button><code class="language-json" id="code-20">{
  "code": 422,
  "status": "error",
  "message": [
    "The amount must be greater than 0.",
    "The success url must be a valid URL."
  ]
}
</code></pre>

</div>

<div class="documentation-item" id="security">
<div class="documentation-header"><h3 class="title">@lang('Security Checklist')</h3></div>

<ul>
<li>Keep your access key on your server only. If it leaks, create a new one from your dashboard.</li>
<li>Always confirm a payment with check-validity before you deliver, and compare <code>custom</code> and the amount with your own order.</li>
<li>Fulfil each order once only. Store the <code>payment_id</code> against the order and ignore repeats.</li>
<li>Use HTTPS for <code>web_hook</code>, <code>success_url</code> and <code>cancel_url</code>.</li>
<li>Do not turn off SSL certificate checks (such as <code>CURLOPT_SSL_VERIFYHOST</code> or <code>verify=False</code>) in your own code.</li>
</ul>
</div>

<div class="documentation-item" id="currencies">
<div class="documentation-header"><h3 class="title">@lang('Supported Currencies')</h3></div>

<p>Use the currency code exactly as shown. The list updates automatically when new currencies are added.</p>
<div style="overflow-x:auto"><table class="table table-bordered text-center bg--section mb-4">
<thead class="bg--base"><tr><th class="text--white">@lang('Currency Name')</th><th class="text--white">@lang('Currency Symbol')</th><th class="text--white">@lang('Currency Code')</th></tr></thead>
<tbody>
@foreach ($currencies as $item)
<tr><td>{{$item->curr_name}}</td><td>{{$item->symbol}}</td><td>{{$item->code}}</td></tr>
@endforeach
</tbody></table></div>
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
</script>
@endpush
