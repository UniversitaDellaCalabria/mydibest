<?php
// approva_pubblicazioni.php - guscio: la pagina di chi approva le richieste di pubblicazione è App\Portale\Pagine\PaginaApprovaPubblicazioni
// (rotta «approva_pubblicazioni», src/Portale/Instradamento/Rotte.php). Non sta nel pannello: il Direttore non ha il modulo Amministrazione.
require_once 'config.php';
require_once 'functions.php';
App\Http\Nucleo::serviFile(__FILE__);
