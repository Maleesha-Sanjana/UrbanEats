<?php

// Error logging target
define('ERROR_LOG_PATH', dirname(__DIR__) . '/logs/error.log');

/**
 * Log error message to secure log file
 */
function log_system_error(string $level, string $message, string $file = '', int $line = 0): void {
    $logDir = dirname(ERROR_LOG_PATH);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    $timestamp = date('Y-m-d H:i:s');
    $logEntry = sprintf(
        "[%s] [%s] %s in %s on line %d (URI: %s, IP: %s)\n",
        $timestamp,
        strtoupper($level),
        $message,
        $file ?: 'unknown',
        $line,
        $_SERVER['REQUEST_URI'] ?? 'CLI',
        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
    );

    @file_put_contents(ERROR_LOG_PATH, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Render user-friendly error page
 */
function render_error_page(int $statusCode, string $title, string $message, string $backUrl = ''): void {
    if (!headers_sent()) {
        http_response_code($statusCode);
    }
    
    // Fallback back URL
    if (empty($backUrl)) {
        $backUrl = '/UrbanEats/public/index.php';
    }

    // Output clean HTML error response
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title) ?> - UrbanEats</title>
        <link rel="stylesheet" href="/UrbanEats/public/css/style.css">
    </head>
    <body class="error-body">
        <div class="error-container">
            <div class="error-card">
                <div class="error-code"><?= $statusCode ?></div>
                <h1 class="error-title"><?= htmlspecialchars($title) ?></h1>
                <p class="error-desc"><?= htmlspecialchars($message) ?></p>
                <div class="error-actions">
                    <a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-primary">Return to Homepage</a>
                    <a href="javascript:history.back()" class="btn btn-secondary">Go Back</a>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

/**
 * Custom PHP Error Handler
 */
function custom_error_handler($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }

    $level = 'ERROR';
    switch ($errno) {
        case E_USER_ERROR:
            $level = 'FATAL ERROR';
            break;
        case E_USER_WARNING:
        case E_WARNING:
            $level = 'WARNING';
            break;
        case E_USER_NOTICE:
        case E_NOTICE:
            $level = 'NOTICE';
            break;
        default:
            $level = "UNKNOWN ($errno)";
            break;
    }

    log_system_error($level, $errstr, $errfile, $errline);

    if ($errno === E_USER_ERROR) {
        render_error_page(500, 'System Error', 'An unexpected error occurred while processing your request. Please try again later.');
    }

    return true; // Don't execute PHP internal error handler
}

/**
 * Custom Exception Handler
 */
function custom_exception_handler(Throwable $exception) {
    log_system_error(
        'UNCAUGHT EXCEPTION',
        get_class($exception) . ': ' . $exception->getMessage() . "\nStack Trace:\n" . $exception->getTraceAsString(),
        $exception->getFile(),
        $exception->getLine()
    );

    render_error_page(
        500,
        'Server Exception',
        'We encountered an issue fulfilling your request. Our engineering team has been notified.'
    );
}

/**
 * Shutdown function for fatal errors
 */
function custom_shutdown_handler() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        log_system_error('FATAL SHUTDOWN', $error['message'], $error['file'], $error['line']);
        render_error_page(500, 'Fatal System Error', 'A critical server error halted execution. Please check the logs.');
    }
}

// Register global handlers
set_error_handler('custom_error_handler');
set_exception_handler('custom_exception_handler');
register_shutdown_function('custom_shutdown_handler');
