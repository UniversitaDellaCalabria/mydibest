<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Attestati\RegoleClasse as RegoleClasseAttestati;
use App\Core\Container;
use App\Core\Sito;
use App\Infrastructure\Storage\Upload;
use App\Tutorato\FirmaRemota;
use App\Tutorato\VerificaPdfFirmato;
use App\Uffici\Direzione;
use App\Infrastructure\Pdf\FirmePades;
use App\Infrastructure\Pdf\VerificaFirme;
use App\Iscritti\Convenzioni;
use App\Iscrizioni\RegoleFsl;

/** Servizi del modulo FSL nel container (scoperta da App\Core\App). */
final class Registrazione
{
    public static function registra(Container $c): void
    {
        // Le regole e le convenzioni della FSL, usate dai moduli Attestati, Iscrizioni e Iscritti
        $c->set(RegoleClasseAttestati::class, static fn (Container $c): RegoleClasseAttestati => $c->get(RegoleClasse::class));
        $c->set(RegoleFsl::class, static fn (Container $c): RegoleFsl => $c->get(RegoleIscrizioniFsl::class));
        $c->set(Convenzioni::class, static fn (Container $c): Convenzioni => $c->get(ConvenzioniIscritti::class));
        // La firma del Direttore: dopo ogni firma registrata può partire il protocollo automatico (risolto solo a firma fatta: se Titulus non è configurato non succede niente)
        $c->set(ServizioFirmaDirettore::class, static fn (Container $c): ServizioFirmaDirettore => (new ServizioFirmaDirettore(
            $c->get(ConvenzioneCompilataRepository::class), $c->get(Direzione::class), $c->get(NotificheConvenzioni::class), $c->get(FirmaRemota::class),
            $c->get(VerificaPdfFirmato::class), $c->get(Upload::class), $c->get(Sito::class)
        ))->conDopoFirma(static fn (int $id): ?string => $c->get(ProtocolloAutomaticoConvenzioneFsl::class)->dopoFirma($id)));
        // La verifica delle firme PAdES dei PDF
        $c->set(VerificaFirme::class, static fn (): VerificaFirme => new FirmePades());
    }
}
