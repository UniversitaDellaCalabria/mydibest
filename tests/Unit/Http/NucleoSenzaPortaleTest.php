<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Core\Schema\Definizioni;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Il nucleo (src/Core e src/Http) non conosce il portale (documentazione/piano_framework_dibest.md, Fase 8): niente nomi del
 * Dipartimento, niente tabelle o ruoli del portale, e nessuna dipendenza da un modulo di dominio (App\Didattica, App\Fsl, App\Portale…):
 * la dipendenza va in un verso solo. Se un giorno il nucleo dovesse servire a un secondo portale, l'estrazione è un lavoro meccanico.
 *
 * src/Http è PULITO e la prova su di lui è dura, senza elenco: deve restare verde sempre.
 * src/Core invece contiene ancora cose del portale, e la prova è un CRICCHETTO (documentazione/piano_framework_dibest.md, «Mai lasciare
 * una prova rossa di proposito»): accetta SOLO le violazioni dell'elenco ELENCO_CORE qui sotto, ognuna con il suo motivo, e FALLISCE se
 *  - compare una violazione in un file che non è in elenco, o di un tipo che per quel file non è in elenco;
 *  - un file in elenco ne ha di più del tetto scritto (dove il tetto c'è);
 *  - l'elenco si allunga (un sesto file, o più voci delle dieci scritte);
 *  - un'esenzione non serve più (la violazione è sparita): allora si toglie la riga, e il cricchetto si stringe.
 * Così non si può peggiorare, si può solo migliorare, e la suite resta verde.
 */
final class NucleoSenzaPortaleTest extends TestCase
{
    /** Nomi del Dipartimento e dei moduli maggiori (senza distinguere maiuscole e minuscole). */
    private const PAROLE = ['dibest', 'unical', 'fsl', 'tirocini'];

    /** Identificatori dei ruoli e dei permessi del portale. */
    private const RUOLI = ['ruolo_id', 'ruoli_secondari', 'is_full_admin', 'is_gestore', 'is_area_manager', 'amministratore globale'];

    /**
     * Nomi di file e cartelle di QUESTO portale (le testate, il file di avvio, le funzioni, la cartella del pannello). Il meccanismo non li
     * nomina nemmeno nei commenti: gli arrivano come configurazione (Vista::layout(), la tabella delle rotte). Solo src/Http è controllato.
     */
    private const FILE_DEL_PORTALE = ['admin/', 'admin_header.php', 'admin_footer.php', 'header.php', 'footer.php', 'functions.php', 'config.php'];

    /** Cartelle di src/ che NON sono moduli di dominio: la libreria di base e lo strato HTTP stesso. */
    private const NON_DOMINIO = ['Core', 'Http', 'Infrastructure'];

    /**
     * Le violazioni che src/Core ha GIÀ (22/11/2026): file => [controllo => [tetto, motivo]]. Il tetto è il numero di righe di oggi; con
     * null il numero è libero (lo schema cresce a ogni tabella che si aggiunge). NON allungare questo elenco per far tornare verde una
     * prova: si porta fuori dal nucleo la cosa che non gli appartiene. Si può solo accorciare.
     *
     * @var array<string, array<string, array{0: ?int, 1: string}>>
     */
    private const ELENCO_CORE = [
        'Core/Sito.php' => [
            'Parole del Dipartimento' => [6, 'dibest2.unical.it come ripiego di HOST_PREDEFINITO e dei nomi ammessi: è la configurazione del portale, non del nucleo. Esce quando il nome starà solo nel .env.'],
        ],
        'Core/Ambiente.php' => [
            'Parole del Dipartimento' => [5, 'i nomi dei cookie del portale (dibest_uscito…) e la sessione MYDIBEST_PP. Escono quando i nomi si leggeranno dalla configurazione.'],
        ],
        'Core/Schema/Definizioni.php' => [
            'Parole del Dipartimento' => [null, 'lo schema del database è del portale, cioè dominio puro (src/Core/Schema va in src/Sistema/Schema, in un passo suo).'],
            'Tabelle del portale' => [null, 'i nomi delle tabelle sono lo schema del portale: stesso motivo.'],
            'Ruoli del portale' => [null, 'la colonna ruoli_secondari di una tabella del portale: stesso motivo.'],
            'Dipendenze da moduli di dominio' => [null, 'le definizioni di alcune tabelle chiamano classi dei moduli (App\Pubblicazioni…): stesso motivo.'],
        ],
        'Core/Schema/Migrazioni.php' => [
            'Parole del Dipartimento' => [null, 'le migrazioni dello schema del portale: stesso motivo.'],
            'Tabelle del portale' => [null, 'le migrazioni toccano tabelle del portale: stesso motivo.'],
            'Dipendenze da moduli di dominio' => [null, 'le migrazioni chiamano classi dei moduli (App\Assistenza…): stesso motivo.'],
        ],
        'Core/App.php' => [
            'Dipendenze da moduli di dominio' => [1, 'è il ponte verso il codice procedurale e registra i moduli per disegno: sparisce con l\'ultimo file procedurale, e resta esente fino ad allora.'],
        ],
    ];

    /**
     * @param list<string> $cartelle cartelle di src/
     * @return list<string> percorsi relativi a src/ dei file PHP
     */
    private static function file(array $cartelle): array
    {
        $out = [];
        foreach ($cartelle as $cartella) {
            $dir = RADICE_SITO . '/src/' . $cartella;
            if (!is_dir($dir)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $out[] = str_replace('\\', '/', substr($f->getPathname(), strlen(RADICE_SITO . '/src/')));
                }
            }
        }
        sort($out);

        return $out;
    }

    /**
     * @param list<string>              $cartelle
     * @param callable(string): ?string $trova riceve una riga e restituisce cosa ha trovato (null = niente)
     * @return list<string> «file:riga: trovato»
     */
    private static function cerca(array $cartelle, callable $trova, bool $saltaCommenti = false): array
    {
        $trovati = [];
        foreach (self::file($cartelle) as $file) {
            foreach (file(RADICE_SITO . '/src/' . $file) ?: [] as $i => $riga) {
                if ($saltaCommenti && preg_match('~^\s*(\*|//|/\*|#)~', $riga) === 1) {
                    continue;
                }
                $t = $trova($riga);
                if ($t !== null) {
                    $trovati[] = $file . ':' . ($i + 1) . ': ' . $t;
                }
            }
        }

        return $trovati;
    }

    /** @param list<string> $trovati */
    private static function riepilogo(string $titolo, array $trovati): string
    {
        $perFile = [];
        foreach ($trovati as $t) {
            $perFile[(string) strstr($t, ':', true)][] = $t;
        }
        $righe = [];
        foreach ($perFile as $file => $voci) {
            $righe[] = sprintf('  %s — %d occorrenze, la prima: %s', $file, count($voci), $voci[0]);
        }

        return $titolo . ' (' . count($trovati) . ' in ' . count($perFile) . " file):\n" . implode("\n", $righe);
    }

    /** @return array<string, callable(string): ?string> nome del controllo => funzione per riga */
    private static function controlli(): array
    {
        $nomi = array_map('strval', array_keys(Definizioni::tabelle()));
        $tabelle = '~\b(?:FROM|JOIN|INTO|UPDATE|TABLE(?:\s+IF\s+(?:NOT\s+)?EXISTS)?)\s+`?(' . implode('|', array_map(static fn (string $n): string => preg_quote($n, '~'), $nomi)) . ')`?\b~i';
        $moduli = [];
        foreach (glob(RADICE_SITO . '/src/*', GLOB_ONLYDIR) ?: [] as $d) {
            if (!in_array(basename($d), self::NON_DOMINIO, true)) {
                $moduli[] = basename($d);
            }
        }
        sort($moduli);
        $dominio = '~\\\\?\bApp\\\\(' . implode('|', array_map('preg_quote', $moduli)) . ')\\\\~';

        return [
            'Parole del Dipartimento' => static function (string $riga): ?string {
                foreach (self::PAROLE as $p) {
                    if (stripos($riga, $p) !== false) {
                        return $p;
                    }
                }

                return null;
            },
            'Tabelle del portale' => static fn (string $riga): ?string => preg_match($tabelle, $riga, $m) === 1 ? 'tabella ' . $m[1] : null,
            'Ruoli del portale' => static function (string $riga): ?string {
                foreach (self::RUOLI as $r) {
                    if (stripos($riga, $r) !== false) {
                        return $r;
                    }
                }

                return null;
            },
            'Dipendenze da moduli di dominio' => static fn (string $riga): ?string => preg_match($dominio, $riga, $m) === 1 ? 'App\\' . $m[1] : null,
            'Nomi dei file del portale' => static function (string $riga): ?string {
                foreach (self::FILE_DEL_PORTALE as $nome) {
                    if (stripos($riga, $nome) !== false) {
                        return $nome;
                    }
                }

                return null;
            },
        ];
    }

    private static function trova(string $controllo, array $cartelle): array
    {
        // le dipendenze si cercano nel codice, non nei commenti che citano una classe; le parole e i nomi, ovunque
        return self::cerca($cartelle, self::controlli()[$controllo], saltaCommenti: $controllo === 'Dipendenze da moduli di dominio');
    }

    public function testIlControlloVedeIlNucleo(): void
    {
        $file = self::file(['Core', 'Http']);

        self::assertContains('Core/Container.php', $file);
        self::assertContains('Http/Risposta.php', $file);
        self::assertContains('Http/Richiesta.php', $file);
        self::assertContains('Didattica', array_map('basename', glob(RADICE_SITO . '/src/*', GLOB_ONLYDIR) ?: []), 'l\'elenco dei moduli di dominio si ricava dalle cartelle di src/');
    }

    public function testIlControlloSaTrovareLeCoseCheCerca(): void
    {
        $c = self::controlli();

        self::assertSame('dibest', $c['Parole del Dipartimento']('const A = "https://dibest2.unical.it";'));
        self::assertSame('tabella comunicazioni', $c['Tabelle del portale']('SELECT * FROM comunicazioni WHERE id = 1'));
        self::assertNull($c['Tabelle del portale']('// gli eventi di oggi'), 'una parola qualunque non è una tabella: serve il contesto SQL');
        self::assertSame('is_full_admin', $c['Ruoli del portale']('if ($is_full_admin) {'));
        self::assertSame('App\Fsl', $c['Dipendenze da moduli di dominio']('use App\Fsl\ServizioScuole;'));
        self::assertNull($c['Dipendenze da moduli di dominio']('use App\Core\File;'));
        self::assertSame('admin/', $c['Nomi dei file del portale']("require \$radice . '/admin/admin_header.php';"));
        self::assertSame('header.php', $c['Nomi dei file del portale']("'sito' => ['header.php', 'footer.php'],"));
        self::assertSame('functions.php', $c['Nomi dei file del portale']('// richiede che functions.php sia già stato caricato'));
        self::assertSame('config.php', $c['Nomi dei file del portale']('il buffer di config.php'));
        self::assertNull($c['Nomi dei file del portale']('una cartella che si chiama amministrazione e un header HTTP'), 'serve il nome del file, non una parola vicina');
    }

    /**
     * Il cuore del cricchetto, senza guardare i file: confronta quello che si è trovato con l'elenco.
     *
     * @param list<string>                                            $trovati «file:riga: trovato», come li dà cerca()
     * @param array<string, array<string, array{0: ?int, 1: string}>> $elenco  file => [controllo => [tetto, motivo]]
     * @return list<string> i problemi, uno per riga; vuoto = tutto in regola
     */
    private static function confrontaConElenco(string $controllo, array $trovati, array $elenco): array
    {
        $perFile = [];
        foreach ($trovati as $t) {
            $f = (string) strstr($t, ':', true);
            $perFile[$f] = ($perFile[$f] ?? 0) + 1;
        }
        $problemi = [];
        foreach ($perFile as $file => $n) {
            if (!isset($elenco[$file][$controllo])) {
                $esempio = current(array_filter($trovati, static fn (string $t): bool => str_starts_with($t, $file . ':')));
                $problemi[] = "VIOLAZIONE NUOVA — $controllo in $file ($n righe, la prima: $esempio): non è nell'elenco delle esenzioni; il nucleo non deve conoscere il portale";
            } elseif ($elenco[$file][$controllo][0] !== null && $n > $elenco[$file][$controllo][0]) {
                $problemi[] = "PEGGIORATO — $controllo in $file: $n righe contro un tetto di " . $elenco[$file][$controllo][0] . ' (toglile, non alzare il tetto)';
            }
        }
        foreach ($elenco as $file => $controlli) {
            if (isset($controlli[$controllo]) && !isset($perFile[$file])) {
                $problemi[] = "ESENZIONE INUTILE — $controllo in $file non ha più violazioni: togli la riga dall'elenco (il cricchetto si stringe)";
            }
        }

        return $problemi;
    }

    /** @return list<string> */
    private static function problemiDelCore(string $controllo): array
    {
        return self::confrontaConElenco($controllo, self::trova($controllo, ['Core']), self::ELENCO_CORE);
    }

    public function testCoreNessunaParolaDelDipartimentoOltreAdElenco(): void
    {
        self::assertSame([], self::problemiDelCore('Parole del Dipartimento'));
    }

    public function testCoreNessunNomeDiTabellaDelPortaleOltreAdElenco(): void
    {
        self::assertSame([], self::problemiDelCore('Tabelle del portale'));
    }

    public function testCoreNessunRuoloDelPortaleOltreAdElenco(): void
    {
        self::assertSame([], self::problemiDelCore('Ruoli del portale'));
    }

    public function testCoreNessunaDipendenzaDaUnModuloDiDominioOltreAdElenco(): void
    {
        self::assertSame([], self::problemiDelCore('Dipendenze da moduli di dominio'));
    }

    /** L'elenco stesso non si allunga: sono i cinque file del piano, ogni voce ha un controllo che esiste e un motivo scritto. */
    public function testLElencoDelleEsenzioniNonSiAllunga(): void
    {
        self::assertSame(
            ['Core/Sito.php', 'Core/Ambiente.php', 'Core/Schema/Definizioni.php', 'Core/Schema/Migrazioni.php', 'Core/App.php'],
            array_keys(self::ELENCO_CORE),
            'le esenzioni sono i cinque file del piano: un sesto non si aggiunge, si porta fuori dal nucleo la cosa che non gli appartiene',
        );
        $conosciuti = array_keys(self::controlli());
        $voci = 0;
        foreach (self::ELENCO_CORE as $file => $controlli) {
            self::assertFileExists(RADICE_SITO . '/src/' . $file);
            foreach ($controlli as $controllo => [$tetto, $motivo]) {
                self::assertContains($controllo, $conosciuti, "$file: controllo sconosciuto");
                self::assertGreaterThan(30, strlen($motivo), "$file / $controllo: manca il motivo scritto");
                self::assertTrue($tetto === null || $tetto > 0);
                ++$voci;
            }
        }
        self::assertSame(10, $voci, 'dieci voci (file × tipo di violazione): una in più vuol dire che l\'elenco si è allungato');
        // Il tetto libero (null) è solo dello schema, che cresce con le tabelle; per gli altri tre file il numero è fisso.
        foreach (['Core/Sito.php', 'Core/Ambiente.php', 'Core/App.php'] as $file) {
            foreach (self::ELENCO_CORE[$file] as $controllo => [$tetto]) {
                self::assertNotNull($tetto, "$file / $controllo: solo lo schema ha il numero libero");
            }
        }
    }

    /** Il cricchetto in sé, su dati finti: i tre modi di fallire e quello di passare. */
    public function testIlCricchettoCapisceNuovoPeggioratoEInutile(): void
    {
        $elenco = ['Core/Vecchio.php' => ['Parole del Dipartimento' => [2, 'motivo scritto per esteso, più di trenta caratteri']]];
        $c = 'Parole del Dipartimento';
        $conNuovo = self::confrontaConElenco($c, ['Core/Nuovo.php:3: dibest', 'Core/Vecchio.php:3: dibest'], $elenco);

        self::assertSame([], self::confrontaConElenco($c, ['Core/Vecchio.php:3: dibest', 'Core/Vecchio.php:9: unical'], $elenco), 'dentro il tetto: passa');
        self::assertCount(1, $conNuovo, 'file nuovo: fallisce');
        self::assertStringContainsString('VIOLAZIONE NUOVA', $conNuovo[0]);
        self::assertStringContainsString('PEGGIORATO', self::confrontaConElenco($c, ['Core/Vecchio.php:1: a', 'Core/Vecchio.php:2: b', 'Core/Vecchio.php:3: c'], $elenco)[0], 'sopra il tetto: fallisce');
        self::assertStringContainsString('ESENZIONE INUTILE', self::confrontaConElenco($c, [], $elenco)[0], 'violazione sparita: si toglie la riga');
        self::assertCount(1, self::confrontaConElenco('Ruoli del portale', ['Core/Vecchio.php:3: is_full_admin'], $elenco), 'stesso file ma un altro tipo di violazione: è l\'elenco che si allunga');
        self::assertSame([], self::confrontaConElenco('Ruoli del portale', [], $elenco), 'un controllo che non è in elenco e non trova niente: nessun problema');
    }

    /** VERDE e da tenere tale, SENZA elenco: il codice nuovo di src/Http non conosce il portale (né le sue parole, né i suoi file). */
    public function testSrcHttpDaSoloEPulito(): void
    {
        foreach (array_keys(self::controlli()) as $controllo) {
            $t = self::trova($controllo, ['Http']);
            self::assertTrue($t === [], self::riepilogo($controllo . ' in src/Http', $t));
        }
    }
}
