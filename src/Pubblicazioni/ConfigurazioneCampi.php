<?php

declare(strict_types=1);

namespace App\Pubblicazioni;

use App\Core\Database;
use Throwable;

/**
 * I campi del form della Richiesta di pubblicazione e le colonne del file Excel, come li ha impostati l'amministratore (scheda «Campi e colonne»
 * dell'Amministrazione). UNICA fonte per la pagina a passi, il JavaScript, la validazione, il riepilogo, le email, la scheda del pannello, l'elenco,
 * l'export, Completamento e il conteggio «da completare».
 *
 * I valori ORIGINALI stanno nel codice: i campi qui sotto (CAMPI) e ColonneMonitoraggio. Nel database (pubblicazioni_campi, pubblicazioni_colonne)
 * si salva SOLO quello che l'amministratore cambia, una colonna per ogni cosa cambiata; NULL = valore del codice, e «Torna all'originale» = cancellare
 * la riga. Senza righe salvate (o con le tabelle non ancora create) il risultato è identico a prima di AM4.
 *
 * Regole fisse (la scheda le dice accanto al campo): Titolo e Data dell'evento sono sempre visibili e obbligatori; il Richiedente (preso dall'account)
 * e lo Stato ci sono sempre; i destinatari restano un gruppo (almeno uno, se il gruppo è visibile); allegati e immagini sono obbligatori o no secondo
 * le Impostazioni; la locandina (v146) è un file a parte, sempre visibile e obbligatoria; URL, note e tag sono facoltativi di partenza (l'amministratore può renderli obbligatori);
 * il tipo delle colonne che scrive il docente non si cambia. Un campo NASCOSTO non si chiede, non si controlla e nella colonna
 * dell'Excel resta vuoto (le richieste già arrivate conservano i loro valori); un campo FACOLTATIVO si controlla solo se compilato.
 *
 * Passo AM5: oltre ai campi e alle colonne di sempre ci sono quelli NUOVI dell'amministratore. Un campo nuovo ha la chiave x_<n> (tipo di MyForms, opzioni, passo 1-7,
 * obbligatorio, aiuto, visibile e, se diventa una colonna del file Excel, in_excel con la sua intestazione); una colonna nuova degli operatori ha la chiave y_<n>
 * (intestazione, a cura di, colore, tipo e «dopo» quale colonna sta). Le definizioni stanno nelle stesse tabelle delle personalizzazioni (la riga ha il «tipo»: così
 * si riconosce da quelle dei campi di sempre) e le gestisce CampiAggiunti; qui si leggono soltanto e si fondono con le altre, così pagina, controlli, riepilogo,
 * email, scheda, export e conteggi non hanno niente di speciale da fare: un campo nuovo compare in fondo al suo passo, una colonna nuova nel posto scelto.
 * I valori stanno nella riga della richiesta (campi_extra_json e colonne_extra_json); PubblicazioniRepository li restituisce come chiavi x_<n> e y_<n> della riga.
 */
final class ConfigurazioneCampi
{
    /**
     * I campi del form, nell'ordine di partenza dentro il passo. Per ognuno: passo (2-6), etichetta e aiuto di partenza, obbligatorio di partenza,
     * obbligatorio fisso, visibile fisso, nota che la scheda mostra accanto. Le chiavi sono quelle di RegolePubblicazione::valida() (e della colonna
     * dell'Excel dove il campo ha la sua: vedi COLONNA_DEL_CAMPO); «destinatari» è il gruppo delle quattro caselle per_*.
     */
    public const CAMPI = [
        'richiedente' => ['passo' => 2, 'etichetta' => 'Richiedente', 'aiuto' => 'Cognome e nome, presi dal tuo account: non si possono cambiare.', 'obbligatorio' => true, 'fisso_obb' => true, 'fisso_vis' => true, 'nota' => 'Sempre presente: è il nome dell\'account di chi invia.'],
        'data_evento' => ['passo' => 2, 'etichetta' => 'Data dell\'evento', 'aiuto' => '', 'obbligatorio' => true, 'fisso_obb' => true, 'fisso_vis' => true, 'nota' => 'Sempre visibile e obbligatoria.'],
        'modalita' => ['passo' => 2, 'etichetta' => 'Modalità di svolgimento', 'aiuto' => '', 'obbligatorio' => true, 'fisso_obb' => false, 'fisso_vis' => false, 'nota' => ''],
        'url_docente' => ['passo' => 2, 'etichetta' => 'URL', 'aiuto' => 'L\'indirizzo completo della pagina di cui chiedi la pubblicazione: deve iniziare con http:// o https://. Se non lo sai, lascia vuoto: lo indicherà chi approva o l\'ufficio comunicazione.', 'obbligatorio' => false, 'fisso_obb' => false, 'fisso_vis' => false, 'nota' => 'Con l\'elenco delle pagine pieno (Impostazioni › Testi) diventa la domanda «Dove vuoi che si pubblichi?»: caselle con le pagine e i canali dell\'elenco, più «Altra pagina del sito DiBEST».'],
        'altri_link_docente' => ['passo' => 2, 'etichetta' => 'URL/Link ad altre pagine', 'aiuto' => 'Copia e incolla per esteso il link (URL) di ogni altra pagina di interesse: uno per riga.', 'obbligatorio' => false, 'fisso_obb' => false, 'fisso_vis' => false, 'nota' => ''],
        'titolo' => ['passo' => 3, 'etichetta' => 'Titolo', 'aiuto' => 'Il titolo della notizia: compare sulla Homepage in anteprima.', 'obbligatorio' => true, 'fisso_obb' => true, 'fisso_vis' => true, 'nota' => 'Sempre visibile e obbligatorio.'],
        'sottotitolo' => ['passo' => 3, 'etichetta' => 'Sottotitolo', 'aiuto' => 'Una frase brevissima ma che spieghi l\'evento.', 'obbligatorio' => true, 'fisso_obb' => false, 'fisso_vis' => false, 'nota' => 'Il numero di caratteri si cambia nelle Impostazioni.'],
        'descrizione' => ['passo' => 3, 'etichetta' => 'Breve descrizione', 'aiuto' => 'Il testo che compare quando si apre la notizia. Scrivilo già formattato (corsivo, grassetto, punteggiatura…) e controlla che tutte le informazioni siano corrette.', 'obbligatorio' => true, 'fisso_obb' => false, 'fisso_vis' => false, 'nota' => 'Il numero di caratteri si cambia nelle Impostazioni.'],
        'destinatari' => ['passo' => 4, 'etichetta' => 'Evento rivolto a', 'aiuto' => '', 'obbligatorio' => true, 'fisso_obb' => true, 'fisso_vis' => false, 'nota' => 'Le quattro caselle restano un gruppo: se è visibile, ne serve almeno una.'],
        'locandina' => ['passo' => 5, 'etichetta' => 'Locandina', 'aiuto' => 'Un solo file PDF, JPG o PNG. Verrà pubblicato come allegato con il nome «Locandina_titolo_breve», qualunque sia il nome del tuo file.', 'obbligatorio' => true, 'fisso_obb' => true, 'fisso_vis' => true, 'nota' => 'Sempre visibile e obbligatoria (dalla v146 la locandina è un file a parte).'],
        'allegati' => ['passo' => 5, 'etichetta' => 'Altri allegati', 'aiuto' => 'Altri file da pubblicare insieme alla locandina (programma, brochure…): PDF, JPG o PNG.', 'obbligatorio' => false, 'fisso_obb' => true, 'fisso_vis' => false, 'nota' => 'Facoltativi di partenza; se sono obbligatori e quanti file si cambia nelle Impostazioni.'],
        'immagini' => ['passo' => 5, 'etichetta' => 'Ulteriori immagini', 'aiuto' => 'Se vuoi che nell\'anteprima compaia un\'immagine, allega una foto. Altrimenti sarà mostrata una parte della locandina.', 'obbligatorio' => false, 'fisso_obb' => true, 'fisso_vis' => false, 'nota' => 'Sempre facoltative; quanti file si cambia nelle Impostazioni.'],
        'tag' => ['passo' => 6, 'etichetta' => 'Tag', 'aiuto' => 'Scrivi i tag che aiutano a trovare la notizia, separati da virgola. Per esempio: DIBEST, notizie, avvisi.', 'obbligatorio' => false, 'fisso_obb' => false, 'fisso_vis' => false, 'nota' => ''],
        'data_inizio_pubblicazione' => ['passo' => 6, 'etichetta' => 'Data inizio pubblicazione', 'aiuto' => '', 'obbligatorio' => true, 'fisso_obb' => false, 'fisso_vis' => false, 'nota' => 'La frase sui giorni di anticipo viene dalle Impostazioni e si aggiunge da sola.'],
        'note' => ['passo' => 6, 'etichetta' => 'Note', 'aiuto' => 'Quello che l\'ufficio comunicazione deve sapere.', 'obbligatorio' => false, 'fisso_obb' => false, 'fisso_vis' => false, 'nota' => ''],
    ];

    /** I tipi di colonna che un operatore può scegliere (le colonne del docente non cambiano tipo). */
    public const TIPI = ['testo' => 'Testo libero', 'si_no' => 'SI / NO', 'numero' => 'Numero'];

    /** Le colonne che ci sono sempre: Data (A), Richiedente (B), Titolo (G) e Stato. */
    public const COLONNE_FISSE = ['data_evento', 'richiedente', 'titolo', 'stato'];

    /** Il campo del form che riempie la colonna dell'Excel con la stessa chiave: nascosto il campo, la colonna resta vuota. */
    public const COLONNA_DEL_CAMPO = [
        'data_evento' => 'data_evento', 'titolo' => 'titolo', 'sottotitolo' => 'sottotitolo', 'descrizione' => 'descrizione', 'modalita' => 'modalita',
    ];

    /** I tipi dei campi nuovi: quelli di MyForms (chiavi di Didattica\Costanti::TIPI_CAMPO_PRATICA) che hanno senso qui, senza file e senza condizioni. */
    public const TIPI_CAMPO_EXTRA = ['text', 'textarea', 'radio', 'multicheck', 'date', 'number', 'checkbox', 'url'];

    /** Il colore dell'intestazione della colonna di un campo nuovo del form (il verde delle colonne scritte da chi invia). */
    public const COLORE_COLONNA_CAMPO = '#00B050';

    /** Il passo più alto della pagina (il riepilogo). */
    public const PASSO_MAX = 7;

    /** Per posizionare la colonna di un campo nuovo: la colonna dell'Excel di ogni campo di sempre che ne ha una sua. */
    private const COLONNA_ANCORA = [
        'richiedente' => 'richiedente', 'data_evento' => 'data_evento', 'modalita' => 'modalita', 'titolo' => 'titolo', 'sottotitolo' => 'sottotitolo', 'descrizione' => 'descrizione',
    ];

    public const MAX_ETICHETTA = 200;
    public const MAX_AIUTO = 500;
    public const MAX_INTESTAZIONE = 300;
    public const MAX_A_CURA_DI = 200;

    private static ?self $predefinita = null;

    /** @var array<string, array<string, mixed>>|null righe salvate dei campi, per chiave (lette una volta; null = da leggere) */
    private ?array $righeCampi;
    /** @var array<string, array<string, mixed>>|null righe salvate delle colonne, per chiave */
    private ?array $righeColonne;

    /**
     * @param Database|null $db senza database (e senza righe passate) valgono i valori del codice
     * @param list<array<string, mixed>>|null $righeCampi righe di pubblicazioni_campi già lette (per le prove); null = leggile dal database
     * @param list<array<string, mixed>>|null $righeColonne righe di pubblicazioni_colonne già lette
     */
    public function __construct(private ?Database $db = null, ?array $righeCampi = null, ?array $righeColonne = null)
    {
        $this->righeCampi = $righeCampi === null ? null : self::perChiave($righeCampi);
        $this->righeColonne = $righeColonne === null ? null : self::perChiave($righeColonne);
    }

    /** Solo i valori del codice (come prima di AM4): per chi non ha una configurazione da passare. */
    public static function predefinita(): self
    {
        return self::$predefinita ??= new self(null, [], []);
    }

    // ─────────────────────────── Campi del form ───────────────────────────

    /**
     * Tutti i campi del form (anche i nascosti) nell'ordine della pagina: per passo e, dentro il passo, per l'ordine scelto.
     *
     * @return list<array{chiave: string, passo: int, etichetta: string, aiuto: string, obbligatorio: bool, visibile: bool, fisso_obb: bool, fisso_vis: bool, nota: string, predefinito: array<string, mixed>, modificato: bool}>
     */
    public function campi(): array
    {
        $salvate = $this->campiSalvati();
        $per_passo = [];
        $posizione = [];
        foreach (self::CAMPI as $k => $d) {
            $posizione[$d['passo']] = ($posizione[$d['passo']] ?? 0) + 1;
            $s = $salvate[$k] ?? [];
            $etichetta = isset($s['etichetta']) && trim((string) $s['etichetta']) !== '' ? trim((string) $s['etichetta']) : $d['etichetta'];
            $aiuto = isset($s['aiuto']) ? (string) $s['aiuto'] : $d['aiuto'];
            $visibile = $d['fisso_vis'] ? true : (!isset($s['visibile']) || (int) $s['visibile'] === 1);
            $obbligatorio = $d['fisso_obb'] ? $d['obbligatorio'] : (isset($s['obbligatorio']) ? (int) $s['obbligatorio'] === 1 : $d['obbligatorio']);
            $per_passo[$d['passo']][] = [
                'chiave' => $k, 'passo' => $d['passo'], 'etichetta' => $etichetta, 'aiuto' => $aiuto, 'obbligatorio' => $obbligatorio, 'visibile' => $visibile,
                'fisso_obb' => $d['fisso_obb'], 'fisso_vis' => $d['fisso_vis'], 'nota' => $d['nota'], 'predefinito' => $d, 'modificato' => $s !== [],
                '_chiave_ordine' => isset($s['ordine']) ? (int) $s['ordine'] : $posizione[$d['passo']], '_posizione' => $posizione[$d['passo']],
            ];
        }
        // i campi nuovi (AM5) stanno in fondo al loro passo, nell'ordine in cui sono stati creati
        $nuovi_per_passo = [];
        foreach ($this->campiExtra() as $x) {
            $nuovi_per_passo[$x['passo']][] = $x;
            $per_passo[$x['passo']] ??= [];
        }
        ksort($per_passo);
        $out = [];
        foreach ($per_passo as $passo => $righe) {
            usort($righe, static fn (array $a, array $b): int => [$a['_chiave_ordine'], $a['_posizione']] <=> [$b['_chiave_ordine'], $b['_posizione']]);
            foreach ($righe as $r) {
                unset($r['_chiave_ordine'], $r['_posizione']);
                $out[] = $r;
            }
            foreach ($nuovi_per_passo[$passo] ?? [] as $x) {
                $out[] = $x;
            }
        }

        return $out;
    }

    /**
     * I campi di un passo, nell'ordine scelto. Di norma solo quelli visibili (quelli che la pagina chiede).
     *
     * @return list<array{chiave: string, passo: int, etichetta: string, aiuto: string, obbligatorio: bool, visibile: bool, fisso_obb: bool, fisso_vis: bool, nota: string, predefinito: array<string, mixed>, modificato: bool}>
     */
    public function campiDelForm(int $passo, bool $ancheNascosti = false): array
    {
        return array_values(array_filter($this->campi(), static fn (array $c): bool => $c['passo'] === $passo && ($ancheNascosti || $c['visibile'])));
    }

    /** @return array{chiave: string, passo: int, etichetta: string, aiuto: string, obbligatorio: bool, visibile: bool, fisso_obb: bool, fisso_vis: bool, nota: string, predefinito: array<string, mixed>, modificato: bool}|null */
    public function campo(string $chiave): ?array
    {
        foreach ($this->campi() as $c) {
            if ($c['chiave'] === $chiave) {
                return $c;
            }
        }

        return null;
    }

    public function visibile(string $chiave): bool
    {
        return ($this->campo($chiave)['visibile'] ?? true) === true;
    }

    /** Obbligatorio e visibile: un campo nascosto non è mai obbligatorio. */
    public function obbligatorio(string $chiave): bool
    {
        $c = $this->campo($chiave);

        return $c !== null && $c['visibile'] && $c['obbligatorio'];
    }

    public function etichetta(string $chiave): string
    {
        return $this->campo($chiave)['etichetta'] ?? $chiave;
    }

    /** @return list<string> le chiavi dei campi nascosti */
    public function nascosti(): array
    {
        return array_values(array_map(static fn (array $c): string => $c['chiave'], array_filter($this->campi(), static fn (array $c): bool => !$c['visibile'])));
    }

    /**
     * I passi che la pagina mostra: il primo (note operative) e l'ultimo (riepilogo) ci sono sempre; gli altri solo se hanno almeno un campo visibile.
     *
     * @return list<int>
     */
    public function passiVisibili(): array
    {
        $con_campi = [];
        foreach ($this->campi() as $c) {
            if ($c['visibile']) {
                $con_campi[$c['passo']] = true;
            }
        }
        $passi = [];
        foreach (array_keys(PassiRichiesta::PASSI) as $n) {
            if ($n === 1 || $n === count(PassiRichiesta::PASSI) || isset($con_campi[$n])) {
                $passi[] = $n;
            }
        }

        return $passi;
    }

    /**
     * Le regole di ogni campo per RegolePubblicazione::valida() e per il JavaScript della pagina.
     *
     * @return array<string, array{visibile: bool, obbligatorio: bool, etichetta: string}>
     */
    public function regole(): array
    {
        $r = [];
        foreach ($this->campi() as $c) {
            $r[$c['chiave']] = ['visibile' => $c['visibile'], 'obbligatorio' => $c['visibile'] && $c['obbligatorio'], 'etichetta' => $c['etichetta']]
                + (empty($c['extra']) ? [] : ['extra' => true, 'tipo' => $c['tipo'], 'passo' => $c['passo']]);
        }

        return $r;
    }

    // ─────────────────────────── Colonne del file Excel ───────────────────────────

    /**
     * Le colonne dell'export nell'ordine scelto: quelle di ColonneMonitoraggio con quanto cambiato. Di norma solo le visibili.
     *
     * @return list<array{chiave: string, lettera: string, intestazione: string, a_cura_di: string, colore: string, operatori: bool, tipo: string, visibile: bool, fissa: bool, modificata: bool, predefinita: array<string, mixed>}>
     */
    public function colonne(bool $ancheNascoste = false): array
    {
        $salvate = $this->colonneSalvate();
        $righe = [];
        $pos = 0;
        foreach (ColonneMonitoraggio::tutte() as $d) {
            $pos++;
            $k = $d['chiave'];
            $s = $salvate[$k] ?? [];
            $fissa = in_array($k, self::COLONNE_FISSE, true);
            $righe[] = [
                'chiave' => $k, 'lettera' => $d['lettera'],
                'intestazione' => isset($s['intestazione']) && trim((string) $s['intestazione']) !== '' ? trim((string) $s['intestazione']) : $d['intestazione'],
                'a_cura_di' => $d['operatori'] && isset($s['a_cura_di']) && trim((string) $s['a_cura_di']) !== '' ? trim((string) $s['a_cura_di']) : $d['a_cura_di'],
                'colore' => isset($s['colore']) && self::coloreValido((string) $s['colore']) ? (string) $s['colore'] : $d['colore'],
                'operatori' => $d['operatori'],
                'tipo' => $d['operatori'] && isset($s['tipo'], self::TIPI[(string) $s['tipo']]) ? (string) $s['tipo'] : $d['tipo'],
                'visibile' => $fissa ? true : (!isset($s['visibile']) || (int) $s['visibile'] === 1),
                'fissa' => $fissa, 'modificata' => $s !== [], 'predefinita' => $d,
                '_chiave_ordine' => isset($s['ordine']) ? (int) $s['ordine'] : $pos, '_posizione' => $pos,
            ];
        }
        usort($righe, static fn (array $a, array $b): int => [$a['_chiave_ordine'], $a['_posizione']] <=> [$b['_chiave_ordine'], $b['_posizione']]);
        foreach ($righe as $i => $r) {
            unset($r['_chiave_ordine'], $r['_posizione']);
            $righe[$i] = $r;
        }
        $out = [];
        foreach ($this->conNuove($righe) as $r) {
            if ($ancheNascoste || $r['visibile']) {
                $out[] = $r;
            }
        }

        return $out;
    }

    /** @return array{chiave: string, lettera: string, intestazione: string, a_cura_di: string, colore: string, operatori: bool, tipo: string, visibile: bool, fissa: bool, modificata: bool, predefinita: array<string, mixed>}|null */
    public function colonna(string $chiave): ?array
    {
        foreach ($this->colonne(true) as $c) {
            if ($c['chiave'] === $chiave) {
                return $c;
            }
        }

        return null;
    }

    /** @return list<string> le chiavi delle colonne dell'export, nell'ordine scelto */
    public function chiaviExcel(): array
    {
        return array_column($this->colonne(), 'chiave');
    }

    /** @return list<string> le intestazioni dell'export, nell'ordine scelto */
    public function intestazioniExcel(): array
    {
        return array_column($this->colonne(), 'intestazione');
    }

    /** @return list<string> le chiavi delle colonne degli operatori VISIBILI (quelle che contano come «da completare»), nell'ordine scelto */
    public function colonneOperatori(): array
    {
        return array_values(array_map(static fn (array $c): string => $c['chiave'], array_filter($this->colonne(), static fn (array $c): bool => $c['operatori'])));
    }

    /** L'intestazione senza la parte «(a cura di …)» e senza spazi doppi: per le etichette della scheda. */
    public function etichettaColonna(string $chiave): string
    {
        $c = $this->colonna($chiave);
        if ($c === null) {
            return $chiave;
        }

        return trim((string) preg_replace(['/\s*\(a cura di [^)]*\)\s*$/u', '/\s+/u'], ['', ' '], $c['intestazione']));
    }

    /**
     * L'intestazione di una colonna dell'elenco del pannello (che ha i suoi titoli brevi, «Data evento», «Titolo»…): il titolo breve di sempre, a meno che
     * l'amministratore abbia dato un'intestazione nuova alla colonna (allora quella, senza «(a cura di …)»).
     */
    public function titoloElenco(string $chiave, string $predefinito): string
    {
        $c = $this->colonna($chiave);

        return $c !== null && $c['intestazione'] !== $c['predefinita']['intestazione'] ? $this->etichettaColonna($chiave) : $predefinito;
    }

    /** True se la colonna è nel file Excel (visibile): una colonna nascosta esce anche dall'elenco del pannello. */
    public function colonnaVisibile(string $chiave): bool
    {
        return ($this->colonna($chiave)['visibile'] ?? true) === true;
    }

    /** True se la colonna dell'Excel è riempita da un campo del form che l'amministratore ha nascosto: la cella resta vuota. */
    public function colonnaDaCampoNascosto(string $chiaveColonna): bool
    {
        $campo = self::COLONNA_DEL_CAMPO[$chiaveColonna] ?? null;

        return $campo !== null && !$this->visibile($campo);
    }

    // ─────────────────────────── Salvataggio (scheda «Campi e colonne») ───────────────────────────

    /**
     * Salva i campi del form come arrivano dalla scheda: campo[chiave][etichetta|aiuto|obbligatorio|visibile|ordine]. Salva solo le differenze dai valori
     * del codice. Titolo, Data, Richiedente e le altre regole fisse si ignorano. Non salva niente se c'è un errore.
     *
     * @param array<string, mixed> $post
     * @return array{errori: array<string, string>, cambi: list<string>}
     */
    public function aggiornaCampi(array $post): array
    {
        $campo = is_array($post['campo'] ?? null) ? $post['campo'] : [];
        $errori = [];
        $nuovi = [];
        foreach (self::CAMPI as $k => $d) {
            if (!is_array($campo[$k] ?? null)) {
                continue;
            }
            $p = $campo[$k];
            $etichetta = trim((string) preg_replace('/\s+/u', ' ', (string) ($p['etichetta'] ?? '')));
            if ($etichetta === '') {
                $errori['campo_' . $k] = '«' . $d['etichetta'] . '»: scrivi il nome del campo.';
            } elseif (mb_strlen($etichetta) > self::MAX_ETICHETTA) {
                $errori['campo_' . $k] = '«' . $d['etichetta'] . '»: il nome è troppo lungo (massimo ' . self::MAX_ETICHETTA . ' caratteri).';
            }
            $aiuto = trim(str_replace("\r\n", "\n", (string) ($p['aiuto'] ?? '')));
            if (mb_strlen($aiuto) > self::MAX_AIUTO) {
                $errori['campo_' . $k] = '«' . $d['etichetta'] . '»: il testo di aiuto è troppo lungo (massimo ' . self::MAX_AIUTO . ' caratteri).';
            }
            $nuovi[$k] = [
                'etichetta' => $etichetta === $d['etichetta'] || $etichetta === '' ? null : $etichetta,
                'aiuto' => $aiuto === $d['aiuto'] ? null : $aiuto,
                'obbligatorio' => $d['fisso_obb'] ? null : (!empty($p['obbligatorio']) === $d['obbligatorio'] ? null : (!empty($p['obbligatorio']) ? 1 : 0)),
                'visibile' => $d['fisso_vis'] ? null : (!empty($p['visibile']) ? null : 0),
                'ordine' => (int) ($p['ordine'] ?? 0),
            ];
        }
        if ($errori) {
            return ['errori' => $errori, 'cambi' => []];
        }
        // l'ordine: dentro ogni passo, se la sequenza è quella di partenza non si salva niente
        foreach ($this->ordineSalvabile($nuovi, array_map(static fn (array $d): int => $d['passo'], self::CAMPI)) as $k => $o) {
            $nuovi[$k]['ordine'] = $o;
        }

        return ['errori' => [], 'cambi' => $this->salva('pubblicazioni_campi', ['etichetta', 'aiuto', 'obbligatorio', 'visibile', 'ordine'], $nuovi, $this->campiSalvati(), fn (string $k): string => 'Campo «' . self::CAMPI[$k]['etichetta'] . '»', self::CAMPI)];
    }

    /**
     * Salva le colonne del file Excel come arrivano dalla scheda: colonna[chiave][intestazione|a_cura_di|colore|tipo|visibile|ordine].
     *
     * @param array<string, mixed> $post
     * @return array{errori: array<string, string>, cambi: list<string>}
     */
    public function aggiornaColonne(array $post): array
    {
        $colonna = is_array($post['colonna'] ?? null) ? $post['colonna'] : [];
        $errori = [];
        $nuovi = [];
        foreach (ColonneMonitoraggio::tutte() as $d) {
            $k = $d['chiave'];
            if (!is_array($colonna[$k] ?? null)) {
                continue;
            }
            $p = $colonna[$k];
            $nome = '«' . ($d['lettera'] !== '' ? $d['lettera'] . ' · ' : '') . ColonneMonitoraggio::etichetta($k) . '»';
            // gli spazi doppi NON si ritoccano: alcune intestazioni originali del file Excel ce li hanno (sono identiche alla Tabella di monitoraggio)
            $intestazione = trim(str_replace(["\r", "\n"], ' ', (string) ($p['intestazione'] ?? '')));
            if ($intestazione === '') {
                $errori['colonna_' . $k] = $nome . ': scrivi l\'intestazione.';
            } elseif (mb_strlen($intestazione) > self::MAX_INTESTAZIONE) {
                $errori['colonna_' . $k] = $nome . ': l\'intestazione è troppo lunga (massimo ' . self::MAX_INTESTAZIONE . ' caratteri).';
            }
            $colore = strtoupper(trim((string) ($p['colore'] ?? '')));
            if (!self::coloreValido($colore)) {
                $errori['colonna_' . $k] = $nome . ': il colore non è valido.';
            }
            $a_cura_di = $d['a_cura_di'];
            $tipo = $d['tipo'];
            if ($d['operatori']) {
                $a_cura_di = trim((string) preg_replace('/\s+/u', ' ', (string) ($p['a_cura_di'] ?? '')));
                if ($a_cura_di === '') {
                    $errori['colonna_' . $k] = $nome . ': scrivi a cura di chi è la colonna.';
                } elseif (mb_strlen($a_cura_di) > self::MAX_A_CURA_DI) {
                    $errori['colonna_' . $k] = $nome . ': il nome di chi la cura è troppo lungo (massimo ' . self::MAX_A_CURA_DI . ' caratteri).';
                }
                $tipo = (string) ($p['tipo'] ?? '');
                if (!isset(self::TIPI[$tipo])) {
                    $errori['colonna_' . $k] = $nome . ': scegli il tipo della colonna.';
                }
            }
            $fissa = in_array($k, self::COLONNE_FISSE, true);
            $nuovi[$k] = [
                'intestazione' => $intestazione === trim($d['intestazione']) || $intestazione === $d['intestazione'] || $intestazione === '' ? null : $intestazione,
                'a_cura_di' => !$d['operatori'] || $a_cura_di === $d['a_cura_di'] ? null : $a_cura_di,
                'colore' => strtoupper($colore) === strtoupper($d['colore']) || !self::coloreValido($colore) ? null : $colore,
                'tipo' => !$d['operatori'] || $tipo === $d['tipo'] ? null : $tipo,
                'visibile' => $fissa ? null : (!empty($p['visibile']) ? null : 0),
                'ordine' => (int) ($p['ordine'] ?? 0),
            ];
        }
        if ($errori) {
            return ['errori' => $errori, 'cambi' => []];
        }
        foreach ($this->ordineSalvabile($nuovi, array_fill_keys(array_keys($nuovi), 0)) as $k => $o) {
            $nuovi[$k]['ordine'] = $o;
        }

        return ['errori' => [], 'cambi' => $this->salva('pubblicazioni_colonne', ['intestazione', 'a_cura_di', 'colore', 'tipo', 'visibile', 'ordine'], $nuovi, $this->colonneSalvate(), function (string $k): string {
            $c = ColonneMonitoraggio::perChiave($k);

            return 'Colonna «' . ($c !== null && $c['lettera'] !== '' ? $c['lettera'] . ' · ' : '') . ColonneMonitoraggio::etichetta($k) . '»';
        }, array_column(ColonneMonitoraggio::tutte(), null, 'chiave'))];
    }

    /** «Torna all'originale» per un campo del form: cancella le sue personalizzazioni. @return list<string> cosa è tornato come prima (vuoto se non c'era niente) */
    public function ripristinaCampo(string $chiave): array
    {
        return $this->cancella('pubblicazioni_campi', $chiave, isset(self::CAMPI[$chiave]) ? 'Campo «' . self::CAMPI[$chiave]['etichetta'] . '»' : '');
    }

    /** «Torna all'originale» per una colonna del file Excel. @return list<string> */
    public function ripristinaColonna(string $chiave): array
    {
        $c = ColonneMonitoraggio::perChiave($chiave);

        return $this->cancella('pubblicazioni_colonne', $chiave, $c !== null ? 'Colonna «' . ($c['lettera'] !== '' ? $c['lettera'] . ' · ' : '') . ColonneMonitoraggio::etichetta($chiave) . '»' : '');
    }

    /** «Torna alle intestazioni originali»: toglie tutte le intestazioni cambiate (colori, tipi, ordine e colonne nascoste restano). @return int quante intestazioni sono tornate come prima */
    public function ripristinaIntestazioni(): int
    {
        if ($this->db === null) {
            return 0;
        }
        $n = 0;
        foreach ($this->colonneSalvate() as $k => $s) {
            // le colonne nuove (y_<n>) sono definite dalla loro intestazione: non sono «da riportare all'originale»
            if (!self::eColonnaNuova((string) $k) && isset($s['intestazione']) && $s['intestazione'] !== null) {
                $n++;
                $this->db->esegui('UPDATE pubblicazioni_colonne SET intestazione = NULL WHERE chiave = ?', [$k]);
            }
        }
        $this->db->esegui('DELETE FROM pubblicazioni_colonne WHERE intestazione IS NULL AND a_cura_di IS NULL AND colore IS NULL AND tipo IS NULL AND visibile IS NULL AND ordine IS NULL');
        $this->righeColonne = null;

        return $n;
    }

    // ─────────────────────────── Campi e colonne nuovi (AM5) ───────────────────────────

    /** True se è la chiave di un campo nuovo del form (x_<n>). */
    public static function eCampoNuovo(string $chiave): bool
    {
        return (bool) preg_match('/^x_[1-9]\d{0,4}$/', $chiave);
    }

    /** True se è la chiave di una colonna nuova degli operatori (y_<n>). */
    public static function eColonnaNuova(string $chiave): bool
    {
        return (bool) preg_match('/^y_[1-9]\d{0,4}$/', $chiave);
    }

    /** Rilegge le personalizzazioni dal database alla prossima domanda (dopo che CampiAggiunti ha scritto). */
    public function ricarica(): void
    {
        if ($this->db !== null) {
            $this->righeCampi = null;
            $this->righeColonne = null;
        }
    }

    /**
     * I campi nuovi del form, anche i nascosti, nell'ordine in cui sono stati creati. Stessa forma di campi() con in più: extra (true), tipo (di MyForms),
     * opzioni (le scelte, per «scelta singola» e «caselle»), in_excel e intestazione_excel (se diventano una colonna del file Excel).
     *
     * @return list<array<string, mixed>>
     */
    public function campiExtra(): array
    {
        $out = [];
        foreach ($this->campiSalvati() as $k => $s) {
            $k = (string) $k;
            if (!self::eCampoNuovo($k) || !isset($s['tipo']) || !in_array((string) $s['tipo'], self::TIPI_CAMPO_EXTRA, true) || trim((string) ($s['etichetta'] ?? '')) === '') {
                continue;
            }
            $passo = (int) ($s['passo'] ?? 0);
            $passo = $passo >= 1 && $passo <= self::PASSO_MAX ? $passo : 6;
            $etichetta = trim((string) $s['etichetta']);
            $obbligatorio = (int) ($s['obbligatorio'] ?? 0) === 1;
            $intestazione = trim((string) ($s['intestazione_excel'] ?? ''));
            $out[] = [
                'chiave' => $k, 'passo' => $passo, 'etichetta' => $etichetta, 'aiuto' => (string) ($s['aiuto'] ?? ''), 'obbligatorio' => $obbligatorio,
                'visibile' => !isset($s['visibile']) || (int) $s['visibile'] === 1, 'fisso_obb' => false, 'fisso_vis' => false, 'nota' => '',
                'predefinito' => ['passo' => $passo, 'etichetta' => $etichetta, 'aiuto' => (string) ($s['aiuto'] ?? ''), 'obbligatorio' => $obbligatorio], 'modificato' => false,
                'extra' => true, 'numero' => (int) substr($k, 2), 'tipo' => (string) $s['tipo'], 'opzioni' => self::opzioniDa((string) ($s['opzioni'] ?? '')),
                'in_excel' => (int) ($s['in_excel'] ?? 0) === 1, 'intestazione_excel' => $intestazione !== '' ? $intestazione : $etichetta,
            ];
        }
        usort($out, static fn (array $a, array $b): int => $a['numero'] <=> $b['numero']);

        return $out;
    }

    /**
     * Le colonne nuove degli operatori (y_<n>), anche le nascoste, nell'ordine in cui sono state create, con «dopo» (la colonna dopo cui stanno, '' = in fondo).
     *
     * @return list<array<string, mixed>>
     */
    public function colonneNuove(): array
    {
        return array_values(array_filter($this->colonne(true), static fn (array $c): bool => ($c['origine'] ?? '') === 'colonna'));
    }

    /** Il valore di un campo nuovo come si legge: le date in gg/mm/aaaa, gli altri com'è. */
    public static function valoreInParole(string $tipo, string $valore): string
    {
        $valore = trim($valore);
        if ($tipo === 'date' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valore, $m)) {
            return $m[3] . '/' . $m[2] . '/' . $m[1];
        }

        return $valore;
    }

    /** Le scelte di un campo scritte come nel costruttore (separate da virgola, punto e virgola o a capo), senza vuoti. @return list<string> */
    public static function opzioniDa(string $testo): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;\n]/', $testo) ?: []), static fn (string $o): bool => $o !== ''));
    }

    /**
     * Le colonne di sempre (già nell'ordine scelto) con quelle nuove. Ogni colonna nuova sta dopo la sua colonna di riferimento: per una colonna degli operatori
     * quella scelta dall'amministratore (vuota o sparita = in fondo); per la colonna di un campo nuovo quella dell'ultimo campo del suo passo che ne ha una
     * (se il passo non ne ha, del passo prima; se non c'è nessuno, in fondo). Più colonne nuove dopo la stessa si seguono: prima quelle dei campi, poi quelle degli
     * operatori, ciascuna nell'ordine in cui è stata creata.
     *
     * @param list<array<string, mixed>> $base
     * @return list<array<string, mixed>>
     */
    private function conNuove(array $base): array
    {
        $nuove = [];
        $campi = $this->campi();
        foreach ($campi as $c) {
            if (empty($c['extra']) || !$c['in_excel']) {
                continue;
            }
            $nuove[] = [
                'chiave' => $c['chiave'], 'lettera' => '', 'intestazione' => $c['intestazione_excel'], 'a_cura_di' => '', 'colore' => self::COLORE_COLONNA_CAMPO, 'operatori' => false,
                'tipo' => 'testo', 'visibile' => $c['visibile'], 'fissa' => false, 'modificata' => false, 'extra' => true, 'origine' => 'campo', 'dopo' => '',
                'predefinita' => ['chiave' => $c['chiave'], 'lettera' => '', 'intestazione' => $c['intestazione_excel'], 'a_cura_di' => '', 'colore' => self::COLORE_COLONNA_CAMPO, 'operatori' => false, 'tipo' => 'testo'],
                '_ancora' => $this->ancoraDelCampo($c, $campi), '_ordine' => [0, (int) $c['numero']],
            ];
        }
        foreach ($this->colonneSalvate() as $k => $s) {
            $k = (string) $k;
            $intestazione = trim((string) ($s['intestazione'] ?? ''));
            if (!self::eColonnaNuova($k) || $intestazione === '') {
                continue;
            }
            $a_cura_di = trim((string) ($s['a_cura_di'] ?? ''));
            $a_cura_di = $a_cura_di !== '' ? $a_cura_di : 'Altri';
            $colore = isset($s['colore']) && self::coloreValido((string) $s['colore']) ? strtoupper((string) $s['colore']) : self::COLORE_COLONNA_CAMPO;
            $tipo = isset($s['tipo'], self::TIPI[(string) $s['tipo']]) ? (string) $s['tipo'] : 'testo';
            $dopo = trim((string) ($s['dopo'] ?? ''));
            $nuove[] = [
                'chiave' => $k, 'lettera' => '', 'intestazione' => $intestazione, 'a_cura_di' => $a_cura_di, 'colore' => $colore, 'operatori' => true, 'tipo' => $tipo,
                'visibile' => !isset($s['visibile']) || (int) $s['visibile'] === 1, 'fissa' => false, 'modificata' => false, 'extra' => true, 'origine' => 'colonna', 'dopo' => $dopo,
                'predefinita' => ['chiave' => $k, 'lettera' => '', 'intestazione' => $intestazione, 'a_cura_di' => $a_cura_di, 'colore' => $colore, 'operatori' => true, 'tipo' => $tipo],
                '_ancora' => $dopo, '_ordine' => [1, (int) substr($k, 2)],
            ];
        }
        if ($nuove === []) {
            return $base;
        }
        usort($nuove, static fn (array $a, array $b): int => $a['_ordine'] <=> $b['_ordine']);
        $figli = [];
        foreach ($nuove as $n) {
            $figli[$n['_ancora'] === $n['chiave'] ? '' : (string) $n['_ancora']][] = $n;
        }
        $out = [];
        $emetti = static function (array $riga) use (&$emetti, &$figli, &$out): void {
            unset($riga['_ancora'], $riga['_ordine']);
            $out[] = $riga;
            $discendenti = $figli[$riga['chiave']] ?? [];
            unset($figli[$riga['chiave']]);
            foreach ($discendenti as $d) {
                $emetti($d);
            }
        };
        foreach ($base as $riga) {
            $emetti($riga);
        }
        // quelle senza una colonna di riferimento valida stanno in fondo
        while ($figli !== []) {
            $primo = array_key_first($figli);
            $gruppo = $figli[$primo];
            unset($figli[$primo]);
            foreach ($gruppo as $d) {
                $emetti($d);
            }
        }

        return $out;
    }

    /** La colonna dopo cui sta quella di un campo nuovo ('' = in fondo). @param array<string, mixed> $x @param list<array<string, mixed>> $campi tutti i campi nell'ordine della pagina */
    private function ancoraDelCampo(array $x, array $campi): string
    {
        $ultima = '';
        foreach ($campi as $c) {
            if ($c['passo'] > $x['passo'] || $c['chiave'] === $x['chiave']) {
                break;
            }
            $colonna = !empty($c['extra']) ? ($c['in_excel'] ? $c['chiave'] : null) : (self::COLONNA_ANCORA[$c['chiave']] ?? null);
            if ($colonna !== null) {
                $ultima = $colonna;
            }
        }

        return $ultima;
    }

    // ─────────────────────────── Interno ───────────────────────────

    /** @return array<string, array<string, mixed>> */
    private function campiSalvati(): array
    {
        return $this->righeCampi ??= $this->leggi('pubblicazioni_campi');
    }

    /** @return array<string, array<string, mixed>> */
    private function colonneSalvate(): array
    {
        return $this->righeColonne ??= $this->leggi('pubblicazioni_colonne');
    }

    /** @return array<string, array<string, mixed>> */
    private function leggi(string $tabella): array
    {
        if ($this->db === null) {
            return [];
        }
        try {
            return self::perChiave($this->db->righe('SELECT * FROM ' . $tabella));
        } catch (Throwable $e) {
            // tabella non ancora creata (caricamento a metà): valgono i valori del codice
            error_log('[Pubblicazioni] ' . $tabella . ' non letta: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * @param list<array<string, mixed>> $righe
     * @return array<string, array<string, mixed>>
     */
    private static function perChiave(array $righe): array
    {
        $out = [];
        foreach ($righe as $r) {
            if (isset($r['chiave'])) {
                $out[(string) $r['chiave']] = $r;
            }
        }

        return $out;
    }

    private static function coloreValido(string $c): bool
    {
        return (bool) preg_match('/^#[0-9A-Fa-f]{6}$/', $c);
    }

    /**
     * Gli «ordine» da salvare: gli elementi si dispongono come li ha messi la scheda (campo ordine di ognuno); dentro ogni gruppo (il passo, o tutto per
     * le colonne) se la sequenza è quella di partenza si salva NULL, altrimenti le posizioni 1…n.
     *
     * @param array<string, array<string, mixed>> $nuovi chiave => valori, con «ordine» come arriva dalla scheda
     * @param array<string, int> $gruppo chiave => gruppo (il passo; 0 per le colonne)
     * @return array<string, int|null>
     */
    private function ordineSalvabile(array $nuovi, array $gruppo): array
    {
        $partenza = array_keys($gruppo); // l'ordine di partenza è quello in cui sono scritti nel codice
        $per_gruppo = [];
        foreach ($partenza as $i => $k) {
            if (isset($nuovi[$k])) {
                $per_gruppo[$gruppo[$k]][] = ['k' => $k, 'o' => (int) $nuovi[$k]['ordine'], 'i' => $i];
            }
        }
        $out = [];
        foreach ($per_gruppo as $elementi) {
            $come_partenza = array_column($elementi, 'k');
            usort($elementi, static fn (array $a, array $b): int => [$a['o'], $a['i']] <=> [$b['o'], $b['i']]);
            $scelta = array_column($elementi, 'k');
            foreach ($scelta as $pos => $k) {
                $out[$k] = $scelta === $come_partenza ? null : $pos + 1;
            }
        }

        return $out;
    }

    /**
     * Scrive nel database le differenze (una riga per elemento che ha almeno un valore cambiato, nessuna riga per quelli come prima) e dice cosa è cambiato.
     *
     * @param list<string> $colonne le colonne salvabili della tabella (oltre a «chiave»)
     * @param array<string, array<string, mixed>> $nuovi chiave => valori nuovi (NULL = valore del codice)
     * @param array<string, array<string, mixed>> $vecchi le righe salvate fin qui
     * @param array<string, array<string, mixed>> $partenza chiave => valori del codice (per scrivere «da → a» anche quando un valore torna quello di partenza)
     * @return list<string> una riga per elemento cambiato: «Campo «Titolo»: nome «A» → «B», visibilità visibile → nascosto, posizione»
     */
    private function salva(string $tabella, array $colonne, array $nuovi, array $vecchi, callable $nome, array $partenza): array
    {
        if ($this->db === null) {
            return [];
        }
        $cambi = [];
        $this->db->transazione(function () use ($tabella, $colonne, $nuovi, $vecchi, $nome, $partenza, &$cambi): void {
            foreach ($nuovi as $k => $n) {
                $v = $vecchi[$k] ?? [];
                $diff = [];
                foreach ($colonne as $c) {
                    $prima = $v[$c] ?? null;
                    $dopo = $n[$c] ?? null;
                    if (($prima === null ? null : (string) $prima) !== ($dopo === null ? null : (string) $dopo)) {
                        $diff[] = $c;
                    }
                }
                if ($diff === []) {
                    continue;
                }
                $parti = [];
                foreach ($diff as $c) {
                    $parti[] = $c === 'ordine' ? 'posizione' : (self::PAROLE[$c] ?? $c) . ' ' . self::mostra($c, $v[$c] ?? null, $partenza[$k] ?? []) . ' → ' . self::mostra($c, $n[$c] ?? null, $partenza[$k] ?? []);
                }
                $cambi[] = $nome($k) . ': ' . implode(', ', $parti);
                $vuota = true;
                foreach ($colonne as $c) {
                    $vuota = $vuota && ($n[$c] ?? null) === null;
                }
                if ($vuota) {
                    $this->db->esegui("DELETE FROM $tabella WHERE chiave = ?", [$k]);
                    continue;
                }
                $nomi = implode(', ', $colonne);
                $segni = implode(', ', array_fill(0, count($colonne), '?'));
                $aggiorna = implode(', ', array_map(static fn (string $c): string => "$c = VALUES($c)", $colonne));
                $this->db->esegui("INSERT INTO $tabella (chiave, $nomi) VALUES (?, $segni) ON DUPLICATE KEY UPDATE $aggiorna", array_merge([$k], array_map(static fn (string $c): mixed => $n[$c] ?? null, $colonne)));
            }
        });
        $this->righeCampi = $tabella === 'pubblicazioni_campi' ? null : $this->righeCampi;
        $this->righeColonne = $tabella === 'pubblicazioni_colonne' ? null : $this->righeColonne;

        return $cambi;
    }

    /** @return list<string> */
    private function cancella(string $tabella, string $chiave, string $nome): array
    {
        if ($this->db === null || $nome === '') {
            return [];
        }
        $n = $this->db->esegui("DELETE FROM $tabella WHERE chiave = ?", [$chiave]);
        $this->righeCampi = $tabella === 'pubblicazioni_campi' ? null : $this->righeCampi;
        $this->righeColonne = $tabella === 'pubblicazioni_colonne' ? null : $this->righeColonne;

        return $n > 0 ? [$nome . ': tornato all\'originale'] : [];
    }

    /** Un valore salvato (NULL = quello del codice) scritto per il registro delle attività. @param array<string, mixed> $partenza i valori del codice di quell'elemento */
    private static function mostra(string $colonna, mixed $v, array $partenza): string
    {
        if ($v === null) {
            $v = $partenza[$colonna] ?? ($colonna === 'visibile' ? 1 : null);
        }
        $testo = match ($colonna) {
            'visibile' => (int) $v === 1 ? 'visibile' : 'nascosto',
            'obbligatorio' => (int) $v === 1 ? 'obbligatorio' : 'facoltativo',
            'tipo' => self::TIPI[(string) $v] ?? (string) $v,
            default => '«' . (mb_strlen((string) $v) > 80 ? mb_substr((string) $v, 0, 80) . '…' : (string) $v) . '»',
        };

        return $testo;
    }

    /** Le cose che si cambiano, in parole (per il registro). */
    private const PAROLE = [
        'etichetta' => 'nome', 'aiuto' => 'testo di aiuto', 'obbligatorio' => 'obbligatorio', 'visibile' => 'visibilità', 'intestazione' => 'intestazione',
        'a_cura_di' => 'a cura di', 'colore' => 'colore', 'tipo' => 'tipo',
    ];
}
