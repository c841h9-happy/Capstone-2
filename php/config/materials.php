<?php

declare(strict_types=1);

require_once __DIR__ . '/products-admin.php';

const MATERIAL_QUANTITY_MAX = 999999.999;

function materialQuantity(mixed $value): string
{
    return rtrim(rtrim(number_format((float) $value, 3, '.', ','), '0'), '.');
}

function parseMaterialQuantity(mixed $value, bool $allowZero = true): ?float
{
    if (!is_scalar($value)) {
        return null;
    }

    $text = trim((string) $value);

    if (preg_match('/^\d{1,6}(?:\.\d{1,3})?$/', $text) !== 1) {
        return null;
    }

    $quantity = round((float) $text, 3);

    if ($quantity > MATERIAL_QUANTITY_MAX || (!$allowZero && $quantity <= 0)) {
        return null;
    }

    return $quantity;
}

function fetchMaterials(): array
{
    return getDatabaseConnection()->query(
        'SELECT id, name, unit, stock_on_hand, low_stock_threshold, is_active
           FROM materials
          ORDER BY is_active DESC, name ASC, id ASC'
    )->fetchAll();
}

function createMaterial(string $name, string $unit): array
{
    $name = trim($name);
    $unit = trim($unit);

    if ($name === '' || mb_strlen($name) > 120) {
        return ['ok' => false, 'error' => 'Enter a material name up to 120 characters.'];
    }

    if ($unit === '' || mb_strlen($unit) > 40) {
        return ['ok' => false, 'error' => 'Enter a stock unit up to 40 characters, such as wire or metre.'];
    }

    $statement = getDatabaseConnection()->prepare('INSERT INTO materials (name, unit) VALUES (:name, :unit)');

    try {
        $statement->execute(['name' => $name, 'unit' => $unit]);
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            return ['ok' => false, 'error' => 'A material with that name already exists.'];
        }

        throw $exception;
    }

    return ['ok' => true, 'error' => '', 'id' => (int) getDatabaseConnection()->lastInsertId()];
}

function setMaterialStock(int $id, float $count, string $note = ''): array
{
    $count = round($count, 3);
    if ($id <= 0 || $count < 0 || $count > MATERIAL_QUANTITY_MAX) {
        return ['ok' => false, 'error' => 'Enter a valid non-negative stock count.'];
    }

    $database = getDatabaseConnection();
    $ours = !$database->inTransaction();
    if ($ours) {
        $database->beginTransaction();
    }

    try {
        $read = $database->prepare('SELECT stock_on_hand FROM materials WHERE id = :id FOR UPDATE');
        $read->execute(['id' => $id]);
        $current = $read->fetchColumn();
        if ($current === false) {
            if ($ours) {
                $database->rollBack();
            }
            return ['ok' => false, 'error' => 'That material no longer exists.'];
        }

        $delta = round($count - (float) $current, 3);
        if ($delta === 0.0) {
            if ($ours) {
                $database->commit();
            }
            return ['ok' => true, 'changed' => false, 'balance' => $count];
        }

        $result = recordMaterialMove($id, $delta, 'adjusted', ['note' => $note]);
        if ($ours) {
            $result['ok'] ? $database->commit() : $database->rollBack();
        }
        return $result + ['changed' => true];
    } catch (Throwable $exception) {
        if ($ours && $database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
}

function setMaterialLowStock(int $id, float $threshold): bool
{
    $threshold = round($threshold, 3);
    if ($id <= 0 || $threshold < 0 || $threshold > MATERIAL_QUANTITY_MAX) {
        return false;
    }

    $statement = getDatabaseConnection()->prepare('UPDATE materials SET low_stock_threshold = :threshold WHERE id = :id');
    $statement->execute(['threshold' => number_format($threshold, 3, '.', ''), 'id' => $id]);

    if ($statement->rowCount() > 0) {
        return true;
    }

    $exists = getDatabaseConnection()->prepare('SELECT 1 FROM materials WHERE id = :id');
    $exists->execute(['id' => $id]);
    return $exists->fetchColumn() !== false;
}

function fetchMaterialMoves(int $orderId = 0): array
{
    $statement = getDatabaseConnection()->prepare(
        'SELECT mm.id, mm.material_name, mm.reason, mm.delta, mm.balance_after, mm.note,
                mm.created_at, mm.order_id, u.name AS user_name
           FROM material_moves mm
           LEFT JOIN users u ON u.id = mm.user_id
          WHERE (:order_id = 0 OR mm.order_id = :order_filter)
          ORDER BY mm.id DESC
          LIMIT 250'
    );
    $statement->execute(['order_id' => max(0, $orderId), 'order_filter' => max(0, $orderId)]);

    return $statement->fetchAll();
}

function materialStockAuditDiscrepancies(): array
{
    return getDatabaseConnection()->query(
        'SELECT m.id, m.name, m.stock_on_hand,
                COALESCE(SUM(mm.delta), 0) AS ledger_total,
                m.stock_on_hand - COALESCE(SUM(mm.delta), 0) AS difference
           FROM materials m
           LEFT JOIN material_moves mm ON mm.material_id = m.id
          GROUP BY m.id, m.name, m.stock_on_hand
         HAVING m.stock_on_hand <> COALESCE(SUM(mm.delta), 0)
          ORDER BY m.name ASC, m.id ASC'
    )->fetchAll();
}

function recordMaterialMove(int $materialId, float $delta, string $reason, array $options = []): array
{
    $delta = round($delta, 3);
    $validReasons = ['opening', 'received', 'used', 'returned', 'adjusted', 'wasted'];

    if ($materialId <= 0 || $delta === 0.0 || abs($delta) > MATERIAL_QUANTITY_MAX || !in_array($reason, $validReasons, true)) {
        return ['ok' => false, 'error' => 'Choose a material and enter a valid non-zero quantity.'];
    }

    $note = trim((string) ($options['note'] ?? ''));

    if (mb_strlen($note) > 255) {
        return ['ok' => false, 'error' => 'The note has to be 255 characters or fewer.'];
    }

    $orderId = isset($options['orderId']) ? (int) $options['orderId'] : null;
    $userId = array_key_exists('userId', $options)
        ? ($options['userId'] === null ? null : (int) $options['userId'])
        : (isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null);
    $database = getDatabaseConnection();
    $ours = !$database->inTransaction();

    if ($ours) {
        $database->beginTransaction();
    }

    try {
        $read = $database->prepare('SELECT name, stock_on_hand FROM materials WHERE id = :id FOR UPDATE');
        $read->execute(['id' => $materialId]);
        $material = $read->fetch();

        if ($material === false) {
            if ($ours) {
                $database->rollBack();
            }

            return ['ok' => false, 'error' => 'That material no longer exists.'];
        }

        $balance = round((float) $material['stock_on_hand'] + $delta, 3);
        $update = $database->prepare('UPDATE materials SET stock_on_hand = :balance WHERE id = :id');
        $update->execute(['balance' => number_format($balance, 3, '.', ''), 'id' => $materialId]);

        $insert = $database->prepare(
            'INSERT INTO material_moves (material_id, material_name, reason, delta, balance_after, order_id, note, user_id)
             VALUES (:material, :name, :reason, :delta, :balance, :order, :note, :user)'
        );
        $insert->execute([
            'material' => $materialId,
            'name' => $material['name'],
            'reason' => $reason,
            'delta' => number_format($delta, 3, '.', ''),
            'balance' => number_format($balance, 3, '.', ''),
            'order' => $orderId,
            'note' => $note,
            'user' => $userId,
        ]);

        if ($ours) {
            $database->commit();
        }

        return ['ok' => true, 'error' => '', 'balance' => $balance, 'moveId' => (int) $database->lastInsertId()];
    } catch (Throwable $exception) {
        if ($ours && $database->inTransaction()) {
            $database->rollBack();
        }

        throw $exception;
    }
}

function saveProductMaterials(int $productId, array $quantities, mixed $yield): array
{
    if ($productId <= 0 || adminFetchProduct($productId) === null) {
        return ['errors' => ['That product no longer exists.']];
    }

    $recipeYield = parseMaterialQuantity($yield, false);

    if ($recipeYield === null) {
        return ['errors' => ['Recipe output must be between 0.001 and ' . materialQuantity(MATERIAL_QUANTITY_MAX) . ' product units.']];
    }

    $wanted = [];

    foreach ($quantities as $key => $raw) {
        $materialId = (int) $key;

        if ($materialId <= 0) {
            continue;
        }

        if (!is_scalar($raw) || trim((string) $raw) === '') {
            if (!is_scalar($raw)) {
                return ['errors' => ['Enter material quantities as numbers.']];
            }
            continue;
        }

        $quantity = parseMaterialQuantity($raw);

        if ($quantity === null) {
            return ['errors' => ['Material amounts must be numbers with up to three decimal places.']];
        }

        if ($quantity > 0) {
            $wanted[$materialId] = $quantity;
        }
    }

    $database = getDatabaseConnection();
    $ours = !$database->inTransaction();

    if ($ours) {
        $database->beginTransaction();
    }

    try {
        $product = $database->prepare('SELECT id FROM products WHERE id = :id FOR UPDATE');
        $product->execute(['id' => $productId]);

        if ($product->fetchColumn() === false) {
            if ($ours) {
                $database->rollBack();
            }
            return ['errors' => ['That product no longer exists.']];
        }

        $known = [];
        if ($wanted !== []) {
            $ids = array_keys($wanted);
            $lookup = $database->prepare('SELECT id FROM materials WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $lookup->execute($ids);
            $known = array_map('intval', $lookup->fetchAll(PDO::FETCH_COLUMN));

            if (count($known) !== count($wanted)) {
                if ($ours) {
                    $database->rollBack();
                }
                return ['errors' => ['A selected material was deleted. Reload and try again.']];
            }
        }

        $saveYield = $database->prepare('UPDATE products SET recipe_yield = :yield WHERE id = :id');
        $saveYield->execute(['yield' => number_format($recipeYield, 3, '.', ''), 'id' => $productId]);

        $delete = $database->prepare('DELETE FROM product_materials WHERE product_id = :id');
        $delete->execute(['id' => $productId]);

        $insert = $database->prepare('INSERT INTO product_materials (product_id, material_id, quantity) VALUES (:product, :material, :quantity)');
        foreach ($wanted as $materialId => $quantity) {
            $insert->execute([
                'product' => $productId,
                'material' => $materialId,
                'quantity' => number_format($quantity, 3, '.', ''),
            ]);
        }

        if ($ours) {
            $database->commit();
        }

        return ['errors' => []];
    } catch (Throwable $exception) {
        if ($ours && $database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
}

function fetchProductMaterialRecipe(int $productId): array
{
    $productQuery = getDatabaseConnection()->prepare('SELECT id, name, recipe_yield FROM products WHERE id = :id');
    $productQuery->execute(['id' => $productId]);
    $product = $productQuery->fetch();

    if ($product === false) {
        return ['product' => null, 'yield' => 1.0, 'materials' => []];
    }

    $statement = getDatabaseConnection()->prepare(
        'SELECT m.id, m.name, m.unit, m.stock_on_hand, m.low_stock_threshold, m.is_active,
                pm.quantity,
                FLOOR(m.stock_on_hand * :yield / NULLIF(pm.quantity, 0)) AS makes
           FROM materials m
           LEFT JOIN product_materials pm ON pm.material_id = m.id AND pm.product_id = :product
          WHERE m.is_active = 1 OR pm.product_id IS NOT NULL
          ORDER BY m.name ASC, m.id ASC'
    );
    $statement->execute(['yield' => $product['recipe_yield'], 'product' => $productId]);

    return ['product' => $product, 'yield' => (float) $product['recipe_yield'], 'materials' => $statement->fetchAll()];
}

function materialBuildableCount(int $productId): ?int
{
    $statement = getDatabaseConnection()->prepare(
        'SELECT MIN(FLOOR(m.stock_on_hand * p.recipe_yield / NULLIF(pm.quantity, 0)))
           FROM product_materials pm
           JOIN materials m ON m.id = pm.material_id
           JOIN products p ON p.id = pm.product_id
          WHERE pm.product_id = :product AND pm.quantity > 0'
    );
    $statement->execute(['product' => $productId]);
    $count = $statement->fetchColumn();

    return $count === false || $count === null ? null : max(0, (int) $count);
}

function productMaterialRequirement(int $orderId): array
{
    $statement = getDatabaseConnection()->prepare(
        'SELECT m.id, m.name, m.unit, m.stock_on_hand,
                SUM(pm.quantity * i.quantity / p.recipe_yield) AS needed
           FROM order_items i
           JOIN product_materials pm ON pm.product_id = i.product_id
           JOIN products p ON p.id = pm.product_id
           JOIN materials m ON m.id = pm.material_id
          WHERE i.order_id = :order AND i.kind = \'product\'
          GROUP BY m.id, m.name, m.unit, m.stock_on_hand
          ORDER BY m.name ASC, m.id ASC'
    );
    $statement->execute(['order' => $orderId]);
    return $statement->fetchAll();
}

function materialStockOut(int $orderId): array
{
    $statement = getDatabaseConnection()->prepare(
        "SELECT material_id, SUM(delta) AS net FROM material_moves
          WHERE order_id = :order AND reason IN ('used','returned') AND material_id IS NOT NULL
          GROUP BY material_id ORDER BY material_id"
    );
    $statement->execute(['order' => $orderId]);
    $out = [];
    foreach ($statement->fetchAll() as $row) {
        $quantity = round(-(float) $row['net'], 3);
        if ($quantity > 0) {
            $out[(int) $row['material_id']] = $quantity;
        }
    }
    return $out;
}

function fetchMaterialOrderMoves(int $orderId): array
{
    $statement = getDatabaseConnection()->prepare(
        'SELECT mm.id, mm.material_id AS component_id, mm.material_name AS component_name,
                mm.reason, mm.delta, mm.balance_after, mm.order_id, mm.note, mm.user_id,
                mm.created_at, u.name AS user_name, o.reference AS order_reference
           FROM material_moves mm
           LEFT JOIN users u ON u.id = mm.user_id
           LEFT JOIN orders o ON o.id = mm.order_id
          WHERE mm.order_id = :order
          ORDER BY mm.id ASC'
    );
    $statement->execute(['order' => $orderId]);

    return $statement->fetchAll();
}

function consumeOrderMaterials(int $orderId): array
{
    $report = ['moves' => 0, 'units' => 0.0, 'short' => [], 'errors' => []];
    $requirements = [];
    foreach (productMaterialRequirement($orderId) as $row) {
        $requirements[(int) $row['id']] = $row;
    }
    $already = materialStockOut($orderId);

    foreach ($requirements as $id => $row) {
        $take = round((float) $row['needed'] - ($already[$id] ?? 0.0), 3);
        if ($take <= 0) {
            continue;
        }
        $result = recordMaterialMove($id, -$take, 'used', ['orderId' => $orderId]);
        if (!$result['ok']) {
            $report['errors'][] = $row['name'] . ': ' . $result['error'];
            continue;
        }
        $report['moves']++;
        $report['units'] += $take;
        if ($result['balance'] < 0) {
            $report['short'][] = ['name' => $row['name'], 'balance' => $result['balance']];
        }
    }
    return $report;
}

function returnOrderMaterials(int $orderId): array
{
    $report = ['moves' => 0, 'units' => 0.0, 'errors' => []];
    foreach (materialStockOut($orderId) as $id => $quantity) {
        $result = recordMaterialMove($id, $quantity, 'returned', ['orderId' => $orderId]);
        if (!$result['ok']) {
            $report['errors'][] = $result['error'];
            continue;
        }
        $report['moves']++;
        $report['units'] += $quantity;
    }
    return $report;
}
