<?php
// fsl_scuole.php - guscio: Formazione Scuola Lavoro › Scuole e convenzioni è App\Portale\Pagine\PaginaScuoleFsl (rotta «admin/fsl_scuole», src/Portale/Instradamento/Rotte.php).
// L'accesso lo decide il Nucleo PRIMA della pagina (PermessiPagine: chi ha almeno le convenzioni FSL); l'indirizzo e il file restano questi.
require_once '../config.php';
require_once '../functions.php';
App\Http\Nucleo::serviFile(__FILE__);
