<?php
session_start();

require_once __DIR__ . '/database_connection.php';

try {
    $db = createDatabaseConnection();
} catch (Throwable $exception) {
    error_log('Database connection failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('The database is temporarily unavailable. Please try again later.');
}

$visitors = 0;

$page = isset($_GET['page']) ? $_GET['page'] : 'home';
?>

<html>

<head>
    <link rel="stylesheet" href="/style.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>

<body>
    <div class="header">
        <?php if (!isset($_SESSION['username'])): ?>
            <a href="/index.php?page=register" rel="nofollow">Register</a> |
        <?php endif; ?>
        <?php if (isset($_SESSION['username'])): ?>
            <a href="/index.php?page=login&logout" rel="nofollow">Logout [<?= $_SESSION['username']; ?>]</a>
        <?php else: ?>
            <a href="/index.php?page=login" rel="nofollow">Login</a>
        <?php endif; ?>
        | Visitors: <?= $visitors; ?>
    </div>
    <div class="banner">
        <h1>Welcome to the website!</h1>
    </div>
    <div class="wrapper">
        <div class="navigation">
            <h3>Navigation</h3>
            <ul>
                <li><a href="/index.php?page=home" rel="nofollow">Home</a></li>
                <li><a href="/index.php?page=about" rel="nofollow">About</a></li>
                <li><a href="/index.php?page=contact" rel="nofollow">Contact</a></li>
            </ul>
        </div>
          <div class="main">
            <?php if (!isset($_GET['page'])): ?>
                <div class="toolbar">
                    <a href="/index.php?page=create" rel="nofollow">Create</a>
                    <a href="/index.php?page=edit&slug=<?php echo $page; ?>" rel="nofollow">Edit</a>
                    <a href="/index.php?page=media&slug==<?php echo $page; ?>" rel="nofollow">Media</a>
                    <a href="/index.php?page=forum&discussion&slug==<?php echo $page; ?>" rel="nofollow">Discussion</a>
                    <a href="/index.php?page=history&slug==<?php echo $page; ?>" rel="nofollow">History</a>
                    <a href="/index.php?page=delete&slug==<?php echo $page; ?>" rel="nofollow">Delete</a>
                </div>
            <?php endif; ?>
            <!-- Main Content -->
            <?php include($page . '.php'); ?>
        </div>
    </div>
</body>

</html>
