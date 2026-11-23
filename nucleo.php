<?php
// nucleo.php - Punto d'ingresso del Nucleo (App\Http\Nucleo) per gli indirizzi che non sono un file: il .htaccess (e strumenti/locale/router.php)
// ci mandano ogni percorso con una «/» che non è né un file né una cartella. Se è l'indirizzo di una rotta (src/Portale/Instradamento/Rotte.php)
// risponde la sua pagina, altrimenti 404 senza aprire database e sessione. Le pagine di oggi NON passano da qui: restano i loro file, con due righe
// che chiamano Nucleo::serviFile(). Eliminabile: cancellando questo file e la sua regola nel .htaccess il portale risponde come prima.
require_once __DIR__ . '/src/autoload.php';

use App\Http\Nucleo;
use App\Http\Richiesta;
use App\Http\Risposta;
use App\Portale\Instradamento\Rotte;

$richiesta = Richiesta::daGlobali();
if (Nucleo::rottaDi(Rotte::elenco(), $richiesta) === null) {
    Risposta::nonTrovata()->emetti();
    exit;
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
\App\Core\App::get(Nucleo::class)->servi($richiesta)->emetti();
