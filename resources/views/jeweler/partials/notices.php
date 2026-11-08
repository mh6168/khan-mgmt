            <?php if ($errorMessage !== null): ?><div class="error-banner" role="alert"><?= JewelerSession::escape($errorMessage) ?></div><?php endif; ?>
            <?php if (isset($_GET['saved'])): ?><div class="notice" role="status">Product saved successfully.</div><?php endif; ?>
            <?php if (isset($_GET['updated'])): ?><div class="notice" role="status">Product availability updated.</div><?php endif; ?>
            <?php if (isset($_GET['deleted'])): ?><div class="notice" role="status">Product deleted.</div><?php endif; ?>
            <?php if ($storageError !== null): ?><div class="warning-banner" role="status">Photo storage is unavailable. Product photo operations are disabled until storage configuration is restored.</div><?php endif; ?>


