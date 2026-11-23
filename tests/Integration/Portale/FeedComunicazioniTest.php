<?php

declare(strict_types=1);

namespace Tests\Integration\Portale;

use App\Core\Sito;
use App\Http\Richiesta;
use App\Portale\ComunicazioniRepository;
use App\Portale\Endpoint\FeedComunicazioni;
use Tests\Integration\DatabaseDiProva;

/** Il feed RSS di comunicazioni_feed.php provato senza browser: una Richiesta::finta() entra, si legge il XML della Risposta. */
final class FeedComunicazioniTest extends DatabaseDiProva
{
    protected function tabelle(): array
    {
        return ['comunicazioni' => self::tabellaVera('comunicazioni')];
    }

    private function feed(): FeedComunicazioni
    {
        return new FeedComunicazioni(new ComunicazioniRepository($this->db), new Sito(sys_get_temp_dir(), 'https://portale.test/mydibest/'));
    }

    private function notizia(int $id, string $titolo, string $sommario, string $testo, string $pubblico = '', string $data = '2026-11-10'): void
    {
        $this->db->esegui(
            "INSERT INTO comunicazioni (id, tipo, titolo, sommario, testo, pubblico, data_pubblicazione, pubblicata, creato_il) VALUES (?, 'notizia', ?, ?, ?, ?, ?, 1, '2026-11-01 10:00:00')",
            [$id, $titolo, $sommario, $testo, $pubblico, $data]
        );
    }

    public function testIntestazioniEDocumentoVuoto(): void
    {
        $r = $this->feed()->risposta(Richiesta::finta('GET', '/comunicazioni_feed.php'));

        self::assertSame(200, $r->codice());
        self::assertSame('application/rss+xml; charset=utf-8', $r->intestazione('Content-Type'));
        self::assertNull($r->intestazione('Content-Disposition'), 'è un feed da leggere, non un file da scaricare');
        self::assertSame(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<rss version=\"2.0\"><channel>\n<title>Notizie del Dipartimento</title>\n"
            . "<link>https://portale.test/mydibest/comunicazioni.php</link>\n<description>Le ultime comunicazioni del Dipartimento</description>\n<language>it</language>\n"
            . "</channel></rss>\n",
            $r->corpo()
        );
    }

    public function testUnaNotiziaConSommarioEUnaSenza(): void
    {
        $this->notizia(7, 'Orari & aule', 'Cambia l\'<orario>', '<p>Testo lungo</p>');
        $this->notizia(5, 'Seconda', '', '<p>Dal 1° dicembre <b>apre</b> lo sportello.</p>', '', '2026-11-09');

        $xml = $this->feed()->risposta(Richiesta::finta('GET', '/comunicazioni_feed.php'))->corpo();

        $item = static fn (int $id, string $titolo, string $data, string $descr): string => '<item><title>' . $titolo . '</title><link>https://portale.test/mydibest/comunicazione.php?id=' . $id
            . '</link><guid isPermaLink="true">https://portale.test/mydibest/comunicazione.php?id=' . $id . "</guid>\n<pubDate>" . $data . "</pubDate>\n<description>" . $descr . "</description></item>\n";
        self::assertStringContainsString(
            $item(7, 'Orari &amp; aule', date(DATE_RSS, strtotime('2026-11-10')), 'Cambia l&apos;&lt;orario&gt;')
            . $item(5, 'Seconda', date(DATE_RSS, strtotime('2026-11-09')), 'Dal 1° dicembre apre lo sportello.'),
            $xml,
            'l\'ordine è dalla più recente, il testo è escapato per XML e senza sommario si usa l\'estratto del testo'
        );
        self::assertNotNull(simplexml_load_string($xml), 'il feed è un XML ben formato');
    }

    public function testPerUnSoloPubblicoEUnValoreSconosciutoVaTraTutti(): void
    {
        $this->notizia(1, 'Per tutti', 's', 't', '');
        $this->notizia(2, 'Solo scuole', 's', 't', 'scuole');
        $this->notizia(3, 'Solo docenti', 's', 't', 'docenti');
        $titoli = function (mixed $per): array {
            $xml = simplexml_load_string($this->feed()->risposta(Richiesta::finta('GET', '/comunicazioni_feed.php', $per === null ? [] : ['per' => $per]))->corpo());
            $out = [];
            foreach ($xml->channel->item as $item) {
                $out[] = (string) $item->title;
            }

            return $out;
        };

        self::assertEqualsCanonicalizing(['Per tutti', 'Solo scuole', 'Solo docenti'], $titoli(null));
        self::assertEqualsCanonicalizing(['Per tutti', 'Solo scuole'], $titoli('scuole'));
        self::assertEqualsCanonicalizing(['Per tutti', 'Solo scuole', 'Solo docenti'], $titoli('boh'), 'un pubblico che non esiste vale «tutti»');
        self::assertEqualsCanonicalizing(['Per tutti', 'Solo scuole', 'Solo docenti'], $titoli(['scuole']), '?per[]=… non è un testo: vale «tutti»');
    }

    public function testNonSuperaLeTrentaNotizie(): void
    {
        for ($i = 1; $i <= 35; $i++) {
            $this->notizia($i, "N$i", 's', 't', '', '2026-10-' . sprintf('%02d', min(28, $i)));
        }

        $xml = simplexml_load_string($this->feed()->risposta(Richiesta::finta('GET', '/comunicazioni_feed.php'))->corpo());

        self::assertCount(30, $xml->channel->item);
    }

    public function testUnaNotiziaNonPubblicataONelFuturoNonCompare(): void
    {
        $this->notizia(1, 'Visibile', 's', 't');
        $this->notizia(2, 'Futura', 's', 't', '', '2999-01-01');
        $this->db->esegui("INSERT INTO comunicazioni (id, tipo, titolo, pubblicata) VALUES (3, 'notizia', 'Bozza', 0)");

        $xml = $this->feed()->risposta(Richiesta::finta('GET', '/comunicazioni_feed.php'))->corpo();

        self::assertStringContainsString('Visibile', $xml);
        self::assertStringNotContainsString('Futura', $xml);
        self::assertStringNotContainsString('Bozza', $xml);
    }
}
