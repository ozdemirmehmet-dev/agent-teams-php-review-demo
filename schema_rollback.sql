-- Undoes schema.sql. Run in the mysql client as an admin user.
--
-- ROLL BACK THE CODE FIRST. The current code relies on the UNIQUE index on users.username to stop
-- duplicate registrations; running this while it is live lets the same username be registered twice.
--
-- users.password stays VARCHAR(255): bcrypt hashes don't fit the old size and would be truncated.

DROP EVENT IF EXISTS ev_purge_login_log;
DROP EVENT IF EXISTS ev_purge_login_throttle;
DROP TABLE IF EXISTS login_throttle;

DROP PROCEDURE IF EXISTS migrate_drop_index;

DELIMITER //

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

DELIMITER ;

CALL migrate_drop_index('login_log', 'idx_login_log_created');
CALL migrate_drop_index('user_roles', 'idx_user_roles_user_role');
CALL migrate_drop_index('users', 'uq_users_username');

DROP PROCEDURE migrate_drop_index;
