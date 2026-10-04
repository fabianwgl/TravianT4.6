<?php

declare(strict_types=1);

use Core\Database\DB;
use Model\AccountExportModel;

require '/app/main_script/copyable/include/env.php';
require '/app/main_script/include/bootstrap.php';

mysqli_report(MYSQLI_REPORT_OFF);
set_error_handler(static function (int $severity, string $message): bool {
    if (str_contains($message, 'Mysqli Error') || str_contains($message, 'SQL syntax')) {
        throw new RuntimeException($message);
    }
    return false;
});

function export_expect_same(mixed $expected, mixed $actual, string $label): void
{
    if ($actual !== $expected) {
        throw new RuntimeException(sprintf(
            '%s: expected %s, received %s',
            $label,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function export_expect_true(bool $actual, string $label): void
{
    export_expect_same(true, $actual, $label);
}

function export_expect_throws(string $class, callable $callback, string $label): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        export_expect_same($class, get_class($exception), $label);
        return;
    }
    throw new RuntimeException($label . ': expected an exception.');
}

function export_sql(DB $db, string $value): string
{
    return "'" . $db->real_escape_string($value) . "'";
}

/**
 * @param mixed $value
 * @param list<string> $forbidden
 */
function export_assert_no_forbidden_keys(mixed $value, array $forbidden, string $path = '$'): void
{
    if (!is_array($value)) {
        return;
    }
    foreach ($value as $key => $child) {
        if (is_string($key) && in_array(strtolower($key), $forbidden, true)) {
            throw new RuntimeException("Forbidden export key {$path}.{$key}");
        }
        export_assert_no_forbidden_keys($child, $forbidden, $path . '.' . (string)$key);
    }
}

$db = DB::getInstance();
$model = new AccountExportModel();
$alphaUid = 0;
$betaUid = 0;
$alphaKid = 0;
$betaKid = 0;
$alphaForumPostId = 0;
$betaForumPostId = 0;
$alphaAuctionId = 0;
$betaAuctionId = 0;
$started = false;

try {
    export_expect_true($db->begin_transaction(), 'account export fixture transaction started');
    $started = true;

    $db->query(
        "INSERT INTO users
            (uuid, name, password, email, race, kid, cp, lastupdate, signupTime,
             location, desc1, desc2, note, sit1Uid, sit1Permissions, sit2Uid, sit2Permissions,
             ajax_token)
         VALUES
            (UUID(), " . export_sql($db, 'Export Álpha 世界') . ', ' .
            export_sql($db, 'fixture-password-alpha') . ', ' .
            export_sql($db, 'alpha@example.test') . ", 1, 0, 123, 100, 100,
             " . export_sql($db, 'Lisboa') . ', ' .
            export_sql($db, 'Perfil UTF-8 ✓') . ', ' .
            export_sql($db, 'Assinatura privada') . ', ' .
            export_sql($db, 'Nota da conta') . ", 0, 87, 0, 87, " .
            export_sql($db, 'fixture-ajax-token-alpha') . ')'
    );
    $alphaUid = (int)$db->lastInsertId();

    $db->query(
        "INSERT INTO users
            (uuid, name, password, email, race, kid, cp, lastupdate, signupTime,
             location, desc1, desc2, note, ajax_token)
         VALUES
            (UUID(), " . export_sql($db, 'Export Beta') . ', ' .
            export_sql($db, 'fixture-password-beta') . ', ' .
            export_sql($db, 'beta@example.test') . ", 2, 0, 456, 200, 200,
             " . export_sql($db, 'Porto') . ', ' .
            export_sql($db, 'Beta profile') . ', ' .
            export_sql($db, 'Beta signature') . ', ' .
            export_sql($db, 'Beta note') . ', ' .
            export_sql($db, 'fixture-ajax-token-beta') . ')'
    );
    $betaUid = (int)$db->lastInsertId();
    export_expect_true($alphaUid > 2 && $betaUid > 2, 'fixture players created');

    $alphaKid = (int)$db->fetchScalar(
        'SELECT id FROM wdata WHERE fieldtype > 0 AND occupied = 0 ORDER BY id LIMIT 1'
    );
    $betaKid = (int)$db->fetchScalar(
        "SELECT id FROM wdata WHERE fieldtype > 0 AND occupied = 0 AND id <> {$alphaKid} ORDER BY id LIMIT 1"
    );
    export_expect_true($alphaKid > 0 && $betaKid > 0, 'fixture village positions available');

    $db->query("UPDATE users SET kid={$alphaKid} WHERE id={$alphaUid}");
    $db->query("UPDATE users SET kid={$betaKid} WHERE id={$betaUid}");
    foreach ([[$alphaKid, $alphaUid, 'Álpha 世界'], [$betaKid, $betaUid, 'Beta village']] as [$kid, $uid, $name]) {
        $db->query(
            "INSERT INTO vdata
                (kid, owner, fieldtype, name, capital, pop, cp, maxstore, maxcrop,
                 last_loyalty_update, lastmupdate, created, expandedfrom)
             VALUES
                ({$kid}, {$uid}, 1, " . export_sql($db, (string)$name) . ", 1, 42, 84, 800, 800,
                 100, 100, 100, 0)"
        );
        $db->query("INSERT INTO fdata (kid, f1, f1t, embassy, heroMansion) VALUES ({$kid}, 5, 1, 1, 3)");
        $db->query("INSERT INTO units (kid, race, u1, u11) VALUES ({$kid}, 1, 7, 1)");
        $db->query("INSERT INTO smithy (kid, u1) VALUES ({$kid}, 4)");
        $db->query("INSERT INTO tdata (kid, u2) VALUES ({$kid}, 2)");
    }
    $db->query("INSERT INTO hero (uid, kid, exp, health, power) VALUES ({$alphaUid}, {$alphaKid}, 99, 88, 7)");
    $db->query("INSERT INTO inventory (uid, helmet, bag) VALUES ({$alphaUid}, 3, 4)");
    $db->query(
        "INSERT INTO face (uid, headProfile, hairColor, hairStyle, ears, eyebrow, eyes, nose, mouth, beard, gender)
         VALUES ({$alphaUid}, 1, 2, 3, 4, 5, 6, 7, 8, 9, 'male')"
    );
    $db->query(
        "INSERT INTO items (uid, btype, type, num, placeId) VALUES ({$alphaUid}, 1, 106, 1, 3)"
    );

    $db->query(
        "INSERT INTO mdata (uid, to_uid, topic, message, time)
         VALUES ({$alphaUid}, {$betaUid}, " . export_sql($db, 'Visible outgoing') . ', ' .
            export_sql($db, 'Olá beta, mensagem UTF-8 ✓') . ', 100)'
    );
    $db->query(
        "INSERT INTO mdata (uid, to_uid, topic, message, delete_sender, time)
         VALUES ({$alphaUid}, {$betaUid}, " . export_sql($db, 'Deleted outgoing') . ', ' .
            export_sql($db, 'Sender deleted this') . ', 1, 101)'
    );
    $db->query(
        "INSERT INTO mdata (uid, to_uid, topic, message, delete_receiver, time)
         VALUES ({$betaUid}, {$alphaUid}, " . export_sql($db, 'Deleted incoming') . ', ' .
            export_sql($db, 'Receiver deleted this') . ', 1, 102)'
    );
    $db->query(
        "INSERT INTO mdata (uid, to_uid, topic, message, time)
         VALUES ({$betaUid}, {$alphaUid}, " . export_sql($db, 'Visible incoming') . ', ' .
            export_sql($db, 'Mensagem recebida') . ', 103)'
    );
    $db->query(
        "INSERT INTO mdata (uid, to_uid, topic, message, time)
         VALUES ({$betaUid}, {$betaUid}, " . export_sql($db, 'Beta private') . ', ' .
            export_sql($db, 'Only beta should see this') . ', 104)'
    );
    $db->query(
        "INSERT INTO mdata (uid, to_uid, topic, message, delete_sender, delete_receiver, time)
         VALUES ({$alphaUid}, {$alphaUid}, " . export_sql($db, 'Self sent retained') . ', ' .
            export_sql($db, 'Self message remains visible as received') . ', 1, 0, 105)'
    );

    $db->query(
        "INSERT INTO notes (uid, to_uid, note_text) VALUES ({$alphaUid}, {$betaUid}, " .
            export_sql($db, 'Nota própria para beta ✓') . ')'
    );
    $db->query(
        "INSERT INTO notes (uid, to_uid, note_text) VALUES ({$betaUid}, {$alphaUid}, " .
            export_sql($db, 'Nota privada beta') . ')'
    );
    $db->query("INSERT INTO friendlist (uid, to_uid, accepted) VALUES ({$alphaUid}, {$betaUid}, 1)");
    $db->query("INSERT INTO ignoreList (uid, ignore_id) VALUES ({$alphaUid}, {$betaUid})");
    $db->query(
        "UPDATE users SET sit1Uid={$betaUid}, sit1Permissions=31 WHERE id={$alphaUid}"
    );
    $db->query(
        "INSERT INTO accounting (uid, cause, reserve, balance, time)
         VALUES ({$alphaUid}, " . export_sql($db, 'fixture-cause') . ', 10, 20, 105)'
    );
    $db->query("INSERT INTO log_ip (uid, ip, time) VALUES ({$alphaUid}, 2130706433, 106)");
    $db->query(
        "INSERT INTO login_handshake (uid, token, isSitter, time)
         VALUES ({$alphaUid}, " . export_sql($db, 'fixture-session-token') . ', 0, 107)'
    );

    $db->query(
        "INSERT INTO forum_post (aid, uid, forumId, topicId, post, time)
         VALUES (0, {$alphaUid}, 1, 100, " . export_sql($db, 'Alpha forum post') . ', 108)'
    );
    $alphaForumPostId = (int)$db->lastInsertId();
    $db->query(
        "INSERT INTO forum_post (aid, uid, forumId, topicId, post, time)
         VALUES (0, {$betaUid}, 1, 200, " . export_sql($db, 'Beta private forum post') . ', 109)'
    );
    $betaForumPostId = (int)$db->lastInsertId();
    $db->query(
        "INSERT INTO forum_edit (uid, postId, time) VALUES ({$alphaUid}, {$alphaForumPostId}, 110)"
    );
    $db->query(
        "INSERT INTO forum_edit (uid, postId, time) VALUES ({$betaUid}, {$betaForumPostId}, 111)"
    );
    $db->query(
        "INSERT INTO forum_vote (uid, topicId, value) VALUES ({$alphaUid}, 100, 1)"
    );
    $db->query(
        "INSERT INTO forum_vote (uid, topicId, value) VALUES ({$betaUid}, 200, -1)"
    );
    $alphaBattleReport = [
        'losses' => [0, 4],
        'attacker' => [
            'kid' => $alphaKid,
            'uid' => $alphaUid,
            'uname' => 'Export Álpha 世界',
            'race' => 1,
            'num' => array_fill(1, 11, 4),
            'dead' => array_fill(1, 11, 0),
            'smithy' => array_fill(1, 11, 4),
            'trapped' => null,
        ],
        'defender' => [[
            'kid' => $betaKid,
            'uid' => $betaUid,
            'uname' => 'Export Beta',
            'race' => 2,
            'num' => array_fill(1, 11, '?'),
            'dead' => null,
            'smithy' => array_fill(1, 11, 9),
        ]],
        'info' => ['none_return' => true],
    ];
    $alphaSpyReport = [
        'losses' => [0, 0],
        'attacker' => [
            'kid' => $alphaKid,
            'uid' => $alphaUid,
            'uname' => 'Export Álpha 世界',
            'race' => 1,
            'num' => array_fill(1, 11, 1),
            'dead' => array_fill(1, 11, 0),
            'smithy' => array_fill(1, 11, 3),
            'trapped' => null,
        ],
        'defender' => [[
            'kid' => $betaKid,
            'uid' => $betaUid,
            'uname' => 'Export Beta',
            'race' => 2,
            'num' => array_fill(1, 11, 2),
            'dead' => array_fill(1, 11, 0),
            'smithy' => array_fill(1, 11, 5),
        ]],
        'info' => [
            'res' => [1 => 321, 2 => 654, 3 => 987, 4 => 111],
            'cranny' => 42,
        ],
    ];
    $betaBattleReport = [
        'losses' => [2, 0],
        'attacker' => [
            'kid' => $betaKid,
            'uid' => $betaUid,
            'uname' => 'Export Beta',
            'race' => 2,
            'num' => array_fill(1, 11, 6),
            'dead' => array_fill(1, 11, 1),
            'smithy' => array_fill(1, 11, 6),
            'trapped' => null,
        ],
        'defender' => [],
        'info' => [],
    ];
    $db->query(
        "INSERT INTO ndata
            (aid, uid, isEnforcement, kid, to_kid, type, bounty, data, time, private_key, viewed, archive)
         VALUES
            (0, {$alphaUid}, 0, {$alphaKid}, {$betaKid}, 1, '', " .
            export_sql($db, serialize($alphaBattleReport)) . ', 112, ' .
            export_sql($db, 'alpha-key') . ', 0, 0)'
    );
    $db->query(
        "INSERT INTO ndata
            (aid, uid, isEnforcement, kid, to_kid, type, bounty, data, time, private_key, viewed, archive)
         VALUES
            (0, {$alphaUid}, 0, {$alphaKid}, {$betaKid}, 5, '', " .
            export_sql($db, serialize($alphaSpyReport)) . ', 113, ' .
            export_sql($db, 'a-spy-key') . ', 0, 0)'
    );
    $db->query(
        "INSERT INTO ndata
            (aid, uid, isEnforcement, kid, to_kid, type, bounty, data, time, private_key, viewed, archive)
         VALUES
            (0, {$alphaUid}, 0, {$alphaKid}, {$betaKid}, 1, '', " .
            export_sql($db, 'O:8:"stdClass":0:{}') . ', 114, ' .
            export_sql($db, 'a-object-key') . ', 0, 0)'
    );
    $cyclicReport = [];
    $cyclicReport['self'] =& $cyclicReport;
    $db->query(
        "INSERT INTO ndata
            (aid, uid, isEnforcement, kid, to_kid, type, bounty, data, time, private_key, viewed, archive)
         VALUES
            (0, {$alphaUid}, 0, {$alphaKid}, {$betaKid}, 1, '', " .
            export_sql($db, serialize($cyclicReport)) . ', 115, ' .
            export_sql($db, 'a-cyclic-key') . ', 0, 0)'
    );
    $db->query(
        "INSERT INTO ndata
            (aid, uid, isEnforcement, kid, to_kid, type, bounty, data, time, private_key, viewed, archive)
         VALUES
            (0, {$alphaUid}, 0, {$alphaKid}, {$betaKid}, 1, '', " .
            export_sql($db, serialize(['nan' => NAN])) . ', 116, ' .
            export_sql($db, 'a-nan-key') . ', 0, 0)'
    );
    $db->query(
        "INSERT INTO ndata
            (aid, uid, isEnforcement, kid, to_kid, type, bounty, data, time, private_key, viewed, archive)
         VALUES
            (0, {$alphaUid}, 0, {$alphaKid}, {$betaKid}, 1, '', " .
            export_sql($db, 'not-a-serialized-report') . ', 117, ' .
            export_sql($db, 'a-mal-key') . ', 0, 0)'
    );
    $db->query(
        "INSERT INTO ndata
            (aid, uid, isEnforcement, kid, to_kid, type, bounty, data, time, private_key, viewed, archive, deleted)
         VALUES
            (0, {$alphaUid}, 0, {$alphaKid}, {$betaKid}, 1, '', " .
            export_sql($db, serialize(['deleted' => 'do-not-export'])) . ', 118, ' .
            export_sql($db, 'deleted-key') . ', 0, 0, 1)'
    );
    $db->query(
        "INSERT INTO ndata
            (aid, uid, isEnforcement, kid, to_kid, type, bounty, data, time, private_key, viewed, archive)
         VALUES
            (0, {$betaUid}, 0, {$betaKid}, {$alphaKid}, 1, '', " .
            export_sql($db, serialize($betaBattleReport)) . ', 116, ' .
            export_sql($db, 'beta-key') . ', 0, 0)'
    );
    $db->query(
        "INSERT INTO auction (uid, btype, type, num, maxSilver, secure_id, time)
         VALUES ({$alphaUid}, 1, 106, 1, 777, " . export_sql($db, 'fixture-auction-secret-alpha') . ', 114)'
    );
    $alphaAuctionId = (int)$db->lastInsertId();
    $db->query(
        "INSERT INTO auction (uid, btype, type, num, maxSilver, secure_id, time)
         VALUES ({$betaUid}, 1, 106, 2, 888, " . export_sql($db, 'fixture-auction-secret-beta') . ', 115)'
    );
    $betaAuctionId = (int)$db->lastInsertId();
    $db->query(
        "INSERT INTO bids (uid, auctionId, outbid, del) VALUES ({$alphaUid}, {$alphaAuctionId}, 0, 0)"
    );
    $db->query(
        "INSERT INTO bids (uid, auctionId, outbid, del) VALUES ({$betaUid}, {$betaAuctionId}, 0, 0)"
    );

    $alpha = $model->export($alphaUid);
    $beta = $model->export($betaUid);

    export_expect_same(AccountExportModel::FORMAT, $alpha['metadata']['format'], 'export format');
    export_expect_same('current_world_account_owned', $alpha['metadata']['scope'], 'export scope');
    export_expect_same($alphaUid, $alpha['metadata']['player_id'], 'export player identity');
    export_expect_same((string)getWorldId(), $alpha['metadata']['world']['id'], 'export world identity');
    export_expect_same((int)getWorldUniqueId(), $alpha['metadata']['world']['unique_id'], 'export world unique identity');
    export_expect_same('Export Álpha 世界', $alpha['profile']['name'], 'UTF-8 profile preserved');
    export_expect_same('Perfil UTF-8 ✓', $alpha['profile']['description'], 'UTF-8 description preserved');
    export_expect_same($alphaKid, (int)$alpha['villages'][0]['village']['kid'], 'own village exported');
    export_expect_same(1, (int)$alpha['villages'][0]['units']['race'], 'own village units exported');
    export_expect_same(99, (int)$alpha['hero']['state']['exp'], 'own hero exported');
    export_expect_same(3, (int)$alpha['inventory']['equipment']['helmet'], 'own inventory exported');
    export_expect_same('Nota própria para beta ✓', $alpha['notes'][0]['text'], 'own note exported');
    export_expect_same([$betaUid], $alpha['ignored_player_ids'], 'own ignore list exported');
    export_expect_same($betaUid, $alpha['sitter_relationships']['delegated_to'][0]['player_id'], 'sitter ID exported');
    export_expect_same(3, count($alpha['messages']), 'message deletion visibility for alpha');
    export_expect_true(
        !in_array('Deleted outgoing', array_column($alpha['messages'], 'topic'), true),
        'sender-deleted message hidden'
    );
    export_expect_true(
        !in_array('Deleted incoming', array_column($alpha['messages'], 'topic'), true),
        'receiver-deleted message hidden'
    );
    export_expect_true(
        in_array('Visible outgoing', array_column($alpha['messages'], 'topic'), true),
        'visible outgoing message exported'
    );
    export_expect_true(
        in_array('Visible incoming', array_column($alpha['messages'], 'topic'), true),
        'visible incoming message exported'
    );
    export_expect_true(
        !in_array('Only beta should see this', array_column($alpha['messages'], 'message'), true),
        'other account private message excluded'
    );
    export_expect_true(
        in_array('Only beta should see this', array_column($beta['messages'], 'message'), true),
        'beta private message retained for beta'
    );
    $selfSentMessages = array_values(array_filter(
        $alpha['messages'],
        static fn(array $message): bool => $message['topic'] === 'Self sent retained'
    ));
    export_expect_same(1, count($selfSentMessages), 'self-sent message retained for receiver view');
    export_expect_same(['received'], $selfSentMessages[0]['visibility'], 'self-sent visibility is received only');
    export_expect_same(1, count($alpha['contacts']), 'contact relationship exported');
    export_expect_same($betaUid, $alpha['contacts'][0]['contact_player_id'], 'contact ID exported');
    export_expect_same(1, count($alpha['accounting']), 'accounting history exported');
    export_expect_same('2130706433', (string)$alpha['stored_login_ip_history'][0]['ip'], 'stored IP history exported');
    export_expect_same(1, count($alpha['forum_posts']), 'own forum post exported');
    export_expect_same('Alpha forum post', $alpha['forum_posts'][0]['text'], 'own forum post content exported');
    export_expect_same(1, count($alpha['forum_edits']), 'own forum edit exported');
    export_expect_same(1, count($alpha['forum_votes']), 'own forum vote exported');
    export_expect_true(
        !in_array('Beta private forum post', array_column($alpha['forum_posts'], 'text'), true),
        'other account forum post excluded'
    );
    export_expect_same(6, count($alpha['reports']), 'own non-deleted reports exported');
    export_expect_same('included', $alpha['reports'][0]['content_status'], 'battle report content included');
    export_expect_true(!array_key_exists('data', $alpha['reports'][0]), 'raw report data excluded');
    export_expect_same($alphaUid, (int)$alpha['reports'][0]['content']['attacker']['uid'], 'own attacker report retained');
    export_expect_true(
        array_key_exists('smithy', $alpha['reports'][0]['content']['attacker']),
        'own attacker smithy retained'
    );
    export_expect_true(
        !array_key_exists('smithy', $alpha['reports'][0]['content']['defender'][0]),
        'no-return defender smithy omitted'
    );
    export_expect_same(array_fill(1, 11, '?'), $alpha['reports'][0]['content']['defender'][0]['num'], 'hidden defender composition stays hidden');
    export_expect_same(null, $alpha['reports'][0]['content']['defender'][0]['dead'], 'hidden defender deaths stay hidden');
    export_expect_same('included', $alpha['reports'][1]['content_status'], 'spy report content included');
    export_expect_same(
        [1 => 321, 2 => 654, 3 => 987, 4 => 111],
        $alpha['reports'][1]['content']['info']['res'],
        'successful spy intelligence retained'
    );
    export_expect_same(
        [1 => 2, 2 => 2, 3 => 2, 4 => 2, 5 => 2, 6 => 2, 7 => 2, 8 => 2, 9 => 2, 10 => 2, 11 => 2],
        $alpha['reports'][1]['content']['defender'][0]['num'],
        'successful spy defender composition retained'
    );
    export_expect_same(array_fill(1, 11, 5), $alpha['reports'][1]['content']['defender'][0]['smithy'], 'successful spy upgrade intelligence retained');
    export_expect_same('unavailable', $alpha['reports'][2]['content_status'], 'object report marked unavailable');
    export_expect_same(null, $alpha['reports'][2]['content'], 'object report content not instantiated');
    export_expect_true(
        !str_contains(json_encode($alpha['reports'][2], JSON_THROW_ON_ERROR), '__PHP_Incomplete_Class'),
        'object report class name excluded'
    );
    foreach ([3 => 'cyclic', 4 => 'non-finite', 5 => 'malformed'] as $reportIndex => $reportLabel) {
        export_expect_same('unavailable', $alpha['reports'][$reportIndex]['content_status'], $reportLabel . ' report marked unavailable');
        export_expect_same(null, $alpha['reports'][$reportIndex]['content'], $reportLabel . ' report content excluded');
    }
    export_expect_true(
        !in_array('do-not-export', array_column($alpha['reports'], 'content'), true),
        'deleted report excluded'
    );
    export_expect_same('included', $beta['reports'][0]['content_status'], 'beta report content included');
    export_expect_same($betaUid, (int)$beta['reports'][0]['content']['attacker']['uid'], 'beta own report retained');
    export_expect_true(
        !in_array((int)$beta['reports'][0]['report_id'], array_map('intval', array_column($alpha['reports'], 'report_id')), true),
        'other account report excluded'
    );
    export_expect_same(1, count($alpha['auctions']), 'own auction exported');
    export_expect_same($alphaAuctionId, (int)$alpha['auctions'][0]['auction_id'], 'own auction identity exported');
    export_expect_same(1, count($alpha['bids']), 'own bid exported');
    export_expect_same($alphaAuctionId, (int)$alpha['bids'][0]['auction_id'], 'own bid auction identity exported');
    export_expect_true(
        !in_array($betaAuctionId, array_map('intval', array_column($alpha['bids'], 'auction_id')), true),
        'other account bid excluded'
    );
    export_expect_true(
        in_array('Beta private forum post', array_column($beta['forum_posts'], 'text'), true),
        'beta own forum post retained'
    );
    export_expect_same($betaUid, (int)$beta['reports'][0]['content']['attacker']['uid'], 'beta own report retained');
    export_expect_same($betaAuctionId, (int)$beta['auctions'][0]['auction_id'], 'beta own auction retained');

    export_assert_no_forbidden_keys($alpha, [
        'password',
        'ajax_token',
        'token',
        'session',
        'private_key',
        'recovery_code',
        'access',
        'uuid',
        'secure_id',
    ]);
    $encodedAlpha = json_encode($alpha, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    export_expect_true(!str_contains($encodedAlpha, 'fixture-password-alpha'), 'password value excluded');
    export_expect_true(!str_contains($encodedAlpha, 'fixture-ajax-token-alpha'), 'AJAX token value excluded');
    export_expect_true(!str_contains($encodedAlpha, 'fixture-session-token'), 'session token value excluded');
    export_expect_true(!str_contains($encodedAlpha, 'fixture-auction-secret-alpha'), 'auction secret excluded');
    foreach ([
        'alpha-key',
        'a-spy-key',
        'a-object-key',
        'a-cyclic-key',
        'a-nan-key',
        'a-mal-key',
        'deleted-key',
        'do-not-export',
    ] as $reportSecret) {
        export_expect_true(!str_contains($encodedAlpha, $reportSecret), 'report private value excluded: ' . $reportSecret);
    }
    export_expect_true(!array_key_exists('max_silver', $alpha['auctions'][0]), 'auction maximum bid excluded');
    export_expect_true(!array_key_exists('maxSilver', $alpha['auctions'][0]), 'raw auction maximum bid excluded');

    export_expect_true($db->rollback(), 'account export fixture rolled back');
    $started = false;
    export_expect_same(0, (int)$db->fetchScalar("SELECT COUNT(*) FROM users WHERE name = 'Export Álpha 世界'"), 'fixture cleanup');
    export_expect_throws(
        InvalidArgumentException::class,
        static fn(): array => $model->export(0),
        'invalid player ID rejected'
    );
    export_expect_throws(
        OutOfBoundsException::class,
        static fn(): array => $model->export(2147483647),
        'missing player ID rejected'
    );
    export_expect_true(!$db->inTransaction(), 'missing account leaves no active transaction');
    echo "Account export regression passed.\n";
} catch (Throwable $exception) {
    if ($started) {
        $db->rollback();
    }
    fwrite(STDERR, "account-export: {$exception->getMessage()}\n");
    exit(1);
}
