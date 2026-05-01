<?php
/**
 * Contact Form Submission Handler
 *
 * Returns "OK" on success (consumed by validate.js) or an error string on failure.
 */

session_start();

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

// Simple rate limiting via session (same pattern as submit-story.php)
$now = time();
if (isset($_SESSION['last_contact_submit']) && ($now - $_SESSION['last_contact_submit']) < 60) {
    http_response_code(429);
    exit('Please wait at least 60 seconds between messages.');
}

$config = require __DIR__ . '/config.php';

// Sanitize & validate
$name    = trim(strip_tags($_POST['name']    ?? ''));
$email   = trim(strip_tags($_POST['email']   ?? ''));
$subject = trim(strip_tags($_POST['subject'] ?? ''));
$message = trim(strip_tags($_POST['message'] ?? ''));

$errors = [];
if ($name === '')                                          $errors[] = 'Name is required.';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
if ($subject === '')                                       $errors[] = 'Subject is required.';
if ($message === '')                                       $errors[] = 'Message is required.';

if (!empty($errors)) {
    http_response_code(422);
    exit(implode(' ', $errors));
}

$_SESSION['last_contact_submit'] = $now;

// Skip sending during local development
if ($config['skip_email'] ?? false) {
    exit('OK');
}

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $config['smtp']['host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $config['smtp']['username'];
    $mail->Password   = $config['smtp']['password'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = $config['smtp']['port'];
    $mail->Timeout    = 10;

    $mail->setFrom($config['sender_email'], $config['sender_name']);
    $mail->addAddress($config['admin_email']);
    $mail->addReplyTo($email, $name);
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';

    $mail->Subject = 'Contact Form: ' . $subject;
    $mail->Body    = '<div style="font-family:Arial,sans-serif;max-width:600px;">'
                   . '<p><strong>From:</strong> ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
                   . ' &lt;' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '&gt;</p>'
                   . '<p><strong>Subject:</strong> ' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</p>'
                   . '<hr>'
                   . '<p style="white-space:pre-wrap">' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</p>'
                   . '</div>';
    $mail->AltBody = "From: {$name} <{$email}>\nSubject: {$subject}\n\n{$message}";
    $mail->send();
} catch (Exception $e) {
    http_response_code(500);
    exit('Unable to send your message. Please try again later.');
}

// --- Submitter acknowledgment (non-fatal if it fails) ---
try {
    $ack = new PHPMailer(true);
    $ack->isSMTP();
    $ack->Host       = $config['smtp']['host'];
    $ack->SMTPAuth   = true;
    $ack->Username   = $config['smtp']['username'];
    $ack->Password   = $config['smtp']['password'];
    $ack->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $ack->Port       = $config['smtp']['port'];
    $ack->Timeout    = 10;

    $ack->setFrom($config['sender_email'], $config['sender_name']);
    $ack->addAddress($email, $name);
    $ack->isHTML(true);
    $ack->CharSet = 'UTF-8';

    $ack->Subject = 'Thank you for reaching out – Kindness Coin';
    $ack->Body    = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;">'
                  . '<h2 style="color:#0d6efd;border-bottom:2px solid #0d6efd;padding-bottom:10px;">Thank you for contacting us!</h2>'
                  . '<p style="font-size:16px;line-height:1.6;color:#333;">Dear ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
                  . '<p style="font-size:16px;line-height:1.6;color:#333;">Thank you for reaching out to Kindness Coin. We have received your message and will get back to you as soon as possible.</p>'
                  . '<p style="font-size:16px;line-height:1.6;color:#333;">In the meantime, feel free to explore our stories of kindness at <a href="https://kindnesscoin.in" style="color:#0d6efd;">kindnesscoin.in</a>.</p>'
                  . '<p style="font-size:16px;line-height:1.6;color:#333;margin-top:30px;">With warm regards,<br><strong>Kindness Coin Team</strong></p>'
                  . '<hr style="border:none;border-top:1px solid #eee;margin:30px 0;">'
                  . '<p style="color:#999;font-size:12px;">This is an automated acknowledgment. Please do not reply to this email.</p>'
                  . '</div>';
    $ack->AltBody = "Dear {$name},\n\nThank you for reaching out to Kindness Coin. We have received your message and will get back to you as soon as possible.\n\nWith warm regards,\nKindness Coin Team";
    $ack->send();
} catch (Exception $e) {
    // Acknowledgment failure is non-fatal — admin email already sent
}

exit('OK');

