<?php

declare(strict_types=1);

namespace Tests\Unit\Forms;

use App\Forms\CostruttoreCampi;
use PHPUnit\Framework\TestCase;

/** Lettura dei campi dal costruttore condiviso del pannello (POST con prefisso): App\Forms\CostruttoreCampi::daPost(). */
final class CostruttoreCampiTest extends TestCase
{
    private const TIPI = ['text', 'textarea', 'radio', 'tabella'];

    public function testNessunCampoDaNull(): void
    {
        self::assertNull(CostruttoreCampi::daPost([], 'c', self::TIPI));
        self::assertNull(CostruttoreCampi::daPost(['c_etichetta' => ['', ' ']], 'c', self::TIPI));
    }

    public function testCampoSemplice(): void
    {
        $json = CostruttoreCampi::daPost([
            'c_etichetta' => ['Nome completo'], 'c_tipo' => ['text'], 'c_opzioni' => [''], 'c_obbl' => ['1'], 'c_aiuto' => [''],
        ], 'c', self::TIPI);
        $campi = json_decode((string) $json, true);
        self::assertSame([
            ['etichetta' => 'Nome completo', 'tipo' => 'text', 'opzioni' => '', 'obbligatorio' => true, 'aiuto' => ''],
        ], $campi);
    }

    public function testCondizioneEAutomazione(): void
    {
        $json = CostruttoreCampi::daPost([
            'c_etichetta' => ['Sei uno studente?', 'Matricola'], 'c_tipo' => ['radio', 'text'], 'c_opzioni' => ['Sì, No', ''], 'c_obbl' => ['0', '0'], 'c_aiuto' => ['', ''],
            'c_cond_campo' => ['', 'Sei uno studente?'], 'c_cond_op' => ['uguale', 'uguale'], 'c_cond_val' => ['', 'Sì'],
            'c_auto_campo' => ['', ''], 'c_auto_op' => ['uguale', 'uguale'], 'c_auto_val' => ['', ''], 'c_auto_imposta' => ['', ''],
        ], 'c', self::TIPI);
        $campi = json_decode((string) $json, true);
        self::assertArrayNotHasKey('cond', $campi[0]);
        self::assertSame(['campo' => 'Sei uno studente?', 'op' => 'uguale', 'valore' => 'Sì'], $campi[1]['cond']);
    }

    public function testPrefissoDiverso(): void
    {
        $json = CostruttoreCampi::daPost([
            'mf_etichetta' => ['Note'], 'mf_tipo' => ['textarea'], 'mf_opzioni' => [''], 'mf_obbl' => ['0'], 'mf_aiuto' => ['Scrivi qui'],
        ], 'mf', self::TIPI);
        $campi = json_decode((string) $json, true);
        self::assertSame('Note', $campi[0]['etichetta']);
        self::assertSame('Scrivi qui', $campi[0]['aiuto']);
    }

    public function testNessunCampoUfficio(): void
    {
        // Il costruttore condiviso non offre i campi "ufficio": la chiave non esiste mai nel risultato.
        $json = CostruttoreCampi::daPost(['c_etichetta' => ['Domanda'], 'c_tipo' => ['text'], 'c_opzioni' => [''], 'c_obbl' => ['0'], 'c_aiuto' => ['']], 'c', self::TIPI);
        $campi = json_decode((string) $json, true);
        self::assertArrayNotHasKey('ufficio', $campi[0]);
    }

    public function testTipoNonAmmessoDiventaText(): void
    {
        // 'docente' non è nell'elenco ammesso: anche forzando il POST (bypassando la tendina) il tipo salvato resta 'text'
        $json = CostruttoreCampi::daPost([
            'c_etichetta' => ['Relatore'], 'c_tipo' => ['docente'], 'c_opzioni' => [''], 'c_obbl' => ['0'], 'c_aiuto' => [''],
        ], 'c', self::TIPI);
        $campi = json_decode((string) $json, true);
        self::assertSame('text', $campi[0]['tipo']);
    }

    public function testTipoAmmessoRestaInvariato(): void
    {
        $json = CostruttoreCampi::daPost([
            'c_etichetta' => ['Note'], 'c_tipo' => ['textarea'], 'c_opzioni' => [''], 'c_obbl' => ['0'], 'c_aiuto' => [''],
        ], 'c', self::TIPI);
        $campi = json_decode((string) $json, true);
        self::assertSame('textarea', $campi[0]['tipo']);
    }

    public function testColonnaEsplicitaNonAmmessaDiventaTesto(): void
    {
        $json = CostruttoreCampi::daPost([
            'c_etichetta' => ['Esami'], 'c_tipo' => ['tabella'], 'c_opzioni' => ['Materia:docente'], 'c_obbl' => ['0'], 'c_aiuto' => [''],
        ], 'c', self::TIPI, ['testo', 'numero']);
        $campi = json_decode((string) $json, true);
        self::assertSame('Materia', $campi[0]['opzioni']);
    }

    public function testColonnaIndovinataDalNomeNonAmmessaDiventaTesto(): void
    {
        // Senza ":tipo" esplicito, CampiModulo::colonne() indovina il tipo dal nome ("Docente" -> 'docente'): va riportato a 'testo' comunque
        $json = CostruttoreCampi::daPost([
            'c_etichetta' => ['Esami'], 'c_tipo' => ['tabella'], 'c_opzioni' => ['Docente'], 'c_obbl' => ['0'], 'c_aiuto' => [''],
        ], 'c', self::TIPI, ['testo', 'numero']);
        $campi = json_decode((string) $json, true);
        self::assertSame('Docente', $campi[0]['opzioni']);
    }

    public function testColonnaAmmessaRestaInvariata(): void
    {
        $json = CostruttoreCampi::daPost([
            'c_etichetta' => ['Esami'], 'c_tipo' => ['tabella'], 'c_opzioni' => ['Punteggio:numero'], 'c_obbl' => ['0'], 'c_aiuto' => [''],
        ], 'c', self::TIPI, ['testo', 'numero']);
        $campi = json_decode((string) $json, true);
        self::assertSame('Punteggio:numero', $campi[0]['opzioni']);
    }

    public function testTipiColonnaAmmessiVuotoUsaTuttiQuelliDiTipiColonna(): void
    {
        // Senza il 4° parametro, tutti i tipi di CostruttoreCampi::TIPI_COLONNA restano ammessi (es. 'scelta')
        $json = CostruttoreCampi::daPost([
            'c_etichetta' => ['Esami'], 'c_tipo' => ['tabella'], 'c_opzioni' => ['Esito:scelta(Promosso|Bocciato)'], 'c_obbl' => ['0'], 'c_aiuto' => [''],
        ], 'c', self::TIPI);
        $campi = json_decode((string) $json, true);
        self::assertSame('Esito:scelta(Promosso|Bocciato)', $campi[0]['opzioni']);
    }

    public function testTipiAmmessiEscludeLeAnagrafiDiAteneo(): void
    {
        $tipi = CostruttoreCampi::tipiAmmessi();
        self::assertNotContains('corso_studio', $tipi);
        self::assertNotContains('insegnamento', $tipi);
        self::assertNotContains('insegnamento_ateneo', $tipi);
        self::assertNotContains('docente', $tipi);
        self::assertNotContains('anno_accademico', $tipi);
        self::assertNotContains('azienda_tesi', $tipi);
        self::assertContains('text', $tipi);
        self::assertContains('tabella', $tipi);
    }
}
