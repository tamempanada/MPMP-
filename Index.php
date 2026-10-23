<?php
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
require_once __DIR__ . '/Product.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$self = basename(__FILE__);
$errors = [];
$old = [];
$editing = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedAction = $_POST['action'] ?? '';
    $action = is_scalar($postedAction) ? (string)$postedAction : '';
    $postedId = $_POST['id'] ?? null;
    $id = is_scalar($postedId) ? filter_var($postedId, FILTER_VALIDATE_INT) : false;

    try {
        $postedToken = $_POST['csrf_token'] ?? '';
        if (!is_string($postedToken) || !hash_equals($_SESSION['csrf_token'], $postedToken)) {
            throw new Exception('Your session token expired. Refresh the page and try again.');
        }

        if ($action === 'create' || $action === 'update') {
            [$data, $errors] = validateProduct($_POST);
            if (!$errors) {
                if ($action === 'create') {
                    createProduct($data);
                    $_SESSION['msg'] = 'Product added to your inventory.';
                } else {
                    if (!$id || !getProduct($id)) {
                        throw new Exception('That product could not be found.');
                    }
                    updateProduct($id, $data);
                    $_SESSION['msg'] = 'Product details updated.';
                }
            }
        } elseif (in_array($action, ['delete', 'sell', 'restock'], true)) {
            if (!$id || !getProduct($id)) {
                throw new Exception('That product could not be found.');
            }
            if ($action === 'delete') {
                deleteProduct($id);
                $_SESSION['msg'] = 'Product deleted. Existing transaction history was retained.';
            } elseif ($action === 'restock') {
                $amount = restockProduct($id, $_POST['qty'] ?? '');
                $_SESSION['msg'] = 'Stock received. ' . money($amount) . ' recorded as restock spending.';
            } else {
                $amount = sellProduct($id, $_POST['qty'] ?? '');
                $_SESSION['msg'] = 'Sale recorded for ' . money($amount) . '.';
            }
        } else {
            throw new Exception('Choose a valid action and try again.');
        }
    } catch (Exception $e) {
        $errors[] = $e instanceof PDOException ? 'A database error occurred. Please try again.' : $e->getMessage();
    }

    if (!$errors) {
        header('Location: ' . $self);
        exit;
    }
    $old = $_POST;
}

$msg = $_SESSION['msg'] ?? '';
unset($_SESSION['msg']);
$requestedEdit = $_GET['edit'] ?? null;
$editId = is_scalar($requestedEdit) ? filter_var($requestedEdit, FILTER_VALIDATE_INT) : false;
if ($editId && $editId > 0) {
    $editing = getProduct($editId);
}
if ($editing && !$old) {
    $old = $editing;
}

$dash = getDashboard();
$products = getProducts();
$recent = getRecentTransactions(10);
$weekly = getWeeklyActivity(7);

function v($arr, $key)
{
    $value = $arr[$key] ?? '';
    return htmlspecialchars(is_scalar($value) ? (string)$value : '', ENT_QUOTES, 'UTF-8');
}
function h($value)
{
    return htmlspecialchars(is_scalar($value) ? (string)$value : '', ENT_QUOTES, 'UTF-8');
}
function csrfField()
{
    return '<input type="hidden" name="csrf_token" value="' . h($_SESSION['csrf_token']) . '">';
}
function shortDate($value)
{
    $date = strtotime((string)$value);
    return $date ? date('M j, Y · g:i a', $date) : (string)$value;
}

$chartMax = 1;
foreach ($weekly as $day) {
    $chartMax = max($chartMax, (float)$day['sales'], (float)$day['restock']);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f7fc">
    <title>Overview · Hellmerry's Vape Shop</title>
    <style>
        :root{--ink:#172033;--muted:#788398;--line:#e7ebf2;--blue:#2878f0;--blue-soft:#eaf2ff;--green:#16a765;--orange:#f08a34;--red:#d84a4a;--paper:#fff;--canvas:#f4f6fa;--shadow:0 8px 28px rgba(26,43,77,.055)}
        *{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--canvas);color:var(--ink);font:15px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}button,input,textarea{font:inherit}button,a{-webkit-tap-highlight-color:transparent}a{color:inherit;text-decoration:none}button{cursor:pointer}
        .app{min-height:100vh;display:grid;grid-template-columns:82px minmax(0,1fr)}.rail{position:sticky;top:0;height:100vh;padding:25px 13px;background:#f8f9fc;border-right:1px solid var(--line);display:flex;align-items:center;flex-direction:column;gap:13px}.brand{width:46px;height:46px;display:grid;place-items:center;border-radius:15px;background:linear-gradient(145deg,#4a9bff,#1767e7);color:#fff;font-size:17px;font-weight:800;box-shadow:0 7px 16px #2878f044;margin-bottom:17px}.rail a{width:44px;height:44px;border-radius:14px;display:grid;place-items:center;color:#8792a4;font-size:20px;transition:.18s}.rail a:hover,.rail a.active{color:var(--blue);background:#e9f1ff}.rail .spacer{flex:1}.rail .rail-bottom{color:#566176}.main{width:min(1600px,100%);margin:0 auto;padding:36px clamp(20px,3.5vw,58px) 54px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:26px}.eyebrow{text-transform:uppercase;font-size:11px;letter-spacing:.13em;color:var(--muted);font-weight:750}.topbar h1{font-size:clamp(26px,3vw,34px);letter-spacing:-.04em;line-height:1.15;margin:5px 0 0}.top-actions{display:flex;align-items:center;gap:10px}.date-pill,.icon-button{height:42px;border:1px solid var(--line);background:#fff;border-radius:13px;color:#657187;padding:0 14px;display:flex;align-items:center;gap:8px}.icon-button{width:42px;justify-content:center;padding:0;font-size:18px}.button{display:inline-flex;justify-content:center;align-items:center;gap:8px;border:0;border-radius:12px;padding:11px 16px;background:var(--blue);color:#fff;font-weight:700;box-shadow:0 5px 12px #2878f02a;transition:transform .16s,background .16s}.button:hover{background:#1768df;transform:translateY(-1px)}.button.secondary{background:#fff;color:#566176;border:1px solid var(--line);box-shadow:none}.button.danger{background:#fff1f0;color:var(--red);box-shadow:none}.button.small{font-size:13px;padding:8px 11px;border-radius:10px}.notice{padding:13px 16px;border-radius:13px;margin:-8px 0 19px;border:1px solid #cbeedc;background:#effaf4;color:#137843}.notice.error{border-color:#f3d1cf;background:#fff4f3;color:#ad3838}.notice ul{margin:0;padding-left:20px}
        .stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:15px;margin-bottom:17px}.stat,.panel{background:var(--paper);border:1px solid var(--line);border-radius:19px;box-shadow:var(--shadow)}.stat{position:relative;min-height:152px;padding:20px 21px;overflow:hidden}.stat-top{display:flex;align-items:center;justify-content:space-between;color:#68758c;font-size:13px;font-weight:650}.stat-icon{height:33px;width:33px;border-radius:11px;display:grid;place-items:center;color:var(--blue);background:var(--blue-soft);font-size:16px}.stat-value{font-size:clamp(23px,2vw,30px);font-weight:760;letter-spacing:-.045em;margin:14px 0 2px}.stat-note{font-size:12px;color:var(--muted)}.stat-accent{position:absolute;right:20px;bottom:15px;width:64px;height:35px;opacity:.7}.stat-accent i{position:absolute;bottom:0;width:8px;border-radius:7px;background:#95bbfb}.stat-accent i:nth-child(1){height:12px;left:0}.stat-accent i:nth-child(2){height:23px;left:13px}.stat-accent i:nth-child(3){height:16px;left:26px}.stat-accent i:nth-child(4){height:32px;left:39px;background:#2878f0}.stat-accent i:nth-child(5){height:20px;left:52px}
        .overview-grid{display:grid;grid-template-columns:minmax(0,1.8fr) minmax(270px,.8fr);gap:17px;margin-bottom:17px}.panel{padding:22px}.panel-head{display:flex;align-items:flex-start;justify-content:space-between;gap:15px;margin-bottom:18px}.panel h2{font-size:19px;letter-spacing:-.025em;margin:0}.panel-sub{font-size:12px;color:var(--muted);margin-top:4px}.legend{display:flex;gap:15px;color:#758197;font-size:12px}.legend span{display:inline-flex;align-items:center;gap:6px}.dot{width:8px;height:8px;border-radius:50%;display:inline-block}.dot.sales{background:#4a84f5}.dot.restock{background:#ffcb63}.chart{height:222px;display:flex;align-items:stretch;gap:12px;padding:7px 4px 0}.chart-day{flex:1;display:flex;min-width:0;flex-direction:column;align-items:center;justify-content:flex-end;gap:11px;color:#8490a3;font-size:11px}.bars{height:178px;width:100%;display:flex;align-items:flex-end;justify-content:center;gap:5px;border-bottom:1px dashed #e7ebf2;padding-bottom:1px}.bar{width:min(18px,35%);min-height:3px;border-radius:10px 10px 4px 4px;background:linear-gradient(180deg,#6899fa,#bcd1ff)}.bar.restock{background:linear-gradient(180deg,#ffd574,#ffedbe)}.chart-empty{height:210px;display:grid;place-items:center;text-align:center;color:var(--muted);font-size:13px}.inventory-summary{display:grid;gap:12px}.inventory-line{display:flex;align-items:center;justify-content:space-between;padding:13px 14px;border-radius:13px;background:#f8faff}.inventory-line span{font-size:13px;color:#68758c}.inventory-line strong{font-size:15px}.inventory-note{margin-top:15px;background:linear-gradient(135deg,#2878f0,#5297ff);color:#fff;border-radius:15px;padding:17px}.inventory-note small{opacity:.8}.inventory-note strong{display:block;font-size:23px;margin:4px 0}.inventory-note p{font-size:12px;opacity:.86;margin:0}
        .content-grid{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(280px,.85fr);gap:17px;align-items:start}.section-panel{padding:0;overflow:hidden}.section-heading{padding:21px 22px;display:flex;justify-content:space-between;align-items:center;gap:16px;border-bottom:1px solid var(--line)}.section-heading h2{font-size:20px;letter-spacing:-.03em;margin:0}.section-heading p{font-size:12px;color:var(--muted);margin:4px 0 0}.search{position:relative}.search input{width:220px;padding:10px 12px 10px 36px;border:1px solid var(--line);border-radius:11px;background:#fff;outline:none}.search input:focus,input:focus,textarea:focus{border-color:#81aef9;box-shadow:0 0 0 3px #2878f018}.search:before{content:'⌕';position:absolute;left:13px;top:5px;color:#8b96a8;font-size:21px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:680px}th{text-align:left;padding:12px 18px;background:#fafbfe;color:#8691a3;font-size:11px;text-transform:uppercase;letter-spacing:.07em;font-weight:700;border-bottom:1px solid var(--line)}td{padding:15px 18px;border-bottom:1px solid #edf0f5;vertical-align:middle;font-size:13px}tbody tr:last-child td{border-bottom:0}.product-cell{min-width:190px}.product-name{font-weight:700;color:#202a3e}.product-meta{max-width:250px;color:#8993a4;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.price{font-weight:700;white-space:nowrap}.muted{color:var(--muted)}.stock-badge,.type-badge{display:inline-flex;align-items:center;gap:5px;padding:5px 9px;border-radius:99px;font-size:11px;font-weight:700;white-space:nowrap}.stock-badge.ok{color:#13814d;background:#eaf8f0}.stock-badge.low{color:#aa6a0b;background:#fff5dd}.stock-badge.out{color:#b83d3d;background:#fff0ef}.actions{display:flex;gap:6px;justify-content:flex-end}.mini-button{border:1px solid var(--line);background:#fff;color:#59667d;border-radius:9px;padding:7px 9px;font-size:11px;font-weight:700}.mini-button:hover{border-color:#b7cdf2;color:var(--blue)}.empty{padding:45px 20px;text-align:center;color:var(--muted)}.empty strong{display:block;color:#39445a;font-size:15px;margin-bottom:5px}.transaction-panel{margin-top:17px}.type-badge.sale{background:#eaf8f0;color:#13814d}.type-badge.restock{background:#fff5e7;color:#a66916}.tx-amount{font-weight:700;white-space:nowrap}.form-panel{padding:21px}.form-panel .panel-head{margin-bottom:15px}.field-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.field{display:flex;flex-direction:column;gap:6px}.field.full{grid-column:1/-1}.field label{font-size:12px;color:#566176;font-weight:650}.required{color:#df5555}.field input,.field textarea{width:100%;border:1px solid #e1e6ee;border-radius:10px;padding:10px 11px;background:#fff;color:var(--ink);outline:none}.field textarea{min-height:72px;resize:vertical}.form-actions{display:flex;gap:9px;margin-top:15px}.form-help{font-size:11px;color:var(--muted);margin:12px 0 0}.actions-menu{position:relative}.actions-menu summary{list-style:none}.actions-menu summary::-webkit-details-marker{display:none}.action-options{position:absolute;right:0;top:34px;z-index:2;background:white;border:1px solid var(--line);box-shadow:var(--shadow);padding:7px;border-radius:11px;display:flex;gap:5px}.trade-form{display:inline-flex;align-items:center;gap:6px}.trade-form input{width:55px;border:1px solid var(--line);border-radius:8px;padding:6px}.footer{padding:23px 4px;color:#99a2b1;font-size:11px;text-align:center}
        @media(max-width:1100px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.overview-grid,.content-grid{grid-template-columns:1fr}.inventory-summary{grid-template-columns:repeat(3,1fr)}.inventory-note{margin:0}}
        @media(max-width:680px){.app{display:block;padding-bottom:66px}.rail{position:fixed;z-index:10;top:auto;bottom:0;left:0;right:0;width:100%;height:62px;padding:7px 12px;background:#fff;border-right:0;border-top:1px solid var(--line);flex-direction:row;justify-content:space-around;gap:4px}.rail .brand,.rail .spacer,.rail .rail-bottom{display:none}.rail a{height:44px;width:48px;font-size:19px}.main{padding:24px 14px 25px}.topbar{align-items:flex-start;margin-bottom:19px}.topbar h1{font-size:25px}.date-pill{display:none}.top-actions .button{padding:10px 11px;font-size:12px}.stats{gap:10px}.stat{min-height:130px;padding:15px}.stat-value{font-size:22px;margin-top:12px}.stat-accent{right:10px;bottom:9px;transform:scale(.8);transform-origin:right bottom}.panel{padding:17px;border-radius:16px}.overview-grid{gap:11px}.chart{gap:5px;height:200px}.bars{height:153px;gap:3px}.legend{gap:9px;font-size:10px}.inventory-summary{grid-template-columns:1fr 1fr}.inventory-note{grid-column:1/-1}.section-heading{padding:17px;align-items:flex-start;flex-direction:column}.search,.search input{width:100%}.section-panel{padding:0}.section-heading .button{width:100%}.section-heading .search{order:2}.field-grid{grid-template-columns:1fr}.field.full{grid-column:auto}.form-actions .button{flex:1}.footer{padding-bottom:5px}}
        @media(prefers-reduced-motion:reduce){*,*:before,*:after{scroll-behavior:auto!important;transition:none!important}}
    </style>
</head>
<body>
<div class="app">
    <nav class="rail" aria-label="Main navigation">
        <a class="brand" href="#overview" aria-label="Hellmerry's home">H</a>
        <a class="active" href="#overview" title="Overview" aria-label="Overview">◫</a>
        <a href="#products" title="Inventory" aria-label="Inventory">▤</a>
        <a href="#transactions" title="Transactions" aria-label="Transactions">↗</a>
        <span class="spacer"></span>
        <a class="rail-bottom" href="#product-form" title="Add product" aria-label="Add product">＋</a>
    </nav>
    <main class="main" id="overview">
        <header class="topbar">
            <div><div class="eyebrow">Hellmerry's Vape Shop</div><h1>Business overview</h1></div>
            <div class="top-actions"><span class="date-pill">◷ &nbsp;<?= h(date('l, M j')) ?></span><a class="button" href="#product-form">＋ <span>Add product</span></a></div>
        </header>

        <?php if ($msg): ?><div class="notice" role="status"><?= h($msg) ?></div><?php endif; ?>
        <?php if ($errors): ?><div class="notice error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

        <section class="stats" aria-label="Business summary">
            <?php $stats = [
                ['Total sales', money($dash['total_sales']), 'Recorded sales revenue', '↗'],
                ['Restock spending', money($dash['total_restock']), 'Recorded inventory purchases', '↓'],
                ['Net balance', money($dash['balance']), 'Sales less restock spending', '＝'],
                ['Total products', number_format($dash['products']), 'Products listed in your inventory', '▤'],
            ]; foreach ($stats as $stat): ?>
                <article class="stat"><div class="stat-top"><span><?= h($stat[0]) ?></span><span class="stat-icon"><?= h($stat[3]) ?></span></div><div class="stat-value"><?= h($stat[1]) ?></div><div class="stat-note"><?= h($stat[2]) ?></div><div class="stat-accent" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></div></article>
            <?php endforeach; ?>
        </section>

        <section class="overview-grid" aria-label="Sales and inventory overview">
            <article class="panel">
                <div class="panel-head"><div><h2>Sales &amp; restocking</h2><div class="panel-sub">Daily totals for the last seven days</div></div><div class="legend"><span><i class="dot sales"></i>Sales</span><span><i class="dot restock"></i>Restocks</span></div></div>
                <?php if ($weekly): ?><div class="chart" role="img" aria-label="Daily sales and restock totals for the last seven days">
                    <?php foreach ($weekly as $day): $salesHeight = max(3, (float)$day['sales'] / $chartMax * 100); $restockHeight = max(3, (float)$day['restock'] / $chartMax * 100); ?>
                        <div class="chart-day" title="<?= h($day['label'] . ': sales ' . money($day['sales']) . ', restocks ' . money($day['restock'])) ?>"><div class="bars"><i class="bar" style="height:<?= h($salesHeight) ?>%"></i><i class="bar restock" style="height:<?= h($restockHeight) ?>%"></i></div><span><?= h($day['label']) ?></span></div>
                    <?php endforeach; ?>
                </div><?php else: ?><div class="chart-empty">Transaction activity will appear here as sales and restocks are recorded.</div><?php endif; ?>
            </article>
            <aside class="panel">
                <div class="panel-head"><div><h2>Inventory snapshot</h2><div class="panel-sub">Current stock position</div></div></div>
                <div class="inventory-summary">
                    <div class="inventory-line"><span>Products</span><strong><?= number_format($dash['products']) ?></strong></div>
                    <div class="inventory-line"><span>Units available</span><strong><?= number_format($dash['units']) ?></strong></div>
                    <div class="inventory-line"><span>Cost value</span><strong><?= money($dash['inventory_cost']) ?></strong></div>
                    <div class="inventory-note"><small>Expected markup profit</small><strong><?= money($dash['expected_profit']) ?></strong><p>Estimated from current stock and listed markup.</p></div>
                </div>
            </aside>
        </section>

        <section class="content-grid">
            <div>
                <section class="panel section-panel" id="products">
                    <div class="section-heading"><div><h2>Products</h2><p><?= number_format(count($products)) ?> items · Low stock means 5 units or fewer</p></div><label class="search"><input id="product-search" type="search" placeholder="Search products" aria-label="Search products"></label></div>
                    <?php if (!$products): ?><div class="empty"><strong>Your inventory is ready to grow</strong>Add your first product using the form.</div><?php else: ?>
                    <div class="table-wrap"><table><thead><tr><th>Product</th><th>Sell price</th><th>Cost / markup</th><th>Stock</th><th>Expected profit</th><th aria-label="Actions"></th></tr></thead><tbody id="product-rows">
                        <?php foreach ($products as $p): $price = sellingPrice($p['cost'], $p['markup']); $profit = expectedProfit($p['quantity'], $p['cost'], $p['markup']); $stockClass = $p['quantity'] <= 0 ? 'out' : ($p['quantity'] <= 5 ? 'low' : 'ok'); $stockText = $p['quantity'] <= 0 ? 'Out of stock' : ($p['quantity'] <= 5 ? 'Low · ' . $p['quantity'] : 'In stock · ' . $p['quantity']); ?>
                        <tr data-search="<?= h(strtolower($p['name'] . ' ' . $p['label'] . ' ' . $p['description'])) ?>">
                            <td class="product-cell"><div class="product-name"><?= h($p['name']) ?></div><div class="product-meta"><?= h($p['label'] ?: ($p['description'] ?: 'Uncategorized')) ?></div></td>
                            <td class="price"><?= money($price) ?></td><td><span class="price"><?= money($p['cost']) ?></span><div class="product-meta"><?= number_format($p['markup'], 2) ?>% markup</div></td>
                            <td><span class="stock-badge <?= h($stockClass) ?>"><?= h($stockText) ?></span></td><td class="price"><?= money($profit) ?></td>
                            <td><details class="actions-menu"><summary class="mini-button">•••</summary><div class="action-options"><a class="mini-button" href="<?= h($self) ?>?edit=<?= (int)$p['id'] ?>#product-form">Edit</a>
                                <form class="trade-form" method="post" action="<?= h($self) ?>" data-product="<?= h($p['name']) ?>" data-unit-price="<?= h($price) ?>" data-trade="Sale"><?= csrfField() ?><input type="hidden" name="action" value="sell"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="number" name="qty" min="1" max="<?= max(1, (int)$p['quantity']) ?>" step="1" value="1" aria-label="Sale quantity for <?= h($p['name']) ?>" <?= $p['quantity'] <= 0 ? 'disabled' : '' ?>><button class="mini-button" type="submit" data-confirm="true">Sell</button></form>
                                <form class="trade-form" method="post" action="<?= h($self) ?>" data-product="<?= h($p['name']) ?>" data-unit-price="<?= h($p['cost']) ?>" data-trade="Restock"><?= csrfField() ?><input type="hidden" name="action" value="restock"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="number" name="qty" min="1" step="1" value="1" aria-label="Restock quantity for <?= h($p['name']) ?>"><button class="mini-button" type="submit" data-confirm="true">Restock</button></form>
                                <form method="post" action="<?= h($self) ?>"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="mini-button" type="submit" data-confirm="Delete this product? Existing transactions will remain in history.">Delete</button></form>
                            </div></details></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody></table></div><div class="empty" id="search-empty" hidden>No products match your search.</div>
                    <?php endif; ?>
                </section>

                <section class="panel section-panel transaction-panel" id="transactions">
                    <div class="section-heading"><div><h2>Recent transactions</h2><p>The latest sales and inventory purchases</p></div><span class="muted" style="font-size:12px">Latest 10</span></div>
                    <?php if (!$recent): ?><div class="empty"><strong>No transactions yet</strong>Sales and restocks will be listed here.</div><?php else: ?>
                    <div class="table-wrap"><table><thead><tr><th>Date &amp; time</th><th>Type</th><th>Product</th><th>Qty</th><th>Amount</th></tr></thead><tbody>
                        <?php foreach ($recent as $t): ?><tr><td class="muted"><?= h(shortDate($t['created_at'])) ?></td><td><span class="type-badge <?= $t['type'] === 'sale' ? 'sale' : 'restock' ?>"><?= $t['type'] === 'sale' ? '↗ Sale' : '↓ Restock' ?></span></td><td class="product-name"><?= h($t['product_name']) ?></td><td><?= number_format((int)$t['qty']) ?></td><td class="tx-amount"><?= money($t['amount']) ?></td></tr><?php endforeach; ?>
                    </tbody></table></div><?php endif; ?>
                </section>
            </div>

            <aside class="panel form-panel" id="product-form">
                <div class="panel-head"><div><h2><?= $editing ? 'Edit product' : 'Add a product' ?></h2><div class="panel-sub"><?= $editing ? 'Update product details and stock.' : 'Add an item to your shop inventory.' ?></div></div><?php if ($editing): ?><a class="mini-button" href="<?= h($self) ?>#products" aria-label="Cancel editing">×</a><?php endif; ?></div>
                <form method="post" action="<?= h($self) ?>" id="product-editor">
                    <?= csrfField() ?><input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>"><?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
                    <div class="field-grid">
                        <div class="field full"><label for="name">Product name <span class="required">*</span></label><input id="name" name="name" type="text" maxlength="120" value="<?= v($old, 'name') ?>" required autocomplete="off"></div>
                        <div class="field full"><label for="label">Category / label</label><input id="label" name="label" type="text" maxlength="80" value="<?= v($old, 'label') ?>" placeholder="e.g. E-liquid"></div>
                        <div class="field full"><label for="description">Description</label><textarea id="description" name="description" maxlength="1000" placeholder="Optional product details"><?= v($old, 'description') ?></textarea></div>
                        <div class="field"><label for="cost">Original cost <span class="required">*</span></label><input id="cost" name="cost" type="number" min="0" step="0.01" value="<?= v($old, 'cost') ?>" required inputmode="decimal"></div>
                        <div class="field"><label for="markup">Markup (%) <span class="required">*</span></label><input id="markup" name="markup" type="number" min="0" step="0.01" value="<?= v($old, 'markup') ?>" required inputmode="decimal"></div>
                        <div class="field full"><label for="quantity">Current stock <span class="required">*</span></label><input id="quantity" name="quantity" type="number" min="0" step="1" value="<?= v($old, 'quantity') ?>" required inputmode="numeric"></div>
                    </div>
                    <div class="form-actions"><button class="button" type="submit"><?= $editing ? 'Save changes' : '＋ Add product' ?></button><?php if ($editing): ?><a class="button secondary" href="<?= h($self) ?>#products">Cancel</a><?php endif; ?></div>
                    <p class="form-help"><span class="required">*</span> Required fields. Editing stock is a correction; use Restock to record inventory spending.</p>
                </form>
            </aside>
        </section>
        <footer class="footer">Hellmerry's Vape Shop · Inventory and sales overview</footer>
    </main>
</div>
<script>
(() => {
    const search = document.getElementById('product-search');
    const rows = [...document.querySelectorAll('#product-rows tr')];
    const empty = document.getElementById('search-empty');
    if (search) search.addEventListener('input', () => {
        const q = search.value.trim().toLocaleLowerCase(); let shown = 0;
        rows.forEach(row => { const match = row.dataset.search.includes(q); row.hidden = !match; if (match) shown++; });
        if (empty) empty.hidden = shown > 0;
    });
    document.querySelectorAll('[data-confirm]').forEach(button => button.form?.addEventListener('submit', event => {
        const form = button.form;
        let message = 'Delete this product? Existing transactions will remain in history.';
        if (form.dataset.trade) {
            const qty = Number(form.querySelector('[name="qty"]')?.value || 0);
            const unit = Number(form.dataset.unitPrice || 0);
            const amount = new Intl.NumberFormat(undefined, {style: 'currency', currency: 'USD'}).format(qty * unit);
            message = `${form.dataset.trade}: ${qty} × ${new Intl.NumberFormat(undefined, {style: 'currency', currency: 'USD'}).format(unit)} = ${amount} for ${form.dataset.product}. Continue?`;
        }
        if (!window.confirm(message)) event.preventDefault();
        else { button.disabled = true; button.textContent = 'Saving…'; }
    }));
    const editor = document.getElementById('product-editor');
    if (editor) editor.addEventListener('submit', event => {
        if (!editor.reportValidity()) { event.preventDefault(); return; }
        const button = editor.querySelector('button[type="submit"]');
        if (button) { button.disabled = true; button.textContent = 'Saving…'; }
    });
})();
</script>
</body>
</html>
