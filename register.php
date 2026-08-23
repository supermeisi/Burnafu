<?php
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
                    '(username, password, email, created_at, last_online) ' .
                    'VALUES (:username, :password, :email, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
            );
            $insert->execute([
                ':username' => $username,
                ':password' => $hashedPassword,
                ':email' => $email,
            ]);

            echo 'Registration successful... you can now login and modify content.<p>';
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
            <td><input type="text" id="username" name="username" minlength="5" maxlength="16" required></td>
        </tr>
        <tr>
            <td>E-Mail:</td>
            <td><input type="email" id="email" name="email" maxlength="254" required></td>
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
    <input type="submit" class="button" value="Register">
</form>