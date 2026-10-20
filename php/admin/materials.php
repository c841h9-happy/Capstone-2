<?php
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/../config/materials.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../components/footer.php';
require_once __DIR__ . '/../components/toast.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = productInputText($_POST, 'action');

    try {
        if ($action === 'add') {
            $result = createMaterial(productInputText($_POST, 'name'), productInputText($_POST, 'unit'));
            if ($result['ok']) {
                flashToast('success', 'Material added. Set its opening count below.');
            } else {
                flashToast('error', $result['error']);
            }
        } else {
            $id = is_numeric($_POST['id'] ?? null) ? (int) $_POST['id'] : 0;
            $amountText = productInputText($_POST, 'qty');
            $amount = parseMaterialQuantity($amountText);
            $material = null;
            foreach (fetchMaterials() as $row) {
                if ((int) $row['id'] === $id) {
                    $material = $row;
                    break;
                }
            }

            if ($material === null) {
                flashToast('error', 'That material no longer exists.');
            } elseif ($action === 'warn') {
                if ($amount === null) {
                    flashToast('error', 'Enter a stock warning amount with up to three decimal places.');
                } elseif (setMaterialLowStock($id, $amount)) {
                    flashToast('success', 'Low-stock warning saved for "' . $material['name'] . '".');
                } else {
                    flashToast('error', 'Unable to save the warning level.');
                }
            } elseif ($amount === null || ($action !== 'set' && $amount <= 0)) {
                flashToast('error', 'Enter a positive amount with up to three decimal places.');
            } elseif ($action === 'receive') {
                $result = recordMaterialMove($id, $amount, 'received', ['note' => productInputText($_POST, 'note')]);
                flashToast($result['ok'] ? 'success' : 'error', $result['ok']
                    ? 'Received ' . materialQuantity($amount) . ' ' . $material['unit'] . ' of "' . $material['name'] . '".'
                    : $result['error']);
            } elseif ($action === 'waste') {
                $result = recordMaterialMove($id, -$amount, 'wasted', ['note' => productInputText($_POST, 'note')]);
                flashToast($result['ok'] ? 'success' : 'error', $result['ok']
                    ? 'Wrote off ' . materialQuantity($amount) . ' ' . $material['unit'] . ' of "' . $material['name'] . '".'
                    : $result['error']);
            } elseif ($action === 'set') {
                $result = setMaterialStock($id, $amount, productInputText($_POST, 'note'));
                flashToast(($result['ok'] ?? false) ? 'success' : 'error', ($result['ok'] ?? false)
                    ? 'Counted "' . $material['name'] . '" at ' . materialQuantity($amount) . ' ' . $material['unit'] . '.'
                    : ($result['error'] ?? 'Unable to save that count.'));
            } else {
                flashToast('error', 'That action is not available.');
            }
        }
    } catch (PDOException $exception) {
        error_log('admin/materials.php: ' . $exception->getMessage());
        flashToast('error', 'Unable to save that change. Check the database connection and fractional inventory migration.');
    }

    redirectTo(url('php/admin/materials.php'));
}

$materials = [];
$loadFailed = false;
try {
    $materials = fetchMaterials();
} catch (PDOException $exception) {
    error_log('admin/materials.php: ' . $exception->getMessage());
    $loadFailed = true;
}

$toasts = takeFlashToasts();
if ($loadFailed) {
    $toasts[] = ['type' => 'error', 'message' => 'Unable to load materials. Run sql\\inventory-fractional-upgrade.sql first.'];
}

renderHeader('Materials');
renderToasts($toasts);
?>
<main class="page">
    <header class="page-head">
        <p class="page-eyebrow">Admin</p>
        <h1>Materials</h1>
        <p class="page-intro">Raw supplies used to make products. Stock can be fractional; every movement is recorded.</p>
    </header>

    <section class="panel material-create-panel">
        <h2>Add material</h2>
        <form class="material-create-form" method="post" action="<?php echo escape(url('php/admin/materials.php')); ?>">
            <?php echo csrfField(); ?>
            <div class="filter-field">
                <label for="material-name">Name</label>
                <input id="material-name" name="name" type="text" maxlength="120" required placeholder="Red Fuzzy Wire">
            </div>
            <div class="filter-field">
                <label for="material-unit">Stock unit</label>
                <input id="material-unit" name="unit" type="text" maxlength="40" required value="strand">
            </div>
            <button class="btn btn-primary" type="submit" name="action" value="add">Add material</button>
        </form>
    </section>

    <div class="admin-toolbar">
        <p class="catalogue-count"><?php echo count($materials); ?> material<?php echo count($materials) === 1 ? '' : 's'; ?></p>
        <a class="btn btn-quiet" href="<?php echo escape(url('php/admin/material-moves.php')); ?>">Movement history</a>
    </div>

    <?php if ($materials === [] && !$loadFailed): ?>
        <div class="empty-state"><h2>No materials yet</h2><p>Add the supplies your products are made from.</p></div>
    <?php elseif ($materials !== []): ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead><tr><th scope="col">Material</th><th scope="col">On hand</th><th scope="col">Warn at</th><th scope="col">Stock actions</th></tr></thead>
                <tbody>
                    <?php foreach ($materials as $material): ?>
                        <?php
                        $id = (int) $material['id'];
                        $onHand = (float) $material['stock_on_hand'];
                        $threshold = (float) $material['low_stock_threshold'];
                        $low = $threshold > 0 && $onHand <= $threshold;
                        ?>
                        <tr>
                            <th scope="row" class="admin-cell-name">
                                <?php echo escape((string) $material['name']); ?>
                                <span class="admin-cell-sub">per <?php echo escape((string) $material['unit']); ?></span>
                                <?php if ((int) $material['is_active'] !== 1): ?><span class="pill pill-hidden">Inactive</span><?php endif; ?>
                            </th>
                            <td class="admin-cell-number">
                                <strong><?php echo escape(materialQuantity($onHand)); ?></strong> <?php echo escape((string) $material['unit']); ?>
                                <?php if ($onHand < 0): ?><span class="pill pill-danger">Short</span><?php elseif ($onHand === 0): ?><span class="pill pill-danger">Out</span><?php elseif ($low): ?><span class="pill pill-warn">Low</span><?php endif; ?>
                            </td>
                            <td>
                                <form class="admin-row-actions" method="post" action="<?php echo escape(url('php/admin/materials.php')); ?>">
                                    <?php echo csrfField(); ?><input type="hidden" name="id" value="<?php echo $id; ?>">
                                    <label class="visually-hidden" for="warn-<?php echo $id; ?>">Warn at quantity</label>
                                    <input class="admin-row-number" id="warn-<?php echo $id; ?>" name="qty" type="text" inputmode="decimal" maxlength="10" value="<?php echo escape(materialQuantity($threshold)); ?>">
                                    <button class="btn btn-quiet btn-small" type="submit" name="action" value="warn">Save</button>
                                </form>
                            </td>
                            <td>
                                <form class="admin-row-actions admin-row-move material-stock-actions" method="post" action="<?php echo escape(url('php/admin/materials.php')); ?>">
                                    <?php echo csrfField(); ?><input type="hidden" name="id" value="<?php echo $id; ?>">
                                    <label class="visually-hidden" for="qty-<?php echo $id; ?>">Material quantity</label>
                                    <input class="admin-row-number" id="qty-<?php echo $id; ?>" name="qty" type="text" inputmode="decimal" maxlength="10" placeholder="0.5">
                                    <label class="visually-hidden" for="note-<?php echo $id; ?>">Movement note</label>
                                    <input class="admin-row-note" id="note-<?php echo $id; ?>" name="note" type="text" maxlength="255" placeholder="Note (optional)">
                                    <button class="btn btn-primary btn-small" type="submit" name="action" value="receive">Receive</button>
                                    <button class="btn btn-quiet btn-small" type="submit" name="action" value="set">Set to</button>
                                    <button class="btn btn-danger btn-small" type="submit" name="action" value="waste">Waste</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>
<?php renderFooter(); ?>
