        <header class="topbar">
            <div><p class="eyebrow">KHAN JEWELER WORKSPACE</p><h1><?= JewelerSession::escape($viewTitle) ?></h1><p><?= JewelerSession::escape($applicationValue('storeName', 'Manage your store')) ?></p></div>
            <div class="top-actions">
                <?php if ($view === 'products'): ?><button class="button" type="button" data-open-product>＋ <span>Add product</span></button><?php endif; ?>
            </div>
        </header>
