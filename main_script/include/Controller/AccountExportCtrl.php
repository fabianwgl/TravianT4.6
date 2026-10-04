<?php

namespace Controller;

use Core\Caching\Caching;
use Core\Database\DBI;
use Core\Security\Password;
use Core\Session;
use Model\AccountExportModel;

final class AccountExportCtrl extends AbstractCtrl
{
    public function render()
    {
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST');
            return $this->error(405, 'Use the download form in Account options.');
        }
        if (!$this->session->isValid()) {
            return $this->error(401, 'Log in to your account before requesting an export.');
        }
        if ($this->session->isSitter() || $this->session->isAdminInAnotherAccount()
            || $this->session->getPlayerId() <= 2) {
            return $this->error(403, 'Only the account owner can download their account data.');
        }
        $checker = $_POST[Session::getCheckerName()] ?? null;
        // This read-only download leaves the form token reusable, so a download
        // does not invalidate the settings forms still open in the same page.
        if (!is_string($checker) || !hash_equals($this->session->getChecker(), $checker)) {
            return $this->error(403, 'Reload Account options and submit the download form again.');
        }
        $password = $_POST['export_password'] ?? null;
        if (!is_string($password) || $password === '' || strlen($password) > 1024) {
            return $this->error(400, 'Enter your current account password.');
        }

        try {
            $playerId = $this->session->getPlayerId();
            $cache = Caching::getInstance();
            // Increment and expiry must be atomic across different login sessions.
            $attempts = Caching::singleton()->eval(
                "local n = redis.call('INCR', KEYS[1]); "
                . "if n == 1 then redis.call('EXPIRE', KEYS[1], 900) end; return n",
                ['account-export:attempts:' . $playerId],
                1
            );
            if (!is_int($attempts) || $attempts < 1) {
                throw new \RuntimeException('Export limiter unavailable.');
            }
            if ($attempts > 5) {
                header('Retry-After: 900');
                return $this->error(429, 'Too many export attempts. Please try again in 15 minutes.');
            }
            $hash = DBI::getInstance()->fetchScalar('SELECT password FROM users WHERE id=?', $playerId);
            if (!is_string($hash) || !Password::verify($password, $hash)) {
                return $this->error(403, 'The password did not match. Return to Account options and try again.');
            }
            if (!$cache->add('account-export:download:' . $playerId, true, 60)) {
                header('Retry-After: 60');
                return $this->error(429, 'An export was requested recently. Please wait one minute.');
            }
            $data = (new AccountExportModel())->export($playerId);
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            // Never log export contents, passwords, SQL, or player identifiers.
            error_log('Account export failed: ' . get_class($exception));
            return $this->error(503, 'Your export could not be prepared. Please try again later.');
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="openvillage-account.json"');
        return $json . "\n";
    }

    private function error(int $status, string $message): string
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Account export | OpenVillage</title><link rel="stylesheet" href="/styles.css">'
            . '</head><body><main><article class="card"><h1>Account export</h1><p>'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</p><a href="options.php?s=2">Return to Account options</a></article></main></body></html>';
    }

    protected function beforeRender()
    {
    }

    protected function afterRender()
    {
    }
}
