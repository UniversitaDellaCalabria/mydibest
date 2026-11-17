<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\GruppoAziende;
use App\Auth\GruppoScuole;
use App\Auth\Pubblico;
use PHPUnit\Framework\TestCase;

final class PubblicoTest extends TestCase
{
    public function testNonLoggatoESoloAnonimi(): void
    {
        self::assertSame([Pubblico::ANONIMI], Pubblico::profiliUtente(null));
        self::assertSame([Pubblico::ANONIMI], Pubblico::profiliUtente(['nome' => 'senza id']));
    }

    public function testRegistratoSenzaAltroProfiloEEsterno(): void
    {
        self::assertSame([Pubblico::REGISTRATI, Pubblico::ESTERNI], Pubblico::profiliUtente(['id' => 5, 'ruolo_id' => 5, 'ruoli_secondari' => '']));
    }

    public function testStudenteDaRuoloDaRuoloSecondarioODaMatricola(): void
    {
        self::assertSame([Pubblico::REGISTRATI, Pubblico::STUDENTI], Pubblico::profiliUtente(['id' => 3, 'ruolo_id' => 3]));
        self::assertSame([Pubblico::REGISTRATI, Pubblico::STUDENTI], Pubblico::profiliUtente(['id' => 9, 'ruolo_id' => 5, 'ruoli_secondari' => '3']));
        self::assertSame([Pubblico::REGISTRATI, Pubblico::STUDENTI], Pubblico::profiliUtente(['id' => 9, 'ruolo_id' => 5, 'matricola_studente' => '12345']));
        // la matricola vuota non basta (come !empty() di oggi)
        self::assertSame([Pubblico::REGISTRATI, Pubblico::ESTERNI], Pubblico::profiliUtente(['id' => 9, 'ruolo_id' => 5, 'matricola_studente' => '']));
    }

    public function testDocentePtaEAltroPersonaleDalGruppo(): void
    {
        $docenti = ['docenti'];
        self::assertSame([Pubblico::REGISTRATI, Pubblico::DOCENTI, Pubblico::PERSONALE], Pubblico::profiliUtente(['id' => 4, 'ruolo_id' => 5, 'ruoli_secondari' => '6'], $docenti));
        $pta = ['personale tecnico amministrativo'];
        self::assertSame([Pubblico::REGISTRATI, Pubblico::PTA, Pubblico::PERSONALE], Pubblico::profiliUtente(['id' => 6, 'ruolo_id' => 5, 'ruoli_secondari' => '7'], $pta));
        $altro = ['altro personale di ateneo'];
        self::assertSame([Pubblico::REGISTRATI, Pubblico::PERSONALE], Pubblico::profiliUtente(['id' => 7, 'ruolo_id' => 5, 'ruoli_secondari' => '8'], $altro));
    }

    public function testPersonaleDalRuoloODalRuoloSecondario(): void
    {
        self::assertSame([Pubblico::REGISTRATI, Pubblico::PERSONALE], Pubblico::profiliUtente(['id' => 8, 'ruolo_id' => 4]));
        self::assertSame([Pubblico::REGISTRATI, Pubblico::PERSONALE], Pubblico::profiliUtente(['id' => 9, 'ruolo_id' => 5, 'ruoli_secondari' => '4']));
    }

    public function testGruppiScuoleEAziende(): void
    {
        self::assertSame('Scuole', GruppoScuole::NOME);
        self::assertSame('Aziende', GruppoAziende::NOME);
        $gScuole = [mb_strtolower(GruppoScuole::NOME)];
        self::assertSame([Pubblico::REGISTRATI, Pubblico::SCUOLE, Pubblico::ESTERNI], Pubblico::profiliUtente(['id' => 10, 'ruolo_id' => 5, 'ruoli_secondari' => '9'], $gScuole));
        $gAziende = [mb_strtolower(GruppoAziende::NOME)];
        self::assertSame([Pubblico::REGISTRATI, Pubblico::AZIENDE, Pubblico::ESTERNI], Pubblico::profiliUtente(['id' => 11, 'ruolo_id' => 5, 'ruoli_secondari' => '10'], $gAziende));
    }

    public function testAmministratoreSempreAmmesso(): void
    {
        $regolaVuota = ['profili' => []];
        self::assertTrue(Pubblico::ammesso($regolaVuota, ['id' => 1, 'ruolo_id' => 1]));
        self::assertTrue(Pubblico::ammesso($regolaVuota, ['id' => 2, 'ruolo_id' => 5, 'ruoli_secondari' => '1']));
        self::assertFalse(Pubblico::ammesso($regolaVuota, ['id' => 3, 'ruolo_id' => 5]), 'nessuno senza i profili giusti, amministratori esclusi');
        self::assertFalse(Pubblico::ammesso($regolaVuota, null), 'non loggato non è amministratore');
    }

    public function testAmmessoPerProfiloSingoloONonAmmesso(): void
    {
        $soloStudenti = ['profili' => [Pubblico::STUDENTI]];
        self::assertTrue(Pubblico::ammesso($soloStudenti, ['id' => 3, 'ruolo_id' => 3]));
        self::assertFalse(Pubblico::ammesso($soloStudenti, ['id' => 4, 'ruolo_id' => 5, 'ruoli_secondari' => '6'], ['docenti']), 'un docente non è uno studente');
        self::assertFalse(Pubblico::ammesso($soloStudenti, null), 'non loggato non è mai uno studente');
    }

    public function testAnonimiAmmetteChiunqueAncheChiHaFattoLAccesso(): void
    {
        $soloAnonimi = ['profili' => [Pubblico::ANONIMI]];
        self::assertTrue(Pubblico::ammesso($soloAnonimi, null), 'non loggato');
        self::assertTrue(Pubblico::ammesso($soloAnonimi, ['id' => 3, 'ruolo_id' => 3]), 'studente');
        self::assertTrue(Pubblico::ammesso($soloAnonimi, ['id' => 4, 'ruolo_id' => 5, 'ruoli_secondari' => '6'], ['docenti']), 'docente');
        self::assertTrue(Pubblico::ammesso($soloAnonimi, ['id' => 9, 'ruolo_id' => 5]), 'esterno');
    }

    public function testTraduzioneDaiVecchiValoriDiModuliEDiRisorse(): void
    {
        self::assertSame(['profili' => [Pubblico::REGISTRATI]], Pubblico::daVecchio('tutti'));
        self::assertSame(['profili' => [Pubblico::STUDENTI]], Pubblico::daVecchio('studenti'));
        self::assertSame(['profili' => [Pubblico::DOCENTI]], Pubblico::daVecchio('docenti'));
        self::assertSame(['profili' => [Pubblico::PERSONALE]], Pubblico::daVecchio('personale'));
        // qualsiasi altro valore non riconosciuto: come 'tutti' (vedi il default del match di oggi)
        self::assertSame(['profili' => [Pubblico::REGISTRATI]], Pubblico::daVecchio(''));
        self::assertSame(['profili' => [Pubblico::REGISTRATI]], Pubblico::daVecchio('qualcosa'));
    }

    public function testTraduzioneDalRuoloAccessoIdDeiTurni(): void
    {
        // 0 = nessuna restrizione, ammesso anche senza accesso (vedi master_template.php e ServizioPrenotazioni)
        $daZero = Pubblico::daVecchio(0);
        self::assertSame(Pubblico::PROFILI, $daZero['profili']);
        self::assertTrue(Pubblico::ammesso($daZero, null), 'ruolo_accesso_id=0: ammesso anche senza accesso');

        // -1 = chiunque abbia fatto l'accesso
        $daMenoUno = Pubblico::daVecchio(-1);
        self::assertSame(['profili' => [Pubblico::REGISTRATI]], $daMenoUno);
        self::assertFalse(Pubblico::ammesso($daMenoUno, null));
        self::assertTrue(Pubblico::ammesso($daMenoUno, ['id' => 9, 'ruolo_id' => 5]));

        // un numero positivo: il vecchio confronto diretto sull'id del ruolo (gruppi creati a mano in Utenti)
        $daSette = Pubblico::daVecchio(7);
        self::assertSame(['profili' => [], 'ruolo_id' => 7], $daSette);
        self::assertTrue(Pubblico::ammesso($daSette, ['id' => 9, 'ruolo_id' => 7]));
        self::assertTrue(Pubblico::ammesso($daSette, ['id' => 9, 'ruolo_id' => 5, 'ruoli_secondari' => '6,7']));
        self::assertFalse(Pubblico::ammesso($daSette, ['id' => 9, 'ruolo_id' => 5, 'ruoli_secondari' => '6']));
        self::assertTrue(Pubblico::ammesso($daSette, ['id' => 1, 'ruolo_id' => 1]), 'amministratore sempre ammesso');

        // stringhe numeriche (come nei form): stesso comportamento degli interi
        self::assertSame($daZero, Pubblico::daVecchio('0'));
        self::assertSame($daMenoUno, Pubblico::daVecchio('-1'));
        self::assertSame($daSette, Pubblico::daVecchio('7'));
    }
}
