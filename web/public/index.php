<?php

declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

$error = isset($_GET['error']) ? trim((string)$_GET['error']) : '';
$ready = false;
try {
    $ready = (int)game_database()->query('SELECT installed FROM config LIMIT 1')->fetchColumn() === 1;
} catch (Throwable $exception) {
    $ready = false;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>OpenVillage 4.6</title>
    <meta name="description" content="A local-first preservation server for a T4.6-style strategy game ruleset.">
    <link rel="stylesheet" href="/styles.css">
</head>
<body>
<main>
    <header class="hero">
        <p class="eyebrow">Local-first preservation edition</p>
        <h1>OpenVillage <span>4.6</span></h1>
        <p>Build villages, trade, form alliances, capture plans, and race to complete the World Wonder.</p>
        <div class="status <?= $ready ? 'ready' : 'waiting' ?>">
            <span></span><?= $ready ? 'Local world ready' : 'World initialization in progress' ?>
        </div>
    </header>

    <section class="grid">
        <article class="card">
            <h2>Enter the world</h2>
            <p>Continue with an existing account.</p>
            <a class="button secondary" href="/game/login.php">Log in</a>
        </article>

        <article class="card accent">
            <h2>Create an account</h2>
            <?php if ($error !== ''): ?>
                <p class="error"><?= escape($error) ?></p>
            <?php endif; ?>
            <form method="post" action="/register.php">
                <input type="hidden" name="csrf" value="<?= escape(csrf_token()) ?>">
                <label>
                    Player name
                    <input name="username" minlength="3" maxlength="15" pattern="[A-Za-z0-9 _-]+" required autocomplete="username">
                </label>
                <label>
                    Email
                    <input type="email" name="email" maxlength="90" required autocomplete="email">
                </label>
                <label>
                    Password
                    <input type="password" name="password" minlength="10" maxlength="128" required autocomplete="new-password">
                </label>
                <label class="check">
                    <input type="checkbox" name="terms" value="1" required>
                    <span>I accept the <a href="/terms.html">terms</a> and <a href="/privacy.html">privacy notice</a>.</span>
                </label>
                <button class="button" type="submit" <?= $ready ? '' : 'disabled' ?>>Choose tribe and start</button>
            </form>
        </article>
    </section>

    <footer>
        <a href="/docs/">Rules and operations</a>
        <span>•</span>
        <a href="/health.php">Health</a>
        <p>Independent preservation project. Not affiliated with or endorsed by Travian Games.</p>
    </footer>
</main>
</body>
</html>
