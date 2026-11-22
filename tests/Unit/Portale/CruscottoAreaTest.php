<?php

declare(strict_types=1);

namespace Tests\Unit\Portale;

use App\Portale\Area\BarraTelefono;
use App\Portale\Area\FiltroProfilo;
use App\Portale\Area\PassiStudente;
use App\Portale\ProssimaCosa;
use PHPUnit\Framework\TestCase;

/** Le regole nuove del cruscotto dell'area personale (S13): la tessera «Il prossimo passo», il filtro dei profili, la barra del telefono, i passi nelle tessere. */
final class CruscottoAreaTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once RADICE_SITO . '/inc/ui.php';
    }

    private static function riga(string $testo, string $link, int $giorni = 0, ?string $scadenza = null): array
    {
        return ['testo' => $testo, 'dettaglio' => 'dettaglio ' . $testo, 'link' => $link, 'giorni' => $giorni] + ($scadenza !== null ? ['scadenza' => $scadenza] : []);
    }

    // ---- ProssimaCosa::tessera ----

    public function testTesseraSenzaCoseDaFareNonCompare(): void
    {
        $this->assertNull(ProssimaCosa::tessera([], '2026-11-22'));
        $this->assertNull(ProssimaCosa::tessera([['titolo' => 'Firme', 'n' => 0, 'righe' => []]], '2026-11-22'));
    }

    public function testTesseraSceglieLaScadenzaPiuVicinaECalcolaIlTotale(): void
    {
        $gruppi = [
            ['titolo' => 'Firme', 'n' => 7, 'righe' => [self::riga('Senza scadenza', 'a.php', 30), self::riga('Tra una settimana', 'b.php', 2, '2026-11-29')]],
            ['titolo' => 'Conferme', 'n' => 1, 'righe' => [self::riga('Domani', 'c.php', 1, '2026-11-23')]],
        ];
        $t = ProssimaCosa::tessera($gruppi, '2026-11-22');
        $this->assertNotNull($t);
        $this->assertSame('Domani', $t['testo']);
        $this->assertSame('c.php', $t['link']);
        $this->assertSame('Conferme', $t['gruppo']);
        $this->assertSame(8, $t['totale'], 'il totale è la somma dei numeri dei gruppi, non delle righe lette');
        $this->assertSame(['testo' => 'entro il 23/11', 'urgente' => true], $t['scadenza']);
    }

    public function testTesseraSenzaScadenzaPrendeQuellaCheAspettaDaPiuGiorni(): void
    {
        $t = ProssimaCosa::tessera([['titolo' => 'Firme', 'n' => 2, 'righe' => [self::riga('Recente', 'a.php', 1), self::riga('Vecchia', 'b.php', 20)]]], '2026-11-22');
        $this->assertSame('Vecchia', $t['testo']);
        $this->assertNull($t['scadenza']);
    }

    public function testScegliNonCambiaLeChiaviDiSempre(): void
    {
        $p = ProssimaCosa::scegli([['titolo' => 'F', 'righe' => [self::riga('A', 'a.php', 3, '2026-12-01')]]], 'altra.php');
        $this->assertSame('A', $p['testo']);
        $this->assertSame(0, $p['altre']);
        $this->assertSame('2026-12-01', $p['scadenza']);
    }

    // ---- FiltroProfilo ----

    public function testFiltroProfiloRicordaLUltimaScelta(): void
    {
        $profili = ['studente', 'docente'];
        $this->assertSame(['scelto' => 'docente', 'salva' => 'docente'], FiltroProfilo::scegli($profili, 'docente', null));
        $this->assertSame(['scelto' => 'docente', 'salva' => null], FiltroProfilo::scegli($profili, null, 'docente'), 'senza ?p= si riapplica la scelta ricordata');
        $this->assertSame(['scelto' => null, 'salva' => FiltroProfilo::TUTTO], FiltroProfilo::scegli($profili, FiltroProfilo::TUTTO, 'docente'), '«Tutto» è una scelta e si ricorda');
        $this->assertSame(['scelto' => null, 'salva' => null], FiltroProfilo::scegli($profili, null, FiltroProfilo::TUTTO));
    }

    public function testFiltroProfiloIgnoraValoriNonValidiEChiHaUnSoloProfilo(): void
    {
        $this->assertSame(['scelto' => null, 'salva' => null], FiltroProfilo::scegli(['studente', 'docente'], 'direttore', null));
        $this->assertSame(['scelto' => null, 'salva' => null], FiltroProfilo::scegli(['studente', 'docente'], null, 'direttore'), 'un profilo ricordato che non c\'è più si lascia perdere');
        $this->assertSame(['scelto' => null, 'salva' => null], FiltroProfilo::scegli(['studente'], 'studente', 'studente'));
    }

    // ---- BarraTelefono ----

    public function testBarraHaCinqueVociEINumeriSoloDoveServono(): void
    {
        $voci = BarraTelefono::voci(4, 2, 'admin/messaggi.php');
        $this->assertSame(['home', 'da_fare', 'calendario', 'messaggi', 'profilo'], array_column($voci, 'chiave'));
        $this->assertSame([0, 4, 0, 2, 0], array_column($voci, 'numero'));
        $this->assertSame('admin/messaggi.php', $voci[3]['href']);
        $this->assertSame(0, BarraTelefono::voci(-3, -1)[1]['numero'], 'mai numeri negativi');
    }

    public function testBarraVoceAttivaDallaPagina(): void
    {
        $this->assertSame('home', BarraTelefono::attiva('/mydibest/area_personale.php'));
        $this->assertSame('da_fare', BarraTelefono::attiva('/da_fare.php?g=firme'));
        $this->assertSame('calendario', BarraTelefono::attiva('calendario.php'));
        $this->assertSame('profilo', BarraTelefono::attiva('/profilo.php'));
        $this->assertSame('messaggi', BarraTelefono::attiva('/admin/messaggi.php', 'admin/messaggi.php'));
        $this->assertSame('messaggi', BarraTelefono::attiva('/miei_eventi.php', 'miei_eventi.php'));
        $this->assertSame('', BarraTelefono::attiva('/firme.php'));
    }

    public function testBarraMemoriaInSessioneConScadenza(): void
    {
        $s = [];
        $this->assertNull(BarraTelefono::letti($s, 1000));
        BarraTelefono::memorizza($s, 5, 3, 'admin/messaggi.php', 1000);
        $this->assertSame(['da_fare' => 5, 'messaggi' => 3, 'href' => 'admin/messaggi.php'], BarraTelefono::letti($s, 1000 + BarraTelefono::DURATA));
        $this->assertNull(BarraTelefono::letti($s, 1001 + BarraTelefono::DURATA), 'dopo qualche minuto i numeri non si mostrano più');
        $this->assertNull(BarraTelefono::letti($s, 900), 'un orologio indietro non fa fidare di numeri dal futuro');
    }

    public function testBarraHtmlAriaCurrentNumeriENomiPerLoScreenReader(): void
    {
        $html = BarraTelefono::html(BarraTelefono::voci(120, 0), 'da_fare');
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertMatchesRegularExpression('~<a href="da_fare.php" aria-current="page">~', $html);
        $this->assertStringContainsString('99+', $html);
        $this->assertStringContainsString('<span class="visually-hidden"> (120)</span>', $html);
        $this->assertStringContainsString('aria-label="Navigazione rapida"', $html);
        $this->assertStringContainsString('d-print-none', $html);
        $this->assertSame(5, substr_count($html, '<li>'));
        $this->assertStringNotContainsString('aria-current', BarraTelefono::html(BarraTelefono::voci(0, 0), ''));
    }

    public function testBarraNonCompareSenzaAccesso(): void
    {
        $prima = $_SESSION ?? null;
        $_SESSION = [];
        $this->assertSame('', ui_barra_telefono('area_personale.php'));
        $_SESSION = ['utente_id' => 7];
        $this->assertStringContainsString('ui-barra-tel', ui_barra_telefono('area_personale.php'));
        if ($prima === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $prima;
        }
    }

    // ---- PassiStudente ----

    public function testPassiDellaPratica(): void
    {
        $stati = static fn (array $r): array => array_column($r['passi'], 'stato');
        $this->assertSame(['corrente', 'da_fare', 'da_fare'], $stati(PassiStudente::pratica('bozza')));
        $this->assertSame(['fatto', 'corrente', 'da_fare'], $stati(PassiStudente::pratica('inviata')));
        $this->assertSame(['fatto', 'corrente', 'da_fare'], $stati(PassiStudente::pratica('in_lavorazione')));
        $this->assertSame(['fatto', 'fatto', 'fatto'], $stati(PassiStudente::pratica('accolta')));
        $this->assertSame(-1, PassiStudente::pratica('respinta')['corrente']);
        $integr = PassiStudente::pratica('integrazione');
        $this->assertSame(['fatto', 'bloccato', 'da_fare'], $stati($integr));
        $this->assertSame('serve la tua integrazione', $integr['passi'][1]['nota']);
    }

    public function testPassiDellaTesiPartonoDaRichiesta(): void
    {
        $senza = PassiStudente::tesi(null);
        $this->assertSame('Richiesta', $senza['passi'][0]['titolo']);
        $this->assertSame(0, $senza['corrente']);
        $this->assertSame('da fare', $senza['passi'][0]['nota']);
        $this->assertSame(1, PassiStudente::tesi('inviata')['corrente']);
    }

    public function testPassiDelTutoratoConTitoliBrevi(): void
    {
        $tappe = [['Incarico accettato e dichiarazione', true, ''], ['Lettera firmata dal docente e dal Direttore', true, 'protocollo 1'], ['Registro delle presenze', false, ''], ['Fine attività firmata dal docente titolare', false, '']];
        $r = PassiStudente::tutorato($tappe);
        $this->assertSame(['Accettato', 'Lettera firmata', 'Registro', 'Fine attività'], array_column($r['passi'], 'titolo'));
        $this->assertSame(['fatto', 'fatto', 'corrente', 'da_fare'], array_column($r['passi'], 'stato'));
        $this->assertSame(2, $r['corrente']);
        $html = ui_passi($r['passi'], $r['corrente'], 'piccolo');
        $this->assertStringContainsString('ui-percorso-piccolo', $html);
        $this->assertSame(1, substr_count($html, 'aria-current="step"'));
    }
}
