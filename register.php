<?php
// Save userame and password to sqlite database
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = false;

    $username = $_POST['username'];
    $password = $_POST['password'];
    $email = $_POST['email'];
    $password2 = $_POST['password'];

    if (!$error) {
    // Hash the password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
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