<?php

declare(strict_types=1);

namespace Tests\Unit\Portale;

use App\Auth\Permessi;
use App\Portale\Instradamento\Rotte;
use App\Portale\Pannello\MenuModuli;
use App\Portale\Pannello\PermessiPagine;
use PHPUnit\Framework\TestCase;

/** La mappa «chi può aprire quale pagina» di admin/: completa (nessuna pagina senza regola) e giusta per quattro tipi di utente. */
final class PermessiPagineTest extends TestCase
{
    private const CARTELLA = __DIR__ . '/../../../admin';

    /** @return list<string> i percorsi dei file .php sotto admin/, sottocartelle comprese, relativi ad admin/ («parziali/x.php») */
    private static function fileDiAdmin(): array
    {
        $base = (string)realpath(self::CARTELLA);
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
                $out[] = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($f->getPathname(), strlen($base))), '/');
            }
        }
        sort($out);

        return $out;
    }

    /** I permessi che il pannello calcola: sono le chiavi di Permessi::elenco(), l'unica fonte (prima si leggevano dal sorgente di admin_header.php con una regex). */
    private static function nomiPermessi(): array
    {
        return Permessi::chiavi();
    }

    private static function usaAdminHeader(string $file): bool
    {
        $src = (string)file_get_contents(self::CARTELLA . '/' . $file);

        // Un guscio del Nucleo (`Nucleo::serviFile(__FILE__)`): la pagina è una classe Pagina che mette il corpo nel layout del pannello con
        // Vista::pannello(), cioè include admin_header.php, e prima ancora il Nucleo chiede l'accesso a PermessiPagine con questo stesso file.
        if (str_contains($src, 'Nucleo::serviFile(__FILE__)')) {
            foreach (Rotte::elenco() as $rotta) {
                if ($rotta->file === 'admin/' . $file) {
                    $classe = (string)file_get_contents(RADICE_SITO . '/src/' . str_replace('\\', '/', substr($rotta->pagina, 4)) . '.php');

                    return str_contains($classe, 'Vista::pannello(');
                }
            }

            return false;
        }

        // «require_once 'admin_header.php'» oppure una pagina che ne include un'altra già in mappa (le anagrafe_*.php includono anagrafe_personale.php)
        return preg_match("#require(_once)?\\s*\\(?\\s*(__DIR__\\s*\\.\\s*)?['\"]/?(admin_header|anagrafe_personale)\\.php['\"]#", $src) === 1;
    }

    public function testOgniFileDiAdminHaUnaRegolaOLaDichiaraComeSchedaOFuori(): void
    {
        $senza = array_values(array_filter(self::fileDiAdmin(), fn(string $f) => PermessiPagine::tipo($f) === null));
        $this->assertSame([], $senza, 'Pagine di admin/ senza regola in App\Portale\Pannello\PermessiPagine (aggiungile in PAGINE, SCHEDE o FUORI): ' . implode(', ', $senza));
    }

    public function testLaMappaNonContieneFileCheNonEsistono(): void
    {
        $esistenti = self::fileDiAdmin();
        $tutti = array_merge(array_keys(PermessiPagine::pagine()), PermessiPagine::schede(), PermessiPagine::fuori());
        $this->assertSame([], array_values(array_diff($tutti, $esistenti)), 'Nella mappa ci sono file che non esistono più');
        $this->assertSame(count($tutti), count(array_unique($tutti)), 'Un file è in più di un elenco');
    }

    public function testLeSchedeEIFuoriNonPassanoDaAdminHeaderELePagineSi(): void
    {
        foreach (self::fileDiAdmin() as $f) {
            $tipo = PermessiPagine::tipo($f);
            if ($f === 'admin_header.php') {
                continue;
            }
            if ($tipo === 'pagina') {
                $this->assertTrue(self::usaAdminHeader($f), "$f è in PAGINE ma non passa da admin_header.php: va in FUORI");
            } else {
                $this->assertFalse(self::usaAdminHeader($f), "$f passa da admin_header.php ma è tra le schede o i «fuori»: va in PAGINE con la sua regola");
            }
        }
    }

    public function testLeRegoleUsanoSoloPermessiCheEsistonoInPermessi(): void
    {
        $noti = self::nomiPermessi();
        foreach (PermessiPagine::pagine() as $pagina => $regola) {
            $this->assertNotSame([], $regola, "$pagina: regola vuota");
            foreach ($regola as $nome) {
                $this->assertTrue($nome === '*' || in_array($nome, $noti, true), "$pagina usa il permesso «{$nome}», che non è fra le chiavi di App\Auth\Permessi::elenco()");
            }
        }
    }

    /** Lo stesso per le voci del menu: un permesso scritto male lì nasconderebbe la voce a tutti, in silenzio. */
    public function testLeVociDelMenuUsanoSoloPermessiCheEsistonoInPermessi(): void
    {
        $noti = self::nomiPermessi();
        foreach (MenuModuli::moduli() as $modulo) {
            foreach (MenuModuli::voci($modulo) as $voce) {
                $usati = array_merge($voce['permessi'], isset($voce['testo_se_no']) ? [$voce['testo_se_no'][0]] : []);
                foreach ($usati as $nome) {
                    $this->assertContains($nome, $noti, "voce {$modulo}/{$voce['id']} usa il permesso «{$nome}», che non è fra le chiavi di App\Auth\Permessi::elenco()");
                }
            }
        }
    }

    /** Ogni chiave di Permessi ha il suo metodo (la tabella METODI è il contratto con la mappa). */
    public function testOgniChiaveDiPermessiHaUnMetodo(): void
    {
        foreach (Permessi::METODI as $chiave => $metodo) {
            $this->assertTrue(method_exists(Permessi::class, $metodo), "Permessi::$metodo() manca (permesso «{$chiave}»)");
        }
    }

    /** I partiali nelle sottocartelle non si aprono da soli: in testa hanno una guardia, che il test cerca nel sorgente. */
    public function testIFileDelleSottocartelleHannoLaGuardia(): void
    {
        $visti = 0;
        foreach (self::fileDiAdmin() as $f) {
            if (!str_contains($f, '/')) {
                continue;
            }
            $visti++;
            $this->assertSame('scheda', PermessiPagine::tipo($f), "$f sta in una sottocartella: dev'essere una scheda (inclusa) o «fuori»");
            $src = (string)file_get_contents(self::CARTELLA . '/' . $f);
            $this->assertMatchesRegularExpression("/if \(!(defined\('[A-Z_]+'\)|function_exists\('nega_accesso_pannello'\))\) \{ http_response_code\(403\); exit\(/", $src, "$f non ha la guardia in testa: Apache lo servirebbe direttamente");
            $this->assertLessThan(strpos($src, '?>') ?: PHP_INT_MAX, strpos($src, 'http_response_code(403)'), "$f: la guardia dev'essere prima del codice");
        }
        $this->assertGreaterThanOrEqual(3, $visti, 'admin/parziali/ dovrebbe avere almeno tre file');
    }

    /** documentazione/inventario_accessi.md è generato dalla mappa: se la mappa cambia e il documento no, la prova dice di rilanciare lo script. */
    public function testIlDocumentoDegliAccessiEAggiornato(): void
    {
        $script = dirname(self::CARTELLA) . '/strumenti/mappa_accessi.php';
        $uscita = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --controlla 2>&1', $uscita, $codice);
        $this->assertSame(0, $codice, implode("
", $uscita));
    }

    public function testUnaPaginaFuoriMappaNonHaUnEsito(): void
    {
        $this->assertNull(PermessiPagine::consente('pagina_nuova.php', ['is_full_admin' => true]));
        $this->assertSame('scheda', PermessiPagine::tipo('didattica_sedute.php'));
        $this->assertSame('fuori', PermessiPagine::tipo('cron_backup.php'));
    }

    /** Le pagine aperte da ciascun tipo di utente (le altre pagine d'ingresso devono dare «no»). */
    private static function aperte(array $permessiVeri): array
    {
        $permessi = array_fill_keys(self::nomiPermessi(), false);
        foreach ($permessiVeri as $nome) {
            self::assertArrayHasKey($nome, $permessi, "permesso sconosciuto $nome");
            $permessi[$nome] = true;
        }
        $aperte = [];
        foreach (array_keys(PermessiPagine::pagine()) as $p) {
            if (PermessiPagine::consente($p, $permessi) === true) {
                $aperte[] = $p;
            }
        }
        sort($aperte);

        return $aperte;
    }

    /** Gli ingressi del pannello: aperti a chi ha almeno un modulo o un'area (permesso «ha_sezioni»); stampa_qr_aula.php resta aperta a chiunque. */
    private const SENZA_REGOLA = ['aree.php', 'cerca.php', 'dashboard.php', 'index.php', 'inizio.php', 'stampa_qr_aula.php'];

    public function testUtenteSenzaPermessiApreSoloStampaQrAula(): void
    {
        // Senza moduli né aree: gli ingressi del pannello non si aprono (admin_header.php rimanda all'area personale, niente 403)
        $this->assertSame(['stampa_qr_aula.php'], self::aperte([]));
        foreach (['aree.php', 'cerca.php', 'dashboard.php', 'index.php', 'inizio.php'] as $ingresso) {
            $this->assertFalse(PermessiPagine::consente($ingresso, ['ha_sezioni' => false]));
            $this->assertTrue(PermessiPagine::rimandaAllAreaPersonale($ingresso), "$ingresso: senza sezioni deve rimandare all'area personale");
        }
        $this->assertFalse(PermessiPagine::rimandaAllAreaPersonale('stampa_qr_aula.php'));
        $this->assertFalse(PermessiPagine::rimandaAllAreaPersonale('utenti.php'), 'le altre pagine negate restano un 403');
    }

    public function testChiHaUnaSezioneApreGliIngressiDelPannello(): void
    {
        $this->assertSame(self::SENZA_REGOLA, self::aperte(['ha_sezioni']));
    }

    public function testGestoreDiUnAreaEventiLavoraNellaSuaAreaMaNonNelPortale(): void
    {
        // Come in admin_header.php per chi gestisce tutta un'area: tutto ciò che sta dentro l'area, niente moduli né sistema
        $aperte = self::aperte(['ha_sezioni', 'is_area_manager', 'can_manage_eventi', 'can_manage_iscritti', 'can_manage_sondaggi', 'can_manage_form', 'can_manage_settings']);
        $attese = array_merge(self::SENZA_REGOLA, [
            'archivio.php', 'eventi.php', 'progetti.php', 'form_builder.php', 'impostazioni_area.php', 'iscritti.php', 'partecipanti.php', 'scanner.php',
            'stampa_badge.php', 'stampa_lista_iscritti.php', 'statistiche.php', 'sondaggi.php', 'prenotazioni_risorse.php', 'risorse.php',
        ]);
        sort($attese);
        $this->assertSame($attese, $aperte);
        foreach (['utenti.php', 'sistema.php', 'menu.php', 'didattica.php', 'fsl.php', 'email.php', 'forms.php', 'amministrazione.php'] as $negata) {
            $this->assertNotContains($negata, $aperte);
        }
    }

    public function testAbilitatoDidatticaApreLaDidatticaMaNonIlSistema(): void
    {
        // ha_modulo('didattica'): Didattica intera, tutorato, convenzioni di tesi, tirocini di Scienze motorie (come in admin_header.php)
        $aperte = self::aperte(['ha_sezioni', 'puo_didattica', 'puo_didattica_tutto', 'puo_tutorato', 'puo_conv_tesi', 'puo_tir_motorie']);
        $attese = array_merge(self::SENZA_REGOLA, ['didattica.php', 'sostituzioni.php', 'tirocini.php', 'uffici.php', 'convenzioni_tesi.php', 'tirocini_motorie.php', 'tutorato.php', 'email.php']);
        sort($attese);
        $this->assertSame($attese, $aperte);
        foreach (['sistema.php', 'utenti.php', 'fsl.php', 'scuole.php', 'iscritti.php', 'eventi.php', 'assistenza.php', 'forms.php'] as $negata) {
            $this->assertNotContains($negata, $aperte);
        }
    }

    public function testAmministratoreApreTutteLePagine(): void
    {
        $tutte = array_keys(PermessiPagine::pagine());
        sort($tutte);
        $this->assertSame($tutte, self::aperte(self::nomiPermessi()));
    }

    public function testIPermessiCheCostanoUnaQueryVengonoCalcolatiSoloSeServono(): void
    {
        $chiamate = 0;
        $permessi = ['puo_orientamento' => function () use (&$chiamate): bool { $chiamate++; return true; }, 'ha_caselle' => function () use (&$chiamate): bool { $chiamate++; return false; }];
        $this->assertTrue(PermessiPagine::consente('utenti.php', ['is_full_admin' => true] + $permessi));
        $this->assertSame(0, $chiamate, 'una pagina che non li chiede non deve calcolarli');
        $this->assertTrue(PermessiPagine::consente('report_ambiti.php', $permessi));
        $this->assertFalse(PermessiPagine::consente('messaggi.php', $permessi));
        $this->assertSame(2, $chiamate);
    }
}
