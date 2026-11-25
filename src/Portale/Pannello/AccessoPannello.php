<?php

declare(strict_types=1);

namespace App\Portale\Pannello;

use App\Attestati\Vista\Librerie;
use App\Auth\Flash;
use App\Http\Richiesta;
use App\Http\Risposta;
use App\Http\Vista;
use App\Portale\Vista\AvvisoEmail;

/**
 * Chi può aprire una pagina del pannello e, se non può, che cosa gli si risponde. È l'UNICA copia di queste regole: le usano il Nucleo (tramite
 * AccessoPortale, per le pagine servite dalle rotte) e la testata admin/admin_header.php (per le pagine che sono ancora script). Le risposte sono le
 * stesse da qualunque strada si arrivi:
 *
 *  - persona non collegata  → rimando al login SSO (302, con il ritorno a questa pagina); se il login è già stato tentato (`?sso_tentato=1`) e non è
 *    riuscito, la pagina «Autenticazione richiesta» (200);
 *  - collegata ma senza sezioni da gestire (pagine d'ingresso) → rimando 303 all'area personale, con un avviso;
 *  - collegata senza il permesso → 403, nel layout del pannello;
 *  - altrimenti si entra.
 * La regola per ogni pagina è PermessiPagine (la mappa); qui non c'è nessun permesso scritto. Una pagina senza regola si apre solo agli amministratori
 * e si scrive nel registro, così si aggiunge la regola.
 *
 * Il «ho già deciso io»: quando decide il Nucleo ({@see self::decidi()}), questa classe lo ricorda (`giaDecisoPer()`) PRIMA di vestire un eventuale
 * 403 col layout, e la testata, che è lo stesso layout, lo chiede e non decide una seconda volta. È una dichiarazione esplicita di chi ha deciso, non
 * un'ipotesi ricavata dalla richiesta; vale per una sola pagina (il nome del file) e per la durata del container (una richiesta). Chi non è passato
 * da `decidi()` (le pagine-script) non ha nessuna dichiarazione, e la testata decide da sé con `seNonCollegata()` e `perPagina()`.
 */
final class AccessoPannello
{
    public const MESSAGGIO_NEGATO = 'Accesso negato. Non hai i permessi per aprire questa pagina.';
    public const MESSAGGIO_SENZA_REGOLA = 'Accesso negato. Questa pagina non ha ancora una regola di accesso: si apre solo agli amministratori.';
    public const MESSAGGIO_SENZA_SEZIONI = 'Non hai sezioni del pannello da gestire: ecco la tua area personale.';

    private ?string $decisoPer = null;
    private bool $negato = false;

    public function __construct(private readonly ContestoPannello $contesto, private readonly Flash $flash, private readonly AvvisoEmail $avviso)
    {
    }

    /**
     * Il «perché» del 403 per chi è fuori solo perché l'email scelta a mano non è confermata: l'avviso con il pulsante «Conferma la tua email».
     * Vuoto per chi ha l'email verificata o nessun elenco «per email» in cui comparire (il suo no è un no vero).
     */
    public function avvisoEmail(Richiesta $r): string
    {
        return $this->avviso->per($this->contesto->utente($r), '../profilo.php#email', true);
    }

    /** Il Nucleo ha già deciso l'accesso a questa pagina (nome del file in admin/, per esempio «contatti.php») in questa richiesta? */
    public function giaDecisoPer(string $pagina): bool
    {
        return $this->decisoPer !== null && $this->decisoPer === $pagina;
    }

    /**
     * Il Nucleo ha deciso di NON far entrare la persona (e sta vestendo il 403 col layout): la testata si ferma subito dopo il punto in cui,
     * per una pagina-script, scatterebbe la sua guardia. Così il 403 è fatto solo di testata, avviso e piè di pagina, uguale a quello che dà la
     * testata da sola: niente finestra di sessione in scadenza e niente controllo dei messaggi per chi non è entrato.
     */
    public function negatoPer(string $pagina): bool
    {
        return $this->negato && $this->giaDecisoPer($pagina);
    }

    /**
     * La persona non è collegata: la risposta da dare (rimando al login SSO, oppure la pagina «Autenticazione richiesta» se il login è già stato
     * tentato). null se è collegata.
     */
    public function seNonCollegata(Richiesta $r, string $pagina): ?Risposta
    {
        if ($this->contesto->collegata($r)) {
            return null;
        }
        if ($r->query('sso_tentato') === null) {
            // Accesso diretto: login SSO e ritorno a questa stessa pagina (sso_tentato evita un ciclo se il login non riesce)
            $parametri = $r->parametri();
            $parametri['sso_tentato'] = 1;
            $ritorno = 'admin/' . $pagina . '?' . http_build_query($parametri);

            return Risposta::vai('../saml_login.php?redirect=' . urlencode($ritorno), 302);
        }

        return Risposta::html(Vista::rendi('pannello/autenticazione_richiesta', [
            // gli stessi indirizzi di url_vendor() (la facciata di inc/attestati.php), senza dipendere da quel file
            'css_bootstrap' => Librerie::urlVendor(\url_base_sito(), 'jsdelivr/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css'),
            'css_icone' => Librerie::urlVendor(\url_base_sito(), 'cdnjs/ajax/libs/font-awesome/6.4.0/css/all.min.css'),
            'link_accedi' => '../saml_login.php?redirect=' . urlencode('admin/index.php'),
        ]));
    }

    /**
     * Per una persona collegata: `true` si entra; `false` manca il permesso (403: il messaggio lo dà {@see self::messaggioDelNo()}); una Risposta
     * quando non è un 403 (le pagine d'ingresso mandano chi non ha sezioni alla sua area personale). Per chi non è collegato risponde `false`:
     * si chiede prima `seNonCollegata()`.
     */
    public function perPagina(Richiesta $r, string $pagina): bool|Risposta
    {
        $permessi = $this->contesto->permessi($r);
        if ($permessi === null) {
            return false;
        }
        $consenso = PermessiPagine::consente($pagina, $permessi->dichiarati());
        if ($consenso === null) {
            error_log('[pannello] pagina senza regola in PermessiPagine: ' . $pagina . ' (utente ' . (int) ($this->contesto->utente($r)['id'] ?? 0) . ')');

            return $permessi->eAmministratore();
        }
        if ($consenso === false && PermessiPagine::rimandaAllAreaPersonale($pagina)) {
            $this->flash->imposta(self::MESSAGGIO_SENZA_SEZIONI, 'info');

            return Risposta::vai('../area_personale.php');
        }

        return $consenso;
    }

    /** Il testo del 403 per questa pagina: quello di una pagina senza regola, o quello solito. */
    public function messaggioDelNo(string $pagina): string
    {
        return PermessiPagine::regola($pagina) === null ? self::MESSAGGIO_SENZA_REGOLA : self::MESSAGGIO_NEGATO;
    }

    /**
     * La decisione intera, per il Nucleo: `true` (si entra) oppure la Risposta da dare (login, area personale, 403 nel layout del pannello).
     * Da qui in poi la testata sa che l'accesso è deciso.
     */
    public function decidi(Richiesta $r, string $pagina): bool|Risposta
    {
        $nonCollegata = $this->seNonCollegata($r, $pagina);
        if ($nonCollegata !== null) {
            return $nonCollegata;
        }
        $esito = $this->perPagina($r, $pagina);
        if ($esito instanceof Risposta) {
            return $esito;
        }
        $this->decisoPer = $pagina; // prima di vestire il 403: il layout è la testata, e non deve decidere una seconda volta
        $this->negato = !$esito;
        if ($esito) {
            return true;
        }

        $avviso = $this->avvisoEmail($r);

        return Risposta::negata($this->messaggioDelNo($pagina), static fn (string $messaggio): string => Vista::pannello(
            'Accesso negato',
            "<div class='alert alert-danger fw-bold shadow-sm m-4'><i class='fa fa-ban me-2'></i> " . htmlspecialchars($messaggio, ENT_QUOTES, 'UTF-8') . '</div>'
            . ($avviso !== '' ? '<div class="m-4">' . $avviso . '</div>' : ''),
        ));
    }
}
