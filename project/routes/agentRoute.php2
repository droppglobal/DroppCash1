<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Agent\AgentController;
use App\Http\Controllers\Agent\LoginController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\Agent\WithdrawalController;
use App\Http\Controllers\Agent\AuthorizationController;
Route::prefix('agent')->name('agent.')->middleware(['maintenance','agent_addon'])->group(function () {

    Route::get('/register',           [LoginController::class,'registerForm'])->name('register');
    Route::post('/register',          [LoginController::class,'register']);
    Route::get('/login',              [LoginController::class,'showLoginForm'])->name('login');
    Route::post('/login',             [LoginController::class,'login']);
    Route::get('/forgot-password',    [LoginController::class,'forgotPasswordForm'])->name('forgot.password');
    Route::post('/forgot-password',   [LoginController::class,'forgotPasswordSubmit']);

    Route::get('forgot-password/verify-code',      [LoginController::class,'verifyCode'])->name('verify.code');
    Route::post('forgot-password/verify-code',     [LoginController::class,'verifyCodeSubmit']);

    Route::get('reset-password',     [LoginController::class,'resetPassword'])->name('reset.password');
    Route::post('reset-password',    [LoginController::class,'resetPasswordSubmit']);

    Route::get('verify-email',      [AuthorizationController::class,'verifyEmail'])->name('verify.email')->middleware('auth:agent');

    Route::post('verify-email',     [AuthorizationController::class,'verifyEmailSubmit'])->middleware('auth:agent');

    Route::get('resend/verify-email/code',     [AuthorizationController::class,'verifyEmailResendCode'])->name('verify.email.resend')->middleware('auth:merchant');

    Route::get('two-step/verification',     [AuthorizationController::class,'twoStep'])->name('two.step.verification')->middleware('auth:merchant');

    Route::post('two-step/verification',     [AuthorizationController::class,'twoStepVerify'])->middleware('auth:merchant');

    Route::get('resend/two-step/verify-code', [AuthorizationController::class,'twoStepResendCode'])->name('two.step.resend')->middleware('auth:merchant');

    Route::get('/logout',[LoginController::class,'logout'])->name('logout')->middleware('auth:agent');

    Route::middleware(['auth:agent','agent_email_verify','twostep'])->group(function(){
       
        Route::get('transactions', [AgentController::class,'transactions'])->name('transactions');
        Route::get('transaction/details/{id}', [AgentController::class,'trxDetails'])->name('trx.details');
        Route::get('/profile-setting',  [AgentController::class,'profileSetting'])->name('profile.setting');
        Route::post('/profile-setting', [AgentController::class,'profileUpdate']);

        Route::get('/change-password',  [AgentController::class,'changePassword'])->name('change.password');
        Route::post('/change-password', [AgentController::class,'updatePassword']);

        Route::get('/two-step/authentication', [AgentController::class,'twoStep'])->name('two.step');
        Route::get('/two-step/verify',  [AgentController::class,'twoStepVerifyForm'])->name('two.step.verify');
        Route::post('/two-step/verify', [AgentController::class,'twoStepVerifySubmit']);
        Route::post('/two-step/authentication', [AgentController::class,'twoStepSendCode']);


        Route::get('/', [AgentController::class,'dashboard'])->name('dashboard');
        Route::get('/generate-qrcode', [AgentController::class,'generateQR'])->name('qr');

        //fund request
        Route::get('/fund-request',            [AgentController::class,'fundRequests'])->name('fund.request');
        Route::post('/fund-request/submit',    [AgentController::class,'fundRequestSubmit'])->name('fund.request.submit');

        //Cash In
        Route::get('/cash-in',        [AgentController::class,'cashIn'])->name('cashin');
        Route::post('/cash-in',       [AgentController::class,'confirmCashIn']);
        Route::post('/check-user',    [AgentController::class,'checkReceiver'])->name('user.check.receiver');

        //withdraw
        Route::get('withdraw-money',    [WithdrawalController::class,'withdrawForm'])->name('withdraw.form')->middleware(['module']);
        Route::post('withdraw-money',   [WithdrawalController::class,'withdrawSubmit'])->middleware(['module']);

        Route::get('withdraw-methods',  [WithdrawalController::class,'methods'])->name('withdraw.methods');
        Route::get('withdraw-history',  [WithdrawalController::class,'history'])->name('withdraw.history');

        Route::get('download-qr/{email}',  [AgentController::class,'downloadQR'])->name('download.qr');

        Route::get('module/{module}',   [AgentController::class,'moduleOff'])->name('module.off');

        // support ticket
        Route::get('support/tickets',   [SupportTicketController::class,'index'])->name('ticket.index');
        Route::post('open/support/tickets',   [SupportTicketController::class,'openTicket'])->name('ticket.open');
        Route::post('reply/ticket/{ticket_num}',   [SupportTicketController::class,'replyTicket'])->name('ticket.reply');

    });


});
