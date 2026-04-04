<?php
/**
 * Story Form Submission Handler
 * 
 * Receives story form data via POST, validates it,
 * stores it in a CSV file, sends admin notification
 * and user acknowledgment emails via PHPMailer.
 */

// Composer autoloader (PHPMailer)
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Load config
$config = require __DIR__ . '/config.php';

// Set JSON response header
header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// Simple rate limiting via session
session_start();
$now = time();
if (isset($_SESSION['last_story_submit']) && ($now - $_SESSION['last_story_submit']) < 60) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Please wait at least 60 seconds between submissions.']);
    exit;
}

// --- Sanitize & Validate Input ---

$fullName     = trim(strip_tags($_POST['fullName'] ?? ''));
$email        = trim(strip_tags($_POST['email'] ?? ''));
$clubName     = trim(strip_tags($_POST['clubName'] ?? ''));
$clubLocation = trim(strip_tags($_POST['clubLocation'] ?? ''));
$story        = trim(strip_tags($_POST['story'] ?? ''));

$errors = [];

if ($fullName === '') {
    $errors[] = 'Full Name is required.';
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'A valid email address is required.';
}
if ($clubName === '') {
    $errors[] = 'Club Name is required.';
}
if ($story === '') {
    $errors[] = 'Your story is required.';
}

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

// --- Store in CSV ---

$timestamp = date('Y-m-d H:i:s');
$csvPath   = $config['csv_path'];

// Ensure data directory exists
$dataDir = dirname($csvPath);
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

$csvRow = [$timestamp, $fullName, $email, $clubName, $clubLocation, $story];

$fp = fopen($csvPath, 'a');
if ($fp === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to save your story. Please try again later.']);
    exit;
}
// Lock file to prevent concurrent write issues
flock($fp, LOCK_EX);
fputcsv($fp, $csvRow);
flock($fp, LOCK_UN);
fclose($fp);

// --- Send Emails via PHPMailer ---

/**
 * Creates and configures a PHPMailer instance with SMTP settings.
 */
function createMailer(array $config): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $config['smtp']['host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $config['smtp']['username'];
    $mail->Password   = $config['smtp']['password'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = $config['smtp']['port'];

    $mail->setFrom($config['sender_email'], $config['sender_name']);
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';

    return $mail;
}

$emailErrors = [];

// --- 1. Admin Notification Email ---
try {
    $mail = createMailer($config);
    $mail->addAddress($config['admin_email']);
    $mail->Subject = "New Kindness Story from {$fullName}";
    $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <h2 style='color: #0d6efd; border-bottom: 2px solid #0d6efd; padding-bottom: 10px;'>
                New Kindness Story Submission
            </h2>
            <table style='width: 100%; border-collapse: collapse; margin-top: 15px;'>
                <tr>
                    <td style='padding: 8px 12px; font-weight: bold; background: #f8f9fa; border: 1px solid #dee2e6; width: 140px;'>Full Name</td>
                    <td style='padding: 8px 12px; border: 1px solid #dee2e6;'>" . htmlspecialchars($fullName) . "</td>
                </tr>
                <tr>
                    <td style='padding: 8px 12px; font-weight: bold; background: #f8f9fa; border: 1px solid #dee2e6;'>Email</td>
                    <td style='padding: 8px 12px; border: 1px solid #dee2e6;'>" . htmlspecialchars($email) . "</td>
                </tr>
                <tr>
                    <td style='padding: 8px 12px; font-weight: bold; background: #f8f9fa; border: 1px solid #dee2e6;'>Club Name</td>
                    <td style='padding: 8px 12px; border: 1px solid #dee2e6;'>" . htmlspecialchars($clubName) . "</td>
                </tr>
                <tr>
                    <td style='padding: 8px 12px; font-weight: bold; background: #f8f9fa; border: 1px solid #dee2e6;'>Club Location</td>
                    <td style='padding: 8px 12px; border: 1px solid #dee2e6;'>" . htmlspecialchars($clubLocation ?: 'Not provided') . "</td>
                </tr>
            </table>
            <div style='margin-top: 20px; padding: 15px; background: #f8f9fa; border-left: 4px solid #0d6efd; border-radius: 4px;'>
                <h3 style='margin: 0 0 10px 0; color: #333;'>Their Story</h3>
                <p style='margin: 0; line-height: 1.6; color: #555;'>" . nl2br(htmlspecialchars($story)) . "</p>
            </div>
            <p style='margin-top: 20px; color: #999; font-size: 12px;'>Submitted on {$timestamp}</p>
        </div>
    ";
    $mail->AltBody = "New story from {$fullName} ({$email})\nClub: {$clubName}\nLocation: {$clubLocation}\n\nStory:\n{$story}\n\nSubmitted: {$timestamp}";
    $mail->send();
} catch (Exception $e) {
    $emailErrors[] = 'Admin email failed: ' . $mail->ErrorInfo;
}

// --- 2. User Acknowledgment Email ---
try {
    $mail = createMailer($config);
    $mail->addAddress($email, $fullName);
    $mail->Subject = 'Thank you for sharing your kindness story!';
    $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <h2 style='color: #0d6efd;'>Thank You, " . htmlspecialchars($fullName) . "!</h2>
            <p style='font-size: 16px; line-height: 1.6; color: #333;'>
                We have received your kindness story and are truly inspired by your journey. 
                Your story will help spread the ripple of kindness across the world.
            </p>
            <div style='margin: 20px 0; padding: 15px; background: #f8f9fa; border-left: 4px solid #0d6efd; border-radius: 4px;'>
                <h3 style='margin: 0 0 10px 0; color: #333;'>Your Story</h3>
                <p style='margin: 0; line-height: 1.6; color: #555;'>" . nl2br(htmlspecialchars($story)) . "</p>
            </div>
            <p style='color: #555;'>
                <strong>One Coin. One Story. One Ripple of Change.</strong>
            </p>
            <hr style='border: none; border-top: 1px solid #eee; margin: 20px 0;'>
            <p style='color: #999; font-size: 12px;'>
                This is an automated message from Kindness Coin. Please do not reply to this email.
            </p>
        </div>
    ";
    $mail->AltBody = "Thank you, {$fullName}!\n\nWe have received your kindness story and are truly inspired by your journey.\n\nYour Story:\n{$story}\n\nOne Coin. One Story. One Ripple of Change.\n\n- Kindness Coin Team";
    $mail->send();
} catch (Exception $e) {
    $emailErrors[] = 'Acknowledgment email failed: ' . $mail->ErrorInfo;
}

// Update rate limit timestamp
$_SESSION['last_story_submit'] = $now;

// --- Response ---
if (!empty($emailErrors)) {
    // CSV was saved but emails had issues
    echo json_encode([
        'success' => true,
        'message' => 'Your story has been saved! However, there was an issue sending confirmation emails. Our team will follow up.',
        'emailWarnings' => $emailErrors
    ]);
} else {
    echo json_encode([
        'success' => true,
        'message' => 'Thank you for sharing your kindness story! A confirmation email has been sent to your inbox.'
    ]);
}
