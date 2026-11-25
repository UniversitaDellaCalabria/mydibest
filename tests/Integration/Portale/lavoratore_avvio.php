<?php

declare(strict_types=1);

/**
 * Un «clic» dell'ufficio su «Avvia la stipula», in un processo PHP vero (lo lancia AvvioDueProcessiTest, non è una prova da solo).
 *
 * Uso: php lavoratore_avvio.php <bootstrap> <istante> <istituto> <email> <file delle email> <file dell'esito>
 *   istante = microtime(true) a cui partire: i due processi aspettano lo stesso istante, così le richieste arrivano insieme davvero.
 * Le email non partono: ogni invio si scrive su una riga del file; l'esito (JSON) dice cosa ha risposto avviaDalPannello().
 */

use App\Anagrafi\ClientApiAteneo;
use App\Core\Container;
use App\Core\Database;
use App\Core\Sito;
use App\Infrastructure\Mail\Mailer;
use App\Iscrizioni\RegistroOperazioni;
use Tests\Doppi\AnagrafiClientApiFinto;
use Tests\Doppi\IscrizioniRegistroFinto;

[, $bootstrap, $istante, $istituto, $email, $fileEmail, $fileEsito] = $argv + array_fill(0, 7, '');
require $bootstrap;

mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli(getenv('PROVE_DB_HOST') ?: '127.0.0.1', getenv('PROVE_DB_USER') ?: 'root', (string) (getenv('PROVE_DB_PASS') ?: ''));
$conn->select_db(getenv('PROVE_DB_UNIT') ?: 'eventi_prova_unit');
$conn->set_charset('utf8mb4');

$mailer = new class ($fileEmail) implements Mailer {
    public function __construct(private readonly string $file)
    {
    }

    public function invia(string $a, string $oggetto, string $corpoHtml, ?string $colore = null, array $allegati = []): bool
    {
        file_put_contents($this->file, json_encode(['a' => $a, 'pid' => getmypid()]) . "\n", FILE_APPEND | LOCK_EX);

        return true;
    }

    public function ultimoErrore(): string
    {
        return '';
    }
};

$c = new Container();
\App\Iscrizioni\Registrazione::registra($c);
\App\Fsl\Registrazione::registra($c);
$c->istanza(Container::class, $c);
$c->istanza(Database::class, Database::per($conn));
$c->istanza(Mailer::class, $mailer);
$c->istanza(\App\Core\Orologio::class, new \App\Core\OrologioDiSistema());
$c->istanza(ClientApiAteneo::class, new AnagrafiClientApiFinto());
$c->istanza(Sito::class, new Sito(RADICE_SITO, 'https://portale.test/eventi'));
$c->istanza(RegistroOperazioni::class, new IscrizioniRegistroFinto());

$servizio = $c->get(\App\Fsl\ServizioConvenzioneOnline::class);   // tutto è pronto: da qui in poi conta solo il tempo
while (microtime(true) < (float) $istante) {
    // si aspetta lo stesso istante dell'altro processo
}
$es = $servizio->avviaDalPannello($istituto, $email, 'ufficio', false);
file_put_contents($fileEsito, json_encode(['esito' => $es['esito'], 'errore' => $es['errore'], 'id' => $es['id']]));
