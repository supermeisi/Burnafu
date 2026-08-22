<?php
// Save userame and password to sqlite database
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $success = true;

    $username = $_POST['username'];
    $password = $_POST['password'];
    $email = $_POST['email'];
    $password2 = $_POST['password'];

    if (empty($username) || empty($password) || empty($email) || empty($password2)) {
        $success = false;
        $error = 'You have to fill out all fields!';
    }

    if ($success) {
        // Hash the password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $insert = $db->prepare('INSERT INTO users (username, password, email, created_at, last_online) VALUES (:username, :password, :email, DATETIME("now", "localtime"), DATETIME("now", "localtime"))');
        $insert->bindValue(':username', $username);
        $insert->bindValue(':password', $password);
        $insert->bindValue(':email', $email);
        $insert->execute();   
        
        echo 'Registration successful... you can now login and modify content.<p>';
        echo 'Username: '.$username.'<p>';
        echo 'E-Mail: '.$email.'<p>';
    }   
}
?>

<!-- Register Page -->
 <h1>Register</h1>
 Here you can register to become a member of this Wiki by filling out the form below. After the registration process is completed, you are able to log in and add or edit content. Please keep in mind that the minimum password length is 8 characters. The length of the username must be between 5 and 16 characters. Certain special characters '_!$@#^&-' are allowed for the username, but not in the beginning. 
<form accept="index.php?page=register" method="post">
    <table>
        <tr>
            <td>Username:</td>
            <td><input type="text" id="username" name="username" required></td>
        </tr>
        <tr>
            <td>E-Mail:</td>
            <td><input type="text" id="email" name="email" required></td>
        </tr>
        <tr>
            <td>Password:</td>
            <td><input type="password" id="password" name="password" required></td>
        </tr>
        <tr>
            <td>Repeat Password:</td>
            <td><input type="password" id="password2" name="password2" required></td>
        </tr>
    </table>
    <input type="submit" class="button" value="Register">
</form>