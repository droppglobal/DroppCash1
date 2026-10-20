<?php

use Illuminate\Support\Str;
use App\Models\Generalsetting;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PaymentApiController;
use App\Http\Controllers\Front\FrontendController;
use App\Http\Controllers\User\ManageInvoiceController;
use App\Http\Controllers\User\SocialRegisterController;
use App\Http\Controllers\Gateway\Flutterwave;
use App\Http\Controllers\Gateway\Instamojo;
use App\Http\Controllers\Gateway\Paypal;
use App\Http\Controllers\Gateway\Coingate;
use App\Http\Controllers\Gateway\Paytm;
use App\Http\Controllers\Gateway\Razorpay;
use App\Http\Controllers\Gateway\Stripe;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('currency-rate', [FrontendController::class, 'currencyRate'])->name('currency.rate');
Route::middleware(['maintenance'])->group(function () {
    Route::prefix('payment')->group(function () {
        Route::post('/process', [PaymentApiController::class, 'paymentProcess'])->name('process.payment');
        Route::get('/process/payment/check', [PaymentApiController::class, 'processPaymentCheck'])->name('process.payment.check');
        Route::get('process/guest/payment/check', [PaymentApiController::class, 'processGuestPaymentCheck'])->name('process.payment.guest');
        Route::post('process/guest/payment/sumbit', [PaymentApiController::class, 'depositPayment'])->name('process.payment.guest.sumbit');
        Route::post('process/guest/payment/paytm/notify', [PaymentApiController::class, 'paytmNotify'])->name('payment.process.paytm.notify');
        Route::get('process-checkout', [PaymentApiController::class, 'processCheckOut'])->name('process.payment.auth');
        Route::post('make-escrow',   [PaymentApiController::class,'makeEscrow'])->name('payment-escrow.create');
        Route::post('create-invoice',   [PaymentApiController::class,'MakeInvoice']);
        Route::post('process-authenticate', [PaymentApiController::class, 'authenticate'])->name('process.payment.authenticate');
        Route::get('process-confirm',  [PaymentApiController::class,'reviewPayment'])->name('payment.confirm');
    Route::post('process-confirm', [PaymentApiController::class,'confirmPayment'])->name('payment.confirm.submit');
        Route::get('check-validity', [PaymentApiController::class, 'checkValidity'])->name('payment.check.validity');
        Route::post('escrow/process',  [PaymentApiController::class,'escrowProcess']);
            Route::get('escrow/status',    [PaymentApiController::class,'escrowStatus']);
            Route::post('escrow/refund',   [PaymentApiController::class,'escrowRefund']);
            Route::post('escrow/simulate', [PaymentApiController::class,'escrowSimulate']);
    });

    // ********************************* FRONTEND SECTION *******************************************//
    Route::get('/', [FrontendController::class, 'index'])->name('front.index');
    Route::get('/about', [FrontendController::class, 'about'])->name('about');
    Route::get('/frequently-asked-questions', [FrontendController::class, 'faq'])->name('faq');
    Route::get('/contact', [FrontendController::class, 'contact'])->name('contact');
    Route::post('/contact', [FrontendController::class, 'contactSubmit']);
    Route::get('/api-documentation', [FrontendController::class, 'apiDoc'])->name('api.doc');
    Route::get('/blogs', [FrontendController::class, 'blogs'])->name('blogs');
    Route::get('/blog-details/{id}-{slug}', [FrontendController::class, 'blogDetails'])->name('blog.details');
    Route::get('/terms-and-policies/{key}-{slug}', [FrontendController::class, 'terms_policies'])->name('terms.details');
    Route::get('/pages/{id}-{slug}', [FrontendController::class, 'pages'])->name('pages');
    Route::get('/change-language/{code}', [FrontendController::class, 'langChange'])->name('lang.change');
    Route::get('/payment-gateway/load', [FrontendController::class, 'paymentOption'])->name('load.payment');

    Route::get('view-invoice/{number}',   [ManageInvoiceController::class, 'invoiceView'])->name('invoice.view');
    Route::get('qr-code-scan/{email}',   [FrontendController::class, 'scanQR'])->name('scan.qr');
});
Route::get('/agent-addon', [FrontendController::class, 'agentAddonOff'])->name('agent.off_module');

Route::post('the/genius/ocean/2441139', [FrontendController::class, 'subscription']);
Route::get('finalize', [FrontendController::class, 'finalize']);
Route::get('update-finalize', [FrontendController::class, 'updateFinalize']);


Route::get('notify/paypal',      [Paypal::class, 'notify'])->name('paypal.notify');
Route::get('notify/stripe',      [Stripe::class, 'notify'])->name('stripe.notify');
Route::post('notify/paytm',      [Paytm::class, 'notify'])->name('paytm.notify');
Route::post('notify/razorpay',   [Razorpay::class, 'notify'])->name('razorpay.notify');
Route::match(['get','post'],'notify/flutterwave',[Flutterwave::class,'notify'])->name('flutterwave.notify');
Route::match(['get','post'],'notify/flutterwave-payout',[\App\Http\Controllers\Gateway\FlutterwavePayoutController::class,'notify'])->name('flutterwave.payout.notify');
Route::get('notify/instamojo',   [Instamojo::class, 'notify'])->name('notify.instamojo');
Route::post('notify/coingate', [Coingate::class, 'notify'])->name('notify.coingate');

Route::get('/maintenance', [FrontendController::class, 'maintenance'])->name('front.maintenance');
