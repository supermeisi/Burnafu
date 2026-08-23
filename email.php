<?php
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function send_email($to, $subject, $body) {
    try {
        $mail = new PHPMailer(true);
        
        //Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.strato.de'; // Set your SMTP server
        $mail->SMTPAuth   = true;
        $mail->Username   = 'register@kahibaro.com'; // SMTP username
        $mail->Password   = 'zzhNmQWPE5sgJEbC';    // SMTP password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // Or PHPMailer::ENCRYPTION_SMTPS
        $mail->Port       = 587; // 587 for TLS, 465 for SSL
    
        //Recipients
        $mail->setFrom('register@kahibaro.com', 'Kahibaro');
        $mail->addAddress($to, 'Mustafa Schmidt');
        //Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
    
        $mail->send();
        //echo 'Message has been sent successfully.';
    } catch (Exception $e) {
        echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}.";
    }
}
?>