# Supported ruleset

OpenVillage currently supports one deliberately narrow compatibility profile.
Other values may be accepted by legacy configuration screens, but they are not
part of the tested release boundary.

## Local world profile

| Rule | Supported value |
| --- | --- |
| Game speed | 10× |
| Map | radius 25; coordinates −25 through +25 (51×51 tiles) |
| Tribes | Romans, Teutons, and Gauls |
| Egyptians and Huns | disabled |
| Movement multiplier | 4× |
| Beginner protection | 24 hours initially; one 24-hour extension |
| Daily quest reset | 24 hours |
| Troop starvation | enabled |
| Payments | disabled |
| Legacy free-gold shortcuts | disabled |

The environment variables in `.env.example` expose the tested speed and map
profile for reproducible installation. Changing them creates an experimental
world and requires a new clean-install and gameplay verification run.

## Round timeline

The official timer profile is scaled by the 10× game speed:

| Event | Time after world start |
| --- | --- |
| Artifacts released | day 10 |
| Building plans released | day 20 |
| Natar World Wonder construction starts | day 25 |
| Natar World Wonder level interval | 2 hours 24 minutes |
| Automatic Natar level 100 / round finish | day 35 |

A player World Wonder requires an active building plan. From level 50 onward,
a second active plan must be held by a different player in the same alliance.
The first-plan holder is intentionally excluded from the alliance-plan check.
The first World Wonder to reach level 100 wins; otherwise the Natar World Wonder
finishes on the automatic timeline.

## Compatibility status

Registration, activation, village creation, login, village fields, village
center, map, building, profile, sessions, map-coordinate boundaries, core
ruleset timers, supported-tribe costs, production/storage formulas, a golden
combat fixture, password migration, and the level-50 endgame plan gate are
exercised by maintained checks. Conquest, artifact capture, Natar attack
composition, and a complete player-built level-100 World Wonder still need
deterministic full-round coverage before a 1.0 compatibility claim.

The old schema filename `T4.4.sql` is retained as a historical filename only.
Compatibility is determined by migrations and runtime verification, not by that
name.
