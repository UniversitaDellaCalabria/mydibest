<?php

declare(strict_types=1);

namespace App\Forms;

use App\Core\Sito;

/**
 * Le risposte di un form viste da chi lo gestisce (admin/forms_risposte.php): elenco con filtri, una risposta, allegati, eliminazione, ZIP.
 * Il permesso (ServizioForms::puoGestire) lo controlla la pagina a ogni richiesta; qui si garantisce che una risposta o un allegato sia
 * davvero di QUEL form (l'id arriva dalla richiesta) e che nei form anonimi non esca mai chi ha risposto.
 */
final class ServizioRisposte
{
    public function __construct(private RispostaRepository $risposte, private ServizioForms $forms, private Sito $sito)
    {
    }

    /** @param array<string, mixed> $form @return list<string> i profili che compaiono tra le risposte */
    public function profiliPresenti(array $form): array
    {
        return $this->risposte->profili((int) $form['id']);
    }

    /**
     * Le risposte che passano i filtri, dalla più recente. Ogni riga ha anche «risposte» (le risposte decodificate). Nei form anonimi non c'è
     * nessun dato di chi ha risposto (nome, cognome, email, id, impronte): le righe vecchie di un form reso anonimo dopo restano comunque pulite.
     *
     * @param array<string, mixed> $form
     * @param array{q: string, dal: string, al: string, profilo: string} $filtri vedi FiltriRisposte::da()
     * @return list<array<string, mixed>>
     */
    public function elenco(array $form, array $filtri): array
    {
        $anonima = !empty($form['anonima']);
        $righe = $this->risposte->filtrate((int) $form['id'], !$anonima, $filtri['profilo'], $filtri['dal'], $filtri['al']);
        $out = [];
        foreach ($righe as $r) {
            $r = $this->ripulita($form, $r);
            if (FiltriRisposte::corrisponde($r, $filtri['q'], $anonima)) {
                $out[] = $r;
            }
        }

        return $out;
    }

    /**
     * Una risposta del form (null se non esiste o è di un altro form), ripulita come in elenco().
     *
     * @param array<string, mixed> $form
     * @return array<string, mixed>|null
     */
    public function risposta(array $form, int $id): ?array
    {
        $r = $this->risposte->delFormPerId((int) $form['id'], $id, empty($form['anonima']));

        return $r ? $this->ripulita($form, $r) : null;
    }

    /**
     * Gli allegati di una o più risposte del form: quelli che esistono davvero nella cartella di QUESTO form. Il percorso salvato nella risposta
     * non si fida: deve stare nella cartella del form, essere un file semplice (niente sottocartelle né «..») e risolvere dentro quella cartella.
     *
     * @param array<string, mixed> $form
     * @param list<array<string, mixed>> $righe righe di forms_risposte
     * @return list<array{risposta: int, indice: int, etichetta: string, nome: string, percorso: string}> percorso = file sul server
     */
    public function allegati(array $form, array $righe): array
    {
        $out = [];
        foreach ($righe as $r) {
            if ((int) ($r['form_id'] ?? 0) !== (int) $form['id']) {
                continue;
            }
            foreach (EsportazioneRisposte::decodifica($r) as $i => $a) {
                if (($a['tipo'] ?? '') !== 'file' || empty($a['file'])) {
                    continue;
                }
                $p = $this->percorsoSicuro((int) $form['id'], (string) $a['file']);
                if ($p !== null) {
                    $nome = trim((string) ($a['nome_file'] ?? '')) !== '' ? (string) $a['nome_file'] : basename($p);
                    $out[] = ['risposta' => (int) $r['id'], 'indice' => $i, 'etichetta' => (string) ($a['etichetta'] ?? ''), 'nome' => $nome, 'percorso' => $p];
                }
            }
        }

        return $out;
    }

    /**
     * Un allegato di una risposta (la domanda numero $indice tra le risposte salvate): null se la risposta non è di questo form, la domanda non è
     * un allegato o il file non c'è / non sta nella cartella del form.
     *
     * @param array<string, mixed> $form
     * @return array{risposta: int, indice: int, etichetta: string, nome: string, percorso: string}|null
     */
    public function allegato(array $form, int $rispostaId, int $indice): ?array
    {
        $r = $this->risposte->delFormPerId((int) $form['id'], $rispostaId);
        if (!$r) {
            return null;
        }
        foreach ($this->allegati($form, [$r]) as $a) {
            if ($a['indice'] === $indice) {
                return $a;
            }
        }

        return null;
    }

    /** Elimina una risposta del form con i suoi allegati. False se la risposta non è di questo form. @param array<string, mixed> $form */
    public function elimina(array $form, int $rispostaId): bool
    {
        $r = $this->risposte->delFormPerId((int) $form['id'], $rispostaId);
        if (!$r) {
            return false;
        }
        $this->cancellaAllegati($form, [$r]);
        $this->risposte->elimina($rispostaId);

        return true;
    }

    /**
     * Elimina le risposte del form inviate prima di questo momento («Y-m-d H:i:s», escluso), con i loro allegati, una per una con elimina() (stessa
     * cancellazione di chi gestisce il form). Serve alla conservazione automatica del cron. Ritorna quante ne ha eliminate.
     *
     * @param array<string, mixed> $form
     */
    public function eliminaPrima(array $form, string $prima): int
    {
        $n = 0;
        foreach ($this->risposte->idInviatePrima((int) $form['id'], $prima) as $id) {
            if ($this->elimina($form, $id)) {
                ++$n;
            }
        }

        return $n;
    }

    /** Elimina tutte le risposte del form con gli allegati. Ritorna quante erano. @param array<string, mixed> $form */
    public function eliminaTutte(array $form): int
    {
        $righe = $this->risposte->delForm((int) $form['id']);
        $this->cancellaAllegati($form, $righe);
        $this->risposte->eliminaDelForm((int) $form['id']);

        return count($righe);
    }

    /**
     * Un ZIP con gli allegati delle risposte indicate (un file temporaneo: chi chiama lo manda e lo cancella). Dentro, ogni file si chiama
     * «risposta-<n>_<domanda>_<nome>». Null se manca ZipArchive, se non ci sono allegati o se il file non si riesce a creare.
     *
     * @param array<string, mixed> $form
     * @param list<array<string, mixed>> $righe
     */
    public function zip(array $form, array $righe): ?string
    {
        $allegati = $this->allegati($form, $righe);
        if (!$allegati || !class_exists(\ZipArchive::class)) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new \ZipArchive();
        if ($tmp === false || $zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            return null;
        }
        $usati = [];
        foreach ($allegati as $a) {
            $ext = strtolower(pathinfo($a['percorso'], PATHINFO_EXTENSION));
            $base = self::pulisciNome(pathinfo($a['nome'], PATHINFO_FILENAME));
            $nome = 'risposta-' . $a['risposta'] . '_' . self::pulisciNome($a['etichetta'], 40) . '_' . $base;
            $nome .= $ext !== '' ? '.' . $ext : '';
            for ($n = 2; isset($usati[$nome]); $n++) {
                $nome = 'risposta-' . $a['risposta'] . '_' . self::pulisciNome($a['etichetta'], 40) . '_' . $base . '-' . $n . ($ext !== '' ? '.' . $ext : '');
            }
            $usati[$nome] = true;
            $zip->addFile($a['percorso'], $nome);
        }
        $ok = $zip->close();

        return $ok ? $tmp : null;
    }

    /**
     * Toglie dalla riga ciò che non si deve mai mostrare (nei form anonimi chi ha risposto; sempre le impronte) e aggiunge le risposte decodificate.
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function ripulita(array $form, array $r): array
    {
        unset($r['utente_impronta'], $r['ip_impronta']);
        if (!empty($form['anonima'])) {
            unset($r['utente_id'], $r['email'], $r['nome'], $r['cognome']);
        }
        $r['risposte'] = EsportazioneRisposte::decodifica($r);

        return $r;
    }

    /**
     * Cancella dal disco gli allegati delle risposte (solo file nella cartella di questo form, come allegati()).
     *
     * @param array<string, mixed> $form
     * @param list<array<string, mixed>> $righe
     */
    private function cancellaAllegati(array $form, array $righe): void
    {
        foreach ($this->allegati($form, $righe) as $a) {
            @unlink($a['percorso']);
        }
    }

    /** Il percorso assoluto del file solo se sta davvero nella cartella degli allegati di questo form. */
    private function percorsoSicuro(int $formId, string $file): ?string
    {
        $dir = $this->forms->cartellaAllegati($formId);
        if ($file === '' || str_contains($file, "\0") || str_contains($file, '..') || str_contains($file, '\\') || !str_starts_with($file, $dir)) {
            return null;
        }
        if (str_contains(substr($file, strlen($dir)), '/')) {
            return null; // gli allegati stanno direttamente nella cartella del form
        }
        $base = realpath($this->sito->radice() . '/' . $dir);
        $p = realpath($this->sito->radice() . '/' . $file);
        if ($base === false || $p === false || !str_starts_with($p, $base . DIRECTORY_SEPARATOR) || !is_file($p)) {
            return null;
        }

        return $p;
    }

    /** Un nome adatto a un file dentro lo ZIP: lettere, numeri, trattini; max $lunghezza caratteri. */
    private static function pulisciNome(string $nome, int $lunghezza = 80): string
    {
        $nome = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '-', $nome), '-');

        return mb_substr($nome !== '' ? $nome : 'file', 0, $lunghezza);
    }
}
