<?php

declare(strict_types=1);

// «Invia al protocollo» sul definitivo di una convenzione FSL in un processo a sé, con un Titulus finto lento, per provare due invii simultanei
// (IterConvenzioneFslTest). Uso: php protocolla_definitivo_fsl.php <bootstrap> <database> <radice dei file> <id compilazione> <istante UNIX di partenza> <file contatore>
// Stampa un JSON con l'esito. Non fa parte del portale: serve solo ai test.

[, $bootstrap, $database, $radice, $id, $parti, $contatore] = $argv + [null, '', '', '', '0', '0', ''];
require $bootstrap;

use App\Anagrafi\ClientApiAteneo;
use App\Core\Container;
use App\Core\Database;
use App\Core\Orologio;
use App\Core\Sito;
use App\Fsl\ProtocollaDefinitivaFslInTitulus;
use App\Infrastructure\Mail\Mailer;
use App\Infrastructure\Pdf\VerificaFirme;
use App\Infrastructure\Sicurezza\Cifratura;
use App\Iscrizioni\RegistroOperazioni;
use App\Tutorato\FirmaRemota;
use Tests\Doppi\FirmaRemotaFinta;
use Tests\Doppi\AnagrafiClientApiFinto;
use Tests\Doppi\IscrizioniRegistroFinto;
use Tests\Doppi\MailerFinto;
use Tests\Doppi\OrologioFisso;
use Tests\Doppi\TitulusFintoFsl;
use Unical\Titulus\FabbricaClienteTitulus;

mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli(getenv('PROVE_DB_HOST') ?: '127.0.0.1', getenv('PROVE_DB_USER') ?: 'root', (string) (getenv('PROVE_DB_PASS') ?: ''), $database);
$conn->set_charset('utf8mb4');
$titulus = new TitulusFintoFsl();
$titulus->fileContatore = $contatore;
$titulus->attesaMicrosecondi = 1500000;
$c = new Container();
\App\Iscrizioni\Registrazione::registra($c);
\App\Fsl\Registrazione::registra($c);
\App\Protocollo\Registrazione::registra($c);
$c->istanza(Container::class, $c);
$c->istanza(Database::class, Database::per($conn));
$c->istanza(Mailer::class, new MailerFinto());
$c->istanza(ClientApiAteneo::class, new AnagrafiClientApiFinto());
$c->istanza(Orologio::class, new OrologioFisso('2026-10-05 12:00:00'));
$c->istanza(Sito::class, new Sito($radice, 'https://portale.test/eventi'));
$c->istanza(RegistroOperazioni::class, new IscrizioniRegistroFinto());
$c->istanza(FirmaRemota::class, new FirmaRemotaFinta());
$c->istanza(Cifratura::class, new Cifratura('una-frase-lunga-almeno-sedici'));
$c->istanza(FabbricaClienteTitulus::class, $titulus);
$c->istanza(VerificaFirme::class, new class () implements VerificaFirme {
    public function firmePades(string $pdf): array
    {
        return str_contains($pdf, '%%FIRMA') ? [['subfilter' => 'ETSI.CAdES.detached', 'nome' => 'Maria Rossi', 'data' => '', 'valida_struttura' => true, 'copre_tutto' => true]] : [];
    }
});
$servizio = $c->get(ProtocollaDefinitivaFslInTitulus::class);   // tutto pronto prima di partire: la gara è sull'invio
time_sleep_until((float) $parti);
$esito = $servizio->protocolla((int) $id, ['data_stipula' => '2026-10-10', 'scadenza' => '2027-10-09', 'docenti' => []], '', 'admin@x.it');
echo json_encode(['errore' => $esito['errore'], 'numero' => $esito['numero']]);
