<?php
$username = '';
$password = '';
$password2 = '';
$email = '';
$setupComplete = false;

try {
    $adminCount = (int) $db->query(
        'SELECT COUNT(*) FROM users WHERE is_admin = 1'
    )->fetchColumn();
} catch (Throwable $exception) {
    $adminCount = 0;
}

if ($adminCount > 0) {
    $setupComplete = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $success = true;
    $error = '';

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $password2 = (string) ($_POST['password2'] ?? '');
    $email = trim((string) ($_POST['email'] ?? ''));

    if ($username === '' || $password === '' || $password2 === '' || $email === '') {
        $success = false;
        $error = 'You have to fill out all fields.';
    }

    if ($success && $password !== $password2) {
        $success = false;
        $error = 'The passwords do not match.';
    }

    if (
        $success &&
        preg_match('/^[A-Za-z0-9][A-Za-z0-9_!$@#^&-]{4,15}$/D', $username) !== 1
    ) {
        $success = false;
        $error = 'The username must contain 5 to 16 valid characters.';
    }

    if ($success && strlen($password) < 8) {
        $success = false;
        $error = 'The password must contain at least 8 characters.';
    }

    if ($success && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $success = false;
        $error = 'Please enter a valid e-mail address.';
    }

    if ($success) {
        try {
            $existingUser = $db->prepare(
                'SELECT 1 FROM users WHERE username = :username OR email = :email LIMIT 1'
            );
            $existingUser->execute([
                ':username' => $username,
                ':email' => $email,
            ]);

            if ($existingUser->fetchColumn() !== false) {
                $success = false;
                $error = 'The username or e-mail address is already registered.';
            }
        } catch (PDOException $exception) {
            $success = false;
            $error = 'The user could not be validated. Please try again later.';
        }
    }

    if ($success) {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        if ($hashedPassword === false) {
            $success = false;
            $error = 'The password could not be secured. Please try again.';
        }
    }

    if ($success) {
        try {
            $insert = $db->prepare(
                'INSERT INTO users ' .
                '(username, password, email, created_at, last_online, is_confirmed, confirmed_at, is_admin) ' .
                'VALUES (:username, :password, :email, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 1, CURRENT_TIMESTAMP, 1)'
            );
            $insert->execute([
                ':username' => $username,
                ':password' => $hashedPassword,
                ':email' => $email,
            ]);

            $_SESSION['username'] = $username;
            $_SESSION['is_admin'] = true;
            $setupComplete = true;
            echo '<p class="success">The administrator account was created successfully.</p>';
            echo '<p>Username: ' . htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
            echo '<p>E-Mail: ' . htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
            echo '<p><a href="/index.php?page=home" class="button">Return to the homepage</a></p>';
        } catch (PDOException $exception) {
            $success = false;
            $error = 'The administrator account could not be created. Please try again later.';
        }
    }

    if (!$success) {
        echo '<p class="error">' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }
}

if (!$setupComplete):
    ?>
    <h1>Setup administrator account</h1>
    <p>Create the first administrator account for this installation. This account is created immediately and has administrator rights.</p>
    <form action="/index.php?page=setup" method="post">
        <table>
            <tr>
                <td>Username:</td>
                <td><input type="text" id="username" name="username" minlength="5" maxlength="16" value="<?= htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required></td>
            </tr>
            <tr>
                <td>E-Mail:</td>
                <td><input type="email" id="email" name="email" maxlength="254" value="<?= htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required></td>
            </tr>
            <tr>
                <td>Password:</td>
                <td><input type="password" id="password" name="password" minlength="8" required></td>
            </tr>
            <tr>
                <td>Repeat Password:</td>
                <td><input type="password" id="password2" name="password2" minlength="8" required></td>
            </tr>
        </table>
        <input type="submit" class="button" value="Create administrator">
    </form>
    <?php
endif;

if ($setupComplete && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo '<p class="success">An administrator already exists for this installation.</p>';
}
