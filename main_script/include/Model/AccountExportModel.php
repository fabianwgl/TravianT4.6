<?php

declare(strict_types=1);

namespace Model;

use Core\Database\DB;
use InvalidArgumentException;
use OutOfBoundsException;
use RuntimeException;

/**
 * Builds the self-service export for one account in the current game world.
 *
 * Every SELECT in this class uses an explicit field list. The game database is
 * deliberately the only data source: global billing, newsletter, support,
 * administrator, session, recovery, and runtime records are separate scopes.
 */
final class AccountExportModel
{
    public const FORMAT = 'openvillage-account-export';
    public const VERSION = 1;

    /**
     * Return current-world data owned by the requested account.
     *
     * The caller must authenticate the player before calling this method. The
     * method itself never follows email addresses or other identity guesses.
     * Missing accounts and non-positive IDs are rejected explicitly.
     *
     * @return array<string, mixed>
     */
    public function export(int $playerId): array
    {
        if ($playerId <= 0) {
            throw new InvalidArgumentException('Player ID must be a positive integer.');
        }

        $db = DB::getInstance();
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) {
            if (!$db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') || !$db->begin_transaction()) {
                throw new RuntimeException('Unable to start the account export snapshot.');
            }
        }

        try {
            $export = $this->buildExport($playerId);
            if ($ownsTransaction && !$db->commit()) {
                throw new RuntimeException('Unable to finish the account export snapshot.');
            }
            return $export;
        } catch (\Throwable $exception) {
            if ($ownsTransaction) {
                $db->rollback();
            }
            throw $exception;
        }
    }

    /**
     * Build the export while the public method holds one repeatable-read
     * snapshot. This prevents village ownership from changing between the
     * parent rows and their child gameplay rows.
     *
     * @return array<string, mixed>
     */
    private function buildExport(int $playerId): array
    {

        $account = $this->fetchOne(
            "SELECT
                id AS player_id,
                name,
                email,
                email_verified,
                race,
                countryFlag AS country_flag,
                showMedals AS show_medals,
                showCountryFlag AS show_country_flag,
                aid AS alliance_id,
                alliance_role_name,
                alliance_role,
                alliance_join_time,
                alliance_contributions,
                gender,
                birthday,
                location,
                language,
                desc1 AS description,
                desc2 AS profile_signature,
                note AS account_note,
                total_pop AS total_population,
                total_villages,
                total_attack_points,
                total_defense_points,
                week_attack_points,
                week_defense_points,
                week_robber_points,
                cp AS culture_points,
                cp_prod AS culture_points_per_day,
                gift_gold,
                bought_gold,
                silver,
                plus AS plus_balance,
                b1 AS wood_boost,
                b2 AS clay_boost,
                b3 AS iron_boost,
                b4 AS crop_boost,
                total_adventures,
                success_adventures_count,
                signupTime AS signup_time,
                protection,
                last_login_time,
                vacationActiveTil AS vacation_active_until,
                vacationUsedDays AS vacation_used_days,
                sit1Uid AS sitter_1_id,
                sit1Permissions AS sitter_1_permissions,
                sit2Uid AS sitter_2_id,
                sit2Permissions AS sitter_2_permissions,
                favorTabs AS favorite_tabs,
                reportFilters AS report_filters,
                allianceSettings AS alliance_settings,
                allianceNotificationEnabled AS alliance_notification_enabled,
                autoComplete AS auto_complete,
                display,
                timezone,
                mapMarkSettings AS map_mark_settings,
                qst_tut AS quest_tutorial,
                qst_economy AS quest_economy,
                qst_world AS quest_world,
                qst_battle AS quest_battle
             FROM users
             WHERE id = {$playerId}
             LIMIT 1"
        );
        if ($account === null) {
            throw new OutOfBoundsException('Player account was not found.');
        }

        $villages = $this->fetchRows(
            "SELECT
                v.kid,
                w.x,
                w.y,
                v.fieldtype AS field_type,
                v.name,
                v.capital,
                v.pop AS population,
                v.cp AS culture_points,
                v.celebration,
                v.festival,
                v.type,
                v.wood,
                v.clay,
                v.iron,
                v.woodp,
                v.clayp,
                v.ironp,
                v.maxstore,
                v.extraMaxstore AS extra_max_store,
                v.crop,
                v.cropp,
                v.maxcrop,
                v.extraMaxcrop AS extra_max_crop,
                v.upkeep,
                v.loyalty,
                v.created,
                v.lastReturn AS last_return,
                v.isWW AS world_wonder,
                v.isFarm AS farm_village,
                v.isArtifact AS artifact_village,
                v.evasion,
                v.expandedfrom AS expanded_from
             FROM vdata v
             LEFT JOIN wdata w ON w.id = v.kid
             WHERE v.owner = {$playerId}
             ORDER BY v.kid"
        );
        $villageIds = array_map(
            static fn(array $village): int => (int)$village['kid'],
            $villages
        );
        $villageScope = $this->inClause($villageIds);

        $buildingFields = ['kid'];
        for ($field = 1; $field <= 40; ++$field) {
            $buildingFields[] = "f{$field}";
            $buildingFields[] = "f{$field}t";
        }
        $buildingFields = array_merge($buildingFields, [
            'f99',
            'f99t',
            'embassy',
            'heroMansion',
            'lastWWUpgrade',
            'wwname',
        ]);
        $buildings = $this->fetchRows(
            'SELECT ' . implode(', ', $buildingFields) . " FROM fdata WHERE kid IN ({$villageScope}) ORDER BY kid"
        );

        $units = $this->fetchRows(
            "SELECT kid, race, u1, u2, u3, u4, u5, u6, u7, u8, u9, u10, u11, u99
             FROM units WHERE kid IN ({$villageScope}) ORDER BY kid"
        );
        $smithy = $this->fetchRows(
            "SELECT kid, u1, u2, u3, u4, u5, u6, u7, u8
             FROM smithy WHERE kid IN ({$villageScope}) ORDER BY kid"
        );
        $tdata = $this->fetchRows(
            "SELECT kid, u2, u3, u4, u5, u6, u7, u8, u9
             FROM tdata WHERE kid IN ({$villageScope}) ORDER BY kid"
        );
        $research = $this->fetchRows(
            "SELECT id AS research_id, kid, nr, mode, end_time
             FROM research WHERE kid IN ({$villageScope}) ORDER BY kid, research_id"
        );
        $training = $this->fetchRows(
            "SELECT id AS training_id, kid, nr, num, item_id, training_time, commence, end_time
             FROM training WHERE kid IN ({$villageScope}) ORDER BY kid, training_id"
        );
        $buildingQueue = $this->fetchRows(
            "SELECT id AS task_id, kid, building_field, isMaster AS master_task, start_time, commence
             FROM building_upgrade WHERE kid IN ({$villageScope}) ORDER BY kid, task_id"
        );
        $demolitions = $this->fetchRows(
            "SELECT id AS demolition_id, kid, building_field, end_time, complete
             FROM demolition WHERE kid IN ({$villageScope}) ORDER BY kid, demolition_id"
        );
        $tradeRoutes = $this->fetchRows(
            "SELECT id AS trade_route_id, kid, to_kid, r1, r2, r3, r4, enabled, start_hour, times, time
             FROM traderoutes WHERE kid IN ({$villageScope}) ORDER BY kid, trade_route_id"
        );
        $oases = $this->fetchRows(
            "SELECT kid, type, did, wood, iron, clay, crop, lasttrain, lastfarmed,
                    conquered_time, loyalty, owner
             FROM odata WHERE owner = {$playerId} ORDER BY kid"
        );

        $buildingsByVillage = $this->indexByVillage($buildings);
        $unitsByVillage = $this->indexByVillage($units);
        $smithyByVillage = $this->indexByVillage($smithy);
        $tdataByVillage = $this->indexByVillage($tdata);
        $researchByVillage = $this->groupByVillage($research);
        $trainingByVillage = $this->groupByVillage($training);
        $buildingQueueByVillage = $this->groupByVillage($buildingQueue);
        $demolitionsByVillage = $this->groupByVillage($demolitions);
        $tradeRoutesByVillage = $this->groupByVillage($tradeRoutes);

        $villageExport = [];
        foreach ($villages as $village) {
            $kid = (int)$village['kid'];
            $villageExport[] = [
                'village' => $village,
                'buildings' => $buildingsByVillage[$kid] ?? null,
                'units' => $unitsByVillage[$kid] ?? null,
                'smithy' => $smithyByVillage[$kid] ?? null,
                'technology' => $tdataByVillage[$kid] ?? null,
                'research' => $researchByVillage[$kid] ?? [],
                'training' => $trainingByVillage[$kid] ?? [],
                'building_queue' => $buildingQueueByVillage[$kid] ?? [],
                'demolitions' => $demolitionsByVillage[$kid] ?? [],
                'trade_routes' => $tradeRoutesByVillage[$kid] ?? [],
            ];
        }

        $messages = $this->fetchRows(
            "SELECT
                id AS message_id,
                uid AS sender_id,
                to_uid AS recipient_id,
                topic,
                message,
                time,
                mode,
                autoType AS auto_type,
                isAlliance AS alliance_message,
                viewed AS recipient_viewed,
                CASE WHEN to_uid = {$playerId} THEN archived ELSE NULL END AS archived,
                (uid = {$playerId} AND delete_sender = 0) AS visible_sent,
                (to_uid = {$playerId} AND delete_receiver = 0) AS visible_received
             FROM mdata
             WHERE (uid = {$playerId} AND delete_sender = 0)
                OR (to_uid = {$playerId} AND delete_receiver = 0)
             ORDER BY time, message_id"
        );
        foreach ($messages as &$message) {
            $visibility = [];
            if ((int)$message['visible_sent'] === 1) {
                $visibility[] = 'sent';
            }
            if ((int)$message['visible_received'] === 1) {
                $visibility[] = 'received';
            }
            $message['visibility'] = $visibility;
            unset($message['visible_sent'], $message['visible_received']);
        }
        unset($message);

        $notes = $this->fetchRows(
            "SELECT id AS note_id, to_uid AS contact_player_id, note_text AS text
             FROM notes WHERE uid = {$playerId} ORDER BY note_id"
        );

        $contacts = [];
        foreach ($this->fetchRows(
            "SELECT uid AS owner_id, to_uid AS contact_player_id, accepted
             FROM friendlist WHERE uid = {$playerId} OR to_uid = {$playerId}
             ORDER BY owner_id, contact_player_id"
        ) as $contact) {
            $contacts[] = [
                'contact_player_id' => (int)$contact['owner_id'] === $playerId
                    ? (int)$contact['contact_player_id']
                    : (int)$contact['owner_id'],
                'direction' => (int)$contact['owner_id'] === $playerId ? 'outgoing' : 'incoming',
                'accepted' => $contact['accepted'],
            ];
        }

        $ignoredPlayerIds = array_map(
            static fn(array $row): int => (int)$row['ignore_id'],
            $this->fetchRows(
                "SELECT ignore_id FROM ignoreList WHERE uid = {$playerId} ORDER BY ignore_id"
            )
        );

        $farmLists = $this->fetchRows(
            "SELECT id AS farm_list_id, kid, name, auto, lastRaid AS last_raid, randSec AS random_seconds
             FROM farmlist WHERE owner = {$playerId} ORDER BY farm_list_id"
        );
        $farmListIds = array_map(
            static fn(array $row): int => (int)$row['farm_list_id'],
            $farmLists
        );
        $farmListScope = $this->inClause($farmListIds);

        $forumPosts = $this->fetchRows(
            "SELECT id AS post_id, forumId AS forum_id, topicId AS topic_id,
                    post AS text, time, deleted
             FROM forum_post WHERE uid = {$playerId} ORDER BY time, post_id"
        );
        $forumEdits = $this->fetchRows(
            "SELECT postId AS post_id, time
             FROM forum_edit WHERE uid = {$playerId} ORDER BY time, post_id"
        );
        $forumVotes = $this->fetchRows(
            "SELECT topicId AS topic_id, value
             FROM forum_vote WHERE uid = {$playerId} ORDER BY topic_id"
        );
        $reports = $this->fetchRows(
            "SELECT id AS report_id, isEnforcement AS enforcement, kid, to_kid,
                    type, bounty, data, time, viewed, archive, deleted, losses, non_deletable
             FROM ndata WHERE uid = {$playerId} AND deleted = 0 ORDER BY time, report_id"
        );
        foreach ($reports as &$report) {
            $report['content'] = $this->reportContent($report['data'], $playerId);
            $report['content_status'] = $report['content'] === null ? 'unavailable' : 'included';
            unset($report['data']);
        }
        unset($report);
        $dailyQuest = $this->fetchOne(
            "SELECT qst1, qst2, qst3, qst4, qst5, qst6, qst7, qst8, qst9, qst10, qst11,
                    alliance_contribution,
                    reward1Type AS reward_1_type, reward1Done AS reward_1_done,
                    reward2Type AS reward_2_type, reward2Done AS reward_2_done,
                    reward3Type AS reward_3_type, reward3Done AS reward_3_done,
                    reward4Type AS reward_4_type, reward4Done AS reward_4_done
             FROM daily_quest WHERE uid = {$playerId} LIMIT 1"
        );
        $playerReferences = $this->fetchRows(
            "SELECT ref_uid AS referring_player_id, uid AS referred_player_id,
                    rewardGiven AS reward_given
             FROM player_references WHERE uid = {$playerId} OR ref_uid = {$playerId}
             ORDER BY referring_player_id, referred_player_id"
        );
        $mapMarks = $this->fetchRows(
            "SELECT tx0, ty0, tx1, ty1, zoomLevel AS zoom_level, version
             FROM map_mark WHERE uid = {$playerId} ORDER BY version, tx0, ty0"
        );
        $raidLists = $this->fetchRows(
            "SELECT id AS raid_list_id, lid AS farm_list_id, kid, distance,
                    u1, u2, u3, u4, u5, u6, u7, u8, u9, u10
             FROM raidlist WHERE lid IN ({$farmListScope}) ORDER BY lid, raid_list_id"
        );
        $auctions = $this->fetchRows(
            "SELECT id AS auction_id, btype, type, num, bids, silver,
                    time
             FROM auction WHERE uid = {$playerId} ORDER BY auction_id"
        );
        $bids = $this->fetchRows(
            "SELECT auctionId AS auction_id, outbid, del AS deleted
             FROM bids WHERE uid = {$playerId} ORDER BY auction_id"
        );
        $artefacts = $this->fetchRows(
            "SELECT id AS artefact_id, kid, release_kid, type, size, conquered, num,
                    effecttype AS effect_type, effect, aoe, status, active
             FROM artefacts WHERE uid = {$playerId} ORDER BY artefact_id"
        );
        $artefactHistory = $this->fetchRows(
            "SELECT artId AS artefact_id, kid, time
             FROM artlog WHERE uid = {$playerId} ORDER BY time, artefact_id"
        );

        $sitterRelationships = [
            'delegated_to' => [],
            'sitting_for' => [],
        ];
        foreach ([1, 2] as $slot) {
            $sitterId = (int)$account["sitter_{$slot}_id"];
            if ($sitterId > 0) {
                $sitterRelationships['delegated_to'][] = [
                    'player_id' => $sitterId,
                    'slot' => $slot,
                    'permissions' => (int)$account["sitter_{$slot}_permissions"],
                ];
            }
        }
        foreach ($this->fetchRows(
            "SELECT id AS player_id, sit1Uid AS sitter_1_id, sit1Permissions AS sitter_1_permissions,
                    sit2Uid AS sitter_2_id, sit2Permissions AS sitter_2_permissions
             FROM users WHERE sit1Uid = {$playerId} OR sit2Uid = {$playerId}
             ORDER BY player_id"
        ) as $sittingAccount) {
            foreach ([1, 2] as $slot) {
                if ((int)$sittingAccount["sitter_{$slot}_id"] === $playerId) {
                    $sitterRelationships['sitting_for'][] = [
                        'player_id' => (int)$sittingAccount['player_id'],
                        'slot' => $slot,
                        'permissions' => (int)$sittingAccount["sitter_{$slot}_permissions"],
                    ];
                }
            }
        }

        return [
            'metadata' => [
                'format' => self::FORMAT,
                'version' => self::VERSION,
                'scope' => 'current_world_account_owned',
                'player_id' => $playerId,
                'world' => [
                    'id' => (string)\getWorldId(),
                    'unique_id' => (int)\getWorldUniqueId(),
                ],
                'generated_at' => time(),
                'included_categories' => [
                    'profile',
                    'preferences_and_quest_progress',
                    'villages_and_owned_gameplay',
                    'messages_visible_to_account',
                    'account_owned_notes_and_contacts',
                    'sitter_relationship_ids',
                    'hero_and_inventory',
                    'accounting',
                    'stored_login_ip_history',
                    'owned_forum_writing_and_votes',
                    'delivered_reports_and_quest_progress',
                    'owned_map_marks_and_farm_lists',
                    'owned_links_and_map_flags',
                    'owned_auction_and_artifact_records',
                ],
                'excluded_categories' => [
                    'passwords_and_authentication_secrets',
                    'sessions_and_recovery_tokens',
                    'administrator_and_moderation_records',
                    'global_billing_newsletter_and_support_records',
                    'shared_alliance_and_other_player_private_records',
                    'other_players_auction_bid_limits',
                ],
                'report_content' => 'Reports are copies delivered to this account and can name other participants. Deleted reports and hidden opponent upgrade values are omitted. Unreadable legacy payloads have content_status unavailable.',
                'message_state' => 'Read receipts are included; archive state is included only for received messages.',
                'incomplete_categories' => [
                    [
                        'category' => 'global_billing_newsletter_and_support_records',
                        'reason' => 'These records live in the central database and require a separate operator-controlled export.',
                    ],
                    [
                        'category' => 'shared_alliance_and_other_player_private_records',
                        'reason' => 'Shared records and data belonging to another account are excluded to preserve account isolation.',
                    ],
                    [
                        'category' => 'administrator_and_moderation_records',
                        'reason' => 'Operator logs, abuse reports, and internal notification/read ledgers require operator handling.',
                    ],
                    [
                        'category' => 'shared_forum_topics_and_alliance_records',
                        'reason' => 'Topic metadata and alliance-wide records can contain other players data and are not account-owned.',
                    ],
                    [
                        'category' => 'inter_player_movements_and_incoming_operations',
                        'reason' => 'Rows created by another account are omitted even when they target one of this accounts villages.',
                    ],
                ],
            ],
            'profile' => $this->pick($account, [
                'player_id',
                'name',
                'email',
                'email_verified',
                'race',
                'country_flag',
                'show_medals',
                'show_country_flag',
                'alliance_id',
                'alliance_role_name',
                'alliance_role',
                'alliance_join_time',
                'alliance_contributions',
                'gender',
                'birthday',
                'location',
                'language',
                'description',
                'profile_signature',
                'account_note',
                'total_population',
                'total_villages',
                'total_attack_points',
                'total_defense_points',
                'week_attack_points',
                'week_defense_points',
                'week_robber_points',
                'culture_points',
                'culture_points_per_day',
                'gift_gold',
                'bought_gold',
                'silver',
                'plus_balance',
                'wood_boost',
                'clay_boost',
                'iron_boost',
                'crop_boost',
                'total_adventures',
                'success_adventures_count',
                'signup_time',
                'protection',
                'last_login_time',
                'vacation_active_until',
                'vacation_used_days',
            ]),
            'preferences' => $this->pick($account, [
                'favorite_tabs',
                'report_filters',
                'alliance_settings',
                'alliance_notification_enabled',
                'auto_complete',
                'display',
                'timezone',
                'map_mark_settings',
            ]),
            'quest_progress' => $this->pick($account, [
                'quest_tutorial',
                'quest_economy',
                'quest_world',
                'quest_battle',
            ]),
            'villages' => $villageExport,
            'oases' => $oases,
            'messages' => $messages,
            'notes' => $notes,
            'contacts' => $contacts,
            'ignored_player_ids' => $ignoredPlayerIds,
            'sitter_relationships' => $sitterRelationships,
            'forum_posts' => $forumPosts,
            'forum_edits' => $forumEdits,
            'forum_votes' => $forumVotes,
            'reports' => $reports,
            'daily_quest' => $dailyQuest,
            'player_references' => $playerReferences,
            'map_marks' => $mapMarks,
            'hero' => [
                'state' => $this->fetchOne(
                    "SELECT kid, exp, health, itemHealth AS item_health, power, offBonus AS off_bonus,
                            defBonus AS def_bonus, production, productionType AS production_type,
                            lastupdate AS last_update, hide
                     FROM hero WHERE uid = {$playerId} LIMIT 1"
                ),
                'face' => $this->fetchOne(
                    "SELECT headProfile AS head_profile, hairColor AS hair_color, hairStyle AS hair_style,
                            ears, eyebrow, eyes, nose, mouth, beard, gender
                     FROM face WHERE uid = {$playerId} LIMIT 1"
                ),
            ],
            'inventory' => [
                'equipment' => $this->fetchOne(
                    "SELECT helmet, body, leftHand AS left_hand, rightHand AS right_hand,
                            shoes, horse, bag
                     FROM inventory WHERE uid = {$playerId} LIMIT 1"
                ),
                'items' => $this->fetchRows(
                    "SELECT id AS item_id, btype, type, num, placeId AS place_id, proc
                     FROM items WHERE uid = {$playerId} ORDER BY item_id"
                ),
            ],
            'accounting' => $this->fetchRows(
                "SELECT cause, reserve, balance, time
                 FROM accounting WHERE uid = {$playerId} ORDER BY time, cause"
            ),
            'stored_login_ip_history' => $this->fetchRows(
                "SELECT ip, time FROM log_ip WHERE uid = {$playerId} ORDER BY time, ip"
            ),
            'owned_links' => $this->fetchRows(
                "SELECT name, url, pos FROM links WHERE uid = {$playerId} ORDER BY pos, name"
            ),
            'owned_map_flags' => $this->fetchRows(
                "SELECT targetId AS target_id, text, color, type
                 FROM mapflag WHERE uid = {$playerId} ORDER BY target_id, type"
            ),
            'farm_lists' => $farmLists,
            'raid_lists' => $raidLists,
            'farm_list_report_links' => $this->fetchRows(
                "SELECT f.kid, f.report_id FROM farmlist_last_reports f
                 JOIN ndata n ON n.id = f.report_id AND n.uid = {$playerId} AND n.deleted = 0
                 WHERE f.uid = {$playerId} ORDER BY f.kid, f.report_id"
            ),
            'auctions' => $auctions,
            'bids' => $bids,
            'artefacts' => $artefacts,
            'artefact_history' => $artefactHistory,
            'adventures' => $this->fetchRows(
                "SELECT id AS adventure_id, kid, dif, time, end AS completed
                 FROM adventure WHERE uid = {$playerId} ORDER BY adventure_id"
            ),
            'medals' => $this->fetchRows(
                "SELECT category, week, rank, points, img AS image
                 FROM medal WHERE uid = {$playerId} ORDER BY week, category, rank"
            ),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchOne(string $sql): ?array
    {
        $rows = $this->fetchRows($sql);
        return $rows[0] ?? null;
    }

    /**
     * Export structured report content without disclosing upgrades retained in
     * storage for armies whose composition was hidden from this recipient.
     *
     * @return array<string, mixed>|null
     */
    private function reportContent(string $stored, int $playerId): ?array
    {
        try {
            $content = @unserialize($stored, ['allowed_classes' => false, 'max_depth' => 64]);
        } catch (\Throwable $exception) {
            return null;
        }
        if (!is_array($content) || !$this->isPlainData($content)) {
            return null;
        }
        $maskUpgrades = static function (array $row) use ($playerId): array {
            $masked = isset($row['num']) && is_array($row['num']) && in_array('?', $row['num'], true);
            $masked = $masked || (array_key_exists('dead', $row) && $row['dead'] === null);
            if ($masked && (int)($row['uid'] ?? 0) !== $playerId) {
                unset($row['smithy']);
            }
            return $row;
        };
        if (isset($content['attacker']) && is_array($content['attacker'])) {
            $content['attacker'] = $maskUpgrades($content['attacker']);
        }
        if (isset($content['defender']) && is_array($content['defender'])) {
            foreach ($content['defender'] as &$defender) {
                if (is_array($defender)) {
                    $defender = $maskUpgrades($defender);
                }
            }
            unset($defender);
        }
        return $content;
    }

    private function isPlainData(mixed $value, int $depth = 0): bool
    {
        // Legacy serialized arrays may contain references back to themselves.
        // Bound traversal as well as decoding so one broken report cannot stop
        // the rest of an account download.
        if ($depth > 64) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                if (!$this->isPlainData($child, $depth + 1)) {
                    return false;
                }
            }
            return true;
        }
        if (is_float($value)) {
            return is_finite($value);
        }
        if (is_string($value)) {
            return preg_match('//u', $value) === 1;
        }
        return $value === null || is_scalar($value);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRows(string $sql): array
    {
        $result = DB::getInstance()->query($sql);
        if ($result === false) {
            throw new RuntimeException('Unable to read account export data.');
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @param list<int> $ids
     */
    private function inClause(array $ids): string
    {
        if ($ids === []) {
            return '0';
        }
        return implode(',', array_map(static fn(int $id): string => (string)$id, $ids));
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    private function pick(array $row, array $fields): array
    {
        $picked = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) {
                $picked[$field] = $row[$field];
            }
        }
        return $picked;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function indexByVillage(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int)$row['kid']] = $row;
        }
        return $indexed;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, list<array<string, mixed>>>
     */
    private function groupByVillage(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int)$row['kid']][] = $row;
        }
        return $grouped;
    }
}
