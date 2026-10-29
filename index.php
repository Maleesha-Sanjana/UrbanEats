<?php

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$query = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
$redirectUrl = ($basePath !== '' ? $basePath : '') . '/public/index.php' . $query;

// Send HTTP 302 redirect header
header('Location: ' . $redirectUrl, true, 302);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="refresh" content="0;url=<?= htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8') ?>">
    <title>Redirecting to UrbanEats...</title>
</head>
<body>
    <p>Loading UrbanEats Cloud Kitchen Platform... If you are not redirected automatically, <a href="<?= htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8') ?>">click here</a>.</p>
</body>
</html>
