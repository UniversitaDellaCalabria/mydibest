<?php

declare(strict_types=1);

namespace Tests\Unit\Amministrazione;

use App\Amministrazione\PermessiAmministrazione;
use PHPUnit\Framework\TestCase;

/** La regola di chi entra nel modulo Amministrazione (senza database): amministratore, solo richieste di pubblicazione, tutto il modulo, nessuno. */
final class PermessiAmministrazioneTest extends TestCase
{
    private const ADMIN = ['id' => 1, 'ruolo_id' => 1, 'ruoli_secondari' => ''];
    private const SENZA = ['id' => 5, 'ruolo_id' => 4, 'ruoli_secondari' => ''];

    private const MODULO_TUTTO = [['tipo' => 'modulo_amministrazione', 'pagina_id' => 0]];
    private const MODULO_RICHIESTE = [['tipo' => 'modulo_pubblicazioni', 'pagina_id' => 0]];

    public function testAmministratoreHaTutto(): void
    {
        self::assertSame(['tutto' => true, 'pubblicazioni' => true], PermessiAmministrazione::valuta(self::ADMIN, []));
        // anche con il ruolo secondario «1», come in tutto il portale
        self::assertSame(['tutto' => true, 'pubblicazioni' => true], PermessiAmministrazione::valuta(['id' => 9, 'ruolo_id' => 5, 'ruoli_secondari' => '6,1'], []));
    }

    public function testSoloRichiesteDiPubblicazioneNonHaTuttoIlModulo(): void
    {
        self::assertSame(['tutto' => false, 'pubblicazioni' => true], PermessiAmministrazione::valuta(self::SENZA, self::MODULO_RICHIESTE));
    }

    public function testSoloAmministrazioneHaTuttoEQuindiAncheLeRichieste(): void
    {
        self::assertSame(['tutto' => true, 'pubblicazioni' => true], PermessiAmministrazione::valuta(self::SENZA, self::MODULO_TUTTO));
        // entrambe le abilitazioni: come tutto il modulo
        self::assertSame(['tutto' => true, 'pubblicazioni' => true], PermessiAmministrazione::valuta(self::SENZA, [...self::MODULO_RICHIESTE, ...self::MODULO_TUTTO]));
    }

    public function testNessunoNonHaNiente(): void
    {
        self::assertSame(['tutto' => false, 'pubblicazioni' => false], PermessiAmministrazione::valuta(self::SENZA, []));
        // altri moduli non bastano: sono cose diverse
        self::assertSame(['tutto' => false, 'pubblicazioni' => false], PermessiAmministrazione::valuta(self::SENZA, [['tipo' => 'modulo_forms', 'pagina_id' => 0], ['tipo' => 'modulo_didattica', 'pagina_id' => 0]]));
        // senza persona (o senza id) niente
        self::assertSame(['tutto' => false, 'pubblicazioni' => false], PermessiAmministrazione::valuta(null, self::MODULO_TUTTO));
        self::assertSame(['tutto' => false, 'pubblicazioni' => false], PermessiAmministrazione::valuta(['id' => 0, 'ruolo_id' => 1], self::MODULO_TUTTO));
    }

    public function testIlModuloValeSoloComePerimetroInteroNonLegatoAUnArea(): void
    {
        self::assertSame(['tutto' => false, 'pubblicazioni' => false], PermessiAmministrazione::valuta(self::SENZA, [
            ['tipo' => 'modulo_amministrazione', 'pagina_id' => 12],
            ['tipo' => 'modulo_pubblicazioni', 'pagina_id' => 12],
        ]));
    }
}
