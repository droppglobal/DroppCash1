<?php
// Mock of the Flutterwave v3 endpoints used for Naira payouts (for tests only).
$file = sys_get_temp_dir() . '/flw_mock_state.json';
$db   = is_file($file) ? json_decode(file_get_contents($file), true) : ['n' => 1000, 'transfers' => [], 'balance' => 1000000.0];
function out($code, $arr) { http_response_code($code); header('Content-Type: application/json'); echo json_encode($arr); exit; }
function save($db) { global $file; file_put_contents($file, json_encode($db)); }
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($auth !== 'Bearer FLWSECK_TEST') out(401, ['status' => 'error', 'message' => 'Invalid authorization key']);
$in = json_decode(file_get_contents('php://input'), true) ?: [];

if ($path === '/v3/_set_balance') { $db['balance'] = (float) $_GET['v']; save($db); out(200, ['ok' => 1]); }
if ($path === '/v3/_reset') { @unlink($file); out(200, ['ok' => 1]); }

if ($path === '/v3/banks/NG') out(200, ['status' => 'success', 'data' => [['id' => 1, 'code' => '058', 'name' => 'Guaranty Trust Bank'], ['id' => 2, 'code' => '044', 'name' => 'Access Bank'], ['id' => 3, 'code' => '011', 'name' => 'First Bank of Nigeria']]]);
if ($path === '/v3/accounts/resolve') {
    $map = ['0690000031' => 'MARY TITI ADEBISI', '0123456789' => 'JOHN DOE', '0111111111' => 'ACME TRADING LIMITED'];
    foreach (['1111111111','2222222222','3333333333','4444444444','5555555555','6666666666','7777777777','8888888888','9999999999'] as $special) $map[$special] = 'ADEBISI MARY TITI';
    $n = $in['account_number'] ?? '';
    if (isset($map[$n])) out(200, ['status' => 'success', 'message' => 'Account details fetched', 'data' => ['account_number' => $n, 'account_name' => $map[$n]]]);
    out(400, ['status' => 'error', 'message' => 'Unable to resolve account', 'data' => null]);
}
if ($path === '/v3/transfers/fee') {
    $a = (float) ($_GET['amount'] ?? 0);
    $fee = $a <= 5000 ? 10.75 : ($a <= 50000 ? 26.875 : 53.75);
    out(200, ['status' => 'success', 'message' => 'Transfer fee fetched', 'data' => [['currency' => 'NGN', 'fee_type' => 'value', 'fee' => $fee]]]);
}
if ($path === '/v3/balances/NGN') out(200, ['status' => 'success', 'data' => ['currency' => 'NGN', 'available_balance' => $db['balance'], 'ledger_balance' => $db['balance']]]);

if ($path === '/v3/transfers' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $acct = $in['account_number'] ?? ''; $ref = $in['reference'] ?? '';
    foreach ($db['transfers'] as $t) if ($t['reference'] === $ref) out(400, ['status' => 'error', 'message' => 'Transfer with this reference already exists', 'data' => null]);
    if ($acct === '2222222222') out(400, ['status' => 'error', 'message' => 'Insufficient balance in your wallet', 'data' => null]);
    if ($acct === '4444444444') out(500, ['status' => 'error', 'message' => 'Internal error']);
    if ($acct === '5555555555') { sleep(3); }
    if ($acct === '7777777777') out(403, ['status' => 'error', 'message' => 'IP not whitelisted']);
    if ($acct === '8888888888') out(400, ['status' => 'error', 'message' => 'Invalid bank code']);
    $db['n']++; $id = $db['n'];
    $t = ['id' => $id, 'account_number' => $acct, 'bank_code' => $in['account_bank'], 'full_name' => 'X', 'created_at' => date('c'), 'currency' => $in['currency'],
          'amount' => $in['amount'], 'fee' => 10.75, 'status' => 'NEW', 'reference' => $ref, 'complete_message' => '', 'polls' => 0, 'plan' => $acct];
    if ($acct === '3333333333') { $t['status'] = 'FAILED'; $t['complete_message'] = 'DISBURSE FAILED: Account blocked'; }
    $db['transfers'][] = $t; save($db);
    unset($t['polls'], $t['plan']);
    out(200, ['status' => 'success', 'message' => 'Transfer Queued Successfully', 'data' => $t]);
}
if ($path === '/v3/transfers' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $list = [];
    foreach ($db['transfers'] as $t) if (!isset($_GET['reference']) || $t['reference'] === $_GET['reference']) { unset($t['polls'], $t['plan']); $list[] = $t; }
    out(200, ['status' => 'success', 'message' => 'Transfers fetched', 'data' => $list]);
}
if (preg_match('#^/v3/transfers/(\d+)$#', $path, $m)) {
    foreach ($db['transfers'] as $i => $t) if ((string) $t['id'] === $m[1]) {
        $db['transfers'][$i]['polls']++;
        if (in_array($t['status'], ['NEW', 'PENDING'], true) && $db['transfers'][$i]['polls'] >= 1) {
            $db['transfers'][$i]['status'] = ($t['plan'] === '6666666666') ? 'FAILED' : (($t['plan'] === '9999999999' && $db['transfers'][$i]['polls'] < 3) ? 'PENDING' : 'SUCCESSFUL');
            $db['transfers'][$i]['complete_message'] = $db['transfers'][$i]['status'] === 'FAILED' ? 'DISBURSE FAILED: Invalid account' : 'Transaction was successful';
        }
        if ($t['plan'] === 'WRONGAMOUNT') $db['transfers'][$i]['amount'] = 1;
        save($db); $x = $db['transfers'][$i]; unset($x['polls'], $x['plan']);
        out(200, ['status' => 'success', 'message' => 'Transfer fetched', 'data' => $x]);
    }
    out(404, ['status' => 'error', 'message' => 'Transfer not found', 'data' => null]);
}
out(404, ['status' => 'error', 'message' => 'no such endpoint']);
