<?php

declare(strict_types=1);

namespace App\ConvenzioniTesi;

use App\Core\Container;
use App\Core\Database;
use App\Core\Orologio;
use App\Core\Sito;
use App\Infrastructure\Pdf\VerificaFirme;
use App\Infrastructure\Storage\Upload;
use App\Protocollo\ProtocolloRepository;
use App\Tutorato\FirmaRemota;
use App\Tutorato\VerificaPdfFirmato;
use App\Uffici\Direzione;
use App\Uffici\UfficiRepository;

/** Servizi del modulo Convenzioni di tesi nel container (scoperta da App\Core\App). */
final class Registrazione
{
    public static function registra(Container $c): void
    {
        // Dopo ogni firma del Direttore registrata può partire il protocollo automatico delle tesi (interruttore «automatico_tesi», spento di partenza). Il collegamento
        // si risolve solo a firma fatta: se Titulus non è configurato non succede niente, e un errore non può mai far fallire la firma.
        $c->set(ServizioConvenzioniTesi::class, static fn (Container $c): ServizioConvenzioniTesi => (new ServizioConvenzioniTesi(
            $c->get(ConvenzioneTesiRepository::class), $c->get(ModelloConvenzione::class), $c->get(NotificheConvenzioniTesi::class), $c->get(Direzione::class),
            $c->get(UfficiRepository::class), $c->get(FirmaRemota::class), $c->get(VerificaPdfFirmato::class), $c->get(VerificaFirme::class), $c->get(Upload::class),
            $c->get(Database::class), $c->get(Orologio::class), $c->get(Sito::class), $c->get(ProtocolloRepository::class)
        ))->conDopoFirma(static fn (int $id): ?string => $c->get(ProtocolloAutomaticoConvenzioneTesi::class)->dopoFirma($id)));
    }
}
