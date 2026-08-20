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
            <?php if(!isset($_SESSION['username'])): ?>
                <a href="/index.php?page=register" rel="nofollow">Register</a> |
            <?php endif; ?>
            <?php if(isset($_SESSION['username'])): ?>
                <a href="/index.php?page=login&logout" rel="nofollow">Logout [<?= $_SESSION['username']; ?>]</a>
            <?php else: ?>
                <a href="/index.php?page=login" rel="nofollow">Login</a>
            <?php endif; ?>
            | Visitors: <?= $visitors; ?>
        </div>
    </body>
</html>
