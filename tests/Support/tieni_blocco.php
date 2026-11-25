<?php

declare(strict_types=1);

// Tiene un blocco del database (GET_LOCK) per qualche secondo in un processo a sé, per le prove che devono vedere un altro processo al lavoro.
// Uso: php tieni_blocco.php <database> <nome del blocco> <secondi>. Scrive «preso» appena ha il blocco. Non fa parte del portale: serve solo ai test.

[, $database, $nome, $secondi] = $argv + [null, '', '', '1'];

mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli(getenv('PROVE_DB_HOST') ?: '127.0.0.1', getenv('PROVE_DB_USER') ?: 'root', (string) (getenv('PROVE_DB_PASS') ?: ''), (string) $database);
$stmt = $conn->prepare('SELECT GET_LOCK(?, 0)');
$stmt->bind_param('s', $nome);
$stmt->execute();
$stmt->bind_result($preso);
$stmt->fetch();
echo ((int) $preso === 1 ? 'preso' : 'occupato') . "\n";
flush();
if ((int) $preso === 1) {
    sleep((int) $secondi);
}
