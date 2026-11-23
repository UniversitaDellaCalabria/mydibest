<?php
// contatti.php - guscio: Sito pubblico › Contatti è App\Portale\Pagine\PaginaContattiPannello (rotta «admin/contatti», src/Portale/Instradamento/Rotte.php).
// L'accesso lo decide il Nucleo PRIMA della pagina (PermessiPagine: solo amministratori); l'indirizzo e il file restano questi.
require_once '../config.php';
require_once '../functions.php';
App\Http\Nucleo::serviFile(__FILE__);
