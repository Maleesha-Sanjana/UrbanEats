<?php

$pageTitle = '403 - Access Denied';
http_response_code(403);
require_once __DIR__ . '/../includes/header.php';
?>

<div class="error-container" style="margin: 3rem auto;">
    <div class="error-card">
        <div class="error-code">403</div>
        <h1 class="error-title">Access Restricted</h1>
        <p class="error-desc">
            You do not have the required role or authorization to access this page.
            If you believe this is an error, please ensure you are logged in with the correct credentials.
        </p>
        <div class="error-actions">
            <a href="<?= $appBase ?>/index.php" class="btn btn-primary">Return to Homepage</a>
            <?php if (!is_logged_in()): ?>
                <a href="<?= $appBase ?>/auth/login.php" class="btn btn-secondary">Log In</a>
            <?php else: ?>
                <a href="javascript:history.back()" class="btn btn-outline">Go Back</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
