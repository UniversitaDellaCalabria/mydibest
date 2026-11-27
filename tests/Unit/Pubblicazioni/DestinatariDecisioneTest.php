<?php

declare(strict_types=1);

namespace Tests\Unit\Pubblicazioni;

use App\Pubblicazioni\NotifichePubblicazioni;
use PHPUnit\Framework\TestCase;

/**
 * Chi riceve un'email dopo che una richiesta di pubblicazione è stata approvata o respinta (NotifichePubblicazioni::destinatariDecisione), senza database:
 * le liste, la persona che sta in più liste (una email sola) e chi non ha un indirizzo (saltato).
 */
final class DestinatariDecisioneTest extends TestCase
{
    /** @return array{persona_id: string, nome: string, email: string} */
    private static function persona(string $id, string $email): array
    {
        return ['persona_id' => $id, 'nome' => $id, 'email' => $email];
    }

    /** @return array<string, list<array{email: string, nome?: string, persona_id?: string}>> */
    private static function liste(): array
    {
        return [
            'richiedente' => [self::persona('P-DOC', 'maria.rossi@unical.it')],
            'approvatore' => [self::persona('P-DIR', 'dario@unical.it'), self::persona('P-FLO', 'floriana@unical.it')],
            'ufficio' => [self::persona('P-UFF', 'silvana@unical.it')],
            'caselle' => [['email' => 'comunicazione.dibest@unical.it']],
            'conoscenza' => [self::persona('P-CON', 'anna@unical.it')],
        ];
    }

    /** @param list<array{gruppo: string, email: string, nome: string}> $d @return array<string, string> indirizzo => gruppo */
    private static function mappa(array $d): array
    {
        return array_column($d, 'gruppo', 'email');
    }

    public function testApprovataVaAOgniListaTranneAChiHaDeciso(): void
    {
        $d = NotifichePubblicazioni::destinatariDecisione(true, self::liste(), 'P-DIR');

        self::assertSame([
            'silvana@unical.it' => 'ufficio',
            'comunicazione.dibest@unical.it' => 'caselle',
            'maria.rossi@unical.it' => 'richiedente',
            'floriana@unical.it' => 'approvatore',
            'anna@unical.it' => 'conoscenza',
        ], self::mappa($d));
    }

    public function testRespintaVaSoloAChiHaInviatoEAgliAltriApprovatori(): void
    {
        $d = NotifichePubblicazioni::destinatariDecisione(false, self::liste(), 'P-FLO');

        self::assertSame(['maria.rossi@unical.it' => 'richiedente', 'dario@unical.it' => 'approvatore'], self::mappa($d));
    }

    public function testChiStaInPiuListeRiceveUnaSolaEmail(): void
    {
        $liste = self::liste();
        // Floriana approva (non è chi decide), lavora ed è informata; Silvana è anche la casella (maiuscole diverse) ed è informata con un altro indirizzo
        $liste['ufficio'][] = self::persona('P-FLO', 'floriana@unical.it');
        $liste['conoscenza'][] = self::persona('P-FLO', 'floriana@unical.it');
        $liste['caselle'][] = ['email' => 'Silvana@Unical.it'];
        $liste['conoscenza'][] = self::persona('P-UFF', 'silvana.privata@unical.it');
        // chi ha inviato è anche informato, con l'indirizzo dell'anagrafe diverso da quello del suo account
        $liste['conoscenza'][] = self::persona('P-DOC', 'm.rossi@unical.it');
        // un approvatore che ha inviato lui la richiesta
        $liste['approvatore'][] = self::persona('P-DOC', 'maria.rossi@unical.it');

        $d = NotifichePubblicazioni::destinatariDecisione(true, $liste, 'P-DIR');

        self::assertSame([
            'silvana@unical.it' => 'ufficio',
            'floriana@unical.it' => 'ufficio',
            'comunicazione.dibest@unical.it' => 'caselle',
            'maria.rossi@unical.it' => 'richiedente',
            'anna@unical.it' => 'conoscenza',
        ], self::mappa($d));
        self::assertCount(5, $d, 'nessuna riga in più');
    }

    public function testChiHaDecisoRestaNelleAltreListe(): void
    {
        $liste = self::liste();
        $liste['ufficio'][] = self::persona('P-DIR', 'dario@unical.it');

        $d = NotifichePubblicazioni::destinatariDecisione(true, $liste, 'P-DIR');

        self::assertSame('ufficio', self::mappa($d)['dario@unical.it'], 'da chi lavora lo riceve; come approvatore no');
        self::assertCount(1, array_filter($d, static fn (array $x): bool => $x['email'] === 'dario@unical.it'));
    }

    public function testSenzaUnIndirizzoValidoLaPersonaSiSalta(): void
    {
        $liste = self::liste();
        $liste['richiedente'] = [self::persona('P-DOC', '')];
        $liste['ufficio'] = [self::persona('P-UFF', 'non un indirizzo'), ['persona_id' => 'P-ALT', 'nome' => 'senza email']];
        // la stessa persona di chi ha inviato, in un'altra lista con un indirizzo buono: la riceve da lì
        $liste['conoscenza'][] = self::persona('P-DOC', 'm.rossi@unical.it');

        $d = NotifichePubblicazioni::destinatariDecisione(true, $liste, 'P-DIR');

        self::assertSame([
            'comunicazione.dibest@unical.it' => 'caselle',
            'floriana@unical.it' => 'approvatore',
            'anna@unical.it' => 'conoscenza',
            'm.rossi@unical.it' => 'conoscenza',
        ], self::mappa($d));
    }

    public function testListeVuoteNessunDestinatario(): void
    {
        self::assertSame([], NotifichePubblicazioni::destinatariDecisione(true, [], ''));
    }
}
