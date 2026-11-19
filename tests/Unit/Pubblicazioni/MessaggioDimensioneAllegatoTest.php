<?php

declare(strict_types=1);

namespace Tests\Unit\Pubblicazioni;

use App\Pubblicazioni\AllegatiPubblicazioni;
use App\Pubblicazioni\LimitiCaricamento;
use PHPUnit\Framework\TestCase;

/** Passo R4: il «massimo N MB» del messaggio di un allegato troppo grande è il limite vero (LimitiCaricamento), non un numero scritto a mano. */
final class MessaggioDimensioneAllegatoTest extends TestCase
{
    /** Un file che PHP ha rifiutato perché supera upload_max_filesize (nessun file sul disco). */
    private function rifiutatoDalServer(): array
    {
        return ['name' => 'locandina.pdf', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0];
    }

    public function testIlLimiteNelMessaggioEQuelloDelServerSeEPiuBasso(): void
    {
        $m = AllegatiPubblicazioni::errore($this->rifiutatoDalServer(), 'allegato', new LimitiCaricamento('8M', '2M', 20));
        $this->assertSame('«locandina.pdf»: il file è troppo grande (massimo 2 MB).', $m);
    }

    public function testConIlServerLargoIlLimiteEQuelloDelPortale(): void
    {
        $m = AllegatiPubblicazioni::errore($this->rifiutatoDalServer(), 'allegato', new LimitiCaricamento('64M', '10M', 20));
        $this->assertSame('«locandina.pdf»: il file è troppo grande (massimo 10 MB).', $m);
        // anche con un server che ne accetta di più, il portale ferma a 10 MB
        $grande = ['name' => 'foto.png', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_OK, 'size' => AllegatiPubblicazioni::MAX_BYTE + 1];
        $this->assertSame('«foto.png»: il file è troppo grande (massimo 10 MB).', AllegatiPubblicazioni::errore($grande, 'immagine', new LimitiCaricamento('64M', '1G', 20)));
    }

    public function testIlLimiteSiScriveInKbOInMbDecimali(): void
    {
        $this->assertSame('512 KB', (new LimitiCaricamento('8M', '512K', 20))->fileMaxInParole());
        $this->assertSame('2,5 MB', (new LimitiCaricamento('8M', '2560K', 20))->fileMaxInParole());
        $this->assertSame('10 MB', (new LimitiCaricamento('8M', '0', 20))->fileMaxInParole(), 'server senza limite: vale il portale');
    }

    public function testSenzaLimitiPassatiSiLeggonoDalServer(): void
    {
        $atteso = '«locandina.pdf»: il file è troppo grande (massimo ' . LimitiCaricamento::dalServer()->fileMaxInParole() . ').';
        $this->assertSame($atteso, AllegatiPubblicazioni::errore($this->rifiutatoDalServer(), 'allegato'));
    }
}
