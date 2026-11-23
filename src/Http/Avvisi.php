<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Il messaggio che una pagina lascia a quella dopo il salvataggio («Contatto salvato.»): si imposta, si rimanda, e si vede UNA volta sola.
 * Il Nucleo lo mette a disposizione della vista (Vista::avviso()) e non lo consuma finché un layout non lo mostra davvero: se la risposta è un
 * rimando, un JSON o un file, il messaggio resta per la pagina che viene dopo. Come per ControlloAccesso, il Nucleo non sa dove sia custodito
 * (sessione…): lo sa chi implementa questa interfaccia (App\Auth\Flash).
 */
interface Avvisi
{
    /** Il messaggio in attesa, già in HTML, e lo toglie: '' se non ce n'è, e la seconda volta sempre ''. */
    public function html(): string;
}
