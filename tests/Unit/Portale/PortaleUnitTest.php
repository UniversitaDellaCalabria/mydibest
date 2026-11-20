<?php

declare(strict_types=1);

namespace Tests\Unit\Portale;

use App\Core\Database;
use App\Core\Sito;
use App\Portale\CacheConfigurazione;
use App\Portale\Colori;
use App\Portale\ConfigurazioneRepository;
use App\Portale\OrganizzazioneProposta;
use App\Portale\Visitatore;
use App\Portale\WidgetHome;
use PHPUnit\Framework\TestCase;

final class PortaleUnitTest extends TestCase
{
    public function testColoriValidoEdEspansione(): void
    {
        self::assertSame('#AABBCC', Colori::valido('#aabbcc'));
        self::assertSame('#AABBCC', Colori::valido('#abc'));
        self::assertSame('#B30000', Colori::valido('rosso'));
        self::assertSame('#112233', Colori::valido('"><script>', '#112233'));
        self::assertSame('#B30000', Colori::valido(null));
    }

    public function testTestoLeggibileSuSfondo(): void
    {
        self::assertSame('#FFFFFF', Colori::testoSu('#000000'));
        self::assertSame('#1F2937', Colori::testoSu('#FFFFFF'));
        self::assertSame('#FFFFFF', Colori::testoSu('#B30000'));
        self::assertSame('#1F2937', Colori::testoSu('#FFD700'));
    }

    public function testVisitatoreEVisibilitaDelleVoci(): void
    {
        $ospite = Visitatore::daSessione([]);
        self::assertTrue($ospite->vede(0));
        self::assertFalse($ospite->vede(-1), 'solo chi è entrato');
        self::assertFalse($ospite->vede(3));
        $studente = Visitatore::daSessione(['utente_id' => 5, 'utente_ruolo_id' => 3, 'utente_ruoli_secondari' => '4,6']);
        self::assertTrue($studente->vede(-1));
        self::assertTrue($studente->vede(3));
        self::assertTrue($studente->vede(6), 'gruppo secondario');
        self::assertFalse($studente->vede(7));
        self::assertFalse($studente->amministratore());
        $gestore = Visitatore::daSessione(['utente_id' => 2, 'utente_ruolo_id' => 5, 'utente_ruoli_secondari' => '2']);
        self::assertTrue($gestore->amministratore());
        self::assertTrue($gestore->vede(7), "un amministratore vede le voci riservate a un ruolo");
        self::assertFalse($gestore->vede(-2), 'i gruppi di persone (uffici, responsabili di risorse) li decide AppartenenzeVisitatore: qui restano nascosti per sicurezza');
    }

    public function testWidgetHomeNormalizzaIlSalvato(): void
    {
        $w = new WidgetHome(new CacheConfigurazione(new ConfigurazioneRepository(Database::per(new \mysqli())), new Sito('/x')));
        $d = $w->widgets(null);
        self::assertSame(WidgetHome::predefiniti()['ordine'], $d['ordine']);
        self::assertSame(1, $d['slideshow']);
        $n = $w->widgets(json_encode(['slideshow' => 0, 'aree_colonne' => 9, 'aree_max' => 100, 'eventi_num' => 5, 'eventi_layout' => 'altro', 'ordine' => ['statistiche', 'finto', 'statistiche', 'slideshow']]));
        self::assertSame([0, 2, 48, 8, 'scroll'], [$n['slideshow'], $n['aree_colonne'], $n['aree_max'], $n['eventi_num'], $n['eventi_layout']]);
        self::assertSame(['statistiche', 'slideshow'], array_slice($n['ordine'], 0, 2));
        self::assertCount(11, $n['ordine'], 'i widget mancanti tornano al loro posto');
        self::assertEqualsCanonicalizing(WidgetHome::predefiniti()['ordine'], $n['ordine'], 'ci sono tutti i widget, una volta sola, e nessuno inventato');
        self::assertContains('notizie', $n['ordine'], "«Ultime notizie» (aggiunto con lo schema v92, commit 086e5f6) torna al suo posto anche se mancava nel salvato");
        self::assertSame(0, $d['notizie'], 'i widget per pubblico, «Ultime notizie» compreso, restano spenti finché non si accendono');
        self::assertSame(1, $w->widgets(json_encode(['notizie' => 'sì']))['notizie'], 'un widget acceso si salva come 1');
        self::assertSame(['slideshow', 'percorsi'], array_slice($w->widgets(json_encode(['ordine' => ['slideshow']]))['ordine'], 0, 2), 'i nuovi widget seguono quello che li precede');
        self::assertSame(['annunci', 'notizie', 'agenda'], array_slice($w->widgets(json_encode(['ordine' => ['annunci', 'agenda']]))['ordine'], 3, 3), '«Ultime notizie» si mette subito dopo «annunci», non in fondo');
    }

    public function testNomeAreaMaiuscoleEPreposizioni(): void
    {
        self::assertSame('Formazione Scuola Lavoro', OrganizzazioneProposta::nomeArea('FORMAZIONE SCUOLA LAVORO'));
        self::assertNotSame('', OrganizzazioneProposta::nomeArea('OPENLAB'));
    }
}
