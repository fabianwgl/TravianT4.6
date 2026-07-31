CREATE TABLE IF NOT EXISTS scheduled_task_failures (
  task_table VARCHAR(64) NOT NULL,
  task_id BIGINT(20) UNSIGNED NOT NULL,
  attempts SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
  payload LONGTEXT NOT NULL,
  last_error VARCHAR(1000) NOT NULL,
  first_failed_at INT(10) UNSIGNED NOT NULL,
  last_failed_at INT(10) UNSIGNED NOT NULL,
  PRIMARY KEY (task_table, task_id),
  KEY retry_audit (last_failed_at, attempts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
