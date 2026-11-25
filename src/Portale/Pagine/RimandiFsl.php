<?php

declare(strict_types=1);

namespace App\Portale\Pagine;

use App\Anagrafi\ServizioScuole;
use App\Fsl\ConvenzioneCompilataRepository;
use App\Fsl\ConvenzioneRepository;
use App\Http\Richiesta;
use App\Infrastructure\Documenti\TabellaEsportabile;

/**
 * Dai vecchi indirizzi di admin/fsl.php (le sei schermate di «Convenzioni con le scuole») alla pagina Scuole e convenzioni, con lo STESSO significato:
 * `tab=stipulare` è il filtro «Senza convenzione», `tab=scuola&k=…` la scheda di quella scuola, `conv_mod=ID` la scheda con la modifica già aperta,
 * `conv_nuova=CODICE&dal=…&al=…` la scheda con «Registrane una già firmata» già aperto, e così via. Un indirizzo vecchio non muore mai (QR, email, PDF):
 * qui si decide soltanto dove porta. Non scrive niente e non fa mai un giro: l'indirizzo di arrivo non porta `tab`.
 *
 * Dei parametri passano solo i valori ammessi (un codice meccanografico vero, un id intero, una data vera, un filtro che esiste): il resto cade.
 * Le ancore (#cv12, #fase-caricata…) non arrivano al server: le traduce lo script piccolo di viste/pannello/_fsl_scuole_ancore.php sulla pagina di arrivo.
 */
final class RimandiFsl
{
    /** Le schermate di admin/fsl.php che oggi sono «Scuole e convenzioni». */
    public const TAB_SCUOLE = ['home', 'iter', 'stipulare', 'convenzioni', 'verifica', 'scuola'];

    /** Le sezioni che restano in admin/fsl.php, «Numeri e report». */
    public const TAB_NUMERI = ['riepilogo', 'valutazioni', 'report'];

    /** Le tabelle del riepilogo che si possono scaricare. */
    public const TABELLE = ['attivita', 'scuole', 'valutazioni', 'report'];

    public function __construct(
        private readonly ConvenzioneRepository $convenzioni,
        private readonly ConvenzioneCompilataRepository $compilate,
        private readonly ServizioScuole $scuole,
    ) {
    }

    /** Il vecchio indirizzo (GET) di una schermata «convenzioni» → la pagina Scuole. */
    public function perLeScuole(Richiesta $r): string
    {
        $tab = (string) $r->query('tab', '');
        $q = [];
        $conv = $tab === 'convenzioni';
        if ($conv && ($modifica = self::intero($r->query('conv_mod'))) > 0 && ($k = $this->chiaveDellaConvenzione($modifica)) !== '') {
            $q = ['k' => $k, 'modifica' => (string) $modifica];
        } elseif ($conv && ($rinnova = self::intero($r->query('conv_rinnova'))) > 0 && ($k = $this->chiaveDellaConvenzione($rinnova)) !== '') {
            $q = ['k' => $k, 'rinnova' => (string) $rinnova] + self::date($r);
        } elseif ($conv && ($codice = ConvenzioneRepository::codiceValido((string) $r->query('conv_nuova', ''))) !== null) {
            $k = $this->scuole->codiceIstituto($codice);
            $q = $k !== '' && $this->scuole->perCodice($codice) !== null ? ['k' => $k, 'nuova' => '1'] + self::date($r) : ['nuova' => '1'];
        } elseif ($conv && self::presente($r->query('nuova'))) {
            $q = ['nuova' => '1'];
        } elseif ($conv && $r->query('vista') === 'archivio') {
            $q = ['stato' => 'scaduta'];
        } elseif ($tab === 'scuola') {
            $k = self::chiave((string) $r->query('k', ''));
            $q = $k !== '' ? ['k' => $k] : [];
        } elseif ($tab === 'verifica' || ($tab === 'stipulare' && $r->query('mostra') !== 'tutte')) {
            $q = ['cosa' => 'senza_convenzione'];
        }
        if (empty($q['k'])) {
            $q += self::scarica($r, $conv);
        }
        $anno = self::anno($r->query('anno'));
        if ($anno !== '') {
            $q = ['anno' => $anno] + $q;
        }

        return 'fsl_scuole.php' . ($q ? '?' . http_build_query($q) : '');
    }

    /** Il vecchio indirizzo di riepilogo, valutazioni o report → la sezione di «Numeri e report» (con il suo anno e, se c'era, il file da scaricare). */
    public function perGliNumeri(Richiesta $r): string
    {
        $tab = (string) $r->query('tab', '');
        $q = [];
        $anno = self::anno($r->query('anno'));
        if ($anno !== '') {
            $q['anno'] = $anno;
        }
        $formato = (string) $r->query('scarica', $r->query('xlsx') !== null ? 'xlsx' : '');
        if ($formato !== '' && isset(TabellaEsportabile::FORMATI[$formato])) {
            $q['scarica'] = $formato;
            $tabella = (string) $r->query('tabella', '');
            $q['tabella'] = in_array($tabella, self::TABELLE, true) ? $tabella : ($tab === 'valutazioni' ? 'valutazioni' : ($tab === 'report' ? 'report' : 'attivita'));

            return 'fsl.php?' . http_build_query($q);
        }

        return 'fsl.php' . ($q ? '?' . http_build_query($q) : '') . '#' . $tab;
    }

    /**
     * Dove portare chi ha mandato un modulo vecchio (POST) a fsl.php: nessuna azione si fa, ma si arriva nel punto giusto: la scheda della scuola cui
     * il modulo si riferiva (se si capisce da un id di compilazione o da un codice) o l'elenco.
     */
    public function perUnModuloVecchio(Richiesta $r): string
    {
        foreach (['cv_prendi', 'cv_titulus', 'cv_completa', 'cv_controllo', 'cv_rifiuta', 'cv_invia_direttore', 'cv_titulus_def', 'cv_carica_protocollato',
            'cv_riprova_originale', 'cv_ripeti_applicazione', 'cv_letto', 'cv_ricorda', 'cv_messaggio', 'cv_incerto_chiudi', 'cv_respinto', 'att_conferma', 'att_scarta',
            'cv_referente_id', 'unisci_resta'] as $nome) {
            $id = self::intero(explode(':', (string) $r->post($nome, ''))[0]);
            if ($id > 0) {
                return 'fsl_scuole.php?cv=' . $id;
            }
        }
        foreach (['torna', 'st_chiedi', 'st_scrivi'] as $nome) {
            $k = self::chiave((string) $r->post($nome, ''));
            if ($k !== '') {
                return 'fsl_scuole.php?k=' . urlencode($k);
            }
        }

        return 'fsl_scuole.php';
    }

    /** La chiave della scheda (codice dell'istituto) cui appartiene una compilazione online; '' se non c'è o la scuola non è in anagrafe. */
    public function chiaveDellaCompilazione(int $id): string
    {
        $c = $id > 0 ? $this->compilate->perId($id) : null;

        return $c === null ? '' : $this->istituto((string) ($c['scuola_codice'] ?? ''));
    }

    /** La chiave della scheda cui appartiene una convenzione del registro; '' se non esiste. */
    public function chiaveDellaConvenzione(int $id): string
    {
        $c = $id > 0 ? $this->convenzioni->perId($id) : null;

        return $c === null ? '' : $this->istituto((string) ($c['scuola_codice'] ?? ''));
    }

    private function istituto(string $codice): string
    {
        return $this->scuole->codiceIstituto($codice);
    }

    /** Una chiave di scheda ammessa: un codice meccanografico (10 caratteri) o una scuola scritta a mano («x» + 12 cifre esadecimali). '' se non lo è. */
    public static function chiave(string $k): string
    {
        $k = trim($k);
        $codice = ConvenzioneRepository::codiceValido($k);
        if ($codice !== null) {
            return $codice;
        }

        return preg_match('/^x[0-9a-f]{12}$/', $k) === 1 ? $k : '';
    }

    /** L'anno scolastico di un vecchio indirizzo: un anno vero o «tutti»; '' se manca o non è ammesso. */
    public static function anno(mixed $v): string
    {
        $v = is_string($v) ? trim($v) : '';
        if ($v === 'tutti') {
            return 'tutti';
        }

        return preg_match('/^\d{4}$/', $v) === 1 && (int) $v >= 2015 && (int) $v <= 2100 ? $v : '';
    }

    /** @return array<string, string> `dal` e `al` se sono date vere (le date proposte per il modulo della convenzione) */
    private static function date(Richiesta $r): array
    {
        $out = [];
        foreach (['dal', 'al'] as $k) {
            $v = $r->query($k);
            if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && strtotime($v) !== false && date('Y-m-d', (int) strtotime($v)) === $v) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /** Il vecchio scaricamento del registro (`tab=convenzioni&scarica=csv`) è quello dell'elenco delle scuole, nello stesso formato. @return array<string, string> */
    private static function scarica(Richiesta $r, bool $registro): array
    {
        $formato = (string) $r->query('scarica', '');

        return $registro && isset(TabellaEsportabile::FORMATI[$formato]) ? ['scarica' => $formato] : [];
    }

    private static function intero(mixed $v): int
    {
        return is_string($v) && preg_match('/^\d{1,9}$/', $v) === 1 ? (int) $v : 0;
    }

    private static function presente(mixed $v): bool
    {
        return is_string($v) && $v !== '' && $v !== '0';
    }
}
