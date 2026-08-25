<?php
$username = '';
$password = '';
$password2  = '';
$email = '';

if (isset($_GET['confirm'], $_GET['user'], $_GET['token'])) {
    $confirmation_user = trim((string) $_GET['user']);
    $confirmation_token = (string) $_GET['token'];

    if ($confirmation_user !== '' && $confirmation_token !== '') {
        try {
            $confirm = $db->prepare(
                'UPDATE users
                 SET is_confirmed = 1
                 WHERE username = :username
                   AND token = :token
                   AND is_confirmed = 0'
            );
            $confirm->execute([
                ':username' => $confirmation_user,
                ':token' => $confirmation_token,
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
    $show_resend_confirmation = false;
    $resend_confirmation_username = '';
    $resend_confirmation_email = '';
    $is_resend_request = isset($_POST['resend_confirmation']);

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $email = trim((string) ($_POST['email'] ?? ''));
    $password2 = (string) ($_POST['password2'] ?? '');

    if ($is_resend_request) {
        $resend_confirmation_username = trim((string) ($_POST['resend_username'] ?? ''));
        $resend_confirmation_email = trim((string) ($_POST['resend_email'] ?? ''));

        if ($resend_confirmation_username === '') {
            $success = false;
            $error = 'The account could not be identified.';
        } elseif ($resend_confirmation_email === '' || !filter_var($resend_confirmation_email, FILTER_VALIDATE_EMAIL)) {
            $success = false;
            $error = 'Please enter a valid e-mail address.';
        } else {
            try {
                $pending_user = $db->prepare(
                    'SELECT username, email FROM users WHERE username = :username AND is_confirmed = 0 LIMIT 1'
                );
                $pending_user->execute([
                    ':username' => $resend_confirmation_username,
                ]);
                $pending_user = $pending_user->fetch();

                if ($pending_user === false) {
                    $success = false;
                    $error = 'The account is already confirmed or could not be found.';
                } else {
                    $email_conflict = $db->prepare(
                        'SELECT username FROM users WHERE email = :email AND username != :username LIMIT 1'
                    );
                    $email_conflict->execute([
                        ':email' => $resend_confirmation_email,
                        ':username' => $resend_confirmation_username,
                    ]);

                    if ($email_conflict->fetch() !== false) {
                        $success = false;
                        $error = 'This e-mail address is already registered to another account.';
                    } else {
                        $token = bin2hex(random_bytes(16));
                        $site_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
                            . ($_SERVER['HTTP_HOST'] ?? 'burfanu.com');
                        $confirmation_url = $site_url . '/index.php?page=register&user='
                            . rawurlencode((string) $pending_user['username'])
                            . '&confirm&token='
                            . rawurlencode($token);

                        $update_user = $db->prepare(
                            'UPDATE users SET email = :email, token = :token WHERE username = :username AND is_confirmed = 0 LIMIT 1'
                        );
                        $update_user->execute([
                            ':email' => $resend_confirmation_email,
                            ':token' => $token,
                            ':username' => $pending_user['username'],
                        ]);

                        $to = $resend_confirmation_email;
                        $subject = 'Confirm your registration on burfanu.com';
                        $body = '<p>Dear ' . htmlspecialchars((string) $pending_user['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ',</p>'
                            . '<p>A new confirmation link has been generated for your registration at burfanu.com.</p>'
                            . '<p>To complete your registration, please confirm your e-mail address by clicking the link below:</p>'
                            . '<p><a href="' . htmlspecialchars($confirmation_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">Confirm your e-mail address</a></p>'
                            . '<p>If you did not register for an account, you can safely ignore this e-mail.</p>'
                            . '<p>Best regards,<br>The burfanu.com Team</p>';

                        send_email($to, $subject, $body);
                        echo '<p class="success">A new confirmation e-mail has been sent.</p>';
                        echo '<p>E-Mail: ' . htmlspecialchars($resend_confirmation_email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
                        $success = false;
                        $error = '';
                    }
                }
            } catch (PDOException $exception) {
                $success = false;
                $error = 'The confirmation e-mail could not be sent. Please try again later.';
            }
        }
    } elseif (empty($username) || empty($password) || empty($email) || empty($password2)) {
        $success = false;
        $error = 'You have to fill out all fields!';
    }

    if (!$is_resend_request && $success && $password !== $password2) {
        $success = false;
        $error = 'The passwords do not match.';
    }

    if (
        !$is_resend_request &&
        $success &&
        preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9_!$@#^&-]{4,15}$/D',
            $username
        ) !== 1
    ) {
        $success = false;
        $error = 'The username must contain 5 to 16 valid characters.';
    }

    if (!$is_resend_request && $success && strlen($password) < 8) {
        $success = false;
        $error = 'The password must contain at least 8 characters.';
    }

    if (!$is_resend_request && $success && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $success = false;
        $error = 'Please enter a valid e-mail address.';
    }

    if (!$is_resend_request && $success) {
        try {
            $existing_user = $db->prepare(
                'SELECT username, email, is_confirmed FROM users WHERE username = :username OR email = :email LIMIT 1'
            );
            $existing_user->execute([
                ':username' => $username,
                ':email' => $email,
            ]);
            $existing_user = $existing_user->fetch();

            if ($existing_user !== false) {
                if ((int) $existing_user['is_confirmed'] === 1) {
                    $success = false;
                    $error = 'The username or e-mail address is already registered.';
                } else {
                    $success = false;
                    $show_resend_confirmation = true;
                    $resend_confirmation_username = (string) $existing_user['username'];
                    $resend_confirmation_email = (string) $existing_user['email'];
                    $error = 'This username or e-mail address is already registered, but not confirmed yet. Do you want to send another confirmation e-mail?';
                }
            }
        } catch (PDOException $exception) {
            $success = false;
            $error = 'The user could not be validated. Please try again later.';
        }
    }

    if (!$is_resend_request && $success) {
        // Hash the password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        if ($hashed_password === false) {
            $success = false;
            $error = 'The password could not be secured. Please try again.';
        }
    }

    if (!$is_resend_request && $success) {
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
            $sql_state = $exception->errorInfo[0]
                ?? (string) $exception->getCode();
            $error = $sql_state === '23000'
                ? 'The username or e-mail address is already registered.'
                : 'Registration could not be saved. Please try again.';
        }
    }

    if (!$success && $error !== '') {
        echo '<p class="error">' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }

    if ($show_resend_confirmation) {
        echo '<form action="/index.php?page=register" method="post">';
        echo '<input type="hidden" name="resend_confirmation" value="1">';
        echo '<input type="hidden" name="resend_username" value="' . htmlspecialchars($resend_confirmation_username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
        echo '<p><label for="resend_email">Update e-mail address:</label> <input type="email" id="resend_email" name="resend_email" value="' . htmlspecialchars($resend_confirmation_email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" maxlength="254" required></p>';
        echo '<input type="submit" class="button" value="Send another confirmation e-mail">';
        echo '</form>';
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