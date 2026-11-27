<?php

declare(strict_types=1);

namespace Tests\Unit\Pubblicazioni;

use App\Pubblicazioni\ServizioPubblicazioni;
use PHPUnit\Framework\TestCase;

/** P1: la locandina si scarica come Locandina_<titolo breve>.<est> (il file su disco non cambia nome). */
final class NomeLocandinaTest extends TestCase
{
    public function testTitoloSenzaAccentiESimboliConGliSpaziComeTrattiniBassi(): void
    {
        $this->assertSame('Locandina_Notte_dei_ricercatori.pdf', ServizioPubblicazioni::nomeLocandina('Notte dei ricercatori', 'pdf'));
        $this->assertSame('Locandina_Perche_luniversita_e_citta_2026.png', ServizioPubblicazioni::nomeLocandina('Perché l\'università è città? (2026)', 'png'));
        $this->assertSame('Locandina_Biologia_Ecologia_Scienze_della_Terra.jpg', ServizioPubblicazioni::nomeLocandina('  «Biologia, Ecologia & Scienze della Terra»  ', 'jpg'));
    }

    public function testAlMassimoQuarantaCaratteriSenzaTrattiniAiLati(): void
    {
        $nome = ServizioPubblicazioni::nomeLocandina(str_repeat('Giornata di studio ', 10), 'pdf');
        $titolo = substr($nome, strlen('Locandina_'), -strlen('.pdf'));
        $this->assertLessThanOrEqual(40, strlen($titolo));
        $this->assertStringStartsWith('Giornata_di_studio_Giornata', $titolo);
        $this->assertStringEndsNotWith('_', $titolo);
        // il taglio cade su un trattino basso: non resta attaccato al punto
        $this->assertSame('Locandina_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.pdf', ServizioPubblicazioni::nomeLocandina(str_repeat('a', 39) . ' ' . str_repeat('b', 20), 'pdf'));
    }

    public function testTitoloSenzaLettereDaSoloLocandina(): void
    {
        $this->assertSame('Locandina.pdf', ServizioPubblicazioni::nomeLocandina('???', 'pdf'));
        $this->assertSame('Locandina.pdf', ServizioPubblicazioni::nomeLocandina('', ''));
    }
}
