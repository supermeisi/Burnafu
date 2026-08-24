<?php
$username = '';
$password = '';
$password2  = '';
$email = '';

if (isset($_GET['confirm'], $_GET['user'], $_GET['token'])) {
    $confirmationUser = trim((string) $_GET['user']);
    $confirmationToken = (string) $_GET['token'];

    if ($confirmationUser !== '' && $confirmationToken !== '') {
        try {
            $confirm = $db->prepare(
                'UPDATE users
                 SET is_confirmed = 1
                 WHERE username = :username
                   AND token = :token
                   AND is_confirmed = 0'
            );
            $confirm->execute([
                ':username' => $confirmationUser,
                ':token' => $confirmationToken,
            ]);

            if ($confirm->rowCount() > 0) {
                echo '<p class="success">Your e-mail address has been confirmed successfully.</p>';
            } else {
                echo '<p class="error">The confirmation link is invalid or has already been used.</p>';
            }
        } catch (PDOException $exception) {
            echo '<p class="error">The confirmation could not be processed. Please try again later.</p>';
        }
    }
}

// Save username and password to the MySQL database.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $success = true;

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $email = trim($_POST['email'] ?? '');
    $password2 = $_POST['password2'] ?? '';

    if (empty($username) || empty($password) || empty($email) || empty($password2)) {
        $success = false;
        $error = 'You have to fill out all fields!';
    }

    if ($success && $password !== $password2) {
        $success = false;
        $error = 'The passwords do not match.';
    }

    if (
        $success &&
        preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9_!$@#^&-]{4,15}$/D',
            $username
        ) !== 1
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
        // Hash the password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        if ($hashed_password === false) {
            $success = false;
            $error = 'The password could not be secured. Please try again.';
        }
    }

    if ($success) {
        $token = bin2hex(random_bytes(16));
        $site_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
            . ($_SERVER['HTTP_HOST'] ?? 'burfanu.com');
        $confirmation_url = $site_url . '/index.php?page=register&user='
            . rawurlencode($username)
            . '&confirm&token='
            . rawurlencode($token);

        try {
            $insert = $db->prepare(
                'INSERT INTO users ' .
                    '(username, password, email, created_at, token, last_online, is_confirmed) ' .
                    'VALUES (:username, :password, :email, CURRENT_TIMESTAMP, :token, CURRENT_TIMESTAMP, 0)'
            );
            $insert->execute([
                ':username' => $username,
                ':password' => $hashed_password,
                ':email' => $email,
                ':token' => $token
            ]);

            $to = $email;
            $subject = 'Confirm your registration on burfanu.com';
            $body = '<p>Dear ' . htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ',</p>'
                . '<p>Thank you for registering at burfanu.com.</p>'
                . '<p>To complete your registration, please confirm your e-mail address by clicking the link below:</p>'
                . '<p><a href="' . htmlspecialchars($confirmation_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">Confirm your e-mail address</a></p>'
                . '<p>If you did not register for an account, you can safely ignore this e-mail.</p>'
                . '<p>Best regards,<br>The burfanu.com Team</p>';

            send_email($to, $subject, $body);

            echo 'Registration successful... please confirm your E-Mail address in order to log in.<p>';
            echo 'Username: ' . htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '<p>';
            echo 'E-Mail: ' . htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '<p>';
        } catch (PDOException $exception) {
            $success = false;
            $sqlState = $exception->errorInfo[0]
                ?? (string) $exception->getCode();
            $error = $sqlState === '23000'
                ? 'The username or e-mail address is already registered.'
                : 'Registration could not be saved. Please try again.';
        }
    }

    if (!$success) {
        echo '<p class="error">' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }
}
?>

<!-- Register Page -->
<h1>Register</h1>
Here you can register to become a member of this Wiki by filling out the form below. After the registration process is completed, you are able to log in and add or edit content. Please keep in mind that the minimum password length is 8 characters. The length of the username must be between 5 and 16 characters. Certain special characters '_!$@#^&-' are allowed for the username, but not in the beginning.
<form action="/index.php?page=register" method="post">
    <table>
        <tr>
            <td>Username:</td>
            <td><input type="text" id="username" name="username" minlength="5" maxlength="16" value="<?= $username ?>" required></td>
        </tr>
        <tr>
            <td>E-Mail:</td>
            <td><input type="email" id="email" name="email" maxlength="254" value="<?= $email ?>" required></td>
        </tr>
        <tr>
            <td>Password:</td>
            <td><input type="password" id="password" name="password" minlength="8" value="<?= $password ?>" required></td>
        </tr>
        <tr>
            <td>Repeat Password:</td>
            <td><input type="password" id="password2" name="password2" minlength="8" value="<?= $password2 ?>" required></td>
        </tr>
    </table>
    <input type="submit" class="button" value="Register">
</form>