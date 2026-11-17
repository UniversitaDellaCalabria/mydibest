<?php

declare(strict_types=1);

namespace Tests\Unit\Forms;

use App\Core\Sito;
use App\Forms\NotificheForms;
use App\Infrastructure\Mail\Mailer;
use PHPUnit\Framework\TestCase;
use Tests\Doppi\MailerFinto;

/** Le email di MyForms dopo una risposta: ricevuta a chi risponde, avviso a chi gestisce (senza nomi nei form anonimi), errori che non si propagano. */
final class NotificheFormsTest extends TestCase
{
    private MailerFinto $mailer;
    private NotificheForms $notifiche;

    protected function setUp(): void
    {
        $this->mailer = new MailerFinto();
        $this->notifiche = new NotificheForms($this->mailer, new Sito('/non/serve', 'https://portale.test/mydibest'));
    }

    /** @param array<string, mixed> $sovrascrivi @return array<string, mixed> */
    private function form(array $sovrascrivi = []): array
    {
        return array_merge([
            'id' => 7, 'codice' => 'abc123def456', 'titolo' => 'Sondaggio  sulle aule', 'anonima' => 0, 'modificabile' => 0, 'ricevuta_email' => 1,
            'email_notifica' => 'responsabile@x.it; segreteria@x.it',
        ], $sovrascrivi);
    }

    /** @return array<string, mixed> */
    private function utente(): array
    {
        return ['id' => 2, 'nome' => 'Luca', 'cognome' => 'Studente', 'email' => 'luca@x.it'];
    }

    /** @return array<string, mixed> */
    private function risposta(): array
    {
        return ['id' => 55, 'codice_ricevuta' => '0123456789abcdef0123456789abcdef', 'inviata_il' => '2026-11-17 10:05:00'];
    }

    /** @return list<array{a: string, oggetto: string, corpo: string, colore: ?string, allegati: array<int, mixed>}> */
    private function aDestinatario(string $indirizzo): array
    {
        return array_values(array_filter($this->mailer->inviate, static fn (array $m): bool => $m['a'] === $indirizzo));
    }

    public function testRicevutaAChiHaFattoLAccesso(): void
    {
        $this->notifiche->rispostaInviata($this->form(['email_notifica' => '']), $this->utente(), $this->risposta());

        self::assertCount(1, $this->mailer->inviate);
        $m = $this->mailer->inviate[0];
        self::assertSame('luca@x.it', $m['a']);
        self::assertStringContainsString('Sondaggio sulle aule', $m['oggetto']);
        self::assertStringContainsString('0123456789abcdef0123456789abcdef', $m['corpo']);
        self::assertStringContainsString('17/11/2026 alle 10:05', $m['corpo']);
    }

    public function testNienteRicevutaSeIlFormNonLaPrevede(): void
    {
        $this->notifiche->rispostaInviata($this->form(['ricevuta_email' => 0, 'email_notifica' => '']), $this->utente(), $this->risposta());
        self::assertSame([], $this->mailer->inviate);
    }

    public function testNienteRicevutaSenzaAccessoOSenzaEmailValida(): void
    {
        $this->notifiche->rispostaInviata($this->form(['email_notifica' => '']), null, $this->risposta());
        $senzaEmail = $this->utente();
        $senzaEmail['email'] = 'non-una-email';
        $this->notifiche->rispostaInviata($this->form(['email_notifica' => '']), $senzaEmail, $this->risposta());
        self::assertSame([], $this->mailer->inviate, "per chi non ha fatto l'accesso la ricevuta non parte (l'email non è verificata)");
    }

    public function testNellaRicevutaIlLinkPerModificareSoloSeIlFormLoPermette(): void
    {
        $this->notifiche->rispostaInviata($this->form(['email_notifica' => '', 'modificabile' => 1]), $this->utente(), $this->risposta());
        $this->notifiche->rispostaInviata($this->form(['email_notifica' => '', 'modificabile' => 0]), $this->utente(), $this->risposta());

        self::assertStringContainsString('https://portale.test/mydibest/form.php?c=abc123def456', $this->mailer->inviate[0]['corpo']);
        self::assertStringNotContainsString('form.php?c=', $this->mailer->inviate[1]['corpo']);
    }

    public function testAvvisoAgliIndirizziDiNotificaConTitoloDataELink(): void
    {
        $this->notifiche->rispostaInviata($this->form(['ricevuta_email' => 0]), $this->utente(), $this->risposta());

        self::assertCount(2, $this->mailer->inviate);
        foreach (['responsabile@x.it', 'segreteria@x.it'] as $a) {
            $m = $this->aDestinatario($a)[0];
            self::assertStringContainsString('Sondaggio sulle aule', $m['oggetto']);
            self::assertStringContainsString('17/11/2026 alle 10:05', $m['corpo']);
            self::assertStringContainsString('https://portale.test/mydibest/admin/forms_risposte.php?id=7', $m['corpo']);
            self::assertStringContainsString('Luca Studente', $m['corpo'], 'in un form non anonimo l\'avviso dice chi ha risposto');
        }
    }

    public function testNeiFormAnonimiLAvvisoNonHaNomeNeEmail(): void
    {
        $this->notifiche->rispostaInviata($this->form(['anonima' => 1, 'ricevuta_email' => 0]), $this->utente(), $this->risposta());

        self::assertCount(2, $this->mailer->inviate);
        foreach ($this->mailer->inviate as $m) {
            self::assertStringNotContainsString('Luca', $m['corpo']);
            self::assertStringNotContainsString('Studente', $m['corpo']);
            self::assertStringNotContainsString('luca@x.it', $m['corpo']);
            self::assertStringNotContainsString('luca@x.it', $m['oggetto']);
            self::assertStringContainsString('anonima', $m['corpo']);
        }
    }

    public function testNeiFormAnonimiNienteRicevutaPerEmailNemmenoAChiHaFattoLAccesso(): void
    {
        // ogni email finisce nel registro log_email (destinatario, oggetto con il titolo): la ricevuta permetterebbe di abbinare la persona alla risposta anonima
        $this->notifiche->rispostaInviata($this->form(['anonima' => 1, 'ricevuta_email' => 1]), $this->utente(), $this->risposta());

        self::assertSame([], $this->aDestinatario('luca@x.it'), 'nessuna email al suo indirizzo');
        self::assertCount(2, $this->mailer->inviate, "l'avviso a chi gestisce parte lo stesso");
        foreach ($this->mailer->inviate as $m) {
            self::assertContains($m['a'], ['responsabile@x.it', 'segreteria@x.it']);
            foreach ([$m['oggetto'], $m['corpo']] as $testo) {
                self::assertStringNotContainsString('Luca', $testo);
                self::assertStringNotContainsString('Studente', $testo);
                self::assertStringNotContainsString('luca@x.it', $testo);
            }
        }
    }

    public function testTitoloEDatiVengonoEscapati(): void
    {
        $f = $this->form(['titolo' => '<script>alert(1)</script>', 'ricevuta_email' => 0]);
        $u = $this->utente();
        $u['nome'] = '<b>Ada</b>';
        $this->notifiche->rispostaInviata($f, $u, $this->risposta());

        foreach ($this->mailer->inviate as $m) {
            self::assertStringNotContainsString('<script>', $m['corpo']);
            self::assertStringNotContainsString('<b>Ada</b>', $m['corpo']);
        }
    }

    public function testIndirizziValidiSenzaRipetizioniEAlMassimoDieci(): void
    {
        self::assertSame(['a@x.it', 'b@x.it'], NotificheForms::indirizzi("a@x.it, B@x.it;\n a@x.it, non-valido, @x.it"));
        self::assertSame([], NotificheForms::indirizzi(''));
        $molti = implode(',', array_map(static fn (int $i): string => "p$i@x.it", range(1, 15)));
        self::assertCount(10, NotificheForms::indirizzi($molti));
    }

    public function testUnErroreDiInvioNonSiPropagaMaiENonFermaGliAltri(): void
    {
        $inviati = [];
        $rotto = new class ($inviati) implements Mailer {
            /** @param list<string> $inviati */
            public function __construct(public array &$inviati)
            {
            }

            public function invia(string $a, string $oggetto, string $corpoHtml, ?string $colore = null, array $allegati = []): bool
            {
                if ($a === 'luca@x.it') {
                    throw new \RuntimeException('server di posta spento');
                }
                $this->inviati[] = $a;

                return $a !== 'segreteria@x.it'; // un altro indirizzo risponde «non inviata»
            }

            public function ultimoErrore(): string
            {
                return 'rifiutata';
            }
        };
        $n = new NotificheForms($rotto, new Sito('/non/serve', 'https://portale.test/mydibest'));
        $vecchio = ini_set('error_log', sys_get_temp_dir() . '/prova_notifiche_forms.log');

        $n->rispostaInviata($this->form(), $this->utente(), $this->risposta()); // non deve lanciare nulla

        ini_set('error_log', (string) $vecchio);
        self::assertSame(['responsabile@x.it', 'segreteria@x.it'], $inviati, "l'errore sulla ricevuta non ferma l'avviso a chi gestisce");
    }
}
