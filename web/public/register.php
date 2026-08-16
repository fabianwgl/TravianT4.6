<?php

declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

function fail_registration(string $message): never
{
    header('Location: /?error=' . rawurlencode($message), true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
    fail_registration('Your form expired. Please try again.');
}

$attempts = $_SESSION['registration_attempts'] ?? [];
$attempts = array_values(array_filter($attempts, static fn ($time) => $time > time() - 60));
if (count($attempts) >= 5) {
    fail_registration('Too many attempts. Please wait one minute.');
}
$attempts[] = time();
$_SESSION['registration_attempts'] = $attempts;

$username = trim((string)($_POST['username'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if (!isset($_POST['terms'])) {
    fail_registration('You must accept the terms and privacy notice.');
}
if (preg_match('/^[A-Za-z0-9 _-]{3,15}$/', $username) !== 1) {
    fail_registration('Player name must be 3–15 letters, numbers, spaces, underscores, or hyphens.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 90) {
    fail_registration('Enter a valid email address.');
}
if (strlen($password) < 10 || strlen($password) > 128) {
    fail_registration('Password must be 10–128 characters.');
}

try {
    $database = game_database();
    $database->beginTransaction();

    $statement = $database->prepare(
        'SELECT 1 FROM users WHERE name = :user_name OR email = :user_email
         UNION ALL
         SELECT 1 FROM activation WHERE name = :activation_name OR email = :activation_email
         LIMIT 1'
    );
    $statement->execute([
        'user_name' => $username,
        'user_email' => $email,
        'activation_name' => $username,
        'activation_email' => $email,
    ]);
    if ($statement->fetchColumn()) {
        $database->rollBack();
        fail_registration('That player name or email is already registered.');
    }

    $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    $passwordHash = password_hash($password, $algorithm);
    $token = bin2hex(random_bytes(24));

    $statement = $database->prepare(
        'INSERT INTO activation (name, password, email, token, refUid, time)
         VALUES (:username, :password, :email, :token, 0, :created)'
    );
    $statement->execute([
        'username' => $username,
        'password' => $passwordHash,
        'email' => $email,
        'token' => $token,
        'created' => time(),
    ]);
    $database->commit();
} catch (Throwable $exception) {
    if (isset($database) && $database->inTransaction()) {
        $database->rollBack();
    }
    error_log($exception->getMessage());
    fail_registration('Registration is temporarily unavailable.');
}

unset($_SESSION['launcher_csrf']);
header('Location: /game/activate.php?token=' . rawurlencode($token), true, 303);
