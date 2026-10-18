<?php
declare(strict_types=1);

/*
 * M-seleni demo e-wallet: JSON API.
 * The browser page (index.html) talks to this file; this file talks to PostgreSQL.
 * All money is fake. This is a class demo, not a real payment system.
 *
 *   GET  api.php?action=state       who is logged in, balance, history
 *   POST api.php?action=register    {name, username, pin}
 *   POST api.php?action=login       {username, pin}
 *   POST api.php?action=logout
 *   POST api.php?action=topup       {amount}
 *   POST api.php?action=transfer    {to, amount, note}
 *   POST api.php?action=bill        {biller, account, amount}
 */

ini_set('display_errors', '0');          // never show PHP errors to the browser
ini_set('log_errors', '1');              // they go to the Apache error log instead

require __DIR__ . '/db.php';

const MAX_CENTS       = 5000000;         // 50,000.00 pesos per transaction
const MAX_FAILED_PINS = 5;               // wrong PINs before a 5 minute lock
// Used so a wrong username takes as long to reject as a wrong PIN.
const DUMMY_HASH      = '$2y$10$wP.R435xS8e7WmfkW4tBKO.U6tONWIGEr8wj2cBUXnXIAcZ9nO5fa';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/* ------------------------------------------------------------------ */
/* small helpers                                                      */
/* ------------------------------------------------------------------ */

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $code = 400): never
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    out(['error' => $message], $code);
}

function textLen(string $s): int
{
    $n = preg_match_all('/./us', $s);
    return $n === false ? PHP_INT_MAX : $n;     // invalid UTF-8 counts as "too long"
}

/** Check an amount typed by the user and return it as a string like "150.50". No floats. */
function parseAmount(mixed $v, int $minCents): string
{
    if (is_int($v)) {
        $v = (string)$v;
    }
    if (!is_string($v) || !preg_match('/^(\d{1,8})(?:\.(\d{1,2}))?$/', trim($v), $m)) {
        fail('Enter a valid amount');
    }
    $cents = (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
    if ($cents < $minCents) {
        fail('Minimum amount is ₱' . number_format($minCents / 100, 2));
    }
    if ($cents > MAX_CENTS) {
        fail('Maximum per transaction is ₱50,000.00');
    }
    return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
}

function requireUser(): int
{
    $uid = $_SESSION['uid'] ?? null;
    if (!is_int($uid)) {
        fail('Please log in again.', 401);
    }
    return $uid;
}

function currentUser(int $uid): ?array
{
    $st = db()->prepare(
        'SELECT u.username, u.full_name, w.balance
           FROM users u JOIN wallets w ON w.user_id = u.id
          WHERE u.id = :id'
    );
    $st->execute([':id' => $uid]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/* ------------------------------------------------------------------ */
/* reading the ledger                                                 */
/* ------------------------------------------------------------------ */

const TX_SELECT =
    'SELECT t.ref, t.type, t.amount, t.note, t.biller, t.account_no AS account, t.token,
            (EXTRACT(EPOCH FROM t.created_at) * 1000)::bigint AS ts,
            fu.username AS from_user, fu.full_name AS from_name,
            tu.username AS to_user,   tu.full_name AS to_name
       FROM transactions t
       LEFT JOIN users fu ON fu.id = t.from_user
       LEFT JOIN users tu ON tu.id = t.to_user ';

/** Turn a ledger row into what the page needs, from the point of view of $me. */
function shapeTx(array $r, array $me): array
{
    $t = [
        'id'     => $r['ref'],
        'amount' => (float)$r['amount'],
        'note'   => (string)($r['note'] ?? ''),
        'ts'     => (int)$r['ts'],
    ];
    if ($r['type'] === 'topup') {
        $t['type']      = 'topup';
        $t['other']     = $me['username'];
        $t['otherName'] = $me['full_name'];
    } elseif ($r['type'] === 'transfer') {
        if ($r['from_user'] === $me['username']) {
            $t['type']      = 'sent';
            $t['other']     = $r['to_user'];
            $t['otherName'] = $r['to_name'];
        } else {
            $t['type']      = 'received';
            $t['other']     = $r['from_user'];
            $t['otherName'] = $r['from_name'];
        }
    } else {
        $t['type']    = 'bill';
        $t['other']   = $r['biller'];
        $t['account'] = $r['account'];
        if ($r['token'] !== null) {
            $t['token'] = $r['token'];
        }
    }
    return $t;
}

function fetchTx(string $ref, array $me): array
{
    $st = db()->prepare(TX_SELECT . 'WHERE t.ref = :ref');
    $st->execute([':ref' => $ref]);
    return shapeTx($st->fetch(), $me);
}

function state(): array
{
    $uid = $_SESSION['uid'] ?? null;
    if (!is_int($uid)) {
        return ['user' => null];
    }
    $me = currentUser($uid);
    if ($me === null) {                      // account vanished: drop the session
        $_SESSION = [];
        return ['user' => null];
    }
    $pdo = db();

    $st = $pdo->prepare(TX_SELECT . 'WHERE t.from_user = :a OR t.to_user = :b ORDER BY t.id DESC LIMIT 100');
    $st->execute([':a' => $uid, ':b' => $uid]);
    $tx = array_map(fn($r) => shapeTx($r, $me), $st->fetchAll());

    $billers = [];
    foreach ($pdo->query(
        'SELECT code, label, company, icon, note, field_label, field_hint, issues_token
           FROM billers WHERE active ORDER BY sort_order, code'
    )->fetchAll() as $b) {
        $billers[$b['code']] = [
            'label'   => $b['label'],   'company' => $b['company'],
            'icon'    => $b['icon'],    'note'    => $b['note'],
            'field'   => $b['field_label'], 'hint' => $b['field_hint'],
        ];
    }

    // the last meter/account number used for each biller, so the form can pre-fill it
    $st = $pdo->prepare(
        "SELECT DISTINCT ON (biller) biller, account_no
           FROM transactions WHERE from_user = :u AND type = 'bill'
          ORDER BY biller, id DESC"
    );
    $st->execute([':u' => $uid]);
    $saved = [];
    foreach ($st->fetchAll() as $r) {
        $saved[$r['biller']] = $r['account_no'];
    }

    // people this user has paid before, newest first
    $st = $pdo->prepare(
        "SELECT u.username
           FROM transactions t JOIN users u ON u.id = t.to_user
          WHERE t.type = 'transfer' AND t.from_user = :u
          GROUP BY u.username ORDER BY max(t.id) DESC LIMIT 5"
    );
    $st->execute([':u' => $uid]);
    $recent = array_column($st->fetchAll(), 'username');

    return [
        'user'    => [
            'username' => $me['username'],
            'name'     => $me['full_name'],
            'balance'  => (float)$me['balance'],
        ],
        'tx'      => $tx,
        'billers' => $billers,
        'saved'   => (object)$saved,
        'recent'  => $recent,
    ];
}

/* ------------------------------------------------------------------ */
/* writing to the ledger                                              */
/* ------------------------------------------------------------------ */

/** Insert one ledger row and return its reference number. */
function insertTx(
    PDO $pdo, string $type, ?int $from, ?int $to, string $amount,
    ?string $note = null, ?string $biller = null, ?string $account = null, ?string $token = null
): string {
    $ref = 'MS' . strtoupper(bin2hex(random_bytes(5)));
    $st = $pdo->prepare(
        'INSERT INTO transactions (ref, type, from_user, to_user, biller, account_no, token, amount, note)
         VALUES (:ref, :type, :f, :t, :b, :acc, :tok, :amt, :note)'
    );
    $st->execute([
        ':ref' => $ref, ':type' => $type, ':f' => $from, ':t' => $to, ':b' => $biller,
        ':acc' => $account, ':tok' => $token, ':amt' => $amount, ':note' => $note,
    ]);
    return $ref;
}

function startSession(int $uid): void
{
    session_regenerate_id(true);            // new session id after login (stops session fixation)
    $_SESSION['uid'] = $uid;
}

function doRegister(array $b): never
{
    $name = trim((string)($b['name'] ?? ''));
    $user = strtolower(trim((string)($b['username'] ?? '')));
    $pin  = (string)($b['pin'] ?? '');

    if ($name === '' || textLen($name) > 30)             fail('Please enter your name (up to 30 characters).');
    if (!preg_match('/^[a-z0-9_]{3,16}$/', $user))       fail('Username: 3-16 letters, numbers or _');
    if (!preg_match('/^\d{4}$/', $pin))                  fail('PIN must be 4 digits');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('INSERT INTO users (username, full_name, pin_hash) VALUES (:u, :n, :h) RETURNING id');
        $st->execute([':u' => $user, ':n' => $name, ':h' => password_hash($pin, PASSWORD_BCRYPT)]);
        $id = (int)$st->fetchColumn();

        $pdo->prepare('INSERT INTO wallets (user_id, balance) VALUES (:id, 500)')->execute([':id' => $id]);
        insertTx($pdo, 'topup', null, $id, '500.00', 'Welcome bonus');
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e->getCode() === '23505') {                 // unique violation: username already exists
            fail('That username is taken');
        }
        throw $e;
    }
    startSession($id);
    out(['ok' => true, 'state' => state()]);
}

function doLogin(array $b): never
{
    $user = strtolower(trim((string)($b['username'] ?? '')));
    $pin  = (string)($b['pin'] ?? '');
    $pdo  = db();

    $st = $pdo->prepare(
        'SELECT id, pin_hash, (locked_until IS NOT NULL AND locked_until > now()) AS locked
           FROM users WHERE username = :u'
    );
    $st->execute([':u' => $user]);
    $u = $st->fetch();

    if ($u === false) {
        password_verify($pin, DUMMY_HASH);               // same delay as a real check
        fail('Wrong username or PIN', 401);
    }
    if ($u['locked']) {
        fail('Too many wrong attempts. Try again in 5 minutes.', 429);
    }
    if (!password_verify($pin, $u['pin_hash'])) {
        $pdo->prepare(
            "UPDATE users
                SET locked_until    = CASE WHEN failed_attempts + 1 >= :max THEN now() + interval '5 minutes' ELSE locked_until END,
                    failed_attempts = CASE WHEN failed_attempts + 1 >= :max2 THEN 0 ELSE failed_attempts + 1 END
              WHERE id = :id"
        )->execute([':max' => MAX_FAILED_PINS, ':max2' => MAX_FAILED_PINS, ':id' => $u['id']]);
        fail('Wrong username or PIN', 401);
    }

    $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = :id')
        ->execute([':id' => $u['id']]);
    startSession((int)$u['id']);
    out(['ok' => true, 'state' => state()]);
}

function doLogout(): never
{
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600, 'path' => $p['path'],
        'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_destroy();
    out(['ok' => true]);
}

function doTopup(int $uid, array $b): never
{
    $amount = parseAmount($b['amount'] ?? null, 100);
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE wallets SET balance = balance + :a WHERE user_id = :u')
        ->execute([':a' => $amount, ':u' => $uid]);
    $ref = insertTx($pdo, 'topup', null, $uid, $amount);
    $pdo->commit();
    out(['ok' => true, 'receipt' => fetchTx($ref, currentUser($uid)), 'state' => state()]);
}

function doTransfer(int $uid, array $b): never
{
    $to     = strtolower(ltrim(trim((string)($b['to'] ?? '')), '@'));
    $note   = trim((string)($b['note'] ?? ''));
    $amount = parseAmount($b['amount'] ?? null, 100);
    if (textLen($note) > 40) {
        fail('The note is too long (40 characters max).');
    }

    $pdo = db();
    $pdo->beginTransaction();

    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute([':u' => $to]);
    $rid = $st->fetchColumn();
    if ($rid === false) {
        fail('No user named @' . $to, 404);
    }
    $rid = (int)$rid;
    if ($rid === $uid) {
        fail("You can't send money to yourself");
    }

    // Lock both wallets, lowest id first. Two people paying each other at the
    // same moment then queue up instead of deadlocking.
    $ids = [$uid, $rid];
    sort($ids);
    $pdo->prepare('SELECT user_id FROM wallets WHERE user_id IN (:a, :b) ORDER BY user_id FOR UPDATE')
        ->execute([':a' => $ids[0], ':b' => $ids[1]]);

    // Take the money only if enough is there. The check and the subtraction are one statement.
    $st = $pdo->prepare('UPDATE wallets SET balance = balance - :amt WHERE user_id = :u AND balance >= :need');
    $st->execute([':amt' => $amount, ':u' => $uid, ':need' => $amount]);
    if ($st->rowCount() !== 1) {
        fail('Not enough balance');
    }
    $pdo->prepare('UPDATE wallets SET balance = balance + :amt WHERE user_id = :u')
        ->execute([':amt' => $amount, ':u' => $rid]);

    $ref = insertTx($pdo, 'transfer', $uid, $rid, $amount, $note !== '' ? $note : null);
    $pdo->commit();                                       // both balances and the ledger row land together
    out(['ok' => true, 'receipt' => fetchTx($ref, currentUser($uid)), 'state' => state()]);
}

function doBill(int $uid, array $b): never
{
    $code    = (string)($b['biller'] ?? '');
    $account = str_replace(' ', '', trim((string)($b['account'] ?? '')));
    $amount  = parseAmount($b['amount'] ?? null, 1000);   // minimum 10 pesos

    $pdo = db();
    $st = $pdo->prepare('SELECT field_label, issues_token FROM billers WHERE code = :c AND active');
    $st->execute([':c' => $code]);
    $biller = $st->fetch();
    if ($biller === false) {
        fail('Unknown biller', 404);
    }
    if (!preg_match('/^\d{6,14}$/', $account)) {
        fail($biller['field_label'] . ' must be 6-14 digits');
    }

    $token = null;
    if ($biller['issues_token']) {                        // made-up demo token, loads no real meter
        $token = implode('-', array_map(
            fn() => str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT),
            range(1, 5)
        ));
    }

    $pdo->beginTransaction();
    $st = $pdo->prepare('UPDATE wallets SET balance = balance - :amt WHERE user_id = :u AND balance >= :need');
    $st->execute([':amt' => $amount, ':u' => $uid, ':need' => $amount]);
    if ($st->rowCount() !== 1) {
        fail('Not enough balance');
    }
    $ref = insertTx($pdo, 'bill', $uid, null, $amount, null, $code, $account, $token);
    $pdo->commit();
    out(['ok' => true, 'receipt' => fetchTx($ref, currentUser($uid)), 'state' => state()]);
}

/* ------------------------------------------------------------------ */
/* routing                                                            */
/* ------------------------------------------------------------------ */

session_name('mseleni');
session_set_cookie_params([
    'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']),
]);
session_start();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($_GET['action'] ?? '');
$body   = [];

if ($method === 'POST') {
    // Only JSON is accepted. A web page on another site cannot send JSON to us
    // without our permission, which blocks cross-site request forgery.
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
        out(['error' => 'Bad request'], 415);
    }
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) {
        out(['error' => 'Bad request'], 400);
    }
} elseif ($method !== 'GET') {
    out(['error' => 'Method not allowed'], 405);
}

try {
    if ($action === 'state' && $method === 'GET') {
        out(state());
    }
    if ($method !== 'POST') {
        out(['error' => 'Not found'], 404);
    }
    switch ($action) {
        case 'register': doRegister($body);
        case 'login':    doLogin($body);
        case 'logout':   doLogout();
        case 'topup':    doTopup(requireUser(), $body);
        case 'transfer': doTransfer(requireUser(), $body);
        case 'bill':     doBill(requireUser(), $body);
        default:         out(['error' => 'Not found'], 404);
    }
} catch (Throwable $e) {
    error_log('M-seleni API error: ' . $e->getMessage());
    try {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
    } catch (Throwable $ignore) {
    }
    out(['error' => 'Server error. Please try again.'], 500);
}
