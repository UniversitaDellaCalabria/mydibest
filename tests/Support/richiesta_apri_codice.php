<?php

declare(strict_types=1);

// Una richiesta «apri la convenzione dal codice della prenotazione» in un processo a sé, per provare due richieste simultanee
// (CompilazioneUnicaTest). Uso: php richiesta_apri_codice.php <bootstrap> <database> <codice> <istante UNIX con decimali a cui partire> [email verificata di chi chiede]
// Stampa il token (o «segnalata» / «nulla»). Non fa parte del portale: serve solo ai test.

[, $bootstrap, $database, $codice, $parti, $emailVerificata] = $argv + [null, '', '', '', '0', ''];
require $bootstrap;

use App\Anagrafi\ClientApiAteneo;
use App\Core\Container;
use App\Core\Database;
use App\Core\Orologio;
use App\Core\Sito;
use App\Fsl\ServizioConvenzioneOnline;
use App\Infrastructure\Mail\Mailer;
use App\Infrastructure\Pdf\VerificaFirme;
use App\Iscrizioni\RegistroOperazioni;
use Tests\Doppi\AnagrafiClientApiFinto;
use Tests\Doppi\IscrizioniRegistroFinto;
use Tests\Doppi\MailerFinto;
use Tests\Doppi\OrologioFisso;

mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli(getenv('PROVE_DB_HOST') ?: '127.0.0.1', getenv('PROVE_DB_USER') ?: 'root', (string) (getenv('PROVE_DB_PASS') ?: ''), $database);
$conn->set_charset('utf8mb4');
$c = new Container();
\App\Iscrizioni\Registrazione::registra($c);
\App\Fsl\Registrazione::registra($c);
$c->istanza(Container::class, $c);
$c->istanza(Database::class, Database::per($conn));
$c->istanza(Mailer::class, new MailerFinto());
$c->istanza(ClientApiAteneo::class, new AnagrafiClientApiFinto());
$c->istanza(Orologio::class, new OrologioFisso('2026-10-05 12:00:00'));
$c->istanza(Sito::class, new Sito(sys_get_temp_dir(), 'https://portale.test/eventi'));
$c->istanza(RegistroOperazioni::class, new IscrizioniRegistroFinto());
$c->istanza(VerificaFirme::class, new class () implements VerificaFirme {
    public function firmePades(string $pdf): array
    {
        return [];
    }
});
$servizio = $c->get(ServizioConvenzioneOnline::class);   // tutto pronto prima di partire: la gara è sulla ricerca/creazione, non sull'avvio
time_sleep_until((float) $parti);
$esito = $servizio->apriDaCodice($codice, $emailVerificata !== '' ? $emailVerificata : null);
echo $esito === null ? 'nulla' : ($esito['token'] ?? 'segnalata');
