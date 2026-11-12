<?php
// TopUp Ulan - PHP version in ONE file (replaces server.js)
// Run:  C:\xampp\php\php.exe -S localhost:3000 -t public router.php

$uriPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (strpos($uriPath, '/api/') !== 0) {
    return false; // not an API call: the server sends the file from /public
}
header('Content-Type: application/json; charset=utf-8');

// ---------- Catalog (edit prices/packs/images here) ----------
$GAMES = [
    'codm' => [
        'id' => 'codm',
        'name' => 'Call of Duty: Mobile',
        'publisher' => 'Activision / Garena',
        'currency' => 'CP',
        'icon' => '/img/cp.jpg',
        'cover' => '/img/one.jpg',
        'fields' => [
            ['key' => 'userId', 'label' => 'Open ID', 'placeholder' => 'e.g. 6742910385'],
        ],
        'packs' => [
            ['id' => 'cp80', 'amount' => 80, 'price' => 49],
            ['id' => 'cp400', 'amount' => 400, 'price' => 245],
            ['id' => 'cp800', 'amount' => 800, 'price' => 490],
            ['id' => 'cp2000', 'amount' => 2000, 'price' => 1225],
            ['id' => 'cp5000', 'amount' => 5000, 'price' => 2990],
            ['id' => 'cp10000', 'amount' => 10000, 'price' => 5900],
        ],
    ],
    'ml' => [
        'id' => 'ml',
        'name' => 'Mobile Legends: Bang Bang',
        'publisher' => 'Moonton',
        'currency' => 'Diamonds',
        'icon' => '/img/diamondd.jpg',
        'cover' => '/img/ml.png',
        'fields' => [
            ['key' => 'userId', 'label' => 'User ID', 'placeholder' => 'e.g. 123456789'],
            ['key' => 'zoneId', 'label' => 'Zone ID', 'placeholder' => 'e.g. 1234'],
        ],
        'packs' => [
            ['id' => 'd5', 'amount' => 5, 'price' => 6.25],
            ['id' => 'd11', 'amount' => 11, 'note' => '10 + 1 Bonus', 'price' => 12.49],
            ['id' => 'd22', 'amount' => 22, 'price' => 24.99],
            ['id' => 'd56', 'amount' => 56, 'price' => 62.47],
            ['id' => 'd112', 'amount' => 112, 'price' => 124.94],
            ['id' => 'd223', 'amount' => 223, 'price' => 249.87],
            ['id' => 'd336', 'amount' => 336, 'price' => 374.81],
            ['id' => 'd570', 'amount' => 570, 'price' => 624.68],
            ['id' => 'd1163', 'amount' => 1163, 'price' => 1249.36],
            ['id' => 'd2398', 'amount' => 2398, 'price' => 2498.72],
            ['id' => 'd6042', 'amount' => 6042, 'price' => 6246.8],
            ['id' => 'wdp', 'amount' => 'Weekly Diamond Pass', 'price' => 131.18],
            ['id' => 'wep', 'amount' => 'Weekly Elite Pack', 'price' => 62.4],
            ['id' => 'mep', 'amount' => 'Monthly Epic Bundle', 'price' => 307.34],
            ['id' => 'twp', 'amount' => 'Twilight Pass', 'price' => 622.38],
        ],
    ],
];

// rate = service fee as a fraction of the price; mobile = needs a SIM number
$PAYMENTS = [
    ['id' => 'gcash', 'name' => 'GCash', 'rate' => 0],
    ['id' => 'maya', 'name' => 'Maya', 'rate' => 0],
    ['id' => 'card', 'name' => 'Credit / Debit Card', 'rate' => 0.035],
    ['id' => '7eleven', 'name' => '7-Eleven (CLiQQ)', 'rate' => 0.02],
    ['id' => 'globe', 'name' => 'Globe', 'rate' => 0.4, 'mobile' => true],
    ['id' => 'tm', 'name' => 'TM', 'rate' => 0.4, 'mobile' => true],
    ['id' => 'smart', 'name' => 'Smart', 'rate' => 0.4, 'mobile' => true],
    ['id' => 'tnt', 'name' => 'TNT', 'rate' => 0.4, 'mobile' => true],
    ['id' => 'dito', 'name' => 'DITO', 'rate' => 0.4, 'mobile' => true],
];

$VAT_RATE = 0;

// Max new orders from one IP address per hour (stops spam)
const MAX_ORDERS_PER_HOUR = 10;

// Optional phone alert: when a customer taps "I've sent the payment",
// a Telegram message is sent to you. Leave the first two empty to turn it off.
const TELEGRAM_BOT_TOKEN = '';   // from @BotFather
const TELEGRAM_CHAT_ID = '';     // your chat id
const ADMIN_URL = '';            // optional, e.g. 

// ---------- Helpers ----------
function out($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail($msg, $code = 400)
{
    out(['error' => $msg], $code);
}

function body()
{
    $j = json_decode(file_get_contents('php://input'), true);
    return is_array($j) ? $j : [];
}

function round2($n)
{
    return round($n, 2);
}

function makeQuote($pack, $pay)
{
    global $VAT_RATE;
    $price = $pack['price'];
    $fee = round2($price * $pay['rate']);
    $vat = round2(($price + $fee) * $VAT_RATE);
    return ['price' => $price, 'fee' => $fee, 'vat' => $vat, 'total' => round2($price + $fee + $vat)];
}

// Connect your supplier's ID-check API here. Return a nickname or null.
function lookupNickname($game, $account)
{
    return null;
}

function findGame($id)
{
    global $GAMES;
    return isset($GAMES[$id]) ? $GAMES[$id] : null;
}

function findPack($game, $packId)
{
    foreach ($game['packs'] as $p) {
        if ($p['id'] === $packId) return $p;
    }
    return null;
}

function findPay($id)
{
    global $PAYMENTS;
    foreach ($PAYMENTS as $p) {
        if ($p['id'] === $id) return $p;
    }
    return null;
}

function mask($v)
{
    $v = (string)$v;
    $n = strlen($v);
    return $n > 3 ? str_repeat('•', $n - 3) . substr($v, -3) : $v;
}

// Orders are saved in orders.json (outside /public)
function withOrders($write, $fn)
{
    $fp = fopen(__DIR__ . '/orders.json', 'c+');
    if (!$fp) fail('Server error: cannot open orders file.', 500);

    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $orders = json_decode($raw ? $raw : '[]', true);
    if (!is_array($orders)) $orders = [];

    $result = $fn($orders);

    if ($write) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $result;
}

// Send a Telegram message to the owner. Never breaks the order if it fails.
function notifyOwner($text)
{
    if (TELEGRAM_BOT_TOKEN === '' || TELEGRAM_CHAT_ID === '') return;
    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';
    $data = http_build_query(['chat_id' => TELEGRAM_CHAT_ID, 'text' => $text]);
    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $data,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 4,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } else {
            @file_get_contents($url, false, stream_context_create(['http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $data,
                'timeout' => 4,
            ]]));
        }
    } catch (Throwable $e) {
        // ignore
    }
}

// Random, hard-to-guess order number, e.g. TU-9F3A27C1
function newOrderId()
{
    return 'TU-' . strtoupper(bin2hex(random_bytes(4)));
}

// ---------- Routing ----------
$route = trim(substr($uriPath, 5), '/');
$method = $_SERVER['REQUEST_METHOD'];
$parts = explode('/', $route);

// GET games
if ($method === 'GET' && $route === 'games') {
    out(['games' => array_values($GAMES), 'payments' => $PAYMENTS]);
}

// POST quotes (price for every payment method)
if ($method === 'POST' && $route === 'quotes') {
    $b = body();
    $game = findGame(isset($b['gameId']) ? $b['gameId'] : '');
    $pack = $game ? findPack($game, isset($b['packId']) ? $b['packId'] : '') : null;
    if (!$pack) fail('Select an amount first.');

    $quotes = [];
    foreach ($PAYMENTS as $p) {
        $quotes[$p['id']] = makeQuote($pack, $p);
    }
    $account = isset($b['account']) && is_array($b['account']) ? $b['account'] : [];
    out(['quotes' => $quotes, 'nickname' => lookupNickname($game, $account)]);
}

// POST quote (one payment method)
if ($method === 'POST' && $route === 'quote') {
    $b = body();
    $game = findGame(isset($b['gameId']) ? $b['gameId'] : '');
    $pack = $game ? findPack($game, isset($b['packId']) ? $b['packId'] : '') : null;
    $pay = findPay(isset($b['paymentId']) ? $b['paymentId'] : '');
    if (!$game || !$pack || !$pay) fail('Choose a game, an amount, and a payment method.');
    out(['quote' => makeQuote($pack, $pay), 'nickname' => null]);
}

// POST orders
if ($method === 'POST' && $route === 'orders') {
    $b = body();
    $game = findGame(isset($b['gameId']) ? $b['gameId'] : '');
    $pack = $game ? findPack($game, isset($b['packId']) ? $b['packId'] : '') : null;
    $pay = findPay(isset($b['paymentId']) ? $b['paymentId'] : '');
    if (!$game || !$pack || !$pay) fail('Choose a game, an amount, and a payment method.');

    $account = isset($b['account']) && is_array($b['account']) ? $b['account'] : [];
    foreach ($game['fields'] as $f) {
        $value = trim((string)(isset($account[$f['key']]) ? $account[$f['key']] : ''));
        $pattern = isset($f['pattern']) ? $f['pattern'] : '^\d{4,20}$';
        if (!preg_match('/' . $pattern . '/', $value)) {
            $hint = isset($f['hint']) ? $f['hint'] : 'numbers only';
            fail('Enter a valid ' . $f['label'] . ' (' . $hint . ').');
        }
        $account[$f['key']] = $value;
    }
    // keep only the fields this game asks for
    $clean = [];
    foreach ($game['fields'] as $f) $clean[$f['key']] = $account[$f['key']];
    $account = $clean;

    $payerMobile = trim((string)(isset($b['payerMobile']) ? $b['payerMobile'] : ''));
    if (!empty($pay['mobile']) && !preg_match('/^09\d{9}$/', $payerMobile)) {
        fail('Enter the mobile number to charge (11 digits, starts with 09).');
    }

    $email = trim((string)(isset($b['email']) ? $b['email'] : ''));
    if ($email !== '' && (strlen($email) > 120 || !preg_match('/^\S+@\S+\.\S+$/', $email))) {
        fail("That email doesn't look right. You can leave it blank.");
    }

    $quote = makeQuote($pack, $pay);
    $item = (is_int($pack['amount']) ? number_format($pack['amount']) . ' ' . $game['currency'] : $pack['amount'])
        . (isset($pack['note']) ? ' (' . $pack['note'] . ')' : '');

    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';

    $order = [
        'id' => newOrderId(),
        'game' => $game['name'],
        'pack' => $pack['amount'],
        'item' => $item,
        'account' => $account,
        'email' => $email,
        'payment' => $pay['name'],
        'payerMobile' => !empty($pay['mobile']) ? $payerMobile : null,
        'price' => $quote['price'],
        'fee' => $quote['fee'],
        'vat' => $quote['vat'],
        'total' => $quote['total'],
        'status' => 'pending_payment',
        'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
        'ip' => $ip,
    ];

    $limited = false;
    withOrders(true, function (&$orders) use ($order, $ip, &$limited) {
        $since = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
        $recent = 0;
        foreach ($orders as $o) {
            if (($o['ip'] ?? '') === $ip && ($o['createdAt'] ?? '') >= $since) $recent++;
        }
        if ($recent >= MAX_ORDERS_PER_HOUR) {
            $limited = true;
            return;
        }
        $orders[] = $order;
    });
    if ($limited) fail('Too many orders. Please try again later.', 429);

    unset($order['ip']);
    out(['order' => $order]);
}

// POST orders/{id}/report  (customer says "I already paid")
// This does NOT approve anything. It only moves the order to
// "awaiting_confirmation" so you can check it in admin.php.
if ($method === 'POST' && count($parts) === 3 && $parts[0] === 'orders' && $parts[2] === 'report') {
    $id = strtoupper(trim($parts[1]));
    $state = 'missing';
    $info = null;
    withOrders(true, function (&$orders) use ($id, &$state, &$info) {
        foreach ($orders as &$o) {
            if (($o['id'] ?? '') === $id) {
                $s = $o['status'] ?? '';
                if ($s === 'pending_payment') {
                    $o['status'] = 'awaiting_confirmation';
                    $o['reportedAt'] = gmdate('Y-m-d\TH:i:s\Z');
                    $state = 'ok';
                    $info = $o;
                } else {
                    $state = $s; // already reported / paid / rejected: change nothing
                }
                break;
            }
        }
    });
    if ($state === 'missing') fail('Order not found.', 404);
    if ($state === 'ok' && $info) {
        notifyOwner("🔔 Payment to check\nOrder: " . $id . "\n" . $info['game'] . " - " . $info['item']
            . "\nTotal: ₱" . number_format((float)$info['total'], 2) . " via " . $info['payment']
            . (ADMIN_URL !== '' ? "\n" . ADMIN_URL : ''));
    }
    if ($state === 'ok' || $state === 'awaiting_confirmation') out(['ok' => true, 'status' => 'awaiting_confirmation']);
    out(['ok' => true, 'status' => $state]);
}

// GET orders/{id} (email, SIM number and IP are never returned; the game ID is shown so
// the customer can check it. Order numbers are random, so only the buyer knows the link.)
if ($method === 'GET' && count($parts) === 2 && $parts[0] === 'orders') {
    $id = strtoupper(trim($parts[1]));
    $order = withOrders(false, function (&$orders) use ($id) {
        foreach ($orders as $o) {
            if ($o['id'] === $id) return $o;
        }
        return null;
    });
    if (!$order) fail('Order not found. Check your order ID.', 404);

    $acc = isset($order['account']) && is_array($order['account']) ? $order['account'] : [];
    $main = isset($acc['userId']) ? $acc['userId'] : (isset($acc['mobile']) ? $acc['mobile'] : '');
    $zone = isset($acc['zoneId']) ? $acc['zoneId'] : '';
    $payer = isset($order['payerMobile']) ? $order['payerMobile'] : null;

    $g = null;
    foreach ($GAMES as $x) {
        if ($x['name'] === $order['game']) $g = $x;
    }
    $label = 'Account ID';
    if ($g) {
        $names = [];
        foreach ($g['fields'] as $i => $f) {
            $names[] = $i ? '(' . $f['label'] . ')' : $f['label'];
        }
        $label = implode(' ', $names);
    }

    unset($order['email'], $order['account'], $order['payerMobile'], $order['ip']);
    $order['accountMasked'] = mask($main) . ($zone !== '' ? '(' . mask($zone) . ')' : '');
    $order['accountId'] = trim($main . ($zone !== '' ? ' (' . $zone . ')' : ''));
    $order['accountLabel'] = $label;
    $order['payerMasked'] = $payer ? mask($payer) : null;
    out(['order' => $order]);
}

// NOTE: the old mock pay routes (/api/mock/pay/... and /api/payment/pay/...)
// were removed on purpose. Only admin.php can mark an order as paid.

fail('Not found.', 404);