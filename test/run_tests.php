<?php
// Standalone tests for the Flutterwave client and the payout decision rules.
// Run:  php -S 127.0.0.1:8093 mock_flutterwave.php &   then   php run_tests.php
require __DIR__ . '/../project/app/Services/Flutterwave/FlutterwaveClient.php';
require __DIR__ . '/../project/app/Services/Flutterwave/InstantPayoutLogic.php';
use App\Services\Flutterwave\FlutterwaveClient as C;
use App\Services\Flutterwave\InstantPayoutLogic as L;

$bad = 0; $n = 0;
function t($name, $ok, $extra = '') { global $bad, $n; $n++; if (!$ok) $bad++; echo ($ok ? 'PASS' : 'FAIL') . ": $name" . (!$ok && $extra !== '' ? "  [$extra]" : '') . "\n"; }
$base = 'http://127.0.0.1:8093/v3';
function mock($path) { global $base; $ch = curl_init($base . $path); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer FLWSECK_TEST']]); curl_exec($ch); curl_close($ch); }
mock('/_reset');
$c = new C('FLWSECK_TEST', $base, 1);

echo "--- client\n";
$banks = $c->banks();
t('banks parsed and sorted by name', $banks && $banks[0]['name'] === 'Access Bank' && $banks[2]['code'] === '058');
t('wrong secret key -> banks unavailable (null), no crash', (new C('WRONG', $base, 1))->banks() === null);
$r = $c->resolve('044', '0690000031');
t('resolve a good account', $r['ok'] && $r['account_name'] === 'MARY TITI ADEBISI');
$rb = $c->resolve('044', '0000000001');
t('resolve a bad account', !$rb['ok'] && $rb['account_name'] === null && $rb['message'] !== '');
t('fee tier 1 (10.75)', $c->fee(1000) === 10.75);
t('fee is rounded UP to the kobo (26.875 -> 26.88)', $c->fee(20000) === 26.88);
t('fee tier 3 (53.75)', $c->fee(500000) === 53.75);
t('balance', $c->balance() === 1000000.0);
t('unreachable server -> http 0, error set', ($x = (new C('K', 'http://127.0.0.1:1/v3', 1))->createTransfer(['a' => 1])) && $x['http'] === 0 && $x['error'] !== null);

echo "--- creating transfers: what each Flutterwave answer means\n";
$mk = function ($ref, $acct) use ($c) { return $c->createTransfer(['account_bank' => '044', 'account_number' => $acct, 'amount' => 5000, 'currency' => 'NGN', 'debit_currency' => 'NGN', 'narration' => 't', 'reference' => $ref]); };
$a = L::interpretCreate($mk('REFA00000001', '1111111111'));
t('accepted -> processing with a transfer id', $a['state'] === 'processing' && $a['transfer_id'] !== null);
$b = L::interpretCreate($mk('REFB00000001', '2222222222'));
t('"insufficient balance" -> low_balance (hold for admin, do NOT refund)', $b['state'] === 'low_balance');
$d = L::interpretCreate($mk('REFC00000001', '3333333333'));
t('created but FAILED -> failed (safe to refund)', $d['state'] === 'failed' && $d['transfer_id'] !== null);
$e = L::interpretCreate($mk('REFD00000001', '4444444444'));
t('Flutterwave 500 -> unknown (never refund / resend blindly)', $e['state'] === 'unknown');
$f = L::interpretCreate($mk('REFE00000001', '5555555555'));
t('timeout (no answer) -> unknown', $f['state'] === 'unknown');
$g = L::interpretCreate($mk('REFA00000001', '1111111111'));
t('same reference sent twice -> unknown (it may already exist), not failed', $g['state'] === 'unknown', $g['message']);
$h = L::interpretCreate($mk('REFG00000001', '7777777777'));
t('403 (IP not allowed) -> failed + config flag', $h['state'] === 'failed' && !empty($h['config']));
$i = L::interpretCreate($mk('REFH00000001', '8888888888'));
t('400 invalid bank -> failed (no transfer created)', $i['state'] === 'failed' && empty($i['config']));
t('garbage / no json -> unknown', L::interpretCreate(['http' => 200, 'json' => null, 'error' => null])['state'] === 'unknown');
t('http 200 but status=error and no data -> not "processing"', L::interpretCreate(['http' => 200, 'json' => ['status' => 'error', 'message' => 'x'], 'error' => null])['state'] !== 'processing');

echo "--- checking a transfer afterwards\n";
$w = ['trx' => 'REFA00000001', 'amount' => 5000];
$t1 = $c->findByReference('REFA00000001');
t('find by our reference', $t1 && (string) $t1['reference'] === 'REFA00000001');
t('unknown reference -> null', $c->findByReference('NOPE00000000') === null);
$v1 = L::interpretTransfer($c->getTransfer($a['transfer_id'])['json']['data'], $w);
t('later poll -> success', $v1['verdict'] === 'success', json_encode($v1));
$j = L::interpretCreate($mk('REFI00000001', '6666666666'));
$v2 = L::interpretTransfer($c->getTransfer($j['transfer_id'])['json']['data'], ['trx' => 'REFI00000001', 'amount' => 5000]);
t('later poll -> failed (refund the user)', $v2['verdict'] === 'failed' && strpos($v2['message'], 'Invalid account') !== false);
$k = L::interpretCreate($mk('REFJ00000001', '9999999999'));
$p1 = L::interpretTransfer($c->getTransfer($k['transfer_id'])['json']['data'], ['trx' => 'REFJ00000001', 'amount' => 5000]);
t('still PENDING -> pending (change nothing)', $p1['verdict'] === 'pending', json_encode($p1));
t('wrong reference -> mismatch', L::interpretTransfer($c->getTransfer($a['transfer_id'])['json']['data'], ['trx' => 'OTHER0000001', 'amount' => 5000])['verdict'] === 'mismatch');
t('wrong amount -> mismatch (never completes)', L::interpretTransfer($c->getTransfer($a['transfer_id'])['json']['data'], ['trx' => 'REFA00000001', 'amount' => 9000])['verdict'] === 'mismatch');
$tt = $c->getTransfer($a['transfer_id'])['json']['data']; $tt['currency'] = 'USD';
t('wrong currency -> mismatch', L::interpretTransfer($tt, $w)['verdict'] === 'mismatch');
t('empty data -> error', L::interpretTransfer(null, $w)['verdict'] === 'error');

echo "--- name match rule\n";
$cases = [
  ['Mary Titi', 'TITI MARY ADEBISI', true, 'order and extra middle name do not matter'],
  ['Mary Titi', 'mary titi', true, 'case'],
  ['John A. Doe', 'DOE JOHN', true, 'initial ignored, order'],
  ['Chinedu Okeke', 'OKEKE CHINEDHU', true, 'one-letter spelling slip on a long name'],
  ['Ngozi Nkoli-Ugo', 'UGO NGOZI', true, 'hyphen'],
  ['Mr Emeka Obi', 'OBI EMEKA CHUKWU', true, 'title ignored'],
  ['Amaka Eze', 'ACME TRADING LIMITED', false, 'different person'],
  ['Mary Titi', 'MARY JOHNSON', false, 'only ONE name part matches'],
  ['Mary Titi', 'TITI', false, 'only one part on the account'],
  ['Mary', 'MARY TITI', false, 'profile has a single word -> review'],
  ['', 'MARY TITI', false, 'empty profile name'],
  ['Mary Titi', '', false, 'empty account name'],
  ['Ade Bo', 'ADE BO', true, 'short names equal'],
  ['Ade Bola', 'ADE BAYO', false, 'one part only'],
  ["Zoë Müller", 'ZOE MULLER', true, 'accents'],
];
foreach ($cases as [$p, $a2, $exp, $why]) t("'$p' vs '$a2' => " . ($exp ? 'match' : 'review') . " ($why)", L::namesMatch($p, $a2) === $exp);

echo "--- input and money rules\n";
t('account must be exactly 10 digits', L::validAccountNumber('0690000031') && !L::validAccountNumber('069000003') && !L::validAccountNumber('06900000311') && !L::validAccountNumber('069000003a') && !L::validAccountNumber(''));
t('amount: kobo allowed, 3 decimals not', L::validAmount('500.50') && L::validAmount(500) && !L::validAmount('500.555') && !L::validAmount('0') && !L::validAmount('-5') && !L::validAmount('abc'));
$pr = L::price(7500.50, 0, 10.75); t('price: amount + FW fee passed to the user', $pr['charge'] === 10.75 && $pr['total'] === 7511.25);
$pr = L::price(10000, 100, 26.88); t('price: method charge + FW fee', $pr['charge'] === 126.88 && $pr['total'] === 10126.88);
t('mask account', L::maskAccount('0690000031') === '******0031');
t('summary fits the 255-character column', strlen(L::summary(str_repeat('B', 150), '0690000031', str_repeat('N', 200))) <= 255);

echo "\n$n checks, " . ($bad ? "$bad FAILED" : 'all passed') . "\n";
exit($bad ? 1 : 0);
