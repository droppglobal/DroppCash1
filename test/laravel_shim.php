<?php
// A tiny stand-in for the few Laravel pieces InstantWithdrawal uses, running on a REAL MariaDB copy of the database.
// It exists only so the real service code can be exercised end to end in tests.
namespace Illuminate\Support\Facades {
    class DB {
        public static $pdo;
        public static function transaction($cb, $attempts = 1) {
            $nested = self::$pdo->inTransaction();
            if (!$nested) self::$pdo->beginTransaction();
            try { $r = $cb(); if (!$nested) self::$pdo->commit(); return $r; }
            catch (\Throwable $e) { if (!$nested && self::$pdo->inTransaction()) self::$pdo->rollBack(); throw $e; }
        }
    }
    class Cache {
        public static $store = [];
        public static function get($k) { return self::$store[$k] ?? null; }
        public static function put($k, $v, $ttl = 0) { self::$store[$k] = $v; }
    }
    class Log { public static $lines = []; public static function info($m) { self::$lines[] = $m; } public static function warning($m) { self::$lines[] = $m; } }
}
namespace {
    date_default_timezone_set('UTC');
    class Carbon {
        public $dt;
        function __construct($s = 'now') { $this->dt = new \DateTimeImmutable($s, new \DateTimeZone('UTC')); }
        function gt($o) { return $this->dt > $o->dt; }
        function lt($o) { return $this->dt < $o->dt; }
        function subSeconds($n) { $c = clone $this; $c->dt = $this->dt->modify("-$n seconds"); return $c; }
        function subMinutes($n) { $c = clone $this; $c->dt = $this->dt->modify("-$n minutes"); return $c; }
        function startOfDay() { $c = clone $this; $c->dt = $this->dt->setTime(0, 0, 0); return $c; }
        function __toString() { return $this->dt->format('Y-m-d H:i:s'); }
    }
    function now() { return new Carbon('now'); }
    function optional($v) { return $v ?? new class { function __get($k) { return null; } }; }
    function __($s) { return $s; }
    function trans($s) { return $s; }
    function e($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
    function report($e) { global $REPORTED; $REPORTED[] = $e->getMessage(); }
    function route($name) { return 'https://droppcash.test/' . $name; }
    function str_rand() { return strtoupper(substr(bin2hex(random_bytes(8)), 0, 12)); }
    function dateFormat($d) { return (string) $d; }
    // same maths as the real helpers.php
    function chargeCalc($charge, $amount, $rate = 1) { return ($charge->fixed_charge * $rate) + ($amount * ($charge->percent_charge / 100)); }
    function amount($a, $type = 1, $length = 0) { return $type == 2 ? round($a, 8) : round($a, $length); }
    function plainAmount($a, $type = 1) { return $type == 2 ? rtrim(rtrim(number_format((float) $a, 8, '.', ''), '0'), '.') : number_format((float) $a, 2, '.', ','); }
    $MAILS = []; $ALERTS = []; $REPORTED = [];
    function mailSend($key, array $data, $user) { global $MAILS; $MAILS[] = ['key' => $key, 'data' => $data, 'to' => $user->email]; }
    function notifyAdmins($key, array $data) { global $ALERTS; $ALERTS[] = ['key' => $key, 'data' => $data]; }
}
namespace App\Models {
    use Illuminate\Support\Facades\DB;
    class Query {
        public $model, $w = [], $b = [];
        function __construct($model) { $this->model = $model; }
        function where($c, $op = null, $v = null) { if (func_num_args() == 2) { $v = $op; $op = '='; } $this->w[] = "`$c` $op ?"; $this->b[] = $v; return $this; }
        function whereIn($c, $arr) { $this->w[] = "`$c` IN (" . implode(',', array_fill(0, count($arr), '?')) . ")"; foreach ($arr as $a) $this->b[] = $a; return $this; }
        function __call($n, $a) { if (strpos($n, 'where') === 0) { return $this->where(strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', substr($n, 5))), $a[0]); } throw new \BadMethodCallException($n); }
        private function where_() { return $this->w ? ' WHERE ' . implode(' AND ', $this->w) : ''; }
        private function fmt($v) { return $v instanceof \Carbon ? (string) $v : $v; }
        function first() { $s = DB::$pdo->prepare('SELECT * FROM `' . ($m = $this->model)::$table . '`' . $this->where_() . ' LIMIT 1'); $s->execute(array_map([$this, 'fmt'], $this->b)); $r = $s->fetch(\PDO::FETCH_ASSOC); return $r ? new $m($r) : null; }
        function firstOrFail() { $r = $this->first(); if (!$r) throw new \RuntimeException('not found'); return $r; }
        function update(array $vals) { $set = []; $b = []; foreach ($vals as $k => $v) { $set[] = "`$k` = ?"; $b[] = $this->fmt($v); } $s = DB::$pdo->prepare('UPDATE `' . ($m = $this->model)::$table . '` SET ' . implode(',', $set) . $this->where_()); $s->execute(array_merge($b, array_map([$this, 'fmt'], $this->b))); return $s->rowCount(); }
        function decrement($col, $amt) { $s = DB::$pdo->prepare('UPDATE `' . ($m = $this->model)::$table . "` SET `$col` = `$col` - ?" . $this->where_()); $s->execute(array_merge([$amt], array_map([$this, 'fmt'], $this->b))); return $s->rowCount(); }
        function sum($col) { $s = DB::$pdo->prepare("SELECT COALESCE(SUM(`$col`),0) FROM `" . ($m = $this->model)::$table . '`' . $this->where_()); $s->execute(array_map([$this, 'fmt'], $this->b)); return (float) $s->fetchColumn(); }
    }
    class Model {
        public static $table; public $attrs = [];
        protected static $dates = ['created_at', 'updated_at', 'payout_at'];
        protected static $rel = [];
        function __construct(array $a = []) { $this->attrs = $a; }
        static function query() { return new Query(static::class); }
        static function __callStatic($n, $a) { return (new Query(static::class))->$n(...$a); }
        static function find($id) { return static::query()->where('id', $id)->first(); }
        function __get($k) {
            if (isset(static::$rel[$k])) { [$cls, $fk] = static::$rel[$k]; return ($cls)::find($this->attrs[$fk] ?? null); }
            $v = $this->attrs[$k] ?? null;
            if ($v !== null && in_array($k, static::$dates) && is_string($v)) return new \Carbon($v);
            return $v;
        }
        function __set($k, $v) { $this->attrs[$k] = $v instanceof \Carbon ? (string) $v : $v; }
        function __isset($k) { return isset($this->attrs[$k]); }
        function refresh() { $r = DB::$pdo->prepare('SELECT * FROM `' . static::$table . '` WHERE id = ?'); $r->execute([$this->attrs['id']]); $this->attrs = $r->fetch(\PDO::FETCH_ASSOC); return $this; }
        function save() {
            $now = date('Y-m-d H:i:s');
            if (empty($this->attrs['id'])) {
                $this->attrs['created_at'] = $this->attrs['created_at'] ?? $now; $this->attrs['updated_at'] = $now;
                $cols = array_keys($this->attrs);
                $s = DB::$pdo->prepare('INSERT INTO `' . static::$table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
                $s->execute(array_values($this->attrs)); $this->attrs['id'] = DB::$pdo->lastInsertId();
            } else {
                $sets = []; $b = []; foreach ($this->attrs as $k => $v) { if ($k == 'id') continue; $sets[] = "`$k` = ?"; $b[] = $v; }
                $b[] = $this->attrs['id'];
                DB::$pdo->prepare('UPDATE `' . static::$table . '` SET ' . implode(',', $sets) . ' WHERE id = ?')->execute($b);
            }
            return true;
        }
        function increment($col, $amt) { DB::$pdo->prepare('UPDATE `' . static::$table . "` SET `$col` = `$col` + ? WHERE id = ?")->execute([$amt, $this->attrs['id']]); $this->refresh(); }
    }
    class User extends Model { public static $table = 'users'; }
    class Currency extends Model { public static $table = 'currencies'; }
    class Withdraw extends Model { public static $table = 'withdraws'; }
    class Transaction extends Model { public static $table = 'transactions'; }
    class PaymentGateway extends Model { public static $table = 'payment_gateways'; function convertAutoData() { return json_decode($this->attrs['information'], true); } }
    class Wallet extends Model { public static $table = 'wallets'; protected static $rel = ['currency' => [Currency::class, 'currency_id']]; }
    class Withdrawals extends Model { public static $table = 'withdrawals'; protected static $rel = ['user' => [User::class, 'user_id'], 'method' => [Withdraw::class, 'method_id'], 'currency' => [Currency::class, 'currency_id']]; }
}
