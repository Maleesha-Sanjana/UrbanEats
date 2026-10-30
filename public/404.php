<?php

$pageTitle = '404 - Page Not Found';
http_response_code(404);
require_once __DIR__ . '/../includes/header.php';
?>

<div class="error-container" style="margin: 3rem auto;">
    <div class="error-card">
        <div class="error-code">404</div>
        <h1 class="error-title">Dish or Page Not Found</h1>
        <p class="error-desc">
            The resource you requested might have been moved, deleted, or is temporarily unavailable in our kitchen.
        </p>
        <div class="error-actions">
            <a href="<?= $appBase ?>/index.php" class="btn btn-primary">Return to Homepage</a>
            <a href="<?= $appBase ?>/menu/list.php" class="btn btn-secondary">Explore Menu</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
