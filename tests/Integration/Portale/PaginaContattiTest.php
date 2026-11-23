<?php

declare(strict_types=1);

namespace Tests\Integration\Portale;

use App\Http\Richiesta;
use App\Http\Vista;
use App\Portale\ContattiRepository;
use App\Portale\Pagine\PaginaContatti;
use Tests\Integration\DatabaseDiProva;

/**
 * «Chi contattare per cosa» (App\Portale\Pagine\PaginaContatti) provata senza browser: una Richiesta::finta() entra, si legge la Risposta.
 * È anche l'ESEMPIO DA COPIARE di documentazione/nucleo.md per provare una pagina nuova: database di prova, modello VERO copiato in una radice
 * temporanea con testate finte (così la prova non dipende da header.php, dalla sessione e dalla connessione), nessuna scrittura fuori dal temporaneo.
 */
final class PaginaContattiTest extends DatabaseDiProva
{
    private string $radice = '';
    private ?string $radicePrima = null;
    /** @var array<string, array{0: string, 1: string}> */
    private array $layoutPrima = [];

    protected function tabelle(): array
    {
        return ['contatti_studenti' => self::tabellaVera('contatti_studenti')];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->radice = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pagina_contatti_' . bin2hex(random_bytes(4));
        mkdir($this->radice . '/viste/pubblico', 0777, true);
        copy(RADICE_SITO . '/viste/pubblico/contatti.php', $this->radice . '/viste/pubblico/contatti.php');   // il modello VERO
        file_put_contents($this->radice . '/header.php', '<?php echo "<html><title>" . htmlspecialchars($page_cfg[\'titolo\'] ?? \'\') . "</title><body>"; ?>');
        file_put_contents($this->radice . '/footer.php', '</body></html>');
        $this->radicePrima = Vista::usaRadice($this->radice);
        $this->layoutPrima = Vista::layout(['sito' => ['header.php', 'footer.php'], 'pannello' => ['header.php', 'footer.php']]);
    }

    protected function tearDown(): void
    {
        Vista::usaRadice($this->radicePrima);
        Vista::layout($this->layoutPrima ?: ['sito' => ['header.php', 'footer.php'], 'pannello' => ['header.php', 'footer.php']]);
        foreach (['viste/pubblico/contatti.php', 'header.php', 'footer.php'] as $f) {
            @unlink($this->radice . '/' . $f);
        }
        @rmdir($this->radice . '/viste/pubblico');
        @rmdir($this->radice . '/viste');
        @rmdir($this->radice);
        parent::tearDown();
    }

    private function pagina(): PaginaContatti
    {
        return new PaginaContatti(new ContattiRepository($this->db));
    }

    private function contatto(int $id, string $titolo, string $link = '', int $visibile = 1): void
    {
        $this->db->esegui(
            'INSERT INTO contatti_studenti (id, titolo, per_cosa, luogo, orari, email, telefono, link_etichetta, link_url, ordine, visibile) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $titolo, 'Certificati', 'Cubo 4B', 'Lun 9-11', 'segreteria@unical.it', '0984 49 1234', '', $link, $id, $visibile]
        );
    }

    public function testSenzaContattiVisibiliLaPaginaLoDice(): void
    {
        $this->contatto(1, 'Nascosto', '', 0);

        $r = ($this->pagina())(Richiesta::finta('GET', '/contatti.php'));

        self::assertSame(200, $r->codice());
        self::assertStringContainsString('<title>Contatti</title>', $r->corpo(), 'il corpo sta dentro la testata del sito');
        self::assertStringContainsString('I contatti sono in preparazione', $r->corpo());
        self::assertStringNotContainsString('Nascosto', $r->corpo());
    }

    public function testUnContattoVisibileSiVedeEIlTestoEEscapato(): void
    {
        $this->contatto(1, 'Segreteria <b>didattica</b>', 'https://example.org/modulo');

        $corpo = ($this->pagina())(Richiesta::finta('GET', '/contatti.php'))->corpo();

        self::assertStringContainsString('Segreteria &lt;b&gt;didattica&lt;/b&gt;', $corpo, 'l\'escape lo fa la vista, la pagina non lo scrive');
        self::assertStringNotContainsString('<b>didattica</b>', $corpo);
        self::assertStringContainsString('href="tel:098449123' . '4"', $corpo, 'il telefono del collegamento è ripulito dalla pagina');
        self::assertStringContainsString('target="_blank" rel="noopener noreferrer"', $corpo, 'un indirizzo esterno si apre in una scheda nuova');
    }
}
