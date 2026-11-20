<?php

declare(strict_types=1);

namespace App\Forms;

use App\Core\Sito;
use App\Email\InvioEmail;
use Throwable;

/**
 * Le email di MyForms dopo una risposta: la ricevuta a chi ha risposto (se il form la prevede e se ne conosce l'email dall'accesso) e l'avviso agli
 * indirizzi di chi gestisce il form (campo «email_notifica»). Nei form anonimi l'avviso non dice chi ha risposto. I testi sono nel catalogo delle email
 * (CatalogoForms, modificabili dalla pagina «Email» del pannello); qui si decide chi le riceve e si preparano i dati. Un errore di invio non si
 * propaga mai (il salvataggio della risposta è già avvenuto): resta nel log.
 */
final class NotificheForms
{
    public function __construct(private InvioEmail $email, private Sito $sito)
    {
    }

    /**
     * Ricevuta e avviso per una nuova risposta appena salvata. Non fa mai fallire chi chiama.
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed>|null $utente chi ha risposto (null = senza accesso)
     * @param array<string, mixed> $risposta la riga di forms_risposte (serve codice_ricevuta e inviata_il)
     */
    public function rispostaInviata(array $form, ?array $utente, array $risposta): void
    {
        try {
            $this->ricevuta($form, $utente, $risposta);
        } catch (Throwable $e) {
            error_log('[MyForms] ricevuta non inviata: ' . $e->getMessage());
        }
        try {
            $this->avvisoAGestori($form, $utente, $risposta);
        } catch (Throwable $e) {
            error_log('[MyForms] avviso non inviato: ' . $e->getMessage());
        }
    }

    /**
     * Avviso al proprietario: il modulo si è chiuso da solo (fine del periodo o numero massimo di risposte). Nessun dato di chi ha risposto, solo il numero.
     * Non lancia mai eccezioni; ritorna se l'email è partita.
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed>|null $proprietario
     * @param string $perche «periodo» (è finito il periodo di apertura) o «massimo» (raggiunto il numero massimo di risposte)
     */
    public function formChiuso(array $form, ?array $proprietario, int $risposte, string $perche): bool
    {
        $titolo = $this->titolo($form);
        $motivo = $perche === 'massimo'
            ? 'ha raggiunto il numero massimo di risposte (' . (int) ($form['max_risposte'] ?? 0) . ')'
            : 'è finito il periodo di apertura' . (!empty($form['aperta_al']) && strtotime((string) $form['aperta_al']) ? ' (il ' . date('d/m/Y \a\l\l\e H:i', (int) strtotime((string) $form['aperta_al'])) . ')' : '');

        return $this->alProprietario($proprietario, 'forms_chiuso', [
            'titolo' => $titolo, 'motivo' => $motivo, 'numero' => $risposte, 'risposte_parola' => $risposte === 1 ? 'risposta' : 'risposte',
        ], $this->linkRisposte($form), 'chiusura');
    }

    /**
     * Avviso al proprietario: tra 7 giorni le risposte inviate prima di una certa data si cancellano da sole (conservazione impostata sul modulo).
     * Non lancia mai eccezioni; ritorna se l'email è partita.
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed>|null $proprietario
     * @param string $cancellazione giorno in cui la cancellazione può avvenire («Y-m-d H:i:s»)
     * @param string $prima le risposte inviate prima di questo momento («Y-m-d H:i:s») sono quelle che si cancellano
     */
    public function avvisoConservazione(array $form, ?array $proprietario, int $quante, string $cancellazione, string $prima): bool
    {
        $titolo = $this->titolo($form);
        $mesi = (int) ($form['conserva_mesi'] ?? 0);
        $giorno = static fn (string $d): string => ($t = strtotime($d)) ? date('d/m/Y', $t) : '';

        return $this->alProprietario($proprietario, 'forms_conservazione', [
            'titolo' => $titolo, 'mesi' => $mesi, 'mesi_parola' => $mesi === 1 ? 'mese' : 'mesi', 'cancellazione' => $giorno($cancellazione),
            'quante' => $quante, 'risposte_parola' => $quante === 1 ? 'risposta' : 'risposte', 'prima' => $giorno($prima),
        ], $this->linkRisposte($form), 'conservazione');
    }

    /**
     * @param array<string, scalar|null> $dati valori dei segnaposto del modello
     * @param array<string, mixed>|null $proprietario
     */
    private function alProprietario(?array $proprietario, string $modello, array $dati, string $link, string $quale): bool
    {
        $email = $proprietario ? trim((string) ($proprietario['email'] ?? '')) : '';
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            error_log('[MyForms] avviso di ' . $quale . ' non inviato: il proprietario non ha un indirizzo email valido');

            return false;
        }
        try {
            if ($this->email->invia($email, $modello, $dati, $link)) {
                return true;
            }
            error_log('[MyForms] avviso di ' . $quale . ' non inviato al proprietario: ' . $this->email->ultimoErrore());
        } catch (Throwable $e) {
            error_log('[MyForms] avviso di ' . $quale . ' non inviato al proprietario: ' . $e->getMessage());
        }

        return false;
    }

    /** @param array<string, mixed> $form */
    private function linkRisposte(array $form): string
    {
        return $this->sito->urlBase() . '/admin/forms_risposte.php?id=' . (int) $form['id'];
    }

    /**
     * Gli indirizzi validi di un elenco scritto a mano (separati da virgole, punti e virgola o a capo), senza ripetizioni, al massimo 10.
     *
     * @return list<string>
     */
    public static function indirizzi(string $elenco): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $elenco, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $e) {
            $e = strtolower(trim($e));
            if (filter_var($e, FILTER_VALIDATE_EMAIL) !== false && !in_array($e, $out, true)) {
                $out[] = $e;
            }
        }

        return array_slice($out, 0, 10);
    }

    /** @param array<string, mixed> $form @param array<string, mixed>|null $utente @param array<string, mixed> $risposta */
    private function ricevuta(array $form, ?array $utente, array $risposta): void
    {
        // Form anonimo: niente ricevuta per email, nemmeno a chi ha fatto l'accesso (ogni email resta nel registro log_email con destinatario e titolo,
        // e si potrebbe abbinare la persona alla risposta anonima)
        if (!empty($form['anonima'])) {
            return;
        }
        $email = $utente ? trim((string) ($utente['email'] ?? '')) : '';
        if (empty($form['ricevuta_email']) || $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }
        // Se il modulo si può correggere, la ricevuta è quella con la frase e il pulsante per riaprirlo
        $modificabile = !empty($form['modificabile']);
        $dati = ['titolo' => $this->titolo($form), 'inviata_il' => self::quando($risposta), 'codice' => (string) $risposta['codice_ricevuta']];
        if (!$this->email->invia($email, $modificabile ? 'forms_ricevuta_modificabile' : 'forms_ricevuta', $dati, $modificabile ? $this->linkForm($form) : '')) {
            error_log('[MyForms] ricevuta non inviata a una persona: ' . $this->email->ultimoErrore());
        }
    }

    /** @param array<string, mixed> $form @param array<string, mixed>|null $utente @param array<string, mixed> $risposta */
    private function avvisoAGestori(array $form, ?array $utente, array $risposta): void
    {
        $destinatari = self::indirizzi((string) ($form['email_notifica'] ?? ''));
        if (!$destinatari) {
            return;
        }
        // $chi è già HTML sicuro (testo fisso, oppure nome ed email passati da h()).
        // Form anonimo: nessun nome e nessuna email nell'avviso, nemmeno di chi ha fatto l'accesso
        if (!empty($form['anonima'])) {
            $chi = 'La risposta è anonima.';
        } elseif ($utente && !empty($utente['id'])) {
            $nome = trim((string) ($utente['nome'] ?? '') . ' ' . (string) ($utente['cognome'] ?? ''));
            $chi = 'Risposta di <strong>' . self::h($nome !== '' ? $nome : 'una persona') . '</strong>' . (trim((string) ($utente['email'] ?? '')) !== '' ? ' (' . self::h((string) $utente['email']) . ')' : '') . '.';
        } else {
            $chi = 'Ha risposto una persona che non ha fatto l\'accesso.';
        }
        $dati = ['titolo' => $this->titolo($form), 'inviata_il' => self::quando($risposta), 'chi' => $chi];
        foreach ($destinatari as $a) {
            if (!$this->email->invia($a, 'forms_nuova_risposta', $dati, $this->linkRisposte($form))) {
                error_log('[MyForms] avviso non inviato a un indirizzo: ' . $this->email->ultimoErrore());
            }
        }
    }

    /** @param array<string, mixed> $form */
    private function titolo(array $form): string
    {
        $t = trim(preg_replace('/\s+/', ' ', (string) ($form['titolo'] ?? '')) ?? '');

        return $t !== '' ? $t : 'senza titolo';
    }

    /** @param array<string, mixed> $form */
    private function linkForm(array $form): string
    {
        return $this->sito->urlBase() . '/form.php?c=' . rawurlencode((string) $form['codice']);
    }

    /** «17/11/2026 alle 10:00» dalla data della risposta. @param array<string, mixed> $risposta */
    private static function quando(array $risposta): string
    {
        $t = strtotime((string) ($risposta['inviata_il'] ?? ''));

        return $t ? date('d/m/Y \a\l\l\e H:i', $t) : '';
    }

    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
