<?php
// fsl.php - guscio: Formazione Scuola Lavoro › Numeri e report è App\Portale\Pagine\PaginaNumeriFsl (rotta «admin/fsl», src/Portale/Instradamento/Rotte.php).
// Le vecchie schermate «convenzioni» (?tab=iter, stipulare, convenzioni, verifica, scuola…) rimandano alla pagina Scuole e convenzioni (RimandiFsl): l'indirizzo resta.
// L'accesso lo decide il Nucleo PRIMA della pagina (PermessiPagine: chi ha almeno le convenzioni FSL).
require_once '../config.php';
require_once '../functions.php';
App\Http\Nucleo::serviFile(__FILE__);
