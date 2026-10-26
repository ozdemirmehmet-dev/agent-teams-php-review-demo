-- Database migration for the login module. Safe to run more than once.
-- Works on MySQL 8 and MariaDB 10.4+. Run in the mysql client (it uses DELIMITER) as an admin user.
-- Undo with schema_rollback.sql.
--
-- Deployment order:
--   1. Run this file.
--   2. Create the app user (bottom of this file) and set the DB_* environment variables.
--   3. Turn on the event scheduler: SET GLOBAL event_scheduler = ON; and event_scheduler=ON in my.cnf.
--      Without it, login_log and login_throttle are never cleaned up.
--   4. Deploy the new code. It still accepts raw md5 hashes, so nobody is locked out during step 5.
--   5. Run `php migrate_md5_passwords.php` (can run while the site is live; see the file for options).
--
-- Expected existing tables (not created here): users, roles, user_roles, login_log.

DROP PROCEDURE IF EXISTS migrate_add_index;
DROP PROCEDURE IF EXISTS migrate_drop_index;
DROP PROCEDURE IF EXISTS migrate_widen_password;
DROP PROCEDURE IF EXISTS migrate_assert_unique_usernames;

DELIMITER //

-- Adds an index unless one with that name exists, or (if p_leading_column is set) unless any
-- existing index, including the primary key, already starts with that column.
CREATE PROCEDURE migrate_add_index(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_leading_column VARCHAR(64),
    IN p_columns VARCHAR(255),
    IN p_unique BOOLEAN
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = p_table
          AND (index_name = p_index
               OR (p_leading_column IS NOT NULL AND column_name = p_leading_column AND seq_in_index = 1))
    ) THEN
        SET @migrate_ddl = CONCAT(
            'ALTER TABLE `', p_table, '` ADD ', IF(p_unique, 'UNIQUE ', ''),
            'INDEX `', p_index, '` (', p_columns, ')'
        );
        PREPARE migrate_stmt FROM @migrate_ddl;
        EXECUTE migrate_stmt;
        DEALLOCATE PREPARE migrate_stmt;
    END IF;
END//

CREATE PROCEDURE migrate_drop_index(IN p_table VARCHAR(64), IN p_index VARCHAR(64))
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = p_table AND index_name = p_index
    ) THEN
        SET @migrate_ddl = CONCAT('ALTER TABLE `', p_table, '` DROP INDEX `', p_index, '`');
        PREPARE migrate_stmt FROM @migrate_ddl;
        EXECUTE migrate_stmt;
        DEALLOCATE PREPARE migrate_stmt;
    END IF;
END//

-- Widens users.password for bcrypt only if it is still too short, so reruns don't rebuild the table.
-- On a large table this copies the whole table and blocks writes while it runs: schedule it off-peak,
-- or run the same change with pt-online-schema-change / gh-ost instead.
CREATE PROCEDURE migrate_widen_password()
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'password'
          AND character_maximum_length < 255
    ) THEN
        ALTER TABLE users MODIFY password VARCHAR(255) NOT NULL;
    END IF;
END//

-- Stops with a clear message instead of a cryptic error from ADD UNIQUE INDEX.
CREATE PROCEDURE migrate_assert_unique_usernames()
BEGIN
    IF EXISTS (SELECT 1 FROM users GROUP BY username HAVING COUNT(*) > 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
            'Duplicate usernames found; list them with: SELECT username, COUNT(*) FROM users GROUP BY username HAVING COUNT(*) > 1';
    END IF;
END//

DELIMITER ;

CALL migrate_widen_password();

CALL migrate_assert_unique_usernames();
CALL migrate_add_index('users', 'uq_users_username', NULL, 'username', TRUE);

-- Skipped automatically when the primary key already starts with user_id.
CALL migrate_add_index('user_roles', 'idx_user_roles_user_role', 'user_id', 'user_id, role_id', FALSE);

-- login_log is an audit log only; this index serves the cleanup job.
CALL migrate_add_index('login_log', 'idx_login_log_created', 'created_at', 'created_at', FALSE);
-- Indexes from an earlier draft that the code no longer uses.
CALL migrate_drop_index('login_log', 'idx_login_log_username_created');
CALL migrate_drop_index('login_log', 'idx_login_log_ip_created');

-- Rate-limit counters: one row per key (sha256 hex), short-lived.
CREATE TABLE IF NOT EXISTS login_throttle (
    throttle_key CHAR(64) NOT NULL,
    hits INT UNSIGNED NOT NULL,
    window_start DATETIME NOT NULL,
    PRIMARY KEY (throttle_key),
    KEY idx_login_throttle_window (window_start)
) ENGINE = InnoDB;

DROP PROCEDURE migrate_add_index;
DROP PROCEDURE migrate_drop_index;
DROP PROCEDURE migrate_widen_password;
DROP PROCEDURE migrate_assert_unique_usernames;

-- Cleanup jobs. Dropped and recreated so a rerun applies changes to their definition.
-- Small, ordered batches keep each delete short and use the created_at / window_start index.
DROP EVENT IF EXISTS ev_purge_login_log;
CREATE EVENT ev_purge_login_log
    ON SCHEDULE EVERY 1 MINUTE
    DO DELETE FROM login_log
       WHERE created_at < NOW() - INTERVAL 90 DAY
       ORDER BY created_at
       LIMIT 5000;

-- Counters are useless after their 1-hour window (the longest one, for registration).
DROP EVENT IF EXISTS ev_purge_login_throttle;
CREATE EVENT ev_purge_login_throttle
    ON SCHEDULE EVERY 1 MINUTE
    DO DELETE FROM login_throttle
       WHERE window_start < NOW() - INTERVAL 1 HOUR
       ORDER BY window_start
       LIMIT 5000;

-- Least-privilege app user. Run by hand with a real password (never commit it) and put it in DB_PASS.
-- Replace app_db with your database name. Remove or change the old root password as well.
--
-- CREATE USER IF NOT EXISTS 'app_login'@'localhost' IDENTIFIED BY '<strong password>';
-- GRANT SELECT, INSERT, UPDATE ON app_db.users TO 'app_login'@'localhost';
-- GRANT SELECT ON app_db.user_roles TO 'app_login'@'localhost';
-- GRANT SELECT ON app_db.roles TO 'app_login'@'localhost';
-- GRANT INSERT ON app_db.login_log TO 'app_login'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON app_db.login_throttle TO 'app_login'@'localhost';
