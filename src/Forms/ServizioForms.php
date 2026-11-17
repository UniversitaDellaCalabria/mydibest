<?php

declare(strict_types=1);

namespace App\Forms;

use App\Anagrafi\ServizioPersone;
use App\Auth\GruppiUtente;
use App\Auth\Pubblico;
use App\Auth\UtenteRepository;
use App\Core\Database;
use App\Core\Orologio;
use App\Core\Sito;
use App\Didattica\AllegatiPratiche;
use App\Didattica\CampiModulo;
use App\Didattica\LetturaRisposte;
use App\Didattica\ScelteAnagrafe;
use App\Didattica\ServizioPratiche;
use App\Infrastructure\Storage\Upload;
use App\Sistema\FileEnv;
use DateTimeImmutable;

/**
 * MyForms: crea/duplica/apre/chiude/elimina un form, decide chi può rispondere e salva le risposte riusando il motore
 * dei campi della Didattica (CampiModulo, LetturaRisposte) e il pubblico unico (App\Auth\Pubblico). Gli allegati dei
 * campi file stanno in una cartella diversa per ogni form (uploads/forms/<id>/), con lo stesso sistema delle pratiche.
 */
final class ServizioForms
{
    /** I codici di statoPerPersona(): si può rispondere, il modulo non è aperto (stato o periodo), è pieno, non è per il profilo, già risposto. */
    public const OK = 'ok';
    public const CHIUSO = 'chiuso';
    public const PIENO = 'pieno';
    public const PROFILO = 'profilo';
    public const GIA = 'gia';

    public function __construct(
        private FormRepository $forms,
        private RispostaRepository $risposte,
        private Upload $upload,
        private Sito $sito,
        private ScelteAnagrafe $scelte,
        private Database $db,
        private FileEnv $env,
        private Orologio $orologio,
        private ServizioPersone $persone,
        private UtenteRepository $utenti,
        private GruppiUtente $gruppi,
        private string $cartellaBase = 'uploads/forms/',
    ) {
    }

    /**
     * Nuovo form in «bozza». Ritorna l'id.
     *
     * @param array<string, mixed> $dati vedi FormRepository::crea()
     */
    public function crea(array $dati): int
    {
        return $this->forms->crea($this->forms->nuovoCodice(), $dati);
    }

    /** Dati generali e opzioni del form (titolo, campi, pubblico, periodo…): non cambia stato, codice né proprietario. @param array<string, mixed> $dati */
    public function modifica(int $id, array $dati): void
    {
        $this->forms->aggiorna($id, $dati);
    }

    /**
     * Copia di un form: nuovo codice, stato «bozza», senza risposte né co-gestori. Ritorna il nuovo id (null se il form non esiste).
     */
    public function duplica(int $id): ?int
    {
        $f = $this->forms->perId($id);
        if (!$f) {
            return null;
        }

        return $this->forms->crea($this->forms->nuovoCodice(), [
            'titolo' => $f['titolo'] . ' (copia)', 'descrizione' => $f['descrizione'], 'campi_json' => $f['campi_json'], 'pubblico_json' => $f['pubblico_json'],
            'una_per_persona' => $f['una_per_persona'], 'modificabile' => $f['modificabile'], 'anonima' => $f['anonima'], 'max_risposte' => $f['max_risposte'],
            'messaggio_fine' => $f['messaggio_fine'], 'email_notifica' => $f['email_notifica'], 'ricevuta_email' => $f['ricevuta_email'], 'conserva_mesi' => $f['conserva_mesi'],
            'proprietario_id' => $f['proprietario_id'],
        ]);
    }

    public function apri(int $id): void
    {
        $this->forms->impostaStato($id, 'aperta');
    }

    public function chiudi(int $id): void
    {
        $this->forms->impostaStato($id, 'chiusa');
    }

    /** Cartella (relativa alla radice del sito, con «/» finale) dove stanno gli allegati di questo form: mai presa dalla richiesta. */
    public function cartellaAllegati(int $formId): string
    {
        return $this->cartellaBase . $formId . '/';
    }

    /** Elimina il form con le sue risposte, i co-gestori e gli allegati caricati. */
    public function elimina(int $id): void
    {
        $this->forms->elimina($id);
        $dir = $this->sito->radice() . '/' . $this->cartellaBase . $id . '/';
        foreach (glob($dir . '*') ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($dir);
    }

    public function aggiungiGestore(int $formId, int $utenteId): void
    {
        $this->forms->aggiungiGestore($formId, $utenteId);
    }

    public function togliGestore(int $formId, int $utenteId): void
    {
        $this->forms->togliGestore($formId, $utenteId);
    }

    /** Amministratore, proprietario o co-gestore. @param array<string, mixed> $form @param array<string, mixed> $utente */
    public function puoGestire(array $form, array $utente): bool
    {
        if (Pubblico::ammesso(['profili' => []], $utente)) {
            return true;
        }
        if ((int) ($form['proprietario_id'] ?? 0) === (int) ($utente['id'] ?? 0)) {
            return true;
        }

        return in_array((int) ($utente['id'] ?? 0), $this->forms->gestori((int) $form['id']), true);
    }

    /** Amministratore o proprietario (non i co-gestori): chi può eliminare il form e cambiare i co-gestori. @param array<string, mixed> $form @param array<string, mixed> $utente */
    public function puoEliminare(array $form, array $utente): bool
    {
        if (Pubblico::ammesso(['profili' => []], $utente)) {
            return true;
        }

        return (int) ($form['proprietario_id'] ?? 0) === (int) ($utente['id'] ?? 0);
    }

    /** I form del pannello: tutti per l'amministratore, solo quelli di cui si è proprietari o co-gestori per chi è solo abilitato a MyForms. @param array<string, mixed> $utente @return list<array<string, mixed>> */
    public function visibiliA(array $utente): array
    {
        if (Pubblico::ammesso(['profili' => []], $utente)) {
            return $this->forms->tutti();
        }

        return $this->forms->deiGestiti((int) ($utente['id'] ?? 0));
    }

    /**
     * Aggiunge un co-gestore scelto dall'anagrafe di Ateneo: deve aver già fatto l'accesso al portale almeno una volta
     * (serve il suo id di utente, non solo l'anagrafica). Ritorna l'errore in italiano o null.
     */
    public function aggiungiGestoreDaAnagrafe(int $formId, string $personaId): ?string
    {
        $p = $this->persone->persona($personaId);
        if (!$p) {
            return "Persona non trovata nell'anagrafe di Ateneo.";
        }
        $email = strtolower(trim((string) ($p['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return "La persona scelta non ha un'email nell'anagrafe di Ateneo.";
        }
        $u = $this->utenti->perEmailOPersona($email, $personaId);
        if (!$u) {
            return "Questa persona non ha ancora fatto l'accesso al portale: deve accedere una prima volta, poi la aggiungi come co-gestore.";
        }
        $this->forms->aggiungiGestore($formId, (int) $u['id']);

        return null;
    }

    /**
     * Se la persona può rispondere ora: [sì/no, motivo in italiano (vuoto se sì)].
     * Per chi non ha fatto l'accesso «una sola risposta per persona» non si può garantire: ci pensa il limite per IP della pagina.
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed>|null $utente
     * @return array{0: bool, 1: string}
     */
    public function puoRispondere(array $form, ?array $utente): array
    {
        $s = $this->statoPerPersona($form, $utente);

        return [$s['codice'] === self::OK, $s['motivo']];
    }

    /**
     * Come puoRispondere(), ma dice anche PERCHÉ non si può (codice), così la pagina sceglie il messaggio giusto: «chiuso» (non aperto, fuori periodo),
     * «pieno» (numero massimo di risposte), «profilo» (il pubblico non comprende questa persona: se non ha fatto l'accesso può bastare accedere),
     * «gia» (una sola risposta per persona e ha già risposto). Il controllo è in quest'ordine.
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed>|null $utente
     * @param list<string>|null $gruppi i gruppi dell'utente già letti (per elencare molti form senza rileggerli ogni volta); null = si leggono qui
     * @return array{codice: string, motivo: string}
     */
    public function statoPerPersona(array $form, ?array $utente, ?array $gruppi = null): array
    {
        $motivo = $this->motivoNonAperto($form);
        if ($motivo !== '') {
            return ['codice' => self::CHIUSO, 'motivo' => $motivo];
        }
        if ((int) ($form['max_risposte'] ?? 0) > 0 && $this->risposte->conta((int) $form['id']) >= (int) $form['max_risposte']) {
            return ['codice' => self::PIENO, 'motivo' => 'Questo modulo ha raggiunto il numero massimo di risposte.'];
        }
        if (!Pubblico::ammesso($this->regola($form), $utente, $gruppi ?? $this->gruppiUtente($utente))) {
            return ['codice' => self::PROFILO, 'motivo' => 'Questo modulo non è aperto al tuo profilo.'];
        }
        if (!empty($form['una_per_persona']) && $this->rispostaDi($form, $utente) !== null) {
            return ['codice' => self::GIA, 'motivo' => 'Hai già risposto a questo modulo.'];
        }

        return ['codice' => self::OK, 'motivo' => ''];
    }

    /**
     * La risposta che questa persona ha già dato al form (la più recente), riconosciuta dall'impronta: funziona anche nei form anonimi, dove l'utente non
     * si salva. Senza accesso non c'è modo di riconoscere nessuno: null.
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed>|null $utente
     * @return array<string, mixed>|null
     */
    public function rispostaDi(array $form, ?array $utente): ?array
    {
        if (!$utente || empty($utente['id'])) {
            return null;
        }

        return $this->risposte->diPersona((int) $form['id'], $this->impronta((int) $form['id'], (int) $utente['id']));
    }

    /**
     * Se la persona può correggere ora la risposta già data (stesse condizioni di modificaRisposta(): form modificabile e aperto, risposta sua, persona
     * ancora ammessa).
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed> $risposta
     * @param array<string, mixed>|null $utente
     * @param list<string>|null $gruppi vedi statoPerPersona()
     */
    public function puoModificare(array $form, array $risposta, ?array $utente, ?array $gruppi = null): bool
    {
        return $this->erroreModifica($form, $risposta, $utente, $gruppi) === null;
    }

    /**
     * I form che si possono compilare ora (aperti, nel periodo, non pieni) e che sono per il profilo di chi guarda: senza accesso solo quelli aperti a
     * chiunque. Per ogni form c'è anche la risposta già data da questa persona (null se non ne ha date): serve a scrivere «già compilato».
     * Un form «una per persona» già compilato resta nell'elenco (con la sua risposta).
     *
     * @param array<string, mixed>|null $utente
     * @return list<array{form: array<string, mixed>, risposta: array<string, mixed>|null}>
     */
    public function apertiPer(?array $utente): array
    {
        $gruppi = $this->gruppiUtente($utente);
        $out = [];
        foreach ($this->forms->apertiAl($this->orologio->adesso()->format('Y-m-d H:i:s')) as $f) {
            $s = $this->statoPerPersona($f, $utente, $gruppi)['codice'];
            if ($s === self::OK || $s === self::GIA) {
                $out[] = ['form' => $f, 'risposta' => $this->rispostaDi($f, $utente)];
            }
        }

        return $out;
    }

    /**
     * Le risposte che questa persona ha dato ai form (per l'area personale), dalla più recente, con il form e se si possono ancora correggere. Si
     * riconoscono dall'impronta, non da utente_id (che nei form anonimi non si scrive).
     *
     * @param array<string, mixed>|null $utente
     * @return list<array{risposta: array<string, mixed>, form: array<string, mixed>, modificabile: bool}>
     */
    public function risposteDi(?array $utente): array
    {
        if (!$utente || empty($utente['id'])) {
            return [];
        }
        $impronte = [];
        foreach ($this->forms->idPubblicati() as $id) {
            $impronte[] = $this->impronta($id, (int) $utente['id']);
        }
        $righe = $this->risposte->dellePersone($impronte);
        $forms = $this->forms->perIds(array_map(static fn (array $r): int => (int) $r['form_id'], $righe));
        $gruppi = $this->gruppiUtente($utente);
        $out = [];
        foreach ($righe as $r) {
            $f = $forms[(int) $r['form_id']] ?? null;
            if ($f) {
                $out[] = ['risposta' => $r, 'form' => $f, 'modificabile' => $this->puoModificare($f, $r, $utente, $gruppi)];
            }
        }

        return $out;
    }

    /** I profili a cui è aperto il form, in parole («Studenti, Docenti»); vuoto se non ne ha scelto nessuno. @param array<string, mixed> $form */
    public function profiliInParole(array $form): string
    {
        $profili = $this->regola($form)['profili'] ?? [];
        $nomi = [];
        foreach (Pubblico::PROFILI as $p) {
            if (in_array($p, $profili, true)) {
                $nomi[] = Pubblico::ETICHETTE[$p] ?? $p;
            }
        }

        return implode(', ', $nomi);
    }

    /**
     * Salva una nuova risposta dal modulo compilato (POST e allegati). Controllo e inserimento stanno sotto un blocco per form (GET_LOCK), così due
     * invii nello stesso istante non superano «una per persona» né il numero massimo di risposte.
     * Ritorna ['ok' => bool, 'errori' => list<string>, 'id' => int|null, 'codice' => string|null].
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed>|null $utente
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @return array{ok: bool, errori: list<string>, id?: int, codice?: string}
     */
    public function salvaRisposta(array $form, ?array $utente, array $post, array $files, string $ip): array
    {
        $formId = (int) $form['id'];
        $blocco = 'forms_' . $formId;
        if ((int) ($this->db->valore('SELECT GET_LOCK(?, 5)', [$blocco]) ?? 0) !== 1) {
            return ['ok' => false, 'errori' => ['Riprova tra qualche secondo.']];
        }
        try {
            [$ok, $motivo] = $this->puoRispondere($form, $utente);
            if (!$ok) {
                return ['ok' => false, 'errori' => [$motivo]];
            }
            $campi = CampiModulo::da((string) ($form['campi_json'] ?? ''));
            $loggato = $utente !== null && !empty($utente['id']);
            if (!$loggato && self::haCampoFile($campi)) {
                return ['ok' => false, 'errori' => ["Questo modulo chiede un allegato: per rispondere devi prima fare l'accesso."]];
            }
            [$risposte, $errori] = $this->lettura($formId)->leggi($campi, $post, $files);
            if ($errori) {
                $this->scartaAllegati($risposte, []); // gli allegati già salvati di un invio che non va a buon fine non devono restare sul server
                return ['ok' => false, 'errori' => $errori];
            }
            $anonima = !empty($form['anonima']);
            $profilo = self::profiloPrincipale(Pubblico::profiliUtente($utente, $this->gruppiUtente($utente)));
            $utenteId = (!$anonima && $loggato) ? (int) $utente['id'] : null;
            $email = (!$anonima && $loggato && !empty($utente['email'])) ? mb_substr((string) $utente['email'], 0, 255) : null;
            // Sempre salvata per chi ha fatto l'accesso (anche nei form anonimi): serve a «una per persona» e a riconoscere la propria risposta
            $utenteImpronta = $loggato ? $this->impronta($formId, (int) $utente['id']) : null;
            $codice = bin2hex(random_bytes(16));
            $id = $this->risposte->inserisci($formId, $utenteId, $email, $profilo, (string) json_encode($risposte, JSON_UNESCAPED_UNICODE), $codice, $this->ipImpronta($ip), $utenteImpronta);

            return ['ok' => true, 'errori' => [], 'id' => $id, 'codice' => $codice];
        } finally {
            $this->db->valore('SELECT RELEASE_LOCK(?)', [$blocco]);
        }
    }

    /**
     * Nuove risposte a una risposta già data: solo se il form è «modificabile», è ancora aperto, la persona è ancora ammessa (il pubblico può essere
     * cambiato dopo l'invio) e la risposta è sua (riconosciuta dall'impronta, non dall'utente_id: così funziona anche nei form anonimi). Gli allegati
     * non ricaricati restano quelli di prima (come fa modulo.php con le pratiche: ServizioPratiche::conservaAllegati/senzaErroriAllegati).
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed> $risposta
     * @param array<string, mixed>|null $utente
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @return array{ok: bool, errori: list<string>}
     */
    public function modificaRisposta(array $form, array $risposta, ?array $utente, array $post, array $files): array
    {
        $errore = $this->erroreModifica($form, $risposta, $utente, null);
        if ($errore !== null) {
            return ['ok' => false, 'errori' => [$errore]];
        }
        $campi = CampiModulo::da((string) ($form['campi_json'] ?? ''));
        $vecchie = json_decode((string) ($risposta['risposte_json'] ?? ''), true);
        $vecchie = is_array($vecchie) ? $vecchie : [];
        [$risposte, $errori] = $this->lettura((int) $form['id'])->leggi($campi, $post, $files);
        $errori = ServizioPratiche::senzaErroriAllegati($errori, $campi, $vecchie);
        if ($errori) {
            $this->scartaAllegati($risposte, $vecchie); // via i nuovi allegati salvati per questo invio, restano quelli di prima
            return ['ok' => false, 'errori' => $errori];
        }
        $risposte = ServizioPratiche::conservaAllegati($risposte, $vecchie);
        $this->risposte->aggiorna((int) $risposta['id'], (string) json_encode($risposte, JSON_UNESCAPED_UNICODE));

        return ['ok' => true, 'errori' => []];
    }

    /**
     * Perché la risposta non si può modificare (messaggio in italiano), o null se si può.
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed> $risposta
     * @param array<string, mixed>|null $utente
     * @param list<string>|null $gruppi
     */
    private function erroreModifica(array $form, array $risposta, ?array $utente, ?array $gruppi): ?string
    {
        if (empty($form['modificabile'])) {
            return 'Questo modulo non permette di modificare la risposta.';
        }
        if ($this->motivoNonAperto($form) !== '') {
            return 'Questo modulo non è più aperto: non si può modificare la risposta.';
        }
        $impronta = (string) ($risposta['utente_impronta'] ?? '');
        if (!$utente || empty($utente['id']) || $impronta === '' || $this->impronta((int) $form['id'], (int) $utente['id']) !== $impronta) {
            return 'Questa risposta non è tua.';
        }
        if (!Pubblico::ammesso($this->regola($form), $utente, $gruppi ?? $this->gruppiUtente($utente))) {
            return 'Questo modulo non è aperto al tuo profilo.';
        }

        return null;
    }

    /** La regola del pubblico del form (['profili' => [...]]; vuota se manca o è illeggibile). @param array<string, mixed> $form @return array{profili?: list<string>} */
    private function regola(array $form): array
    {
        $r = json_decode((string) ($form['pubblico_json'] ?? ''), true);

        return is_array($r) ? $r : ['profili' => []];
    }

    /** Motivo per cui il form non è aperto ora (stato, aperta_dal, aperta_al); vuoto se è aperto. @param array<string, mixed> $form */
    private function motivoNonAperto(array $form): string
    {
        if (($form['stato'] ?? '') === 'chiusa') {
            return 'Questo modulo è chiuso: non accetta più risposte.';
        }
        if (($form['stato'] ?? '') !== 'aperta') {
            return 'Questo modulo non è aperto.';
        }
        $adesso = $this->orologio->adesso();
        if (!empty($form['aperta_dal']) && $adesso < new DateTimeImmutable((string) $form['aperta_dal'])) {
            return 'Questo modulo si apre il ' . date('d/m/Y \a\l\l\e H:i', strtotime((string) $form['aperta_dal'])) . '.';
        }
        if (!empty($form['aperta_al']) && $adesso > new DateTimeImmutable((string) $form['aperta_al'])) {
            return 'Questo modulo si è chiuso il ' . date('d/m/Y \a\l\l\e H:i', strtotime((string) $form['aperta_al'])) . '.';
        }

        return '';
    }

    /**
     * Elimina dal server gli allegati caricati in questo invio (le risposte lette) che non sono tra quelli già salvati: un invio con errori non li
     * deve lasciare in giro (come fa modulo.php per le pratiche). Si toccano solo file dentro la cartella del sito.
     *
     * @param list<array<string, mixed>> $lette
     * @param list<array<string, mixed>> $giaSalvate
     */
    private function scartaAllegati(array $lette, array $giaSalvate): void
    {
        $tenere = array_column($giaSalvate, 'file');
        foreach ($lette as $r) {
            $f = (string) ($r['file'] ?? '');
            if ($f === '' || in_array($f, $tenere, true) || str_contains($f, '..')) {
                continue;
            }
            $percorso = $this->sito->radice() . '/' . $f;
            if (is_file($percorso)) {
                @unlink($percorso);
            }
        }
    }

    private function lettura(int $formId): LetturaRisposte
    {
        return new LetturaRisposte(new AllegatiPratiche($this->upload, $this->sito, $this->cartellaBase . $formId . '/'), $this->scelte);
    }

    /** @param list<array<string, mixed>> $campi */
    private static function haCampoFile(array $campi): bool
    {
        foreach ($campi as $c) {
            if ($c['tipo'] === 'file') {
                return true;
            }
        }

        return false;
    }

    /** Il profilo più specifico da salvare nella risposta (non «registrati»): es. «studenti» invece di «registrati, studenti». @param list<string> $profili */
    private static function profiloPrincipale(array $profili): string
    {
        $specifici = array_values(array_diff($profili, [Pubblico::REGISTRATI]));

        return $specifici[0] ?? ($profili[0] ?? Pubblico::ANONIMI);
    }

    /** Nomi (minuscoli) dei gruppi dell'utente nella tabella «ruoli» (scuole, aziende…), per Pubblico::ammesso(). @param array<string, mixed>|null $u @return list<string> */
    private function gruppiUtente(?array $u): array
    {
        return $this->gruppi->nomi($u);
    }

    /** hash dell'IP con un sale del sito (la chiave di cifratura del .env): mai l'IP in chiaro. Null se l'IP è vuoto. */
    private function ipImpronta(string $ip): ?string
    {
        $ip = trim($ip);

        return $ip === '' ? null : hash('sha256', $ip . '|' . (string) $this->env->valore('CIFRATURA_CHIAVE'));
    }

    /**
     * Impronta della persona su questo form (hash di form_id|utente_id|chiave del .env): mai l'id da solo, e diversa da un form all'altro.
     * Sempre calcolabile per chi ha fatto l'accesso, anche nei form anonimi dove utente_id non si salva: è così che si riconosce «una per persona»
     * e la propria risposta da modificare.
     */
    private function impronta(int $formId, int $utenteId): string
    {
        return hash('sha256', $formId . '|' . $utenteId . '|' . (string) $this->env->valore('CIFRATURA_CHIAVE'));
    }
}
