<?php

declare(strict_types=1);

namespace Tests\Unit\Pubblicazioni;

use App\Pubblicazioni\ApprovazionePubblicazioni;
use App\Pubblicazioni\Costanti;
use App\Pubblicazioni\PersonePubblicazioni;
use App\Pubblicazioni\ServizioPubblicazioni;
use PHPUnit\Framework\TestCase;

/** P2: chi può approvare (solo l'account collegato alla persona), lo stato con cui nasce la richiesta, il motivo obbligatorio, gli stati nuovi. Senza database. */
final class ApprovazioneRegoleTest extends TestCase
{
    public function testPuoApprovareChiHaUnAccountCollegatoAUnaPersonaDellaLista(): void
    {
        $approvatori = ['P-100', 'P-200'];
        $this->assertTrue(PersonePubblicazioni::collegata(['id' => 7, 'persona_id' => 'P-200'], $approvatori), 'account collegato a una persona della lista');
        $this->assertFalse(PersonePubblicazioni::collegata(['id' => 8, 'persona_id' => 'P-999'], $approvatori), 'account collegato a un\'altra persona');
        $this->assertFalse(PersonePubblicazioni::collegata(['id' => 9, 'persona_id' => null], $approvatori), 'account non collegato a nessuna persona');
        $this->assertFalse(PersonePubblicazioni::collegata(['id' => 9, 'persona_id' => ''], $approvatori), 'persona_id vuoto non vale mai');
        $this->assertFalse(PersonePubblicazioni::collegata(['id' => 9], $approvatori));
        $this->assertFalse(PersonePubblicazioni::collegata(null, $approvatori), 'senza accesso');
        $this->assertFalse(PersonePubblicazioni::collegata(['persona_id' => 'P-100'], $approvatori), 'senza id utente');
        $this->assertFalse(PersonePubblicazioni::collegata(['id' => 7, 'persona_id' => 'P-100'], []), 'lista vuota: nessuno approva');
    }

    public function testUnaSolaEmailNonDaIlPermesso(): void
    {
        // l'email dell'account (anche se è proprio quella dell'approvatore) non conta: si guarda solo persona_id
        $this->assertFalse(PersonePubblicazioni::collegata(['id' => 7, 'persona_id' => null, 'email' => 'direttore@unical.it', 'email_personalizzata' => 1], ['P-100']));
    }

    public function testApprovazioneSpentaOSenzaApprovatoriLaRichiestaNasceRicevuta(): void
    {
        $this->assertSame('ricevuta', ServizioPubblicazioni::statoPer(false, 3), 'approvazione spenta: come prima');
        $this->assertSame('ricevuta', ServizioPubblicazioni::statoPer(true, 0), 'accesa ma nessun approvatore con account: non resta in attesa di nessuno');
        $this->assertSame('ricevuta', ServizioPubblicazioni::statoPer(false, 0));
        $this->assertSame('da_approvare', ServizioPubblicazioni::statoPer(true, 1), 'accesa e con un approvatore: aspetta');
    }

    public function testRespingereVuoleUnMotivoDiAlMassimoMilleCaratteri(): void
    {
        $this->assertNotNull(ApprovazionePubblicazioni::erroreMotivo(''));
        $this->assertNotNull(ApprovazionePubblicazioni::erroreMotivo("  \n  "), 'solo spazi = vuoto');
        $this->assertNull(ApprovazionePubblicazioni::erroreMotivo('Manca la locandina definitiva.'));
        $this->assertNull(ApprovazionePubblicazioni::erroreMotivo(str_repeat('è', Costanti::MAX_MOTIVO)), 'mille caratteri vanno bene (si contano i caratteri, non i byte)');
        $this->assertStringContainsString('troppo lungo', (string) ApprovazionePubblicazioni::erroreMotivo(str_repeat('a', Costanti::MAX_MOTIVO + 1)));
        $this->assertSame(1000, Costanti::MAX_MOTIVO);
    }

    public function testGliStatiNuoviEdIlNomeDiRicevuta(): void
    {
        $stati = array_keys(Costanti::STATI);
        $this->assertSame('da_approvare', $stati[0], 'In attesa di approvazione è il primo');
        $this->assertSame('In attesa di approvazione', Costanti::STATI['da_approvare']);
        $this->assertSame('Approvata', Costanti::STATI['ricevuta'], 'lo stato di sempre si legge «Approvata»: le richieste di prima valgono come approvate');
        $this->assertSame(['approvatore', 'ufficio', 'conoscenza'], array_keys(Costanti::RUOLI_PERSONE));
    }
}
