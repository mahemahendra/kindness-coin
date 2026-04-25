<?php
/**
 * Story Form Submission Handler
 * 
 * Receives story form data via POST, validates it,
 * stores it in a CSV file, sends admin notification
 * and user acknowledgment emails via PHPMailer.
 */

// session_start must come before any header() call
session_start();

// Catch fatal errors and return JSON instead of a blank 500
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(500);
        }
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $err['message']]);
    }
});

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

// --- Handle optional image upload ---
$imagePath = '';

if (isset($_FILES['storyImage']) && $_FILES['storyImage']['error'] === UPLOAD_ERR_OK) {
    $file     = $_FILES['storyImage'];
    $maxBytes = 5 * 1024 * 1024; // 5 MB

    // Validate size
    if ($file['size'] > $maxBytes) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Image must be 5 MB or smaller.']);
        exit;
    }

    // Validate MIME type via finfo (not trusting client-supplied type)
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if ($mimeType !== 'image/jpeg') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Only JPEG images are accepted.']);
        exit;
    }

    // Build safe filename: timestamp + random hex + .jpg
    $uploadsDir = __DIR__ . '/data/uploads';
    if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0755, true);
    }

    $safeFilename = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.jpg';
    $destPath     = $uploadsDir . '/' . $safeFilename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to save the uploaded image. Please try again.']);
        exit;
    }

    // Store relative path (relative to project root) in CSV
    $imagePath = 'forms/data/uploads/' . $safeFilename;
}

$csvRow = [$timestamp, $fullName, $email, $clubName, $clubLocation, $story, $imagePath];

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
    $mail->Timeout    = 10; // fail fast if SMTP is unreachable (prevents max_execution_time 500s)

    $mail->setFrom($config['sender_email'], $config['sender_name']);
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';

    return $mail;
}

$emailErrors = [];

// --- 1. Admin Notification Email ---
$mail = null;
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
    $emailErrors[] = 'Admin email failed: ' . ($mail ? $mail->ErrorInfo : $e->getMessage());
}

// --- 2. User Acknowledgment Email ---
$mail = null;
try {
    $mail = createMailer($config);
    $mail->addAddress($email, $fullName);
    $mail->Subject = 'Thank you for sharing your kindness story!';
    $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <h2 style='color: #0d6efd; border-bottom: 2px solid #0d6efd; padding-bottom: 10px;'>
                Thank you&#8212;truly!
            </h2>
            <p style='font-size: 16px; line-height: 1.6; color: #333;'>
                Dear " . htmlspecialchars($fullName) . ",
            </p>
            <p style='font-size: 16px; line-height: 1.6; color: #333;'>
                I just wanted to personally appreciate you for being part of the Kindness Coin journey. The fact that you chose to carry this forward means more than words can express.
            </p>
            <p style='font-size: 16px; line-height: 1.6; color: #333;'>
                Sometimes, the smallest acts create the biggest impact. What you&#8217;ve done may seem simple, but it has the power to brighten someone&#8217;s day, lift a spirit, and quietly inspire others to do the same. That&#8217;s how kindness grows&#8212;one person at a time.
            </p>
            <p style='font-size: 16px; line-height: 1.6; color: #333;'>
                It&#8217;s always heartening to see Lions like you leading with heart. You&#8217;re now part of a beautiful chain of goodwill that is spreading far beyond what we can see.
            </p>
            <p style='font-size: 16px; line-height: 1.6; color: #333;'>
                Thank you for being a part of this. I&#8217;m certain the kindness you&#8217;ve shared will continue to travel and touch many more lives.
            </p>
            <p style='font-size: 16px; line-height: 1.6; color: #333; margin-top: 30px;'>
                With warm appreciation,
            </p>
            <p style='font-size: 16px; line-height: 1.6; color: #333; margin: 4px 0;'>
                <strong>Your Friend in Service,</strong><br>
                <strong>PID Vijay Raju</strong>
            </p>
            <hr style='border: none; border-top: 1px solid #eee; margin: 30px 0;'>
            <p style='color: #999; font-size: 12px;'>
                This is an automated message from Kindness Coin. Please do not reply to this email.
            </p>
        </div>
    ";
    $mail->AltBody = "Thank you—truly!\n\nDear {$fullName},\n\nI just wanted to personally appreciate you for being part of the Kindness Coin journey. The fact that you chose to carry this forward means more than words can express.\n\nSometimes, the smallest acts create the biggest impact. What you've done may seem simple, but it has the power to brighten someone's day, lift a spirit, and quietly inspire others to do the same. That's how kindness grows—one person at a time.\n\nIt's always heartening to see Lions like you leading with heart. You're now part of a beautiful chain of goodwill that is spreading far beyond what we can see.\n\nThank you for being a part of this. I'm certain the kindness you've shared will continue to travel and touch many more lives.\n\nWith warm appreciation,\n\nYour Friend in Service,\nPID Vijay Raju";
    $mail->send();
} catch (Exception $e) {
    $emailErrors[] = 'Acknowledgment email failed: ' . ($mail ? $mail->ErrorInfo : $e->getMessage());
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
