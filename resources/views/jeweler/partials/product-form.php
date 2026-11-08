<dialog id="product-dialog" aria-labelledby="product-dialog-title">
    <div class="dialog-head"><h2 id="product-dialog-title"><?= $editProduct === null ? 'Add product' : 'Edit product' ?></h2><button class="icon-button" type="button" aria-label="Close product form" data-close-product>×</button></div>
    <form class="product-form" method="post" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= JewelerSession::escape($csrfToken) ?>">
        <input type="hidden" name="action" value="<?= $editProduct === null ? 'create' : 'update' ?>">
        <input type="hidden" name="productId" value="<?= JewelerSession::escape((string) ($editProduct['id'] ?? '')) ?>">
        <input type="hidden" name="productType" id="product-type-input" value="<?= JewelerSession::escape((string) ($editProduct['productType'] ?? 'watch')) ?>">
        <div class="form-type" <?= $editProduct === null ? '' : 'hidden' ?>><button type="button" data-product-type="watch" aria-pressed="<?= ($editProduct['productType'] ?? 'watch') === 'watch' ? 'true' : 'false' ?>">Watch</button><button type="button" data-product-type="jewelry" aria-pressed="<?= ($editProduct['productType'] ?? '') === 'jewelry' ? 'true' : 'false' ?>">Jewelry piece</button></div>
        <?php if ($editProduct !== null && ($editProduct['photoDisplayUrls'] ?? []) !== []): ?><div class="field full" style="margin-bottom:16px"><span>Current photos</span><div class="existing-photos"><?php foreach ($editProduct['photoDisplayUrls'] as $photoUrl): ?><img src="<?= JewelerSession::escape((string) $photoUrl) ?>" alt="Current product photo"><?php endforeach; ?></div></div><?php endif; ?>
        <fieldset data-product-fields="watch" <?= ($editProduct['productType'] ?? 'watch') === 'watch' ? '' : 'hidden' ?> style="padding:0;border:0;margin:0">
            <div class="form-grid">
                <div class="field"><label for="watch-brand">Brand</label><input id="watch-brand" name="brand" required value="<?= JewelerSession::escape((string) ($editProduct['brand'] ?? '')) ?>"></div>
                <div class="field"><label for="watch-model">Model</label><input id="watch-model" name="model" required value="<?= JewelerSession::escape((string) ($editProduct['model'] ?? '')) ?>"></div>
                <div class="field"><label for="watch-type">Watch type</label><input id="watch-type" name="watchType" required value="<?= JewelerSession::escape((string) ($editProduct['watchType'] ?? '')) ?>"></div>
                <div class="field"><label for="watch-material">Case material</label><input id="watch-material" name="caseMaterial" required value="<?= JewelerSession::escape((string) ($editProduct['caseMaterial'] ?? '')) ?>"></div>
                <div class="field"><label for="watch-dial">Dial color</label><input id="watch-dial" name="dialColor" required value="<?= JewelerSession::escape((string) ($editProduct['dialColor'] ?? '')) ?>"></div>
                <div class="field"><label for="watch-movement">Movement type</label><input id="watch-movement" name="movementType" required value="<?= JewelerSession::escape((string) ($editProduct['movementType'] ?? '')) ?>"></div>
                <div class="field"><label for="watch-price">Price (EGP)</label><input id="watch-price" name="price" type="number" min="0.01" step="0.01" required value="<?= JewelerSession::escape((string) ($editProduct['price'] ?? '')) ?>"></div>
                <div class="field"><label for="watch-origin">Country of origin</label><input id="watch-origin" name="countryOfOrigin" required value="<?= JewelerSession::escape((string) ($editProduct['countryOfOrigin'] ?? '')) ?>"></div>
                <div class="field full"><label for="watch-3d">Optional 3D URL</label><input id="watch-3d" name="threeDModelUrl" type="url" placeholder="https://" value="<?= JewelerSession::escape((string) ($editProduct['threeDModelUrl'] ?? '')) ?>"></div>
            </div>
        </fieldset>
        <fieldset data-product-fields="jewelry" <?= ($editProduct['productType'] ?? '') === 'jewelry' ? '' : 'hidden' ?> style="padding:0;border:0;margin:0">
            <div class="form-grid">
                <div class="field"><label for="jewelry-title">Title</label><input id="jewelry-title" name="title" required value="<?= JewelerSession::escape((string) ($editProduct['title'] ?? '')) ?>"></div>
                <div class="field"><label for="jewelry-category">Category</label><input id="jewelry-category" name="category" required value="<?= JewelerSession::escape((string) ($editProduct['category'] ?? '')) ?>"></div>
                <div class="field"><label for="jewelry-total-weight">Total weight (g)</label><input id="jewelry-total-weight" name="totalWeight" type="number" min="0.01" step="0.01" required value="<?= JewelerSession::escape((string) ($editProduct['totalWeight'] ?? '')) ?>"></div>
                <div class="field"><label for="jewelry-gold-weight">Gold weight (g)</label><input id="jewelry-gold-weight" name="goldWeight" type="number" min="0.01" step="0.01" required value="<?= JewelerSession::escape((string) ($editProduct['goldWeight'] ?? '')) ?>"></div>
                <div class="field"><label for="jewelry-karat">Gold karat</label><select id="jewelry-karat" name="goldKarat" required><?php foreach ([24, 22, 21, 18] as $karat): ?><option value="<?= $karat ?>" <?= (int) ($editProduct['goldKarat'] ?? 18) === $karat ? 'selected' : '' ?>><?= $karat ?>K</option><?php endforeach; ?></select></div>
                <div class="field"><label for="jewelry-color">Gold color</label><input id="jewelry-color" name="goldColor" required value="<?= JewelerSession::escape((string) ($editProduct['goldColor'] ?? '')) ?>"></div>
                <div class="field"><label for="jewelry-origin">Country of origin</label><input id="jewelry-origin" name="countryOfOrigin" required value="<?= JewelerSession::escape((string) ($editProduct['countryOfOrigin'] ?? '')) ?>"></div>
                <div class="field"><label for="jewelry-current-premium">Current premium / g (EGP)</label><input id="jewelry-current-premium" name="currentPremium" type="number" min="0" step="0.01" required value="<?= JewelerSession::escape((string) ($editProduct['currentPremium'] ?? '')) ?>"></div>
                <div class="field"><label for="jewelry-original-premium">Original premium / g (optional)</label><input id="jewelry-original-premium" name="originalPremium" type="number" min="0" step="0.01" value="<?= JewelerSession::escape((string) ($editProduct['originalPremium'] ?? '')) ?>"><small class="field-help">Used to show an original price and a calculated discount when higher than the current premium.</small></div>
                <div class="field"><label for="jewelry-3d">Optional 3D URL</label><input id="jewelry-3d" name="threeDModelUrl" type="url" placeholder="https://" value="<?= JewelerSession::escape((string) ($editProduct['threeDModelUrl'] ?? '')) ?>"></div>
                <div class="price-preview" id="jewelry-price-preview" aria-live="polite"><strong id="jewelry-current-price">Price unavailable</strong><p id="jewelry-original-price"></p><p id="jewelry-discount" class="discount" hidden></p><p id="jewelry-price-warning" class="preview-warning" <?= $goldPrices === [] ? '' : 'hidden' ?>>A valid gold price is unavailable. No estimate will be shown.</p></div>
            </div>
        </fieldset>
        <div class="field full" style="margin-top:15px"><label for="product-photos"><?= $editProduct === null ? 'Product photos' : 'Replace photos (optional)' ?></label><input id="product-photos" name="photos[]" type="file" accept="image/jpeg,image/png,image/webp" multiple <?= $editProduct === null ? 'required' : '' ?>><small class="field-help">JPG, PNG, or WEBP. Up to 10 MB per photo. Selecting photos replaces the current gallery.</small></div>
        <div class="form-footer"><button class="button secondary" type="button" data-close-product>Cancel</button><button class="button" type="submit"><?= $editProduct === null ? 'Create listing' : 'Save changes' ?></button></div>
    </form>
</dialog>
