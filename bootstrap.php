<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

spl_autoload_register(static function ($class) {
    $prefix = 'Mlab\\GiftCard\\';
    if (strpos($class, $prefix) === 0) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});
