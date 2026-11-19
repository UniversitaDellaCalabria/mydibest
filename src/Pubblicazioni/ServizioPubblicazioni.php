<?php

declare(strict_types=1);

namespace App\Pubblicazioni;

use App\Auth\Abilitazioni\AbilitazioniRepository;
use App\Auth\GruppiUtente;
use App\Auth\Pubblico;
use App\Core\Database;
use App\Core\Esito;
use App\Core\IndirizzoClient;
use App\Core\Orologio;
use App\Infrastructure\Audit\AuditLog;
use RuntimeException;
use Throwable;

/**
 * Le richieste di pubblicazione dei docenti e la tabella di monitoraggio (il file Excel).
 *
 * La richiesta del docente NON entra da sola nella tabella di monitoraggio: arriva come «ricevuta» con nel_monitoraggio = 0 e le colonne degli
 * operatori vuote; ci entra solo quando un operatore preme «Invia al file Excel» (inviaAlMonitoraggio), e in quel momento si precompilano D, E
 * e i destinatari L, P, Q, R. Si può togliere (togliDalMonitoraggio) senza perdere le colonne compilate. Le righe a mano (creaManuale) entrano subito.
 *
 * Chi gestisce le richieste (puoGestire: amministratori e abilitati al modulo «Richieste di pubblicazione» del pannello) lo decide una funzione sola,
 * usata dalle pagine del pannello e da puoVedere; qui si controlla anche che chi invia sia docente.
 * Ogni operazione degli operatori lascia una riga nel registro (log_attivita, AuditLog).
 */
final class ServizioPubblicazioni
{
    public function __construct(
        private PubblicazioniRepository $repo,
        private AllegatiPubblicazioni $file,
        private Database $db,
        private Orologio $orologio,
        private AuditLog $audit,
        private IndirizzoClient $client,
        private GruppiUtente $gruppi,
        private NotifichePubblicazioni $notifiche,
        private AbilitazioniRepository $abilitazioni,
    ) {
    }

    /**
     * Gli errori campo per campo (vuoto = tutto a posto): vedi RegolePubblicazione::valida().
     *
     * @param array<string, mixed> $dati
     * @param array{allegati?: list<array<string, mixed>>, immagini?: list<array<string, mixed>>} $file
     * @return array<string, string>
     */
    public function valida(array $dati, array $file): array
    {
        return RegolePubblicazione::valida($dati, $file, $this->oggi());
    }

    /** Se l'utente può inviare una richiesta: chi ha il profilo «docenti» (e, come in tutto il portale, gli amministratori). @param array<string, mixed>|null $utente */
    public function puoInviare(?array $utente): bool
    {
        return $utente !== null && !empty($utente['id']) && Pubblico::ammesso(['profili' => [Pubblico::DOCENTI]], $utente, $this->gruppi->nomi($utente));
    }

    /**
     * La richiesta del docente: riga e file salvati insieme (o niente). Stato «ricevuta», NON nel monitoraggio, colonne degli operatori vuote.
     * Il richiedente («Cognome Nome») si prende dall'account. Ritorna l'id della richiesta (0 se non salvata) e gli errori campo per campo
     * (chiave «_» per quelli generali).
     *
     * @param array<string, mixed> $utente la riga di utenti
     * @param array<string, mixed> $dati
     * @param array{allegati?: list<array<string, mixed>>, immagini?: list<array<string, mixed>>} $file
     * @return array{id: int, errori: array<string, string>}
     */
    public function invia(array $utente, array $dati, array $file): array
    {
        if (!$this->puoInviare($utente)) {
            return ['id' => 0, 'errori' => ['_' => 'Le richieste di pubblicazione possono inviarle solo i docenti.']];
        }
        $errori = $this->valida($dati, $file);
        if ($errori) {
            return ['id' => 0, 'errori' => $errori];
        }
        $campi = RegolePubblicazione::normalizza($dati) + [
            'utente_id' => (int) $utente['id'],
            'richiedente' => self::nomeCognome($utente),
            'origine' => 'form',
            'stato' => 'ricevuta',
            'inviata_il' => $this->adesso(),
            'nel_monitoraggio' => 0,
        ];
        $id = $this->salvaConFile($campi, $file);
        if ($id === 0) {
            return ['id' => 0, 'errori' => ['_' => 'Non è stato possibile salvare la richiesta: riprova tra qualche minuto.']];
        }
        // Avviso all'ufficio (indirizzi di ImpostazioniPubblicazioni) e ricevuta al docente: un errore di invio resta nel log e non annulla il salvataggio
        try {
            $this->notifiche->richiestaInviata($utente, $id);
        } catch (Throwable $e) {
            error_log('[Pubblicazioni] email non inviate: ' . $e->getMessage());
        }

        return ['id' => $id, 'errori' => []];
    }

    /**
     * Chi gestisce le richieste di pubblicazione (pannello, scheda, tabella di monitoraggio, impostazioni): gli amministratori e chi ha il modulo
     * «Richieste di pubblicazione» abilitato (Utenti e abilitazioni). Un solo posto per tutte le pagine.
     *
     * @param array<string, mixed>|null $utente la riga di utenti
     */
    public function puoGestire(?array $utente): bool
    {
        if ($utente === null || empty($utente['id'])) {
            return false;
        }
        // Pubblico::ammesso con nessun profilo ammette solo gli amministratori
        if (Pubblico::ammesso(['profili' => []], $utente)) {
            return true;
        }

        // Il modulo intero è un perimetro «modulo_pubblicazioni» (pagina_id 0), come gli altri moduli (ServizioAbilitazioni::haModulo)
        foreach ($this->abilitazioni->ambitiDi((int) $utente['id']) as $a) {
            if ($a['tipo'] === 'modulo_pubblicazioni' && $a['pagina_id'] === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Chi può vedere la richiesta e scaricarne i file: il docente che l'ha inviata e chi gestisce le richieste (puoGestire).
     *
     * @param array<string, mixed>|null $utente la riga di utenti
     * @param array<string, mixed> $p la riga di pubblicazioni
     */
    public function puoVedere(?array $utente, array $p): bool
    {
        if ($utente === null || empty($utente['id'])) {
            return false;
        }
        if (!empty($p['utente_id']) && (int) $p['utente_id'] === (int) $utente['id']) {
            return true;
        }

        return $this->puoGestire($utente);
    }

    /**
     * Il file di una richiesta pronto da scaricare, solo se l'utente può vederla e il file è davvero nella cartella degli allegati.
     * Ritorna null in tutti gli altri casi (richiesta o file inesistenti, utente senza permesso): chi chiama risponde «File non trovato» senza dire perché.
     *
     * @param array<string, mixed>|null $utente
     * @return array{percorso: string, nome: string, estensione: string}|null
     */
    public function fileDaScaricare(?array $utente, int $id, int $allegatoId): ?array
    {
        $p = $this->repo->perId($id);
        if ($p === null || !$this->puoVedere($utente, $p)) {
            return null;
        }
        $a = $this->repo->allegato($id, $allegatoId);
        $percorso = $a !== null ? $this->file->percorsoAssoluto((string) $a['percorso']) : null;
        if ($a === null || $percorso === null) {
            return null;
        }

        return ['percorso' => $percorso, 'nome' => (string) $a['nome_originale'], 'estensione' => strtolower(pathinfo($percorso, PATHINFO_EXTENSION))];
    }

    /**
     * «Invia al file Excel»: la richiesta entra nella tabella di monitoraggio. Rifiutata se è respinta o già dentro. Precompila SOLO le colonne
     * ancora vuote: D con l'URL del docente, E con gli altri link, L/P/Q/R con «SI» per i destinatari spuntati (se la riga era stata tolta e rimessa,
     * quanto già scritto dagli operatori resta com'è).
     *
     * @param array<string, mixed> $operatore la riga di utenti di chi preme il pulsante
     */
    public function inviaAlMonitoraggio(int $id, array $operatore): Esito
    {
        $p = $this->repo->perId($id);
        if (!$p) {
            return Esito::errore('La richiesta non esiste più.');
        }
        if ($p['stato'] === 'respinta') {
            return Esito::errore('Una richiesta respinta non si può inviare al file Excel.');
        }
        if ((int) $p['nel_monitoraggio'] === 1) {
            return Esito::errore('La richiesta è già nel file Excel.');
        }
        $uid = (int) ($operatore['id'] ?? 0);
        $precompilate = array_filter(self::precompilazione($p), static fn (string $v, string $k): bool => self::vuota($p[$k] ?? null), ARRAY_FILTER_USE_BOTH);
        $this->db->transazione(function () use ($id, $uid, $precompilate): void {
            $this->repo->impostaMonitoraggio($id, true, $uid, $this->adesso());
            $this->repo->precompila($id, $precompilate);
        });
        $this->registra($uid, 'Richieste di pubblicazione: inviata al file Excel', ['id' => $id, 'titolo' => (string) $p['titolo'], 'precompilate' => array_keys($precompilate)]);

        return Esito::ok('La richiesta è nel file Excel.');
    }

    /** «Togli dal file Excel»: la riga esce dalla tabella di monitoraggio; le colonne compilate restano salvate. @param array<string, mixed> $operatore */
    public function togliDalMonitoraggio(int $id, array $operatore): Esito
    {
        $p = $this->repo->perId($id);
        if (!$p) {
            return Esito::errore('La richiesta non esiste più.');
        }
        if ((int) $p['nel_monitoraggio'] !== 1) {
            return Esito::errore('La richiesta non è nel file Excel.');
        }
        $uid = (int) ($operatore['id'] ?? 0);
        $this->repo->impostaMonitoraggio($id, false, null, null);
        $this->registra($uid, 'Richieste di pubblicazione: tolta dal file Excel', ['id' => $id, 'titolo' => (string) $p['titolo']]);

        return Esito::ok('La richiesta non è più nel file Excel. Le colonne compilate restano salvate.');
    }

    /**
     * Una riga a mano (iniziativa arrivata per altre vie): obbligatori solo data dell'evento, richiedente (lo scrive l'operatore) e titolo; il resto
     * è facoltativo ma, se compilato, ha gli stessi controlli di formato del form (RegolePubblicazione::valida con $aMano = true). Entra subito nella tabella di monitoraggio, con la stessa precompilazione di inviaAlMonitoraggio;
     * le colonne degli operatori eventualmente indicate in $dati si salvano subito. Stato: quello indicato (non «respinta»), altrimenti «ricevuta».
     *
     * @param array<string, mixed> $dati
     * @param array<string, mixed> $operatore
     * @param array{allegati?: list<array<string, mixed>>, immagini?: list<array<string, mixed>>} $file
     * @return array{id: int, errori: array<string, string>}
     */
    public function creaManuale(array $dati, array $operatore, array $file = []): array
    {
        $errori = RegolePubblicazione::valida($dati, $file, $this->oggi(), true);
        $richiedente = RegolePubblicazione::richiedente($dati);
        [$colonne, $erroriColonne] = self::colonneDa($dati);
        $errori += $erroriColonne;
        if ($errori) {
            return ['id' => 0, 'errori' => $errori];
        }
        $uid = (int) ($operatore['id'] ?? 0);
        $stato = (string) ($dati['stato'] ?? '');
        $adesso = $this->adesso();
        $normali = RegolePubblicazione::normalizza($dati);
        $campi = $normali + array_filter($colonne, static fn (?string $v): bool => $v !== null) + [
            'utente_id' => null,
            'richiedente' => $richiedente,
            'origine' => 'manuale',
            'stato' => isset(Costanti::STATI[$stato]) && $stato !== 'respinta' ? $stato : 'ricevuta',
            'inviata_il' => $adesso,
            'nel_monitoraggio' => 1,
            'monitoraggio_dal' => $adesso,
            'monitoraggio_da' => $uid ?: null,
        ];
        foreach (self::precompilazione($normali) as $k => $v) {
            if (self::vuota($campi[$k] ?? null)) {
                $campi[$k] = $v;
            }
        }
        $id = $this->salvaConFile($campi, $file);
        if ($id === 0) {
            return ['id' => 0, 'errori' => ['_' => 'Non è stato possibile salvare la riga: riprova tra qualche minuto.']];
        }
        $this->registra($uid, 'Richieste di pubblicazione: riga aggiunta a mano', ['id' => $id, 'titolo' => (string) $normali['titolo']]);

        return ['id' => $id, 'errori' => []];
    }

    /**
     * Salva le colonne degli operatori e lo stato. I campi del docente non si toccano mai. Le colonne si salvano solo se la riga è nella tabella
     * di monitoraggio (lo stato si cambia sempre). Passare a «respinta» una riga nel monitoraggio la toglie dal monitoraggio (la pagina chiede conferma).
     * Scrive modificata_il/da e una riga nel registro con le colonne cambiate (vecchio → nuovo). Le colonne SI/NO accettano solo SI, NO o vuoto;
     * «N. Partecipanti» solo un numero intero o vuoto.
     *
     * @param array<string, mixed> $dati colonne degli operatori (ColonneMonitoraggio::colonneOperatori) e «stato»; le altre chiavi si ignorano
     * @param array<string, mixed> $operatore
     */
    public function aggiornaOperatori(int $id, array $dati, array $operatore): Esito
    {
        $p = $this->repo->perId($id);
        if (!$p) {
            return Esito::errore('La richiesta non esiste più.');
        }
        $stato = array_key_exists('stato', $dati) ? (string) $dati['stato'] : (string) $p['stato'];
        if (!isset(Costanti::STATI[$stato])) {
            return Esito::errore('Lo stato scelto non è valido.');
        }
        $dentro = (int) $p['nel_monitoraggio'] === 1;
        $cambi = [];
        $colonne = [];
        if ($dentro) {
            [$nuove, $errori] = self::colonneDa($dati);
            if ($errori) {
                return Esito::errore(implode(' ', $errori));
            }
            foreach ($nuove as $k => $nuovo) {
                $vecchio = self::vuota($p[$k] ?? null) ? null : (string) $p[$k];
                if ($nuovo !== $vecchio) {
                    $colonne[$k] = $nuovo;
                    $cambi[$k] = ['da' => $vecchio, 'a' => $nuovo];
                }
            }
        }
        if ($stato !== $p['stato']) {
            $cambi['stato'] = ['da' => $p['stato'], 'a' => $stato];
        }
        if ($cambi === []) {
            return Esito::ok('Non c\'era nulla da salvare.');
        }
        $togli = $dentro && $stato === 'respinta';
        if ($togli) {
            $cambi['nel_monitoraggio'] = ['da' => 1, 'a' => 0];
        }
        $uid = (int) ($operatore['id'] ?? 0);
        $adesso = $this->adesso();
        $this->db->transazione(function () use ($id, $colonne, $stato, $p, $togli, $uid, $adesso): void {
            if ($colonne) {
                $this->repo->aggiornaColonneOperatori($id, $colonne, $uid, $adesso);
            }
            if ($stato !== $p['stato']) {
                $this->repo->cambiaStato($id, $stato, $uid, $adesso);
            }
            if ($togli) {
                $this->repo->impostaMonitoraggio($id, false, null, null);
            }
        });
        $this->registra($uid, 'Richieste di pubblicazione: modifica', ['id' => $id, 'titolo' => (string) $p['titolo'], 'cambi' => $cambi]);

        return Esito::ok($togli ? 'Salvato. La richiesta è respinta ed è stata tolta dal file Excel.' : 'Modifiche salvate.');
    }

    /** Elimina la richiesta con i suoi file (la pagina chiede conferma). @param array<string, mixed> $operatore */
    public function elimina(int $id, array $operatore): Esito
    {
        $p = $this->repo->perId($id);
        if (!$p || !$this->repo->elimina($id)) {
            return Esito::errore('La richiesta non esiste più.');
        }
        $this->registra((int) ($operatore['id'] ?? 0), 'Richieste di pubblicazione: eliminata', [
            'id' => $id, 'titolo' => (string) $p['titolo'], 'richiedente' => (string) $p['richiedente'], 'data_evento' => (string) $p['data_evento'],
        ]);

        return Esito::ok('Richiesta eliminata, con i suoi file.');
    }

    /**
     * I valori che «Invia al file Excel» propone per le colonne degli operatori: D = URL del docente, E = altri link, L/P/Q/R = «SI» per i
     * destinatari spuntati (solo i valori non vuoti).
     *
     * @param array<string, mixed> $p la riga (o i dati normalizzati)
     * @return array<string, string>
     */
    public static function precompilazione(array $p): array
    {
        $v = [
            'd_url_pubblicazione' => trim((string) ($p['url_docente'] ?? '')),
            'e_url_altre_pagine' => trim((string) ($p['altri_link_docente'] ?? '')),
        ];
        foreach (Costanti::DESTINATARI as $campo => [, $colonna]) {
            if (!empty($p[$campo])) {
                $v[$colonna] = 'SI';
            }
        }

        return array_filter($v, static fn (string $x): bool => $x !== '');
    }

    /**
     * Gli errori delle colonne degli operatori in $dati, campo per campo (SI/NO, numero intero): serve alla scheda per scriverli accanto al campo,
     * senza perdere quanto scritto. Gli stessi controlli di aggiornaOperatori.
     *
     * @param array<string, mixed> $dati
     * @return array<string, string>
     */
    public static function erroriColonne(array $dati): array
    {
        return self::colonneDa($dati)[1];
    }

    /** «Cognome Nome» dell'account. @param array<string, mixed> $utente */
    public static function nomeCognome(array $utente): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', trim((string) ($utente['cognome'] ?? '')) . ' ' . trim((string) ($utente['nome'] ?? ''))));
    }

    /**
     * Le colonne degli operatori presenti in $dati, ripulite (testo senza spazi ai lati, '' → null, al massimo MAX_COLONNA caratteri), e gli errori
     * delle colonne SI/NO e numero.
     *
     * @param array<string, mixed> $dati
     * @return array{0: array<string, string|null>, 1: array<string, string>}
     */
    private static function colonneDa(array $dati): array
    {
        $out = [];
        $errori = [];
        foreach (ColonneMonitoraggio::colonneOperatori() as $k) {
            if (!array_key_exists($k, $dati)) {
                continue;
            }
            $v = mb_substr(trim(str_replace("\r\n", "\n", (string) $dati[$k])), 0, Costanti::MAX_COLONNA);
            $tipo = ColonneMonitoraggio::perChiave($k)['tipo'] ?? 'testo';
            if ($v !== '' && $tipo === 'si_no') {
                $v = mb_strtoupper($v);
                if (!in_array($v, ['SI', 'NO'], true)) {
                    $errori[$k] = ColonneMonitoraggio::etichetta($k) . ': scegli SI, NO o lascia vuoto.';
                }
            }
            if ($v !== '' && $tipo === 'numero' && !ctype_digit($v)) {
                $errori[$k] = ColonneMonitoraggio::etichetta($k) . ': scrivi un numero intero o lascia vuoto.';
            }
            $out[$k] = $v === '' ? null : $v;
        }

        return [$out, $errori];
    }

    /**
     * Riga e file in una transazione: se un file non si salva non resta nulla (i file già copiati si cancellano). Ritorna l'id, 0 se non riesce.
     *
     * @param array<string, mixed> $campi
     * @param array{allegati?: list<array<string, mixed>>, immagini?: list<array<string, mixed>>} $file
     */
    private function salvaConFile(array $campi, array $file): int
    {
        $salvati = [];
        try {
            return $this->db->transazione(function () use ($campi, $file, &$salvati): int {
                $id = $this->repo->inserisci($campi);
                if ($id <= 0) {
                    throw new RuntimeException('riga non inserita');
                }
                foreach (['allegati' => 'allegato', 'immagini' => 'immagine'] as $chiave => $tipo) {
                    foreach ($file[$chiave] ?? [] as $f) {
                        $percorso = $this->file->salva($f, $tipo);
                        if ($percorso === null) {
                            throw new RuntimeException('file non salvato: ' . (string) ($f['name'] ?? ''));
                        }
                        $salvati[] = $percorso;
                        $this->repo->inserisciAllegato($id, $tipo, basename((string) ($f['name'] ?? 'file')), $percorso, (int) ($f['size'] ?? 0), (string) $campi['inviata_il']);
                    }
                }

                return $id;
            });
        } catch (Throwable $e) {
            error_log('[Pubblicazioni] richiesta non salvata: ' . $e->getMessage());
            foreach ($salvati as $s) {
                $this->file->elimina($s);
            }

            return 0;
        }
    }

    /** @param array<string, mixed> $dettagli */
    private function registra(int $uid, string $azione, array $dettagli): void
    {
        $this->audit->registra($uid, $azione, $dettagli, $this->client->ip() ?? 'Sconosciuto');
    }

    private static function vuota(mixed $v): bool
    {
        return $v === null || trim((string) $v) === '';
    }

    private function adesso(): string
    {
        return $this->orologio->adesso()->format('Y-m-d H:i:s');
    }

    private function oggi(): string
    {
        return $this->orologio->adesso()->format('Y-m-d');
    }
}
