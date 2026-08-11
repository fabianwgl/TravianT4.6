# Ruleset parity matrix

This matrix is the release-boundary index for the supported local profile. Each
row names the canonical implementation and the executable evidence that must
stay green. A row marked **verified** is covered by the listed check; it is not
a claim about unsupported tribes, unverified assets, or public hosting.

| Area | Frozen requirement | Canonical implementation | Evidence | Status |
| --- | --- | --- | --- | --- |
| World profile | 10× speed, 51×51 map (radius 25), Romans/Teutons/Gauls only, 4× movement, starvation, payments/free-gold disabled | `sections/globalConfig.php`, `main_script/copyable/include/env.php` | `tests/runtime-regression.php` profile assertions | verified |
| Round timers | Artifacts day 10, plans day 20, Natar World Wonder day 25, 2 h 24 m level interval, automatic finish day 35 | `sections/globalConfig.php`, `main_script/include/Game/Formulas.php` | `tests/runtime-regression.php` timer assertions | verified |
| Protection and daily reset | 24-hour initial protection, one 24-hour extension, 24-hour quest reset | `main_script/include/Game/Formulas.php`, `main_script/include/Model/Quest.php` | `tests/runtime-regression.php` protection/profile assertions | verified |
| Map boundaries | Coordinate round-trips remain valid at every supported edge | `main_script/include/Game/Formulas.php` | `tests/runtime-regression.php` edge-coordinate round trips | verified |
| Economy and construction | Resource production/storage, cranny, oasis effects, costs, build times, queued levels, and reservations follow the frozen profile | `main_script/include/Game/Formulas.php`, `main_script/include/Game/Buildings/BuildingHelper.php`, `main_script/include/Model/MasterBuilder.php` | `tests/formula-golden-regression.php`, `tests/runtime-regression.php` | verified |
| Units and training | Supported-tribe unit costs, research, training time, merchants, carry capacity, and movement speed are stable | `main_script/include/Game/Formulas.php`, `main_script/include/Model/TrainingModel.php`, `main_script/include/Model/MarketPlaceProcessor.php` | `tests/formula-golden-regression.php`, `tests/runtime-regression.php` | verified |
| Combat and hero | Combat strength, walls, hero bonuses, travel, artifacts, simulator vectors, and village-destruction succession remain deterministic | `main_script/include/Model/BattleModel.php`, `main_script/include/Model/AccountDeleter.php`, `main_script/include/Core/Automation.php`, `main_script/include/Core/Jobs/TransactionalTask.php`, `main_script/include/Controller/RallyPoint/Simulator.php`, `main_script/include/Game/Hero` | `tests/formula-golden-regression.php`, `tests/runtime-regression.php` | verified |
| Registration and core play | Registration → activation → village creation → login → authenticated village, map, building, and profile routes work | `web/public`, `main_script/include/Controller`, `main_script/include/Model/RegisterModel.php` | `scripts/smoke-game.sh`, clean-install verifier | verified |
| Complete round | Register, build, train, trade, attack, settle, conquer, capture/activate an artifact, satisfy plans, reach World Wonder 100, and render both winner surfaces | `main_script/include/Automation.php`, `main_script/include/Model/Movements`, `main_script/include/Model/WonderOfTheWorldModel.php`, `main_script/include/Controller/WinnerCtrl.php` | `tests/complete-round-regression.php` | verified |
| World Wonder plans | Player plan required to start; from level 50 a distinct allied player plan is required | `main_script/include/Game/Buildings/BuildingHelper.php` | `tests/runtime-regression.php`, `tests/complete-round-regression.php` | verified |
| Natar World Wonder attacks | Crossed attack levels, preserved two-wave army profile, travel time, and one-second demolition offset | `main_script/include/Model/WonderOfTheWorldModel.php` | `tests/runtime-regression.php` deterministic army hash and movement fixtures | verified |
| Grey-area settlement | All 14 Natar demolition waves are scheduled in deterministic order and timing | `main_script/include/Model/NatarsModel.php`, `main_script/include/Model/Movements/SettlersProcessor.php` | `tests/runtime-regression.php` grey-area movement fixture | verified |
| Crash and replay behavior | Worker effects commit atomically, retry after rollback, and suppress duplicate delivery | `main_script/include/Core/TransactionalTask.php`, `main_script/include/Automation.php` | `tests/runtime-regression.php` transactional task families | verified |
| Database lifecycle | Forward migrations import on empty and existing worlds; backups and restores are rehearsed | `main_script/copyable/include/migrate.php`, `scripts/backup.sh`, `scripts/restore.sh` | `scripts/verify.sh`, `scripts/verify-clean-install.sh` | verified |

## How to use this matrix

Any ruleset change must update the affected row, its fixture, and the relevant
golden or end-to-end assertion in the same responsibility commit. A new tribe,
speed, map size, payment path, or public deployment is outside this matrix until
it has its own row and a clean-install verification run.
