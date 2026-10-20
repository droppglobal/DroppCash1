<?php


namespace App\Http\Controllers\Gateway;

use App\{
    Models\PaymentGateway
};
use App\Http\Controllers\Controller;
use App\Http\Controllers\PaymentApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use App\Http\Controllers\User\DepositController;
use Omnipay\Omnipay;
use App\Models\Currency;

class Paypal extends Controller
{


    public static function initiate($request, $payment_data, $type)
    {

        $payment_amount = $payment_data['amount'];

        $cancel_url = '';
        switch ($type) {
            case 'deposit':
                Session::put('type', $type);
                $cancel_url = route('user.deposit.submit');
                break;

            case 'api':
                Session::put('type', $type);
                $cancel_url = route('process.payment.guest');
                break;

            default:
                # code...
                break;
        }

        $data = PaymentGateway::whereKeyword('paypal')->first();
        $paydata = $data->convertAutoData();
        $gateway = Omnipay::create('PayPal_Rest');
        $gateway->setClientId($paydata['client_id']);
        $gateway->setSecret($paydata['client_secret']);
        $gateway->setTestMode(true);

        $notify_url = route('paypal.notify');
        $cancel_url = $cancel_url;

        
        try {
            $response = $gateway->purchase(array(
                'amount' => $payment_amount,
                'currency' => getCurrencyCode(),
                'returnUrl' => $notify_url,
                'cancelUrl' => $cancel_url,
            ))->send();

            if ($response->isRedirect()) {
                Session::put('input_data', $request->all());
                if ($response->redirect()) {
                    return ['status' => 1, 'url' => $response->redirect()];
                }
            } else {
                return redirect()->back()->with('unsuccess', $response->getMessage());
            }
        } catch (\Throwable $th) {
            return redirect()->back()->with('unsuccess', $th->getMessage());
        }
    }

    public function notify(Request $request)
    {


        $message = '';
        $status  = 0;
        $txn_id  = '';

        $responseData = $request->all();

        if (empty($responseData['PayerID']) || empty($responseData['token'])) {
            return [
                'status' => false,
                'message' => __('Unknown error occurred'),
            ];
        }


        $data = PaymentGateway::whereKeyword('paypal')->first();
        $paydata = $data->convertAutoData();
        $gateway = Omnipay::create('PayPal_Rest');
        $gateway->setClientId($paydata['client_id']);
        $gateway->setSecret($paydata['client_secret']);
        $gateway->setTestMode(true);
        $transaction = $gateway->completePurchase(array(
            'payer_id' => $responseData['PayerID'],
            'transactionReference' => $responseData['paymentId'],
        ));

        $response = $transaction->send();
        if ($response->isSuccessful()) {
            $txn_id = $response->getData()['transactions'][0]['related_resources'][0]['sale']['id'];
            $status = 1;
        }

        switch (Session::get('type')) {
            case 'deposit':
                return (new DepositController)->notifyOperation(['message' => $message, 'status' => $status, 'txn_id' => $txn_id]);
                break;
            case 'api':
                return (new PaymentApiController)->notifyOperation(['message' => $message, 'status' => $status, 'txn_id' => $txn_id]);
                break;

            default:
                dd('wrong');
                break;
        }
    }
}
