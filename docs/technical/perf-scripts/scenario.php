<?php
// Usage (app container): php scenario.php prep | battle | timeline [seconds]
// prep     : far-future queue rows + raids that resolve immediately (reports for the test players)
// battle   : time ONE large battle resolved in-process (Automation::processMovementTask) with query count
// timeline : schedule builds/training/movements due in 20..80 s, poll every 25 ms, print lag per task as JSON
require '/app/main_script/copyable/include/env.php';
require '/app/main_script/include/bootstrap.php';
use Core\Database\DB;
use Core\Automation;
$db = DB::getInstance();
$mode = $argv[1] ?? 'prep';
$nowS = time(); $nowMs = (int)miliseconds();
function rows($sql) { global $db; $r = $db->query($sql); $o = []; while ($x = $r->fetch_assoc()) $o[] = $x; return $o; }
$testUsers = rows("SELECT id, kid, race FROM users WHERE name LIKE 'perf%' AND access=1 ORDER BY id");
$fakeVil = rows("SELECT v.kid, v.owner FROM vdata v JOIN users u ON u.id=v.owner WHERE u.access=3 AND v.isFarm=0 ORDER BY RAND() LIMIT 800");
function addMove($kid, $to, $race, $u, $type, $mode, $startMs, $endMs) {
    global $db;
    $c = ''; $v = '';
    foreach ($u as $i => $n) { $c .= ",u$i"; $v .= ",$n"; }
    $db->query("INSERT INTO movement (kid,to_kid,race,mode,attack_type,start_time,end_time$c) VALUES ($kid,$to,$race,$mode,$type,$startMs,$endMs$v)");
    return $db->lastInsertId();
}

if ($mode === 'prep') {
    $far = $nowS + 86400 * 3; $i = 0;
    foreach ($testUsers as $u) {
        foreach ([3, 5] as $f) $db->query("INSERT INTO building_upgrade (kid,building_field,isMaster,start_time,commence) VALUES ({$u['kid']},$f,0,$nowS,$far)");
        foreach ([1, 2, 3] as $nr) $db->query("INSERT INTO training (kid,nr,num,item_id,training_time,commence,end_time) VALUES ({$u['kid']},$nr,50,$nr,60,$far," . ($far + 3000) . ")");
        $t = $fakeVil[$i++]['kid'];
        addMove($u['kid'], $t, $u['race'], [1 => 100], 3, 0, $nowMs, $nowMs + 86400000 * 2);       // far-future outgoing attack
        addMove($t, $u['kid'], 1, [1 => 100], 3, 0, $nowMs, $nowMs + 86400000 * 2);               // far-future incoming attack
        // 25 raids that resolve within seconds -> battle reports for this player
        for ($k = 0; $k < 25; $k++) {
            $tv = $fakeVil[$i++ % count($fakeVil)]['kid'];
            addMove($u['kid'], $tv, $u['race'], [1 => 20], 4, 0, $nowMs, $nowMs + 1000 + $k * 300);
        }
    }
    // background queue volume: 300 far-future building tasks + 150 trainings in fake villages
    for ($k = 0; $k < 300; $k++) {
        $kid = $fakeVil[$k % count($fakeVil)]['kid'];
        $db->query("INSERT INTO building_upgrade (kid,building_field,isMaster,start_time,commence) VALUES ($kid," . (($k % 18) + 1) . ",0,$nowS," . ($far + $k) . ")");
    }
    for ($k = 0; $k < 150; $k++) {
        $kid = $fakeVil[$k % count($fakeVil)]['kid'];
        $db->query("INSERT INTO training (kid,nr,num,item_id,training_time,commence,end_time) VALUES ($kid," . (($k % 4) + 1) . ",20,1,60," . ($far + $k) . "," . ($far + $k + 1200) . ")");
    }
    echo "prep done\n";
    exit;
}

function setupBattle($attKid, $attRace, $defKid) {
    global $db, $fakeVil;
    $defRace = (int)$db->fetchScalar("SELECT race FROM units WHERE kid=$defKid");
    $wall = [1 => 31, 2 => 32, 3 => 33][$defRace] ?? 31;
    $db->query("UPDATE fdata SET f40=20, f40t=$wall WHERE kid=$defKid");
    $db->query("UPDATE units SET u1=20000,u2=15000,u3=10000,u4=5000,u5=8000,u6=6000,u7=300,u8=100 WHERE kid=$defKid");
    // three reinforcement stacks
    for ($i = 0; $i < 3; $i++) {
        $from = $fakeVil[$i + 10];
        $race = (int)$db->fetchScalar("SELECT race FROM units WHERE kid={$from['kid']}");
        $db->query("INSERT INTO enforcement (uid,kid,to_kid,race,u1,u2,u3,u4,u5,u6) VALUES ({$from['owner']},{$from['kid']},$defKid,$race,8000,6000,5000,2000,3000,2000)");
    }
}

if ($mode === 'battle') {
    // attacker: first test player's village; defender: a fake village
    $att = $testUsers[0]; $def = $fakeVil[0]['kid'];
    setupBattle($att['kid'], $att['race'], $def);
    $id = addMove($att['kid'], $def, $att['race'], [1 => 20000, 2 => 10000, 3 => 6000, 5 => 4000, 6 => 3000, 7 => 300, 8 => 100], 3, 0, $nowMs, $nowMs + 86400000);
    $db->query("UPDATE movement SET ctar1=1, ctar2=1 WHERE id=$id");
    $q0 = (int)$db->fetchScalar("SHOW SESSION STATUS LIKE 'Questions'") ?: 0;
    $r = $db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc(); $q0 = (int)$r['Value'];
    $t = microtime(true);
    $ok = Automation::getInstance()->processMovementTask((int)$id);
    $dt = (microtime(true) - $t) * 1000;
    $r = $db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_assoc(); $q1 = (int)$r['Value'];
    printf("battle task: ok=%d wall_ms=%.1f queries=%d peak_mem_mb=%.1f\n", $ok, $dt, $q1 - $q0 - 1, memory_get_peak_usage() / 1048576);
    exit;
}

if ($mode === 'timeline') {
    $N = ['b' => 150, 't' => 150, 'm' => 60];
    $t0 = microtime(true); $t0s = $nowS; $t0ms = $nowMs; $exp = [];
    $used = [];
    for ($k = 0; $k < $N['b']; $k++) {
        $v = $fakeVil[$k]['kid']; $f = ($k % 18) + 1; if (isset($used["$v-$f"])) continue; $used["$v-$f"] = 1;
        $due = $t0s + 20 + mt_rand(0, 60);
        $db->query("INSERT INTO building_upgrade (kid,building_field,isMaster,start_time,commence) VALUES ($v,$f,0,$t0s,$due)");
        $exp[] = ['type' => 'build', 'table' => 'building_upgrade', 'id' => (int)$db->lastInsertId(), 'due_ms' => $due * 1000];
    }
    for ($k = 0; $k < $N['t']; $k++) {
        $v = $fakeVil[$k + 200]['kid']; $due = $t0s + 20 + mt_rand(0, 60);
        $db->query("INSERT INTO training (kid,nr,num,item_id,training_time,commence,end_time) VALUES ($v," . (($k % 4) + 1) . ",1,1,1,$due,$due)");
        $exp[] = ['type' => 'train', 'table' => 'training', 'id' => (int)$db->lastInsertId(), 'due_ms' => $due * 1000];
    }
    for ($k = 0; $k < $N['m']; $k++) {
        $u = $testUsers[$k % count($testUsers)]; $tv = $fakeVil[$k + 400]['kid']; $due = $t0ms + 20000 + mt_rand(0, 60000);
        $id = addMove($u['kid'], $tv, $u['race'], [1 => 30], 4, 0, $t0ms, $due);
        $exp[] = ['type' => 'raid', 'table' => 'movement', 'id' => (int)$id, 'due_ms' => $due];
    }
    // one large battle at t0+40s (attacker: test player 1 village)
    $att = $testUsers[1]; $def = $fakeVil[600]['kid'];
    setupBattle($att['kid'], $att['race'], $def);
    $due = $t0ms + 40000;
    $id = addMove($att['kid'], $def, $att['race'], [1 => 20000, 2 => 10000, 3 => 6000, 5 => 4000, 6 => 3000, 7 => 300, 8 => 100], 3, 0, $t0ms, $due);
    $exp[] = ['type' => 'big_battle', 'table' => 'movement', 'id' => (int)$id, 'due_ms' => $due];
    fprintf(STDERR, "scheduled %d tasks, polling...\n", count($exp));
    $pending = [];
    foreach ($exp as $i => $e) $pending[$i] = $e;
    $limit = microtime(true) + ($argv[2] ?? 150);
    $res = [];
    while ($pending && microtime(true) < $limit) {
        $byTable = [];
        foreach ($pending as $i => $e) $byTable[$e['table']][$i] = $e['id'];
        foreach ($byTable as $tbl => $ids) {
            $alive = [];
            $r = $db->query("SELECT id FROM $tbl WHERE id IN(" . implode(',', $ids) . ")");
            while ($x = $r->fetch_assoc()) $alive[(int)$x['id']] = 1;
            $nowPoll = (int)(microtime(true) * 1000);
            foreach ($ids as $i => $id) if (!isset($alive[$id])) { $res[] = $pending[$i] + ['gone_ms' => $nowPoll, 'lag_ms' => $nowPoll - $pending[$i]['due_ms']]; unset($pending[$i]); }
        }
        usleep(25000);
    }
    foreach ($pending as $e) $res[] = $e + ['gone_ms' => null, 'lag_ms' => null];
    echo json_encode($res), "\n";
    exit;
}
