<?php
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/../config/materials.php';
require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../components/footer.php';

$moves = [];
$discrepancies = [];
$orderId = is_numeric($_GET['order'] ?? null) ? max(0, (int) $_GET['order']) : 0;
$loadFailed = false;
try {
    $moves = fetchMaterialMoves($orderId);
    $discrepancies = materialStockAuditDiscrepancies();
} catch (PDOException $exception) {
    error_log('admin/material-moves.php: ' . $exception->getMessage());
    $loadFailed = true;
}

renderHeader('Material movements');
?>
<main class="page">
    <p class="breadcrumb"><a href="<?php echo escape(url('php/admin/materials.php')); ?>">Materials</a><span aria-hidden="true">&rsaquo;</span><span>Movement history</span></p>
    <header class="page-head">
        <p class="page-eyebrow">Admin</p>
        <h1>Material movements</h1>
        <p class="page-intro">Every receipt, stock-take, waste write-off, and order consumption.</p>
    </header>
    <?php if ($orderId > 0): ?>
        <p class="catalogue-count">Showing material movements for order #<?php echo $orderId; ?>.
            <a href="<?php echo escape(url('php/admin/material-moves.php')); ?>">Show all</a>
        </p>
    <?php endif; ?>
    <?php if ($discrepancies !== []): ?>
        <div class="notice notice-alarm">
            <strong>The material books do not add up.</strong>
            <?php foreach ($discrepancies as $index => $wrong): ?>
                <?php echo $index === 0 ? '' : '; '; ?>
                <?php echo escape((string) $wrong['name']); ?> says <?php echo escape(materialQuantity($wrong['stock_on_hand'])); ?>,
                but its movements total <?php echo escape(materialQuantity($wrong['ledger_total'])); ?>.
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($loadFailed): ?>
        <div class="empty-state"><h2>We could not load the history</h2><p>Run sql\inventory-fractional-upgrade.sql and try again.</p></div>
    <?php elseif ($moves === []): ?>
        <div class="empty-state"><h2>No material movements yet</h2><p>Stock changes will appear here with their reason and running balance.</p></div>
    <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead><tr><th scope="col">When</th><th scope="col">Material</th><th scope="col">Reason</th><th scope="col">Change</th><th scope="col">On hand after</th><th scope="col">Note</th></tr></thead>
                <tbody>
                    <?php foreach ($moves as $move): ?>
                        <?php $delta = (float) $move['delta']; $balance = (float) $move['balance_after']; ?>
                        <tr>
                            <td class="admin-cell-date"><?php echo escape(date('j M Y, g:ia', strtotime((string) $move['created_at']) ?: 0)); ?></td>
                            <th scope="row" class="admin-cell-name"><?php echo escape((string) $move['material_name']); ?></th>
                            <td><?php echo escape(ucfirst((string) $move['reason'])); ?></td>
                            <td class="admin-cell-number"><span class="<?php echo $delta < 0 ? 'ledger-out' : 'ledger-in'; ?>"><?php echo $delta > 0 ? '+' : ''; ?><?php echo escape(materialQuantity($delta)); ?></span></td>
                            <td class="admin-cell-number"><?php echo escape(materialQuantity($balance)); ?></td>
                            <td><?php echo $move['note'] === '' ? '<span class="admin-cell-blank">&mdash;</span>' : escape((string) $move['note']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>
<?php renderFooter(); ?>
