<?php
// Database operations and business rules for Hellmerry's Vape Shop.
// The SQLite database is created beside these two application files.

function db()
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . __DIR__ . '/hellmerry.db');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        $pdo->exec("CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            label TEXT NOT NULL DEFAULT '',
            cost REAL NOT NULL DEFAULT 0,
            markup REAL NOT NULL DEFAULT 0,
            quantity INTEGER NOT NULL DEFAULT 0
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER,
            product_name TEXT NOT NULL,
            type TEXT NOT NULL,
            qty INTEGER NOT NULL,
            amount REAL NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_transactions_type_id ON transactions(type, id DESC)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_transactions_created_at ON transactions(created_at)');
    }
    return $pdo;
}

function money($n)
{
    return '$' . number_format((float)$n, 2);
}

function sellingPrice($cost, $markup)
{
    return round((float)$cost * (1 + (float)$markup / 100), 2);
}

function expectedProfit($qty, $cost, $markup)
{
    return round((int)$qty * (float)$cost * (float)$markup / 100, 2);
}

function textLength($value)
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }
    $count = preg_match_all('/./us', $value, $matches);
    return $count === false ? strlen($value) : $count;
}

function validateProduct($in)
{
    $errors = [];
    $value = function ($key) use ($in) {
        $raw = $in[$key] ?? '';
        return is_scalar($raw) ? trim((string)$raw) : '';
    };
    $d = [
        'name' => $value('name'),
        'description' => $value('description'),
        'label' => $value('label'),
        'cost' => $value('cost'),
        'markup' => $value('markup'),
        'quantity' => $value('quantity'),
    ];

    if ($d['name'] === '') {
        $errors[] = 'Product name is required.';
    } elseif (textLength($d['name']) > 120) {
        $errors[] = 'Product name must be 120 characters or fewer.';
    }
    if (textLength($d['label']) > 80) {
        $errors[] = 'Category / label must be 80 characters or fewer.';
    }
    if (textLength($d['description']) > 1000) {
        $errors[] = 'Description must be 1,000 characters or fewer.';
    }
    if (!is_numeric($d['cost']) || !is_finite((float)$d['cost']) || (float)$d['cost'] < 0) {
        $errors[] = 'Original cost must be a valid amount of 0 or more.';
    }
    if (!is_numeric($d['markup']) || !is_finite((float)$d['markup']) || (float)$d['markup'] < 0) {
        $errors[] = 'Markup must be a valid percentage of 0 or more.';
    }
    $quantity = filter_var($d['quantity'], FILTER_VALIDATE_INT);
    if ($quantity === false || $quantity < 0) {
        $errors[] = 'Stock must be a whole number of 0 or more.';
    }

    if (!$errors) {
        $d['cost'] = round((float)$d['cost'], 2);
        $d['markup'] = round((float)$d['markup'], 2);
        $d['quantity'] = (int)$quantity;
    }
    return [$d, $errors];
}

function positiveQuantity($qty, $label)
{
    if (!is_scalar($qty)) {
        throw new Exception($label . ' quantity must be a whole number of at least 1.');
    }
    $value = filter_var($qty, FILTER_VALIDATE_INT);
    if ($value === false || $value < 1) {
        throw new Exception($label . ' quantity must be a whole number of at least 1.');
    }
    return $value;
}

function validProductId($id)
{
    if (!is_scalar($id)) {
        throw new Exception('That product could not be found.');
    }
    $value = filter_var($id, FILTER_VALIDATE_INT);
    if ($value === false || $value < 1) {
        throw new Exception('That product could not be found.');
    }
    return $value;
}

function getProducts()
{
    return db()->query('SELECT * FROM products ORDER BY name COLLATE NOCASE')->fetchAll();
}

function getProduct($id)
{
    $id = validProductId($id);
    $st = db()->prepare('SELECT * FROM products WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function createProduct($d)
{
    $st = db()->prepare('INSERT INTO products (name, description, label, cost, markup, quantity) VALUES (?, ?, ?, ?, ?, ?)');
    $st->execute([$d['name'], $d['description'], $d['label'], $d['cost'], $d['markup'], $d['quantity']]);
    return (int)db()->lastInsertId();
}

// Editing the stock count is an inventory correction and does not record spending.
function updateProduct($id, $d)
{
    $id = validProductId($id);
    $st = db()->prepare('UPDATE products SET name = ?, description = ?, label = ?, cost = ?, markup = ?, quantity = ? WHERE id = ?');
    $st->execute([$d['name'], $d['description'], $d['label'], $d['cost'], $d['markup'], $d['quantity'], $id]);
    if (!$st->rowCount()) {
        // SQLite reports zero when all fields are unchanged; confirm the row still exists.
        if (!getProduct($id)) {
            throw new Exception('That product could not be found.');
        }
    }
}

function deleteProduct($id)
{
    $id = validProductId($id);
    $st = db()->prepare('DELETE FROM products WHERE id = ?');
    $st->execute([$id]);
    if (!$st->rowCount()) {
        throw new Exception('That product could not be found.');
    }
}

function restockProduct($id, $qty)
{
    $id = validProductId($id);
    $qty = positiveQuantity($qty, 'Restock');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id, name, cost, quantity FROM products WHERE id = ?');
        $st->execute([$id]);
        $p = $st->fetch();
        if (!$p) {
            throw new Exception('That product could not be found.');
        }
        if ($qty > PHP_INT_MAX - (int)$p['quantity']) {
            throw new Exception('That restock quantity is too large.');
        }
        $totalCost = round($qty * (float)$p['cost'], 2);
        $pdo->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ?')->execute([$qty, $id]);
        $pdo->prepare("INSERT INTO transactions (product_id, product_name, type, qty, amount) VALUES (?, ?, 'restock', ?, ?)")
            ->execute([$id, $p['name'], $qty, $totalCost]);
        $pdo->commit();
        return $totalCost;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function sellProduct($id, $qty)
{
    $id = validProductId($id);
    $qty = positiveQuantity($qty, 'Sale');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id, name, cost, markup, quantity FROM products WHERE id = ?');
        $st->execute([$id]);
        $p = $st->fetch();
        if (!$p) {
            throw new Exception('That product could not be found.');
        }
        if ($qty > (int)$p['quantity']) {
            throw new Exception('Not enough stock. Only ' . (int)$p['quantity'] . ' left.');
        }
        $total = round($qty * sellingPrice($p['cost'], $p['markup']), 2);
        $update = $pdo->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ? AND quantity >= ?');
        $update->execute([$qty, $id, $qty]);
        if (!$update->rowCount()) {
            throw new Exception('Stock changed before the sale could be recorded. Refresh and try again.');
        }
        $pdo->prepare("INSERT INTO transactions (product_id, product_name, type, qty, amount) VALUES (?, ?, 'sale', ?, ?)")
            ->execute([$id, $p['name'], $qty, $total]);
        $pdo->commit();
        return $total;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function getDashboard()
{
    $pdo = db();
    $transactionTotals = $pdo->query("SELECT
        COALESCE(SUM(CASE WHEN type = 'sale' THEN amount ELSE 0 END), 0) AS sales,
        COALESCE(SUM(CASE WHEN type = 'restock' THEN amount ELSE 0 END), 0) AS restock
        FROM transactions")->fetch();
    $inventory = $pdo->query('SELECT COUNT(*) AS items, COALESCE(SUM(quantity), 0) AS units,
        COALESCE(SUM(quantity * cost), 0) AS inventory_cost,
        COALESCE(SUM(quantity * cost * markup / 100), 0) AS expected_profit FROM products')->fetch();
    $sales = (float)$transactionTotals['sales'];
    $restock = (float)$transactionTotals['restock'];
    return [
        'total_sales' => round($sales, 2),
        'total_restock' => round($restock, 2),
        'balance' => round($sales - $restock, 2),
        'products' => (int)$inventory['items'],
        'units' => (int)$inventory['units'],
        'inventory_cost' => round((float)$inventory['inventory_cost'], 2),
        'expected_profit' => round((float)$inventory['expected_profit'], 2),
    ];
}

function getRecentTransactions($limit = 10)
{
    $limit = max(1, min(100, (int)$limit));
    $st = db()->prepare('SELECT * FROM transactions ORDER BY id DESC LIMIT ?');
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

function getWeeklyActivity($days = 7)
{
    $days = max(1, min(31, (int)$days));
    $pdo = db();
    $st = $pdo->prepare("SELECT date(created_at) AS day,
        COALESCE(SUM(CASE WHEN type = 'sale' THEN amount ELSE 0 END), 0) AS sales,
        COALESCE(SUM(CASE WHEN type = 'restock' THEN amount ELSE 0 END), 0) AS restock
        FROM transactions WHERE date(created_at) >= date('now', ?)
        GROUP BY date(created_at) ORDER BY day");
    $st->execute(['-' . ($days - 1) . ' days']);
    $found = [];
    foreach ($st->fetchAll() as $row) {
        $found[$row['day']] = $row;
    }
    if (!$found) {
        return [];
    }
    $result = [];
    $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
    for ($offset = $days - 1; $offset >= 0; $offset--) {
        $date = $today->modify('-' . $offset . ' days')->format('Y-m-d');
        $row = $found[$date] ?? ['sales' => 0, 'restock' => 0];
        $result[] = [
            'label' => date('D', strtotime($date)),
            'sales' => (float)$row['sales'],
            'restock' => (float)$row['restock'],
        ];
    }
    return $result;
}

function reportDateCondition($period)
{
    return $period === 'month'
        ? "date(created_at) >= date('now', 'start of month')"
        : "date(created_at) >= date('now', 'weekday 0', '-6 days')";
}

function getPeriodReport($period = 'week')
{
    $period = $period === 'month' ? 'month' : 'week';
    $condition = reportDateCondition($period);
    $row = db()->query("SELECT
        COALESCE(SUM(CASE WHEN type = 'sale' THEN amount ELSE 0 END), 0) AS sales,
        COALESCE(SUM(CASE WHEN type = 'restock' THEN amount ELSE 0 END), 0) AS restock,
        COALESCE(SUM(CASE WHEN type = 'sale' THEN qty ELSE 0 END), 0) AS units_sold,
        COALESCE(SUM(CASE WHEN type = 'restock' THEN qty ELSE 0 END), 0) AS units_restocked,
        SUM(CASE WHEN type = 'sale' THEN 1 ELSE 0 END) AS sale_count,
        SUM(CASE WHEN type = 'restock' THEN 1 ELSE 0 END) AS restock_count
        FROM transactions WHERE $condition")->fetch();
    $row['sales'] = round((float)$row['sales'], 2);
    $row['restock'] = round((float)$row['restock'], 2);
    $row['net'] = round($row['sales'] - $row['restock'], 2);
    $row['units_sold'] = (int)$row['units_sold'];
    $row['units_restocked'] = (int)$row['units_restocked'];
    $row['sale_count'] = (int)$row['sale_count'];
    $row['restock_count'] = (int)$row['restock_count'];
    return $row;
}

function getPeriodBreakdown($period = 'week')
{
    $period = $period === 'month' ? 'month' : 'week';
    $condition = reportDateCondition($period);
    if ($period === 'month') {
        $bucket = "date(created_at, '-' || ((CAST(strftime('%w', created_at) AS INTEGER) + 6) % 7) || ' days')";
    } else {
        $bucket = 'date(created_at)';
    }
    $sql = "SELECT $bucket AS bucket,
        COALESCE(SUM(CASE WHEN type = 'sale' THEN amount ELSE 0 END), 0) AS sales,
        COALESCE(SUM(CASE WHEN type = 'restock' THEN amount ELSE 0 END), 0) AS restock,
        COALESCE(SUM(CASE WHEN type = 'sale' THEN qty ELSE 0 END), 0) AS units_sold,
        COALESCE(SUM(CASE WHEN type = 'restock' THEN qty ELSE 0 END), 0) AS units_restocked
        FROM transactions WHERE $condition GROUP BY bucket ORDER BY bucket DESC";
    $rows = db()->query($sql)->fetchAll();
    foreach ($rows as &$row) {
        $timestamp = strtotime($row['bucket']);
        $row['label'] = $period === 'month'
            ? date('M j', $timestamp)
            : date('D, M j', $timestamp);
        $row['sales'] = round((float)$row['sales'], 2);
        $row['restock'] = round((float)$row['restock'], 2);
        $row['net'] = round($row['sales'] - $row['restock'], 2);
        $row['units_sold'] = (int)$row['units_sold'];
        $row['units_restocked'] = (int)$row['units_restocked'];
    }
    unset($row);
    return $rows;
}

function getProductPerformance($days = 30)
{
    $days = max(1, min(365, (int)$days));
    $st = db()->prepare("SELECT p.id, p.name, p.label, p.quantity,
        COALESCE(SUM(t.qty), 0) AS units_sold,
        COALESCE(SUM(t.amount), 0) AS sales_amount
        FROM products p LEFT JOIN transactions t ON t.product_id = p.id
            AND t.type = 'sale' AND datetime(t.created_at) >= datetime('now', ?)
        GROUP BY p.id ORDER BY units_sold DESC, p.name COLLATE NOCASE");
    $st->execute(['-' . $days . ' days']);
    $products = $st->fetchAll();
    $selling = 0;
    foreach ($products as $product) {
        if ((int)$product['units_sold'] > 0) {
            $selling++;
        }
    }
    $highCount = $selling ? (int)ceil($selling / 3) : 0;
    $rank = 0;
    foreach ($products as &$product) {
        $units = (int)$product['units_sold'];
        if ($units === 0) {
            $product['performance'] = 'low';
        } else {
            $rank++;
            $product['performance'] = $rank <= $highCount ? 'high' : 'stable';
        }
        $product['units_sold'] = $units;
        $product['sales_amount'] = round((float)$product['sales_amount'], 2);
    }
    unset($product);
    return $products;
}

function getStorageAnalyzer()
{
    $row = db()->query("SELECT COUNT(*) AS products,
        COALESCE(SUM(quantity), 0) AS units,
        COALESCE(SUM(quantity * cost), 0) AS cost_value,
        SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END) AS out_of_stock,
        SUM(CASE WHEN quantity BETWEEN 1 AND 5 THEN 1 ELSE 0 END) AS low_stock,
        SUM(CASE WHEN quantity > 5 THEN 1 ELSE 0 END) AS available
        FROM products")->fetch();
    foreach (['products', 'units', 'out_of_stock', 'low_stock', 'available'] as $key) {
        $row[$key] = (int)$row[$key];
    }
    $row['cost_value'] = round((float)$row['cost_value'], 2);
    return $row;
}
