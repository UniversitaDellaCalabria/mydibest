<?php
// =========================================================================
// Controlla un PDF firmato con le stesse regole del portale: serve per provare una firma vera PRIMA di usarla, senza passare dal portale.
// Si lancia da riga di comando, su qualunque computer con PHP.
//
//   php strumenti/verifica_firma_pdf.php firmato.pdf
//        È quello che controlla il portale quando la SCUOLA o l'AZIENDA carica la convenzione firmata: dice le firme e le marche temporali trovate,
//        di che tipo, di chi, se coprono tutto il file e se sono integre.
//   php strumenti/verifica_firma_pdf.php firmato_dal_direttore.pdf firmato_dalla_scuola.pdf [CODICEFISCALE]
//        Come per la firma del DIRETTORE sulle convenzioni: il primo deve avere almeno una firma in più del secondo; il certificato di un'altra
//        persona (codice fiscale facoltativo) è un avviso.
//   Con --severo (in fondo) il confronto usa le regole degli altri moduli (lettere, pratiche, verbali, registro), che bloccano anche il file riscritto,
//        il formato non riconosciuto e la firma che non copre tutto il file.
// In fondo stampa separati i MOTIVI BLOCCANTI (il portale rifiuta il file) e gli AVVISI (il file passa e l'ufficio li legge accanto al PDF).
// Non modifica nulla e non invia nulla. Esce con 0 se il portale accetterebbe il file, con 1 se no, con 2 se i file indicati non ci sono.
// =========================================================================
if (PHP_SAPI !== 'cli') {
    exit;
}
require_once dirname(__DIR__) . '/src/autoload.php';

use App\Infrastructure\Pdf\FirmePades;
use App\Infrastructure\Pdf\MessaggiFirma;
use App\Tutorato\VerificaPdfFirmato;

$arg = array_slice($_SERVER['argv'], 1);
$severo = in_array('--severo', $arg, true);
$arg = array_values(array_diff($arg, ['--severo']));
if (!$arg || !is_file($arg[0]) || (isset($arg[1]) && !is_file($arg[1])) || ($severo && !isset($arg[1]))) {
    fwrite(STDERR, "Uso: php strumenti/verifica_firma_pdf.php firmato.pdf [precedente.pdf [CODICEFISCALE] [--severo]]\n");
    exit(2);
}
$pdf = (string) file_get_contents($arg[0]);
$f = new FirmePades();
$v = new VerificaPdfFirmato($f);
$firme = $f->firmePades($pdf);
$vere = MessaggiFirma::senzaMarche($firme);
$bloccanti = [];
$avvisi = [];

echo 'File: ' . basename($arg[0]) . ' (' . number_format(strlen($pdf), 0, ',', '.') . " byte)\n";
echo 'Firme: ' . count($vere) . ', marche temporali: ' . (count($firme) - count($vere)) . "\n";
foreach ($firme as $i => $x) {
    echo '  ' . ($i + 1) . '. ' . ($x['marca_temporale'] ? 'marca temporale' : 'firma') . ', formato ' . ($x['subfilter'] ?: 'sconosciuto') . ($x['nome'] !== '' ? ', nome «' . $x['nome'] . '»' : '')
        . ($x['data'] !== '' ? ', data ' . $x['data'] : '') . ', struttura ' . ($x['valida_struttura'] ? 'integra' : 'NON integra') . ', copre tutto il file: ' . ($x['copre_tutto'] ? 'sì' : 'no') . "\n";
}
if ($vere) {
    $info = $v->analizza($pdf);
    echo 'Certificato: ' . ($info['nome'] !== '' ? $info['nome'] : '(nome non leggibile)') . ($info['cf'] !== '' ? ', codice fiscale ' . $info['cf'] : ', codice fiscale non presente nel certificato') . ($info['emittente'] !== '' ? ', emesso da ' . $info['emittente'] : '') . "\n";
    echo 'Integrità crittografica: ' . match ($info['integra']) {
        null => 'non verificabile da qui',
        true => 'il documento non è stato alterato (la catena dei certificati la verifica chi protocolla con gli strumenti di Ateneo)',
        false => 'controllo non riuscito',
    } . "\n";
}

if (isset($arg[1])) {
    $cf = $arg[2] ?? '';
    echo 'Confronto con «' . basename($arg[1]) . '», regole ' . ($severo ? 'degli altri moduli (severe)' : 'della firma del Direttore sulle convenzioni') . "\n";
    [$err, $esito] = $v->verifica((string) file_get_contents($arg[1]), $pdf, $cf, $severo);
    if ($err !== null) {
        $bloccanti[] = $err;
    } elseif ($esito['avviso'] !== '') {
        $avvisi[] = $esito['avviso'];
    }
} else {
    if (strncmp($pdf, '%PDF-', 5) !== 0) {
        $bloccanti[] = MessaggiFirma::nonPdf($pdf);
    } elseif (!$vere) {
        $bloccanti[] = MessaggiFirma::per(MessaggiFirma::SENZA_FIRMA);
    }
    if ($vere && MessaggiFirma::avvisi($firme) !== '') {
        $avvisi[] = MessaggiFirma::avvisi($firme);
    }
}
foreach (['MOTIVI BLOCCANTI' => $bloccanti, 'AVVISI PER L\'UFFICIO' => $avvisi] as $titolo => $righe) {
    echo "\n{$titolo}:\n" . ($righe ? '  - ' . implode("\n  - ", $righe) : '  nessuno') . "\n";
}
echo $bloccanti ? "\nIl portale NON accetterebbe questo file.\n" : "\nIl portale accetterebbe questo file" . ($avvisi ? ', con gli avvisi qui sopra' : '') . ".\n";
exit($bloccanti ? 1 : 0);
