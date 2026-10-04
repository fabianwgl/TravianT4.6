<?php

declare(strict_types=1);

use Core\Database\DBI;
use Core\Security\Password;

require '/app/main_script/copyable/include/env.php';
require '/app/main_script/include/bootstrap.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: create-sitter or remove-sitter\n");
    exit(2);
}

$db = DBI::getInstance();
$mode = $argv[1];

if ($mode === 'create-sitter') {
    if ($argc !== 5) {
        fwrite(STDERR, "Usage: create-sitter OWNER_NAME SITTER_NAME SITTER_PASSWORD\n");
        exit(2);
    }
    [$ownerName, $sitterName, $sitterPassword] = array_slice($argv, 2);
    $owner = $db->fetchSingleRow(
        'SELECT id, sit1Uid FROM users WHERE name=? LIMIT 1',
        [$ownerName]
    );
    if (!is_array($owner) || (int)$owner['id'] <= 2) {
        throw new RuntimeException('Disposable owner was not found.');
    }
    if ((int)$owner['sit1Uid'] !== 0) {
        throw new RuntimeException('Disposable owner already has a first sitter.');
    }
    if ($db->fetchScalar('SELECT id FROM users WHERE name=? LIMIT 1', [$sitterName])) {
        throw new RuntimeException('Disposable sitter name is already in use.');
    }

    $db->beginTransaction();
    try {
        $db->run(
            'INSERT INTO users (uuid, name, password, email, race, kid, desc1, desc2, note)
             VALUES (UUID(), ?, ?, ?, 1, 0, ?, ?, ?)',
            [$sitterName, Password::hash($sitterPassword), '', '', '', '']
        );
        $sitterId = (int)$db->lastInsertId();
        $changed = $db->run(
            'UPDATE users SET sit1Uid=?, sit1Permissions=87 WHERE id=? AND sit1Uid=0',
            [$sitterId, (int)$owner['id']]
        );
        if ($changed->rowCount() !== 1) {
            throw new RuntimeException('Disposable owner sitter slot changed unexpectedly.');
        }
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollBack();
        throw $exception;
    }
    echo (int)$owner['id'] . ' ' . $sitterId . "\n";
    exit(0);
}

if ($mode === 'remove-sitter') {
    if ($argc !== 6) {
        fwrite(STDERR, "Usage: remove-sitter OWNER_ID SITTER_ID OWNER_NAME SITTER_NAME\n");
        exit(2);
    }
    $ownerId = filter_var($argv[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 3]]);
    $sitterId = filter_var($argv[3], FILTER_VALIDATE_INT, ['options' => ['min_range' => 3]]);
    $ownerName = $argv[4];
    $sitterName = $argv[5];
    if ($ownerId === false || $sitterId === false || $ownerId === $sitterId) {
        throw new InvalidArgumentException('Fixture IDs must be distinct disposable player IDs.');
    }
    $owner = $db->fetchSingleRow(
        'SELECT sit1Uid FROM users WHERE id=? AND name=? LIMIT 1',
        [$ownerId, $ownerName]
    );
    $sitter = $db->fetchSingleRow(
        'SELECT id FROM users WHERE id=? AND name=? LIMIT 1',
        [$sitterId, $sitterName]
    );
    if (!is_array($owner) || !is_array($sitter) || (int)$owner['sit1Uid'] !== $sitterId) {
        throw new RuntimeException('Disposable sitter relationship did not match the fixture.');
    }
    $db->beginTransaction();
    try {
        $changed = $db->run(
            'UPDATE users SET sit1Uid=0, sit1Permissions=87 WHERE id=? AND name=? AND sit1Uid=?',
            [$ownerId, $ownerName, $sitterId]
        );
        if ($changed->rowCount() !== 1) {
            throw new RuntimeException('Disposable owner sitter slot changed unexpectedly.');
        }
        $deleted = $db->run(
            'DELETE FROM users WHERE id=? AND name=?',
            [$sitterId, $sitterName]
        );
        if ($deleted->rowCount() !== 1) {
            throw new RuntimeException('Disposable sitter row was not removed.');
        }
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollBack();
        throw $exception;
    }
    echo "removed\n";
    exit(0);
}

fwrite(STDERR, "Unknown account export HTTP fixture mode.\n");
exit(2);
