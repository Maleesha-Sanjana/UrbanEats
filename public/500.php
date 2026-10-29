<?php

$pageTitle = '500 - Internal Server Error';
http_response_code(500);
require_once __DIR__ . '/../includes/header.php';
?>

<div class="error-container" style="margin: 3rem auto;">
    <div class="error-card">
        <div class="error-code">500</div>
        <h1 class="error-title">Internal Server Error</h1>
        <p class="error-desc">
            Something unexpected occurred on our end while preparing your request.
            Our technical team has been notified through the system error logs.
        </p>
        <div class="error-actions">
            <a href="<?= $appBase ?>/index.php" class="btn btn-primary">Return to Homepage</a>
            <a href="javascript:history.back()" class="btn btn-outline">Go Back</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
