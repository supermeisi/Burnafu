<?php
session_start();

$visitors = 0;
?>

<html>

<head>
    <link rel="stylesheet" href="/style.css">
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
    </div>
</body>

</html>