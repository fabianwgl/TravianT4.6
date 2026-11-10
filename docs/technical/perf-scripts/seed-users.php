<?php
// Usage (in app container): php seed-users.php COUNT
// Creates COUNT fake (access=3) players with a base village each via the project's RegisterModel.
require '/app/main_script/copyable/include/env.php';
require '/app/main_script/include/bootstrap.php';
use Model\RegisterModel;
$n = (int)($argv[1] ?? 300);
$reg = new RegisterModel();
$t0 = microtime(true);
$made = 0;
for ($i = 1; $i <= $n; $i++) {
    $made += $reg->addFakeUser(["pf" . $i . substr(md5((string)$i), 0, 5)]);
}
printf("created %d users in %.1fs\n", $made, microtime(true) - $t0);

// Phase 2: extra villages (second/third villages) for random fake users.
$extra = (int)($argv[2] ?? 0);
if ($extra > 0) {
    $db = Core\Database\DB::getInstance();
    $users = [];
    $r = $db->query("SELECT id, race FROM users WHERE access=3");
    while ($row = $r->fetch_assoc()) $users[] = $row;
    $find = $reg->generateFakeUserVillage($extra);
    $k = 0;
    if ($find !== '' && $find !== null) {
        $db->query("UPDATE available_villages SET occupied=1 WHERE kid IN($find)");
        foreach (explode(',', $find) as $kid) {
            $u = $users[array_rand($users)];
            if ($reg->createNewVillage($u['id'], $u['race'], $kid)) $k++;
        }
    }
    printf("created %d extra villages\n", $k);
}
