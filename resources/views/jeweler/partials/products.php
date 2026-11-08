                <div class="toolbar" role="search">
                    <input class="search" id="product-search" type="search" placeholder="Search your products" aria-label="Search products">
                    <label>Type <select id="type-filter" aria-label="Filter by product type"><option value="all">All types</option><option value="watch">Watches</option><option value="jewelry">Jewelry pieces</option></select></label>
                    <label>Availability <select id="availability-filter" aria-label="Filter by availability"><option value="all">All listings</option><option value="available">Available</option><option value="unavailable">Unavailable</option></select></label>
                </div>
                <?php if ($products === []): ?><div class="empty-state"><h2>No product listings yet</h2><p>When you add watches or jewelry pieces, they will appear here.</p><button class="button" style="margin-top:16px" type="button" data-open-product>Add your first product</button></div>
                <?php else: ?><div class="products-grid" id="products-grid">
                    <?php foreach ($products as $product): $type = (string) ($product['productType'] ?? 'jewelry'); $availability = (string) ($product['availability'] ?? 'unavailable'); $title = $productTitle($product); ?>
                        <article class="product-card" data-product-card data-type="<?= JewelerSession::escape($type) ?>" data-availability="<?= JewelerSession::escape($availability) ?>" data-search="<?= JewelerSession::escape(strtolower($title . ' ' . $type . ' ' . (string) ($product['category'] ?? '') . ' ' . (string) ($product['brand'] ?? ''))) ?>">
                            <div class="product-image"><?php if (($product['photoDisplayUrls'][0] ?? '') !== ''): ?><img src="<?= JewelerSession::escape((string) $product['photoDisplayUrls'][0]) ?>" alt="<?= JewelerSession::escape($title) ?>" loading="lazy"><?php else: ?><span class="product-image-placeholder">Photo unavailable</span><?php endif; ?></div>
                            <div class="product-body">
                                <div class="product-title-row"><div><h3><?= JewelerSession::escape($title) ?></h3><p class="product-type"><?= $type === 'watch' ? 'Watch' : 'Jewelry piece' ?><?php if ($type === 'jewelry' && ($product['category'] ?? '') !== ''): ?> · <?= JewelerSession::escape((string) $product['category']) ?><?php endif; ?></p></div><span class="availability <?= $availability === 'available' ? '' : 'off' ?>"><?= $availability === 'available' ? 'Available' : 'Unavailable' ?></span></div>
                                <?php $price = $productPrice($product); ?><p class="price-line"><?= $price === null ? 'Price unavailable' : number_format($price, 2) . ' EGP' ?><?php if ($type === 'jewelry'): ?><small> · current gold pricing</small><?php endif; ?></p>
                                <div class="product-meta">Created <?= JewelerSession::escape($formatDate($product['createdAt'] ?? null)) ?></div>
                                <div class="product-actions">
                                    <a class="button secondary small" href="<?= JewelerSession::escape(JewelerSession::url('/jeweler/dashboard.php?view=products&edit=' . rawurlencode((string) $product['id']))) ?>">Edit</a>
                                    <form class="inline-form" method="post"><input type="hidden" name="_csrf" value="<?= JewelerSession::escape($csrfToken) ?>"><input type="hidden" name="action" value="availability"><input type="hidden" name="productId" value="<?= JewelerSession::escape((string) $product['id']) ?>"><input type="hidden" name="availability" value="<?= $availability === 'available' ? 'unavailable' : 'available' ?>"><button class="button secondary small" type="submit">Mark <?= $availability === 'available' ? 'unavailable' : 'available' ?></button></form>
                                    <form class="inline-form" method="post" data-delete-form><input type="hidden" name="_csrf" value="<?= JewelerSession::escape($csrfToken) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="productId" value="<?= JewelerSession::escape((string) $product['id']) ?>"><button class="button danger small" type="submit">Delete</button></form>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div><div class="empty-state" id="filter-empty" hidden><p>No products match those filters.</p></div><?php endif; ?>
