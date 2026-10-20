<?php
// End-to-end test: the REAL InstantWithdrawal service + REAL MariaDB copy of the database + mock Flutterwave.
// Run through run_e2e.sh (it prepares a fresh database copy).
require __DIR__ . '/laravel_shim.php';
require __DIR__ . '/../project/app/Services/Flutterwave/FlutterwaveClient.php';
require __DIR__ . '/../project/app/Services/Flutterwave/InstantPayoutLogic.php';
require __DIR__ . '/../project/app/Services/Flutterwave/InstantWithdrawal.php';

use App\Models\{User, Wallet, Withdraw, Withdrawals, Transaction};
use App\Services\Flutterwave\InstantWithdrawal as IW;
use Illuminate\Support\Facades\DB;

DB::$pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=t;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
DB::$pdo->exec("SET time_zone = '+00:00'");
IW::$apiBase = 'http://127.0.0.1:8093/v3';
IW::$timeout = 1;

$bad = 0; $n = 0;
function t($name, $ok, $extra = '') { global $bad, $n; $n++; if (!$ok) $bad++; echo ($ok ? 'PASS' : 'FAIL') . ": $name" . (!$ok && $extra !== '' ? "  [$extra]" : '') . "\n"; }
function q($sql, $b = []) { $s = DB::$pdo->prepare($sql); $s->execute($b); return $s; }
function one($sql, $b = []) { return q($sql, $b)->fetch(); }
function mock($path) { $ch = curl_init('http://127.0.0.1:8093/v3' . $path); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer FLWSECK_TEST'], CURLOPT_TIMEOUT => 10]); $r = curl_exec($ch); curl_close($ch); return json_decode($r, true); }
function mockTransfers($ref) { $f = sys_get_temp_dir() . '/flw_mock_state.json'; $db = json_decode(file_get_contents($f), true); return count(array_filter($db['transfers'], function ($t) use ($ref) { return $t['reference'] === $ref; })); }
function bal($uid) { return (float) one('SELECT balance FROM wallets WHERE user_id=? AND user_type=1 AND currency_id=13', [$uid])['balance']; }
function setBal($uid, $v) { q('UPDATE wallets SET balance=? WHERE user_id=? AND user_type=1 AND currency_id=13', [$v, $uid]); }
function wal($uid, $cur = 13) { return Wallet::where('user_id', $uid)->where('user_type', 1)->where('currency_id', $cur)->first(); }
function mails($key = null) { global $MAILS; return $key ? array_values(array_filter($MAILS, function ($m) use ($key) { return $m['key'] === $key; })) : $MAILS; }
function alerts() { global $ALERTS; return $ALERTS; }
function clearNotes() { global $MAILS, $ALERTS; $MAILS = []; $ALERTS = []; }
function ledger($trxUser, $remark) { return (int) one('SELECT COUNT(*) c FROM transactions WHERE user_id=? AND remark=? AND user_type=1', [$trxUser, $remark])['c']; }
function eq($a, $b) { return abs($a - $b) < 0.005; }

// ---------------------------------------------------------------- setup
mock('/_reset'); mock('/_set_balance?v=1000000');
$user = User::find(1);                                    // Mary Titi - a real user in the database copy
$other = (int) one('SELECT id FROM users WHERE id<>1 ORDER BY id LIMIT 1')['id'];
foreach ([1, $other] as $uid) {
    if (!wal($uid)) q("INSERT INTO wallets (user_id,user_type,currency_id,balance,created_at,updated_at) VALUES (?,1,13,0,NOW(),NOW())", [$uid]);
}
setBal(1, 100000); setBal($other, 50000);
q('UPDATE withdraws SET status=1, daily_limit=0 WHERE is_instant=1');
$method = Withdraw::where('is_instant', 1)->first();
if (!one("SELECT id FROM payment_gateways WHERE keyword='flutterwave'")) {
    q("INSERT INTO payment_gateways (subtitle,title,name,type,information,keyword,currency_id,checkout,status,subscription) VALUES ('','Flutterwave','Flutterwave','automatic','{}','flutterwave','[\"13\"]',1,1,1)");
}
q("UPDATE payment_gateways SET information=? WHERE keyword='flutterwave'", ['{"secret_key":"FLWSECK_TEST","text":"x"}']);
$baseMoney = ledger(1, 'withdraw_money'); $baseReject = ledger(1, 'withdraw_reject');
echo "setup: user {$user->name}, instant method #{$method->id} min {$method->min_amount} max {$method->max_amount}\n\n";

echo "=== 1. happy path: name matches, paid\n";
$start = bal(1); clearNotes();
$r = IW::create($user, wal(1), $method, 5000, '044', '1111111111');
t('create ok and settled as completed', $r['ok'] && $r['state'] === 'completed', json_encode($r['message'] ?? ''));
$w1 = Withdrawals::find($r['withdrawal']->id);
t('wallet debited exactly amount + Flutterwave fee (5000 + 10.75)', eq(bal(1), $start - 5010.75), bal(1));
t('row: paid, charge 10.75, total 5010.75, accepted', $w1->status == 1 && $w1->payout_status === 'completed' && eq($w1->charge, 10.75) && eq($w1->total_amount, 5010.75));
t('row: bank details + Flutterwave id stored', $w1->bank_name === 'Access Bank' && $w1->account_number === '1111111111' && $w1->flw_transfer_id !== null && $w1->account_name === 'ADEBISI MARY TITI');
t('exactly one transfer at Flutterwave for this reference', mockTransfers($w1->trx) === 1);
t('ledger entry "withdraw_money" written once', ledger(1, 'withdraw_money') === $baseMoney + 1);
t('user got the "accepted" email, nobody was alerted', count(mails('accept_withdraw')) === 1 && !alerts());

echo "\n=== 2. final steps can only happen once\n";
$before = bal(1);
t('fail() after completion does nothing (no refund)', IW::fail($w1, 'x') === false && eq(bal(1), $before));
t('complete() again does nothing (no second ledger entry)', IW::complete($w1) === false && ledger(1, 'withdraw_money') === $baseMoney + 1);
t('send() on a finished withdrawal sends nothing', IW::send($w1) === 'completed' && mockTransfers($w1->trx) === 1);

echo "\n=== 3. name does not match -> review, then admin approves\n";
$start = bal(1); clearNotes();
$r = IW::create($user, wal(1), $method, 3000, '058', '0123456789');
$w = Withdrawals::find($r['withdrawal']->id);
t('goes to review, still debited, nothing sent', $r['ok'] && $r['state'] === 'review' && $w->payout_status === 'review' && $w->status == 0 && eq(bal(1), $start - 3010.75) && mockTransfers($w->trx) === 0);
t('admins alerted with the reason, user told it is pending', count(alerts()) === 1 && strpos(alerts()[0]['data']['reason'], 'does not match') !== false && count(mails('withdraw_request_user')) === 1);
$a = IW::approve($w);
$w = Withdrawals::find($w->id);
t('admin approve sends it and it completes', $a['ok'] && $w->status == 1 && $w->payout_status === 'completed' && mockTransfers($w->trx) === 1);
t('approving again is refused', IW::approve($w)['ok'] === false);

echo "\n=== 4. Flutterwave refuses the transfer -> refunded once\n";
$start = bal(1); clearNotes();
$r = IW::create($user, wal(1), $method, 2000, '044', '3333333333');
$w = Withdrawals::find($r['withdrawal']->id);
t('reported as failed, user told the money is back', $r['ok'] === false && $r['state'] === 'failed');
t('wallet back to the starting balance', eq(bal(1), $start), bal(1) . ' vs ' . $start);
t('row rejected with the reason, refund ledger entry once', $w->status == 2 && $w->payout_status === 'failed' && strpos($w->reject_reason, 'Account blocked') !== false && ledger(1, 'withdraw_reject') === $baseReject + 1);
t('user got the "rejected" email', count(mails('reject_withdraw')) === 1);
t('complete() after a failure does nothing', IW::complete($w) === false && Withdrawals::find($w->id)->status == 2);
$again = bal(1); IW::fail($w, 'again');
t('second refund attempt changes nothing', eq(bal(1), $again));

echo "\n=== 5. pending for a while, then paid\n";
$start = bal(1);
$r = IW::create($user, wal(1), $method, 1500, '011', '9999999999');
$w = Withdrawals::find($r['withdrawal']->id);
t('first answer: still processing, money held (debited)', $r['state'] === 'processing' && $w->status == 0 && eq(bal(1), $start - 1510.75));
$s1 = IW::reconcile($w, true);
t('a later check: still pending, nothing changed, money still held', $s1 === 'processing' && Withdrawals::find($w->id)->status == 0 && eq(bal(1), $start - 1510.75));
$s3 = IW::reconcile($w, true);
t('then success -> completed', $s3 === 'completed' && Withdrawals::find($w->id)->status == 1);
t('webhook-style check on a finished withdrawal is harmless', IW::reconcile($w, true) === 'completed' && ledger(1, 'withdraw_money') >= $baseMoney + 3);

echo "\n=== 6. Flutterwave later says FAILED -> refund\n";
$start = bal(1);
$r = IW::create($user, wal(1), $method, 1800, '044', '6666666666');
t('failed after being accepted: refunded in full', $r['state'] === 'failed' && eq(bal(1), $start));

echo "\n=== 7. our Flutterwave balance is too low -> held for admin, user NOT refunded\n";
mock('/_set_balance?v=100'); $start = bal(1); clearNotes();
$r = IW::create($user, wal(1), $method, 5000, '044', '1111111111');
$w = Withdrawals::find($r['withdrawal']->id);
t('held in review, still debited, no transfer created', $r['state'] === 'review' && $w->payout_status === 'review' && eq(bal(1), $start - 5010.75) && mockTransfers($w->trx) === 0);
t('admins told about the low balance', count(alerts()) >= 1 && strpos(alerts()[0]['data']['reason'], 'balance is too low') !== false);
mock('/_set_balance?v=1000000');
$a = IW::approve($w); $w = Withdrawals::find($w->id);
t('after funding, approve pays it', $a['ok'] && $w->status == 1 && mockTransfers($w->trx) === 1);

echo "\n=== 8. Flutterwave answers 'insufficient balance' -> held, not refunded\n";
$start = bal(1);
$r = IW::create($user, wal(1), $method, 2500, '044', '2222222222');
$w = Withdrawals::find($r['withdrawal']->id);
t('held in review (money stays debited)', $r['state'] === 'review' && $w->status == 0 && eq(bal(1), $start - 2510.75));

echo "\n=== 9. Flutterwave 5xx -> unknown: never refund, never resend blindly\n";
$start = bal(1); clearNotes();
$r = IW::create($user, wal(1), $method, 2200, '044', '4444444444');
$w = Withdrawals::find($r['withdrawal']->id);
t('stays processing without an id, money still held', $r['state'] === 'processing' && $w->flw_transfer_id === null && $w->status == 0 && eq(bal(1), $start - 2210.75));
t('a check right away changes nothing', IW::reconcile($w, true) === 'processing' && Withdrawals::find($w->id)->status == 0 && eq(bal(1), $start - 2210.75));
q('UPDATE withdrawals SET created_at = DATE_SUB(NOW(), INTERVAL 20 MINUTE) WHERE id=?', [$w->id]);
t('after 15 minutes of silence -> handed to an admin (still no refund)', IW::reconcile($w, true) === 'review' && Withdrawals::find($w->id)->status == 0 && eq(bal(1), $start - 2210.75) && count(alerts()) >= 1);

echo "\n=== 10. timeout: Flutterwave got it, but we did not hear back\n";
$start = bal(1);
$r = IW::create($user, wal(1), $method, 2300, '044', '5555555555');
$w = Withdrawals::find($r['withdrawal']->id);
t('we do not know -> processing, money held, nothing refunded', $r['state'] === 'processing' && $w->flw_transfer_id === null && eq(bal(1), $start - 2310.75));
sleep(3);
$s = IW::reconcile($w, true);                                      // finds it by our reference
$s = IW::reconcile($w, true);                                      // then confirms it by id
t('later found by reference and completed; paid exactly once', Withdrawals::find($w->id)->status == 1 && mockTransfers($w->trx) === 1, $s);

echo "\n=== 11. two sends for the same withdrawal -> one transfer\n";
$r = IW::create($user, wal(1), $method, 1000, '044', '9999999999');
$w = Withdrawals::find($r['withdrawal']->id);
IW::send($w); IW::send($w);
t('exactly one transfer was created', mockTransfers($w->trx) === 1);

echo "\n=== 12. rules checked on the server (nothing from the browser is trusted)\n";
$start = bal(1); $rows = (int) one('SELECT COUNT(*) c FROM withdrawals')['c'];
$cases = [
  ['someone else\'s wallet is refused',            function () use ($user, $method, $other) { return IW::create($user, wal($other), $method, 5000, '044', '1111111111'); }],
  ['below the minimum is refused',                 function () use ($user, $method) { return IW::create($user, wal(1), $method, 499, '044', '1111111111'); }],
  ['above the maximum is refused',                 function () use ($user, $method) { return IW::create($user, wal(1), $method, 500001, '044', '1111111111'); }],
  ['3 decimal places is refused',                  function () use ($user, $method) { return IW::create($user, wal(1), $method, 1000.555, '044', '1111111111'); }],
  ['bad account number is refused',                function () use ($user, $method) { return IW::create($user, wal(1), $method, 1000, '044', '12345'); }],
  ['unknown bank is refused',                      function () use ($user, $method) { return IW::create($user, wal(1), $method, 1000, '999', '1111111111'); }],
  ['an account Flutterwave cannot find is refused', function () use ($user, $method) { return IW::create($user, wal(1), $method, 1000, '044', '0000000001'); }],
  ['more than the wallet holds is refused',        function () use ($user, $method) { return IW::create($user, wal(1), $method, 500000, '044', '1111111111'); }],
];
setBal(1, 100000); $start = 100000;
foreach ($cases as [$label, $fn]) { $r = $fn(); t($label, $r['ok'] === false, json_encode($r['message'] ?? '')); }
t('none of those debited anything or created a withdrawal', eq(bal(1), $start) && (int) one('SELECT COUNT(*) c FROM withdrawals')['c'] === $rows);
q('UPDATE withdraws SET status=0 WHERE id=?', [$method->id]); $off = Withdraw::find($method->id);
t('a disabled instant method is refused', IW::create($user, wal(1), $off, 1000, '044', '1111111111')['ok'] === false);
q('UPDATE withdraws SET status=1 WHERE id=?', [$method->id]);
$usd = (int) one("SELECT id FROM currencies WHERE code='USD'")['id'];
if (!wal(1, $usd)) q("INSERT INTO wallets (user_id,user_type,currency_id,balance,created_at,updated_at) VALUES (1,1,?,500,NOW(),NOW())", [$usd]);
t('a non-Naira wallet is refused', IW::create($user, wal(1, $usd), Withdraw::find($method->id), 100, '044', '1111111111')['ok'] === false);

echo "\n=== 13. daily limit (optional) and charges\n";
q('UPDATE withdraws SET daily_limit=7000 WHERE id=?', [$method->id]); $m2 = Withdraw::find($method->id);
q("UPDATE withdrawals SET created_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE instant=1");
setBal(1, 100000);
$a = IW::create($user, wal(1), $m2, 5000, '044', '1111111111');
$b = IW::create($user, wal(1), $m2, 3000, '044', '1111111111');
t('first 5000 passes, next 3000 would pass 7000 a day -> refused', $a['ok'] === true && $b['ok'] === false && strpos($b['message'], 'daily limit') !== false);
q('UPDATE withdraws SET daily_limit=0, percent_charge=1 WHERE id=?', [$method->id]); $m3 = Withdraw::find($method->id);
$start = bal(1);
$c = IW::create($user, wal(1), $m3, 10000, '044', '1111111111');
$wc = Withdrawals::find($c['withdrawal']->id);
t('method charge (1% = 100) + Flutterwave fee (26.88) = 126.88 charged to the user', eq($wc->charge, 126.88) && eq($wc->total_amount, 10126.88) && eq(bal(1), $start - 10126.88));
q('UPDATE withdraws SET percent_charge=0 WHERE id=?', [$method->id]);

echo "\n=== 14. books balance: nothing paid twice, nothing refunded twice\n";
$paid   = (int) one("SELECT COUNT(*) c FROM withdrawals WHERE instant=1 AND status=1 AND user_id=1")['c'];
$failed = (int) one("SELECT COUNT(*) c FROM withdrawals WHERE instant=1 AND status=2 AND user_id=1")['c'];
t("every paid withdrawal has exactly one 'withdraw_money' ledger entry ($paid)", ledger(1, 'withdraw_money') - $baseMoney === $paid);
t("every failed withdrawal has exactly one 'withdraw_reject' refund entry ($failed)", ledger(1, 'withdraw_reject') - $baseReject === $failed);
t('every paid withdrawal has a Flutterwave transfer id', (int) one("SELECT COUNT(*) c FROM withdrawals WHERE instant=1 AND status=1 AND (flw_transfer_id IS NULL OR flw_transfer_id='')")['c'] === 0);
t('no withdrawal is paid AND refunded', (int) one("SELECT COUNT(*) c FROM withdrawals WHERE instant=1 AND status=2 AND flw_transfer_id IS NOT NULL AND payout_message LIKE 'Transaction was successful'")['c'] === 0);
$dupes = (int) one("SELECT COUNT(*) c FROM (SELECT trx FROM withdrawals WHERE instant=1 GROUP BY trx HAVING COUNT(*)>1) d")['c'];
t('no duplicate transaction references', $dupes === 0);
$tr = json_decode(file_get_contents(sys_get_temp_dir() . '/flw_mock_state.json'), true)['transfers'];
$refs = array_count_values(array_column($tr, 'reference'));
t('Flutterwave never received the same reference twice', max($refs ?: [0]) === 1);

echo "\n$n checks, " . ($bad ? "$bad FAILED" : 'all passed') . "\n";
exit($bad ? 1 : 0);
