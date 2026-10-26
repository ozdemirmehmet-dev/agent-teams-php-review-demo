<?php

/**
 * Moves every old password out of md5 or plain text. Users keep their passwords:
 *   - md5 hashes are wrapped in bcrypt and upgraded to a normal hash on the user's next login;
 *   - plain-text passwords (from the first version of login.php) are hashed directly.
 *
 * Safe to run while the site is live and to restart: rows are processed in batches,
 * each update only applies if the row hasn't changed meanwhile, and finished rows are skipped.
 *
 * Usage:  php migrate_md5_passwords.php [--from-id=N] [--shard=K/M]
 *   --from-id   resume after a stop (the script prints the last id of each batch)
 *   --shard     run M copies in parallel, each with a different K (0..M-1), to finish M times faster
 */

declare(strict_types=1);

use App\Auth\PasswordHasher;
use App\Config;
use App\Database;

require_once __DIR__ . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$batchSize = 500;

$options = getopt('', ['from-id:', 'shard:']);
$lastId = (int) ($options['from-id'] ?? 0);
[$shard, $shards] = array_map('intval', explode('/', (string) ($options['shard'] ?? '0/1')));
if ($shards < 1 || $shard < 0 || $shard >= $shards) {
    fwrite(STDERR, "--shard must look like K/M with 0 <= K < M\n");
    exit(1);
}

$db = Database::connect(Config::fromEnv());
$hasher = new PasswordHasher();

$select = $db->prepare('SELECT id, password FROM users WHERE id > ? AND MOD(id, ?) = ? ORDER BY id LIMIT ?');
$update = $db->prepare('UPDATE users SET password = ? WHERE id = ? AND password = ?');

$migrated = 0;

do {
    $select->bind_param('iiii', $lastId, $shards, $shard, $batchSize);
    $select->execute();
    $rows = $select->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($rows as $row) {
        $lastId = (int) $row['id'];
        $oldValue = (string) $row['password'];
        $newValue = $hasher->migrateStored($oldValue);
        if ($newValue === null) {
            continue;
        }

        $update->bind_param('sis', $newValue, $lastId, $oldValue);
        $update->execute();
        $migrated += $update->affected_rows;
    }

    if ($rows !== []) {
        echo "migrated so far: {$migrated}, last id: {$lastId}\n";
    }
} while (count($rows) === $batchSize);

$select->close();
$update->close();

echo "Done. Migrated {$migrated} password(s).\n";
