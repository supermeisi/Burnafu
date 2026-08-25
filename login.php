<?php
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    $_SESSION = [];
    echo '<p class="success">You have been logged out.</p>';
}

if (isset($_SESSION['username'])) {
    echo '<p class="error">You are already logged in...</p>';
    exit;
}

$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$error = '';

$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } elseif (
        preg_match('/^[A-Za-z0-9][A-Za-z0-9_!$@#^&-]{4,15}$/D', $username) !== 1
    ) {
        $error = 'The username must contain 5 to 16 valid characters.';
    } else {
        try {
            $userStatement = $db->prepare(
                'SELECT username, password, is_confirmed, is_admin FROM users WHERE username = :username LIMIT 1'
            );
            $userStatement->execute([':username' => $username]);
            $user = $userStatement->fetch();

            if ($user === false) {
                $error = 'The username or password is incorrect.';
            } elseif ((int) $user['is_confirmed'] !== 1) {
                $error = 'Please confirm your e-mail address before logging in.';
            } elseif (!password_verify($password, $user['password'])) {
                $error = 'The username or password is incorrect.';
            } else {
                $_SESSION['username'] = $user['username'];
                $_SESSION['is_admin'] = (bool) $user['is_admin'];

                try {
                    $updateOnline = $db->prepare(
                        'UPDATE users SET last_online = CURRENT_TIMESTAMP WHERE username = :username'
                    );
                    $updateOnline->execute([':username' => $user['username']]);
                } catch (PDOException $exception) {
                    // Ignore timestamp refresh failures and continue the login flow.

                }

                echo '<p class="success">Log in successful</p>';

                exit;
            }
        } catch (PDOException $exception) {
            $error = 'The login could not be processed. Please try again later.';
        }
    }
}

if ($error !== '') {
    echo '<p class="error">' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
}
?>

<!-- Login Page -->
<h1>Login</h1>

<form action="/index.php?page=login" method="post">
    <table>
        <tr>
            <td>Username:</td>
            <td><input type="text" id="username" name="username" minlength="5" maxlength="16" value="<?= htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required></td>
        </tr>
        <tr>
            <td>Password:</td>
            <td><input type="password" id="password" name="password" minlength="8" required></td>
        </tr>
    </table>
    <input type="submit" class="button" value="Login">
</form>