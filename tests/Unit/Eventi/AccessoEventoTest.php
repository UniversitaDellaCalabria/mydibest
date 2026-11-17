<?php

declare(strict_types=1);

namespace Tests\Unit\Eventi;

use App\Eventi\AccessoEvento;
use PHPUnit\Framework\TestCase;

/** La regola unica su eventi.ruolo_accesso_id: chi può iscriversi (0 tutti, -1 serve l'accesso, n un ruolo; ruolo 1 sempre, ruolo 2 no). */
final class AccessoEventoTest extends TestCase
{
    public function testServeAccesso(): void
    {
        self::assertFalse(AccessoEvento::serveAccesso(0));
        self::assertTrue(AccessoEvento::serveAccesso(-1));
        self::assertTrue(AccessoEvento::serveAccesso(3));
    }

    /** @return iterable<string, array{int, bool, int, list<string>, bool}> ruoloAccesso, connesso, ruolo principale, secondari, ammesso */
    public static function casi(): iterable
    {
        // 0: tutti, anche senza accesso
        yield '0 anonimo' => [0, false, 5, [], true];
        yield '0 connesso' => [0, true, 3, [], true];
        // -1: serve solo l'accesso
        yield '-1 anonimo' => [-1, false, 5, [], false];
        yield '-1 connesso ospite' => [-1, true, 5, [], true];
        yield '-1 connesso gestore' => [-1, true, 2, [], true];
        // n uguale al ruolo principale, tra i secondari, diverso
        yield '3 principale' => [3, true, 3, [], true];
        yield '3 tra i secondari' => [3, true, 5, ['6', '3'], true];
        yield '3 solo secondario in prima posizione' => [3, true, 5, ['3'], true];
        yield '3 docente' => [3, true, 4, [], false];
        yield '3 docente con secondari diversi' => [3, true, 4, ['6', '7'], false];
        yield '3 secondari vuoti (testo vuoto)' => [3, true, 5, [''], false];
        yield '6 gruppo creato a mano, secondario' => [6, true, 5, ['6'], true];
        yield '6 senza il gruppo' => [6, true, 3, [], false];
        // il ruolo 1 passa sempre, principale o secondario
        yield '3 amministratore principale' => [3, true, 1, [], true];
        yield '3 amministratore secondario' => [3, true, 5, ['1'], true];
        yield '6 amministratore' => [6, true, 1, [], true];
        yield '-1 amministratore' => [-1, true, 1, [], true];
        // il gestore (ruolo 2) NON passa
        yield '3 gestore principale' => [3, true, 2, [], false];
        yield '3 gestore secondario' => [3, true, 5, ['2'], false];
        yield '2 gestore stesso ruolo' => [2, true, 2, [], true];
        // non connesso: nessuna restrizione diversa da 0 passa, nemmeno se i dati dicono altro
        yield '3 anonimo con ruolo 3' => [3, false, 3, ['3'], false];
        yield '3 anonimo con ruolo 1' => [3, false, 1, ['1'], false];
        yield '-1 anonimo con ruolo 1' => [-1, false, 1, [], false];
        yield '6 anonimo' => [6, false, 5, [], false];
    }

    /**
     * @dataProvider casi
     * @param list<string> $secondari
     */
    public function testAmmesso(int $ruoloAccesso, bool $connesso, int $ruolo, array $secondari, bool $atteso): void
    {
        self::assertSame($atteso, AccessoEvento::ammesso($ruoloAccesso, $connesso, $ruolo, $secondari));
    }

    public function testIdDeiSecondariSiConfrontaComeTesto(): void
    {
        // «13» non è «3»: nessun confronto parziale o numerico lasco
        self::assertFalse(AccessoEvento::ammesso(3, true, 5, ['13']));
        self::assertFalse(AccessoEvento::ammesso(3, true, 5, ['3.0']));
    }
}
