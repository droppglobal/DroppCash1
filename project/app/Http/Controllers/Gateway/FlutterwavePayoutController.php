<?php

namespace App\Http\Controllers\Gateway;

use App\Http\Controllers\Controller;
use App\Models\Withdrawals;
use App\Services\Flutterwave\InstantWithdrawal;
use Illuminate\Http\Request;

/**
 * Flutterwave calls this address (webhook / callback_url) when a payout finishes.
 *
 * Nothing in the call is trusted. It is only a hint "look at withdrawal X": we find OUR withdrawal by the reference,
 * ask Flutterwave directly (with our secret key) what really happened, and settle from that answer.
 * A fake call can therefore never pay or refund anything.
 */
class FlutterwavePayoutController extends Controller
{
    public function notify(Request $request)
    {
        $payload   = json_decode($request->getContent(), true);
        $payload   = is_array($payload) ? $payload : $request->all();
        $reference = $payload['data']['reference'] ?? $payload['reference'] ?? $request->query('reference');

        if (is_string($reference) && $reference !== '' && strlen($reference) <= 64) {
            $withdraw = Withdrawals::where('trx', $reference)->where('instant', 1)
                ->whereIn('payout_status', ['processing', 'queued'])->first();
            if ($withdraw) {
                try {
                    InstantWithdrawal::reconcile($withdraw);
                } catch (\Throwable $th) {
                    report($th);
                }
            }
        }

        return response('ok', 200);
    }
}
