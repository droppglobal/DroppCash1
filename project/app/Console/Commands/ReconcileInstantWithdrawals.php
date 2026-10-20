<?php

namespace App\Console\Commands;

use App\Models\Withdrawals;
use App\Services\Flutterwave\InstantWithdrawal;
use Illuminate\Console\Command;

class ReconcileInstantWithdrawals extends Command
{
    protected $signature = 'withdrawals:reconcile';
    protected $description = 'Check instant Naira withdrawals that are still waiting for Flutterwave and settle them';

    public function handle()
    {
        $count = 0;
        Withdrawals::where('instant', 1)->where('status', 0)->whereIn('payout_status', ['processing', 'queued'])
            ->orderBy('id')->limit(100)->get()
            ->each(function ($withdraw) use (&$count) {
                try {
                    InstantWithdrawal::reconcile($withdraw, true);
                    $count++;
                } catch (\Throwable $th) {
                    report($th);
                }
            });
        $this->info("Checked $count withdrawal(s).");
        return 0;
    }
}
