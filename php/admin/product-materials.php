<?php
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/../config/materials.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../components/footer.php';
require_once __DIR__ . '/../components/toast.php';

$id = is_numeric($_GET['id'] ?? null) ? (int) $_GET['id'] : 0;
$recipe = fetchProductMaterialRecipe($id);
$product = $recipe['product'];
if ($product === null) {
    http_response_code(404);
    renderHeader('Product not found');
    ?>
    <main class="page"><div class="empty-state"><h1>Product not found</h1><p>There is no product with that id.</p><p><a class="btn btn-primary" href="<?php echo escape(url('php/admin/products.php')); ?>">Back to products</a></p></div></main>
    <?php
    renderFooter();
    exit;
}

$errors = [];
$postedQuantities = null;
$postedYield = (string) $recipe['yield'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $postedQuantities = is_array($_POST['qty'] ?? null) ? $_POST['qty'] : [];
    $postedYield = is_scalar($_POST['yield'] ?? null) ? trim((string) $_POST['yield']) : '';
    $saved = saveProductMaterials($id, $postedQuantities, $postedYield);
    $errors = $saved['errors'];
    if ($errors === []) {
        flashToast('success', 'Saved the materials recipe for "' . $product['name'] . '".');
        redirectTo(url('php/admin/product-materials.php?id=' . $id));
    }
    $recipe = fetchProductMaterialRecipe($id);
}

$quantities = [];
foreach ($recipe['materials'] as $material) {
    if ($material['quantity'] !== null) {
        $quantities[(int) $material['id']] = (float) $material['quantity'];
    }
}
$buildable = materialBuildableCount($id);
$toasts = takeFlashToasts();
foreach ($errors as $error) {
    $toasts[] = ['type' => 'error', 'message' => $error];
}
renderHeader('Product materials');
renderToasts($toasts);
?>
<main class="page">
    <p class="breadcrumb"><a href="<?php echo escape(url('php/admin/products.php')); ?>">Products</a><span aria-hidden="true">&rsaquo;</span><span><?php echo escape((string) $product['name']); ?></span></p>
    <header class="page-head">
        <p class="page-eyebrow">Admin</p>
        <h1>Materials for <?php echo escape((string) $product['name']); ?></h1>
        <p class="page-intro">Set the material quantity for one batch and how many products that batch makes. For example, 1 strand yielding 2 roses uses half a strand per rose.</p>
    </header>

    <div class="profile-panels">
        <section class="panel">
            <h2>Material recipe</h2>
            <?php if ($recipe['materials'] === []): ?>
                <p class="panel-intro">There are no materials yet. Add the raw supplies first.</p>
                <p class="panel-actions"><a class="btn btn-primary" href="<?php echo escape(url('php/admin/materials.php')); ?>">Manage materials</a></p>
            <?php else: ?>
                <form class="recipe-form" method="post" action="<?php echo escape(url('php/admin/product-materials.php?id=' . $id)); ?>">
                    <?php echo csrfField(); ?>
                    <div class="filter-field">
                        <label for="recipe-yield">Products made per batch</label>
                        <input id="recipe-yield" name="yield" type="text" inputmode="decimal" maxlength="10" value="<?php echo escape($postedYield); ?>" required>
                    </div>
                    <p class="panel-intro">Enter the amount of each material used to make that batch. Leave unused materials blank.</p>
                    <?php foreach ($recipe['materials'] as $material): ?>
                        <?php
                        $materialId = (int) $material['id'];
                        $boxValue = $postedQuantities !== null
                            ? (is_scalar($postedQuantities[$materialId] ?? null) ? trim((string) ($postedQuantities[$materialId] ?? '')) : '')
                            : (isset($quantities[$materialId]) ? materialQuantity($quantities[$materialId]) : '');
                        ?>
                        <div class="recipe-item<?php echo $material['quantity'] !== null ? ' recipe-item-in' : ''; ?>">
                            <label class="recipe-item-part" for="material-<?php echo $materialId; ?>">
                                <span class="recipe-item-name"><?php echo escape((string) $material['name']); ?> <span class="admin-cell-sub">per <?php echo escape((string) $material['unit']); ?></span>
                                    <?php if ((int) $material['is_active'] !== 1): ?><span class="pill pill-hidden">Inactive</span><?php endif; ?>
                                </span>
                            </label>
                            <span class="recipe-item-stock">
                                <?php echo escape(materialQuantity($material['stock_on_hand'])); ?> in stock
                                <?php if ($material['quantity'] !== null): ?>
                                    &middot; enough for <?php echo number_format(max(0, (int) floor((float) $material['makes']))); ?> product units
                                <?php endif; ?>
                            </span>
                            <input class="admin-row-number" id="material-<?php echo $materialId; ?>" name="qty[<?php echo $materialId; ?>]" type="text" inputmode="decimal" maxlength="10" value="<?php echo escape($boxValue); ?>" placeholder="0">
                        </div>
                    <?php endforeach; ?>
                    <div class="panel-actions">
                        <button class="btn btn-primary" type="submit">Save materials recipe</button>
                        <a class="btn btn-quiet" href="<?php echo escape(url('php/admin/materials.php')); ?>">Manage materials</a>
                        <a class="btn btn-quiet" href="<?php echo escape(url('php/admin/products.php')); ?>">Back to products</a>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h2>Availability</h2>
            <dl class="card-list">
                <dt>Batch yield</dt><dd><?php echo escape(materialQuantity($recipe['yield'])); ?> product units</dd>
                <dt>Can make</dt>
                <dd><?php echo $buildable === null ? 'No material recipe yet.' : number_format($buildable) . ' from current stock'; ?></dd>
                <dt>Materials</dt><dd><?php echo count($quantities); ?> configured</dd>
            </dl>
        </section>
    </div>
</main>
<?php renderFooter(); ?>
