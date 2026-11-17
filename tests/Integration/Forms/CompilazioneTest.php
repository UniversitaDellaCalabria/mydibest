<?php

declare(strict_types=1);

namespace Tests\Integration\Forms;

use App\Auth\LimiteRichieste;
use App\Auth\RateLimitRepository;
use App\Core\Orologio;
use App\Forms\ControlliAnonimi;
use App\Forms\FormRepository;
use App\Forms\RispostaRepository;
use App\Forms\ServizioForms;
use App\Iscrizioni\CaptchaPrenotazione;
use DateTimeImmutable;
use ReflectionMethod;
use Tests\Doppi\AuthSessioneInMemoria;

/** MyForms, lato compilazione: elenco dei form aperti per una persona, le sue risposte (anche nei form anonimi), motivi del «no», controlli di chi non ha fatto l'accesso. */
final class CompilazioneTest extends FormsBase
{
    protected function tabelle(): array
    {
        return array_merge(parent::tabelle(), ['rate_limit_attempts' => \App\Core\Schema\Definizioni::tabelle()['rate_limit_attempts']]);
    }

    private function utente(int $id): array
    {
        return $this->db->riga('SELECT * FROM utenti WHERE id = ?', [$id]);
    }

    private function sv(): ServizioForms
    {
        return $this->servizio(ServizioForms::class);
    }

    private function riga(int $id): array
    {
        return $this->servizio(FormRepository::class)->perId($id);
    }

    /** Un form già aperto. @param list<array<string, mixed>> $campi @param array<string, mixed> $opzioni */
    private function formAperto(string $titolo, array $opzioni = [], array $campi = [['etichetta' => 'Note', 'tipo' => 'text']]): int
    {
        $id = $this->form($titolo, $campi, $opzioni);
        $this->sv()->apri($id);

        return $id;
    }

    /** @param list<array{form: array<string, mixed>, risposta: array<string, mixed>|null}> $elenco @return list<string> */
    private function titoli(array $elenco): array
    {
        $t = array_map(static fn (array $x): string => (string) $x['form']['titolo'], $elenco);
        sort($t);

        return $t;
    }

    public function testStatoPerPersonaDaIlMotivoGiusto(): void
    {
        $sv = $this->sv();

        $bozza = $this->form('In bozza', [['etichetta' => 'Note', 'tipo' => 'text']]);
        self::assertSame(ServizioForms::CHIUSO, $sv->statoPerPersona($this->riga($bozza), $this->utente(2))['codice']);

        $chiuso = $this->formAperto('Chiuso');
        $sv->chiudi($chiuso);
        $s = $sv->statoPerPersona($this->riga($chiuso), $this->utente(2));
        self::assertSame(ServizioForms::CHIUSO, $s['codice']);
        self::assertStringContainsString('chiuso', $s['motivo']);

        $fuori = $this->formAperto('Fuori periodo');
        $sv->modifica($fuori, $this->datiModifica($this->riga($fuori), ['aperta_al' => '2026-11-01 00:00:00']));
        $s = $sv->statoPerPersona($this->riga($fuori), $this->utente(2));
        self::assertSame(ServizioForms::CHIUSO, $s['codice']);
        self::assertStringContainsString('01/11/2026', $s['motivo'], 'il messaggio dice la data');

        $pieno = $this->formAperto('Pieno', ['max_risposte' => 1, 'una_per_persona' => 0]);
        self::assertTrue($sv->salvaRisposta($this->riga($pieno), $this->utente(4), ['campo_c1' => 'x'], [], '1.1.1.1')['ok']);
        self::assertSame(ServizioForms::PIENO, $sv->statoPerPersona($this->riga($pieno), $this->utente(2))['codice']);

        $studenti = $this->formAperto('Studenti', ['pubblico_json' => json_encode(['profili' => ['studenti']])]);
        self::assertSame(ServizioForms::PROFILO, $sv->statoPerPersona($this->riga($studenti), $this->utente(3))['codice'], 'un docente non è nel pubblico degli studenti');
        self::assertSame(ServizioForms::PROFILO, $sv->statoPerPersona($this->riga($studenti), null)['codice'], 'senza accesso: serve accedere');
        self::assertSame(ServizioForms::OK, $sv->statoPerPersona($this->riga($studenti), $this->utente(2))['codice']);

        self::assertTrue($sv->salvaRisposta($this->riga($studenti), $this->utente(2), ['campo_c1' => 'x'], [], '1.1.1.1')['ok']);
        self::assertSame(ServizioForms::GIA, $sv->statoPerPersona($this->riga($studenti), $this->utente(2))['codice']);
        [$ok, $motivo] = $sv->puoRispondere($this->riga($studenti), $this->utente(2));
        self::assertFalse($ok);
        self::assertSame('Hai già risposto a questo modulo.', $motivo, 'puoRispondere dice lo stesso di sempre');
    }

    public function testApertiPerMostraSoloQuelliAperti(): void
    {
        $sv = $this->sv();
        $this->form('Bozza', [['etichetta' => 'N', 'tipo' => 'text']]);
        $chiuso = $this->formAperto('Chiuso');
        $sv->chiudi($chiuso);
        $scaduto = $this->formAperto('Scaduto');
        $sv->modifica($scaduto, $this->datiModifica($this->riga($scaduto), ['aperta_al' => '2026-11-10 00:00:00']));
        $futuro = $this->formAperto('Non ancora');
        $sv->modifica($futuro, $this->datiModifica($this->riga($futuro), ['aperta_dal' => '2026-12-01 00:00:00']));
        $this->formAperto('Pieno', ['max_risposte' => 1, 'una_per_persona' => 0]);
        $pieno = (int) $this->db->valore("SELECT id FROM forms WHERE titolo = 'Pieno'");
        $sv->salvaRisposta($this->riga($pieno), $this->utente(4), ['campo_c1' => 'x'], [], '1.1.1.1');
        $this->formAperto('Aperto a tutti i registrati');
        $dentro = $this->formAperto('Nel periodo');
        $sv->modifica($dentro, $this->datiModifica($this->riga($dentro), ['aperta_dal' => '2026-11-01 00:00:00', 'aperta_al' => '2026-12-01 00:00:00']));

        self::assertSame(['Aperto a tutti i registrati', 'Nel periodo'], $this->titoli($sv->apertiPer($this->utente(2))));
    }

    public function testApertiPerSegueIlProfiloDiChiGuarda(): void
    {
        $sv = $this->sv();
        $this->formAperto('Per studenti', ['pubblico_json' => json_encode(['profili' => ['studenti']])]);
        $this->formAperto('Per chiunque', ['pubblico_json' => json_encode(['profili' => ['anonimi']])]);
        $this->formAperto('Per chi ha fatto accesso', ['pubblico_json' => json_encode(['profili' => ['registrati']])]);
        $this->formAperto('Per nessuno', ['pubblico_json' => json_encode(['profili' => []])]);

        self::assertSame(['Per chi ha fatto accesso', 'Per chiunque', 'Per studenti'], $this->titoli($sv->apertiPer($this->utente(2))), 'studente');
        self::assertSame(['Per chi ha fatto accesso', 'Per chiunque'], $this->titoli($sv->apertiPer($this->utente(3))), 'chi non è studente non vede quello per studenti');
        self::assertSame(['Per chiunque'], $this->titoli($sv->apertiPer(null)), 'senza accesso solo quelli aperti a chiunque');
        self::assertSame(['Per chi ha fatto accesso', 'Per chiunque', 'Per nessuno', 'Per studenti'], $this->titoli($sv->apertiPer($this->utente(1))), "l'amministratore li vede tutti");
    }

    public function testApertiPerDiceSeHaGiaRispostoENonNascondeIlForm(): void
    {
        $sv = $this->sv();
        $id = $this->formAperto('Una per persona', ['una_per_persona' => 1]);
        $altro = $this->formAperto('Mai compilato');
        $esito = $sv->salvaRisposta($this->riga($id), $this->utente(2), ['campo_c1' => 'x'], [], '1.1.1.1');
        self::assertTrue($esito['ok']);

        $per = [];
        foreach ($sv->apertiPer($this->utente(2)) as $x) {
            $per[$x['form']['titolo']] = $x['risposta'];
        }
        self::assertNotNull($per['Una per persona']);
        self::assertSame($esito['id'], (int) $per['Una per persona']['id']);
        self::assertNull($per['Mai compilato']);
        self::assertSame($altro, (int) $this->db->valore("SELECT id FROM forms WHERE titolo = 'Mai compilato'"));

        // un'altra persona non ha risposto a nulla
        foreach ($sv->apertiPer($this->utente(4)) as $x) {
            self::assertNull($x['risposta']);
        }
    }

    public function testRispostaDiRiconoscePerImprontaAncheNeiFormAnonimi(): void
    {
        $sv = $this->sv();
        $id = $this->formAperto('Anonimo', ['anonima' => 1, 'una_per_persona' => 1]);
        $esito = $sv->salvaRisposta($this->riga($id), $this->utente(2), ['campo_c1' => 'x'], [], '1.1.1.1');
        $riga = $this->servizio(RispostaRepository::class)->perId($esito['id']);
        self::assertNull($riga['utente_id'], 'nei form anonimi utente_id non si scrive');

        $mia = $sv->rispostaDi($this->riga($id), $this->utente(2));
        self::assertNotNull($mia);
        self::assertSame($esito['id'], (int) $mia['id']);
        self::assertNull($sv->rispostaDi($this->riga($id), $this->utente(4)), "un'altra persona non vede la risposta altrui");
        self::assertNull($sv->rispostaDi($this->riga($id), null), 'senza accesso non si riconosce nessuno');
    }

    public function testRisposteDiUnaPersonaConImprontaEDataEModifica(): void
    {
        $sv = $this->sv();
        $a = $this->formAperto('Anonimo aperto', ['anonima' => 1, 'modificabile' => 1]);
        $b = $this->formAperto('Normale', ['modificabile' => 0]);
        $c = $this->formAperto('Poi chiuso', ['modificabile' => 1]);
        $daAltri = $this->formAperto('Solo di altri', ['una_per_persona' => 0]);
        $bozza = $this->form('Tornato in bozza', [['etichetta' => 'N', 'tipo' => 'text']]);
        foreach ([$a, $b, $c] as $id) {
            self::assertTrue($sv->salvaRisposta($this->riga($id), $this->utente(2), ['campo_c1' => 'x'], [], '1.1.1.1')['ok']);
        }
        self::assertTrue($sv->salvaRisposta($this->riga($daAltri), $this->utente(4), ['campo_c1' => 'x'], [], '1.1.1.1')['ok']);
        $sv->chiudi($c);

        $mie = [];
        foreach ($sv->risposteDi($this->utente(2)) as $x) {
            $mie[$x['form']['titolo']] = $x;
        }
        $titoli = array_keys($mie);
        sort($titoli);
        self::assertSame(['Anonimo aperto', 'Normale', 'Poi chiuso'], $titoli, 'anche la risposta al form anonimo, riconosciuta dall\'impronta');
        self::assertTrue($mie['Anonimo aperto']['modificabile'], 'modificabile, ancora aperto');
        self::assertFalse($mie['Normale']['modificabile'], 'il form non permette di modificare');
        self::assertFalse($mie['Poi chiuso']['modificabile'], 'il form è stato chiuso');
        self::assertNotEmpty($mie['Normale']['risposta']['codice_ricevuta']);
        self::assertNull($mie['Anonimo aperto']['risposta']['utente_id'], 'nel form anonimo non si scrive utente_id');

        self::assertSame([], $sv->risposteDi(null));
        self::assertCount(1, $sv->risposteDi($this->utente(4)), 'ognuno vede solo le sue');
        self::assertNull($sv->rispostaDi($this->riga($bozza), $this->utente(2)));
    }

    public function testRisposteDiNonMostraIFormTornatiInBozza(): void
    {
        $sv = $this->sv();
        $id = $this->formAperto('Poi in bozza');
        self::assertTrue($sv->salvaRisposta($this->riga($id), $this->utente(2), ['campo_c1' => 'x'], [], '1.1.1.1')['ok']);
        $this->db->esegui("UPDATE forms SET stato = 'bozza' WHERE id = ?", [$id]);
        self::assertSame([], $sv->risposteDi($this->utente(2)));
    }

    public function testPuoModificareAncheSeIlFormEPieno(): void
    {
        $sv = $this->sv();
        $id = $this->formAperto('Pieno ma modificabile', ['max_risposte' => 1, 'modificabile' => 1, 'una_per_persona' => 1]);
        self::assertTrue($sv->salvaRisposta($this->riga($id), $this->utente(2), ['campo_c1' => 'x'], [], '1.1.1.1')['ok']);

        $form = $this->riga($id);
        self::assertSame(ServizioForms::PIENO, $sv->statoPerPersona($form, $this->utente(2))['codice']);
        $mia = $sv->rispostaDi($form, $this->utente(2));
        self::assertTrue($sv->puoModificare($form, $mia, $this->utente(2)), 'chi ha già risposto può correggere anche se il numero massimo è raggiunto');
        self::assertFalse($sv->puoModificare($form, $mia, $this->utente(4)), 'ma non un altro');
        self::assertFalse($sv->puoModificare($form, $mia, null));
        $esito = $sv->modificaRisposta($form, $mia, $this->utente(2), ['campo_c1' => 'y'], []);
        self::assertTrue($esito['ok']);
    }

    public function testPuoModificareFinisceConLaChiusura(): void
    {
        $sv = $this->sv();
        $id = $this->formAperto('Si chiude', ['modificabile' => 1]);
        self::assertTrue($sv->salvaRisposta($this->riga($id), $this->utente(2), ['campo_c1' => 'x'], [], '1.1.1.1')['ok']);
        $mia = $sv->rispostaDi($this->riga($id), $this->utente(2));
        self::assertTrue($sv->puoModificare($this->riga($id), $mia, $this->utente(2)));
        $sv->chiudi($id);
        self::assertFalse($sv->puoModificare($this->riga($id), $mia, $this->utente(2)));
        self::assertFalse($sv->modificaRisposta($this->riga($id), $mia, $this->utente(2), ['campo_c1' => 'y'], [])['ok']);
    }

    public function testProfiliInParole(): void
    {
        $sv = $this->sv();
        $id = $this->formAperto('Parole', ['pubblico_json' => json_encode(['profili' => ['docenti', 'studenti']])]);
        self::assertSame('Studenti, Docenti', $sv->profiliInParole($this->riga($id)), 'nell\'ordine dei pannelli, in italiano');
        $vuoto = $this->formAperto('Nessuno', ['pubblico_json' => json_encode(['profili' => []])]);
        self::assertSame('', $sv->profiliInParole($this->riga($vuoto)));
    }

    public function testIlMotivoDiUnFormChiusoDiceCheEChiuso(): void
    {
        $sv = $this->sv();
        $id = $this->formAperto('Chiuso a mano');
        $sv->chiudi($id);
        self::assertSame('Questo modulo è chiuso: non accetta più risposte.', $sv->puoRispondere($this->riga($id), $this->utente(2))[1]);
    }

    public function testUnInvioConErroriNonLasciaGliAllegatiSulServer(): void
    {
        $sv = $this->sv();
        $dir = dirname(__DIR__, 3) . '/' . $this->cartella;
        @mkdir($dir, 0777, true);
        $nuovo = $dir . 'nuovo.pdf';
        $vecchio = $dir . 'vecchio.pdf';
        file_put_contents($nuovo, 'x');
        file_put_contents($vecchio, 'y');
        $m = new ReflectionMethod(ServizioForms::class, 'scartaAllegati');
        $m->setAccessible(true);

        $m->invoke($sv, [['etichetta' => 'A', 'file' => $this->cartella . 'nuovo.pdf'], ['etichetta' => 'B', 'file' => $this->cartella . 'vecchio.pdf'], ['etichetta' => 'C', 'valore' => 'solo testo']], [['etichetta' => 'B', 'file' => $this->cartella . 'vecchio.pdf']]);

        self::assertFileDoesNotExist($nuovo, 'il file caricato per un invio non riuscito viene tolto');
        self::assertFileExists($vecchio, 'quello già salvato con la risposta precedente resta');
        @unlink($vecchio);
    }

    // ── Controlli di chi non ha fatto l'accesso ──

    /** @return array{0: ControlliAnonimi, 1: CaptchaPrenotazione, 2: AuthSessioneInMemoria, 3: object} */
    private function controlli(): array
    {
        $sessione = new AuthSessioneInMemoria();
        $orologio = new class () implements Orologio {
            public int $secondi = 0;

            public function adesso(): DateTimeImmutable
            {
                return (new DateTimeImmutable('2026-11-17 10:00:00'))->modify('+' . $this->secondi . ' seconds');
            }
        };
        $captcha = new CaptchaPrenotazione($sessione, $orologio);

        return [new ControlliAnonimi($captcha, new LimiteRichieste(new RateLimitRepository($this->db))), $captcha, $sessione, $orologio];
    }

    /** La risposta giusta alla domanda «Quanto fa a + b?» appena generata. */
    private function risposta(array $domanda): string
    {
        preg_match('/(\d+) \+ (\d+)/', $domanda['domanda'], $m);

        return (string) ((int) $m[1] + (int) $m[2]);
    }

    public function testControlliAnonimiTrappolaDeiRobot(): void
    {
        [$c, $captcha, , $orologio] = $this->controlli();
        $d = $c->domanda();
        $orologio->secondi = 10;

        $err = $c->verifica(1, ['sito_web' => 'http://spam.it', 'captcha_id' => $d['id'], 'captcha_risposta' => $this->risposta($d)], '1.2.3.4');
        self::assertNotNull($err, 'il campo trappola compilato blocca anche con la risposta giusta');
    }

    public function testControlliAnonimiDomandaGiustaSbagliataOTroppoInFretta(): void
    {
        [$c, , , $orologio] = $this->controlli();

        $d = $c->domanda();
        self::assertNotNull($c->verifica(1, ['captcha_id' => $d['id'], 'captcha_risposta' => $this->risposta($d)], '1.2.3.4'), 'troppo in fretta (0 secondi)');

        $d2 = $c->domanda();
        $orologio->secondi = 10;
        self::assertNotNull($c->verifica(1, ['captcha_id' => $d2['id'], 'captcha_risposta' => '999'], '1.2.3.4'), 'risposta sbagliata');

        // la domanda vale una volta sola: dopo l'uso (giusto o no) bisogna ripartire
        self::assertNotNull($c->verifica(1, ['captcha_id' => $d2['id'], 'captcha_risposta' => $this->risposta($d2)], '1.2.3.4'), 'domanda già usata');
    }

    public function testControlliAnonimiPassaConLaRispostaGiusta(): void
    {
        [$c, , , $orologio] = $this->controlli();
        $d = $c->domanda();
        $orologio->secondi = 10;

        self::assertNull($c->verifica(1, ['captcha_id' => $d['id'], 'captcha_risposta' => $this->risposta($d), 'sito_web' => ''], '1.2.3.4'));
    }

    public function testControlliAnonimiLimitePerConnessioneEPerForm(): void
    {
        [$c] = $this->controlli();
        for ($i = 0; $i < ControlliAnonimi::MAX_INVII_ORA; ++$i) {
            $err = $c->verifica(5, ['captcha_id' => 'x', 'captcha_risposta' => '1'], '9.9.9.9');
            self::assertStringContainsString('domanda di controllo', (string) $err, 'finché si è sotto il limite si arriva alla domanda');
        }
        $err = $c->verifica(5, ['captcha_id' => 'x', 'captcha_risposta' => '1'], '9.9.9.9');
        self::assertStringContainsString('Troppi invii', (string) $err, 'oltre il limite: stop, anche prima della domanda');

        // un'altra connessione, o un altro form, non sono toccati
        self::assertStringContainsString('domanda di controllo', (string) $c->verifica(5, ['captcha_id' => 'x', 'captcha_risposta' => '1'], '8.8.8.8'));
        self::assertStringContainsString('domanda di controllo', (string) $c->verifica(6, ['captcha_id' => 'x', 'captcha_risposta' => '1'], '9.9.9.9'));
    }
}
