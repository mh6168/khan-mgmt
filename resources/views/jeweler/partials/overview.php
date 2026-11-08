                <section class="metrics" aria-label="Store overview">
                    <div class="metric"><span class="metric-label">Total product listings</span><strong class="metric-value"><?= $totalProducts ?></strong></div>
                    <div class="metric"><span class="metric-label">Available listings</span><strong class="metric-value metric-accent"><?= $availableProducts ?></strong></div>
                    <div class="metric"><span class="metric-label">Unavailable listings</span><strong class="metric-value"><?= $unavailableProducts ?></strong></div>
                    <div class="metric"><span class="metric-label">Total reservations</span><strong class="metric-value">0</strong></div>
                    <div class="metric"><span class="metric-label">Completed reservations</span><strong class="metric-value">0</strong></div>
                </section>
                <section>
                    <div class="section-heading"><div><h2>Gold market</h2><p><?= JewelerSession::escape((string) ($goldData['message'] ?? 'Gold price data unavailable.')) ?></p></div><span class="availability <?= ($goldData['cached'] ?? false) ? 'off' : '' ?>"><?= ($goldData['cached'] ?? false) ? 'Cached' : (($goldData['available'] ?? false) ? 'Live' : 'Unavailable') ?></span></div>
                    <?php if ($goldPrices === []): ?>
                        <div class="warning-banner">A valid gold price is unavailable. Jewelry prices cannot be calculated until a current or cached price is available.</div>
                    <?php else: ?>
                        <div class="gold-strip">
                            <div class="gold-intro"><strong>Gold price per gram</strong><span>Updated <?= JewelerSession::escape((string) ($goldData['updatedAt'] ?? 'time unavailable')) ?> · EGP</span></div>
                            <?php foreach ([24, 22, 21, 18] as $karat): ?><div class="gold-item"><strong><?= isset($goldPrices[$karat]) ? number_format($goldPrices[$karat], 2) : 'Unavailable' ?></strong><span><?= $karat ?>K <small>EGP / gram</small></span></div><?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
                <section>
                    <div class="section-heading"><div><h2>Recent products</h2><p>Your latest product listings</p></div><a class="button secondary small" href="<?= JewelerSession::escape(JewelerSession::url('/jeweler/dashboard.php?view=products')) ?>">View products</a></div>
                    <?php if ($products === []): ?><div class="empty-state"><h2>Your catalog is ready for its first listing</h2><p>Add a watch or jewelry piece to begin building your KHAN storefront.</p><a class="button" style="margin-top:16px" href="<?= JewelerSession::escape(JewelerSession::url('/jeweler/dashboard.php?view=products')) ?>">Open products</a></div>
                    <?php else: ?><div class="products-grid"><?php foreach (array_slice($products, 0, 3) as $product): ?>
                        <article class="product-card"><div class="product-image"><?php if (($product['photoDisplayUrls'][0] ?? '') !== ''): ?><img src="<?= JewelerSession::escape((string) $product['photoDisplayUrls'][0]) ?>" alt="<?= JewelerSession::escape($productTitle($product)) ?>" loading="lazy"><?php else: ?><span class="product-image-placeholder">Photo unavailable</span><?php endif; ?></div><div class="product-body"><div class="product-title-row"><div><h3><?= JewelerSession::escape($productTitle($product)) ?></h3><p class="product-type"><?= ($product['productType'] ?? '') === 'watch' ? 'Watch' : 'Jewelry piece' ?></p></div><span class="availability <?= ($product['availability'] ?? '') === 'available' ? '' : 'off' ?>"><?= ($product['availability'] ?? '') === 'available' ? 'Available' : 'Unavailable' ?></span></div><p class="price-line"><?php $price = $productPrice($product); ?><?= $price === null ? 'Price unavailable' : number_format($price, 2) . ' EGP' ?></p><div class="product-meta">Added <?= JewelerSession::escape($formatDate($product['createdAt'] ?? null)) ?></div></div></article>
                    <?php endforeach; ?></div><?php endif; ?>
                </section>
