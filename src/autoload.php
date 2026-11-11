<?php

declare(strict_types=1);

/*
 * src/autoload.php - Autoload PSR-4 senza Composer.
 * Sul server non si esegue Composer: questo file basta per caricare le classi di src/ (namespace App\ → cartella src/) e il
 * connettore Titulus (namespace Unical\Titulus\ → protocollo/php-titulus-utility/src/, pacchetto indipendente che si pubblica a parte).
 * In sviluppo, se esiste vendor/autoload.php (composer install), src/bootstrap.php usa quello (con PHPUnit e gli strumenti).
 */

spl_autoload_register(static function (string $classe): void {
    $mappa = [
        'App\\' => __DIR__ . '/',
        'Unical\\Titulus\\' => dirname(__DIR__) . '/protocollo/php-titulus-utility/src/',
    ];
    foreach ($mappa as $prefisso => $cartella) {
        if (strncmp($classe, $prefisso, strlen($prefisso)) !== 0) {
            continue;
        }
        $file = $cartella . str_replace('\\', '/', substr($classe, strlen($prefisso))) . '.php';
        if (is_file($file)) {
            require $file;
        }

        return;
    }
});
