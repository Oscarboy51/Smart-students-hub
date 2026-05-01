<?php
// ============================================================
// products.php — /htdocs/ssh/api/products.php
// ============================================================
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

match ($action) {
    'list'        => listProducts(),
    'get'         => getProduct(),
    'create'      => createProduct(),
    'update'      => updateProduct(),
    'delete'      => deleteProduct(),
    'fav'         => toggleFav(),
    'cart-add'    => cartAdd(),
    'cart-remove' => cartRemove(),
    'cart-list'   => cartList(),
    'cart-clear'  => cartClear(),
    default       => respond(['ok' => false, 'msg' => 'Unknown action'], 404),
};

function listProducts(): void {
    $cat   = (int)($_GET['cat'] ?? 0);
    $q     = trim($_GET['q'] ?? '');
    $sort  = $_GET['sort'] ?? 'newest';
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = 24;
    $offset = ($page - 1) * $limit;

    $where = ['p.is_deleted=0'];
    $params = [];

    if ($cat > 0)  { $where[] = 'p.category_id=?'; $params[] = $cat; }
    if ($q !== '') { $where[] = 'MATCH(p.name,p.description) AGAINST(? IN BOOLEAN MODE)'; $params[] = $q . '*'; }

    $orderBy = match ($sort) {
        'price-asc'  => 'p.price ASC',
        'price-desc' => 'p.price DESC',
        'popular'    => 'p.fav_count DESC',
        default      => 'p.is_boosted DESC, p.created_at DESC',
    };

    $sql = "SELECT p.*, u.username AS seller_name, u.student_id AS seller_sid,
                   c.name AS category_name, c.emoji AS category_emoji
            FROM products p
            JOIN users u    ON u.id = p.seller_id
            JOIN categories c ON c.id = p.category_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY $orderBy
            LIMIT $limit OFFSET $offset";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();

    // Total count
    $cntSql = "SELECT COUNT(*) FROM products p WHERE " . implode(' AND ', $where);
    $cntStmt = db()->prepare($cntSql);
    $cntStmt->execute($params);
    $total = (int)$cntStmt->fetchColumn();

    respond(['ok' => true, 'products' => $products, 'total' => $total, 'page' => $page]);
}

function getProduct(): void {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare(
        'SELECT p.*, u.username AS seller_name, u.student_id AS seller_sid, u.email AS seller_email,
                c.name AS category_name, c.emoji AS category_emoji
         FROM products p
         JOIN users u ON u.id=p.seller_id
         JOIN categories c ON c.id=p.category_id
         WHERE p.id=? AND p.is_deleted=0'
    );
    $stmt->execute([$id]);
    $p = $stmt->fetch();
    $p ? respond(['ok' => true, 'product' => $p]) : respond(['ok' => false, 'msg' => 'Not found'], 404);
}

function createProduct(): void {
    $user = requireAuth();
    if (!in_array($user['role'], ['seller', 'admin'])) respond(['ok' => false, 'msg' => 'Sellers only'], 403);
    $b = body();
    $required = ['name', 'category_id', 'price', 'phone'];
    foreach ($required as $f) { if (empty($b[$f])) respond(['ok' => false, 'msg' => "Field '$f' required"]); }

    $stmt = db()->prepare(
        'INSERT INTO products (seller_id, name, category_id, price, description, phone, image_url, stock_status)
         VALUES (?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $user['sub'],
        htmlspecialchars($b['name'], ENT_QUOTES),
        (int)$b['category_id'],
        round((float)$b['price'], 2),
        htmlspecialchars($b['description'] ?? '', ENT_QUOTES),
        preg_replace('/[^0-9+\s]/', '', $b['phone']),
        $b['image_url'] ?? null,
        in_array($b['stock_status'] ?? 'in', ['in','limited','out']) ? $b['stock_status'] : 'in',
    ]);
    respond(['ok' => true, 'id' => (int)db()->lastInsertId()], 201);
}

function updateProduct(): void {
    $user = requireAuth();
    $id   = (int)($_GET['id'] ?? 0);
    $b    = body();

    $stmt = db()->prepare('SELECT seller_id FROM products WHERE id=? AND is_deleted=0');
    $stmt->execute([$id]);
    $p = $stmt->fetch();
    if (!$p) respond(['ok' => false, 'msg' => 'Product not found'], 404);
    if ($p['seller_id'] != $user['sub'] && $user['role'] !== 'admin')
        respond(['ok' => false, 'msg' => 'Forbidden'], 403);

    $fields = [];
    $vals   = [];
    $allow  = ['name', 'price', 'description', 'phone', 'image_url', 'stock_status', 'category_id'];
    foreach ($allow as $f) {
        if (isset($b[$f])) { $fields[] = "$f=?"; $vals[] = $b[$f]; }
    }
    if (!$fields) respond(['ok' => false, 'msg' => 'Nothing to update']);
    $vals[] = $id;
    db()->prepare('UPDATE products SET ' . implode(',', $fields) . ' WHERE id=?')->execute($vals);
    respond(['ok' => true]);
}

function deleteProduct(): void {
    $user = requireAuth();
    $id   = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT seller_id FROM products WHERE id=?');
    $stmt->execute([$id]);
    $p = $stmt->fetch();
    if (!$p) respond(['ok' => false, 'msg' => 'Not found'], 404);
    if ($p['seller_id'] != $user['sub'] && $user['role'] !== 'admin')
        respond(['ok' => false, 'msg' => 'Forbidden'], 403);
    db()->prepare('UPDATE products SET is_deleted=1 WHERE id=?')->execute([$id]);
    respond(['ok' => true]);
}

function toggleFav(): void {
    $user = requireAuth();
    $pid  = (int)body()['product_id'];
    $pdo  = db();
    $chk  = $pdo->prepare('SELECT 1 FROM product_favorites WHERE user_id=? AND product_id=?');
    $chk->execute([$user['sub'], $pid]);
    if ($chk->fetch()) {
        $pdo->prepare('DELETE FROM product_favorites WHERE user_id=? AND product_id=?')->execute([$user['sub'], $pid]);
        $pdo->prepare('UPDATE products SET fav_count=GREATEST(0,fav_count-1) WHERE id=?')->execute([$pid]);
        respond(['ok' => true, 'faved' => false]);
    } else {
        $pdo->prepare('INSERT IGNORE INTO product_favorites (user_id, product_id) VALUES (?,?)')->execute([$user['sub'], $pid]);
        $pdo->prepare('UPDATE products SET fav_count=fav_count+1 WHERE id=?')->execute([$pid]);
        respond(['ok' => true, 'faved' => true]);
    }
}

function cartAdd(): void {
    $user = requireAuth();
    $pid  = (int)body()['product_id'];
    $pdo  = db();
    $p    = $pdo->prepare('SELECT stock_status FROM products WHERE id=? AND is_deleted=0');
    $p->execute([$pid]);
    $prod = $p->fetch();
    if (!$prod) respond(['ok' => false, 'msg' => 'Product not found'], 404);
    if ($prod['stock_status'] === 'out') respond(['ok' => false, 'msg' => 'Out of stock']);
    try {
        $pdo->prepare('INSERT INTO cart_items (user_id, product_id) VALUES (?,?)')->execute([$user['sub'], $pid]);
        respond(['ok' => true]);
    } catch (\PDOException $e) {
        respond(['ok' => false, 'msg' => 'Already in cart']);
    }
}

function cartRemove(): void {
    $user = requireAuth();
    $pid  = (int)body()['product_id'];
    db()->prepare('DELETE FROM cart_items WHERE user_id=? AND product_id=?')->execute([$user['sub'], $pid]);
    respond(['ok' => true]);
}

function cartList(): void {
    $user = requireAuth();
    $stmt = db()->prepare(
        'SELECT p.id, p.name, p.price, p.image_url, p.stock_status,
                u.username AS seller_name, u.student_id AS seller_sid, p.phone,
                c.name AS category_name, c.emoji
         FROM cart_items ci
         JOIN products p ON p.id=ci.product_id
         JOIN users u ON u.id=p.seller_id
         JOIN categories c ON c.id=p.category_id
         WHERE ci.user_id=? AND p.is_deleted=0
         ORDER BY ci.added_at DESC'
    );
    $stmt->execute([$user['sub']]);
    respond(['ok' => true, 'items' => $stmt->fetchAll()]);
}

function cartClear(): void {
    $user = requireAuth();
    db()->prepare('DELETE FROM cart_items WHERE user_id=?')->execute([$user['sub']]);
    respond(['ok' => true]);
}
