<?php

declare(strict_types=1);

// Errors go to the log only. Function arguments (such as passwords) are left out of stack traces;
// #[\SensitiveParameter] covers the same case if this ini setting is overridden.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('zend.exception_ignore_args', '1');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// PSR-4: App\Foo\Bar -> src/Foo/Bar.php. Replace with Composer's autoloader if the project adopts Composer.
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Functions can't be autoloaded.
require_once __DIR__ . '/Http/functions.php';
