<?php

declare(strict_types=1);

namespace App;

use mysqli;

final class Database
{
    public static function connect(Config $config): mysqli
    {
        $db = mysqli_init();
        // Sets the charset during the handshake, saving the extra SET NAMES round trip.
        $db->options(MYSQLI_SET_CHARSET_NAME, 'utf8mb4');
        $db->real_connect($config->dbHost, $config->dbUser, $config->dbPass, $config->dbName, $config->dbPort);

        return $db;
    }
}
