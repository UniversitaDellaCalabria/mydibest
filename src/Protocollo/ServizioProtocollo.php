<?php

declare(strict_types=1);

namespace App\Protocollo;

use App\Core\Ambiente;
use App\Infrastructure\Sicurezza\Cifratura;
use Throwable;
use Unical\Titulus\CatalogoTitulus;
use Unical\Titulus\ClienteTitulus;
use Unical\Titulus\ConfigTitulus;
use Unical\Titulus\DocumentoDaProtocollare;
use Unical\Titulus\EsitoProtocollo;
use Unical\Titulus\FabbricaClienteTitulus;
use Unical\Titulus\GeneratoreXml;
use Unical\Titulus\ParametriProtocollo;
use Unical\Titulus\ServizioTitulus;

/**
 * Invio di un documento a Titulus secondo la regola della richiesta (porta di services.py del connettore Python: protocolla_arrivo,
 * protocolla_partenza, protocolla_interno e le versioni «avvia iter»). Non lancia mai eccezioni: ogni invio, riuscito o no, finisce nel
 * registro (titulus_invii) e chi chiama riceve un EsitoProtocollo con il motivo già scritto in italiano.
 */
final class ServizioProtocollo
{
    /** Il file letto da Titulus non può superare questa dimensione (30 MB). */
    public const MAX_BYTE_FILE = 30 * 1024 * 1024;

    /** Il nome del server di Titulus configurato, letto una volta sola (il registro degli invii controlla un indirizzo per riga). */
    private ?string $hostConfigurato = null;

    public function __construct(
        private ProtocolloRepository $repo,
        private Cifratura $cifratura,
        private FabbricaClienteTitulus $fabbrica,
        private GeneratoreXml $xml,
        private ?Ambiente $ambiente = null,
    ) {
    }

    /** Il collegamento è acceso e completo? (testo del problema, null se si può inviare) */
    public function nonPronto(): ?string
    {
        $c = $this->config();
        if (!$c->attivo) {
            return 'Il collegamento a Titulus non è attivo (si accende da Sistema → Protocollo).';
        }

        return $c->incompleta();
    }

    /**
     * Copia di prova (AMBIENTE=prova): si invia solo se l'indirizzo è di pre-produzione di Cineca; altrimenti si rifiuta e il motivo
     * finisce nel registro degli invii (come ogni altro rifiuto). Il pulsante resta visibile: così il motivo si legge nel registro.
     */
    private function vietatoInProva(): ?string
    {
        return ($this->ambiente ?? Ambiente::corrente())->titulusVietato($this->config()->urlWs);
    }

    public function protocolla(RichiestaProtocollo $r): EsitoProtocollo
    {
        $regola = $r->regolaId !== null ? $this->repo->regola($r->regolaId) : $this->repo->regolaPer($r->applicaA);
        // Documento tra uffici senza mittente indicato da chi chiama: si usa l'ufficio della regola (e il suo RPA, se c'è)
        if ($regola !== null && (string) $regola['tipo_documento'] === 'interno' && $r->mittenteInterno === null && trim((string) $regola['uo']) !== '') {
            $r = $r->conMittenteInterno([
                'nome' => trim((string) $regola['rpa_nome']) !== '' ? (string) $regola['rpa_nome'] : 'Portale MyDiBEST',
                'uff_nome' => CatalogoTitulus::UFFICI[(string) $regola['uo']] ?? (string) $regola['uo'],
                'uff_cod' => (string) $regola['uo'],
            ]);
        }

        return $this->esegui($r, $regola);
    }

    /**
     * Le regole, la password e il registro sono del portale; l'invio vero (XML, SOAP, fascicolazione, controlli sul documento) lo fa il
     * pacchetto Unical\Titulus, a cui qui si passano i parametri ricavati dalla regola.
     *
     * @param array<string, mixed>|null $regola
     */
    private function esegui(RichiestaProtocollo $r, ?array $regola): EsitoProtocollo
    {
        try {
            $errore = $this->nonPronto() ?? $this->vietatoInProva() ?? $this->controlla($r, $regola);
            if ($errore !== null) {
                return $this->fallito($r, $regola, $errore);
            }
            \assert($regola !== null);
            $password = $this->cifratura->decifra($this->repo->passwordCifrata());
            if ($password === null) {
                return $this->fallito($r, $regola, 'La password di Titulus non si legge: controlla che CIFRATURA_CHIAVE nel .env sia quella di quando è stata salvata, oppure salvala di nuovo.');
            }

            $bozza = (string) $regola['azione'] === 'iter';
            $voce = $bozza ? (string) ($this->repo->voceIndice((int) $regola['voce_indice_id'])['voce'] ?? '') : '';
            $rep = $regola['repertorio_id'] ? (string) ($this->repo->repertorio((int) $regola['repertorio_id'])['codice'] ?? '') : '';
            $parametri = new ParametriProtocollo(
                tipo: (string) $regola['tipo_documento'],
                uo: (string) $regola['uo'],
                codTitolario: (string) $regola['cod_titolario'],
                rpaNome: (string) $regola['rpa_nome'],
                azione: (string) $regola['azione'],
                rpaMatricola: (string) $regola['rpa_matricola'],
                voceIndice: $voce,
                codRepertorio: $rep,
                fascicoloNumero: (string) $regola['fascicolo_numero'],
                inviaEmailRpa: (int) $regola['invia_email_rpa'] === 1,
                op: $regola['op_uo'] !== '' ? ['uo' => (string) $regola['op_uo'], 'nome' => (string) $regola['op_nome'], 'matricola' => (string) $regola['op_matricola']] : null,
                cc: array_map(static fn (array $c): array => ['uo' => (string) $c['uo'], 'nome' => (string) $c['nome'], 'matricola' => (string) $c['matricola']], $this->repo->copieConoscenza((int) $regola['id'])),
            );
            $documento = new DocumentoDaProtocollare($r->oggetto, $r->principale, $r->allegati, $r->esterno, $r->mittenteInterno, $r->rifTipo, $r->rifId);

            $titulus = new ServizioTitulus($this->config(), $password, $this->fabbrica, new RegistroInviiPortale($this->repo, $r, $regola), $this->xml);

            return $titulus->protocolla($documento, $parametri);
        } catch (Throwable $e) {
            return $this->fallito($r, $regola, $e->getMessage());
        }
    }

    /** Configurazione di collegamento letta dal database (autore di riserva: il portale). */
    private function config(): ConfigTitulus
    {
        $riga = $this->repo->configurazione();
        if (trim((string) ($riga['autore'] ?? '')) === '') {
            $riga['autore'] = 'Portale MyDiBEST';
        }

        return ConfigTitulus::daArray($riga);
    }

    /**
     * Controlli prima dell'invio (gli stessi del connettore: dati del documento secondo il verso, fascicolo con la matricola dell'RPA, voce d'indice per l'iter).
     *
     * @param array<string, mixed>|null $regola
     */
    private function controlla(RichiestaProtocollo $r, ?array $regola): ?string
    {
        if ($regola === null) {
            return 'Nessuna regola di Titulus per questa richiesta (e nessuna regola generale): si crea in Sistema → Protocollo.';
        }
        if ((int) $regola['attiva'] !== 1) {
            return 'La regola «' . $regola['nome'] . '» è disattivata.';
        }
        if (trim($r->oggetto) === '') {
            return 'Manca l\'oggetto del documento.';
        }
        if (trim((string) $regola['uo']) === '' || trim((string) $regola['cod_titolario']) === '') {
            return 'La regola «' . $regola['nome'] . '» non ha l\'UO o il codice del titolario.';
        }
        if ((string) $regola['fascicolo_numero'] !== '' && trim((string) $regola['rpa_matricola']) === '') {
            return 'La regola «' . $regola['nome'] . '» indica un fascicolo ma non la matricola dell\'RPA, che serve per inserire il documento.';
        }
        if ($regola['azione'] === 'iter' && (int) $regola['voce_indice_id'] === 0) {
            return 'La regola «' . $regola['nome'] . '» avvia l\'iter ma non ha la voce d\'indice.';
        }
        $esterno = $r->esterno + ['nome' => '', 'cognome' => '', 'codice_fiscale' => ''];
        if ($regola['tipo_documento'] === 'interno') {
            $m = $r->mittenteInterno;
            if ($m === null || trim($m['nome']) === '' || trim($m['uff_nome']) === '' || trim($m['uff_cod']) === '') {
                return 'Per un documento tra uffici servono nome, ufficio e codice dell\'ufficio del mittente.';
            }
        } elseif (trim($esterno['nome'] . $esterno['cognome']) === '') {
            return $regola['tipo_documento'] === 'arrivo' ? 'Manca il nome di chi scrive.' : 'Manca il nome del destinatario.';
        } elseif ($regola['tipo_documento'] === 'partenza' && trim($esterno['codice_fiscale']) === '') {
            return 'Per un documento in partenza serve il codice fiscale del destinatario.';
        }

        return null;
    }

    /** @param array<string, mixed>|null $regola */
    private function fallito(RichiestaProtocollo $r, ?array $regola, string $motivo): EsitoProtocollo
    {
        try {
            $id = (new RegistroInviiPortale($this->repo, $r, $regola))->registra($this->riga($r, $regola, 'errore', '', '', '', mb_substr($motivo, 0, 2000)));
        } catch (Throwable) {
            $id = 0;
        }

        return new EsitoProtocollo(false, $motivo, invioId: $id);
    }

    /**
     * @param array<string, mixed>|null $regola
     * @return array<string, mixed>
     */
    private function riga(RichiestaProtocollo $r, ?array $regola, string $esito, string $nrecord, string $numProt, string $url, ?string $errore): array
    {
        return [
            'regola_id' => $regola['id'] ?? null, 'applica_a' => $r->applicaA, 'rif_tipo' => $r->rifTipo, 'rif_id' => $r->rifId, 'oggetto' => $r->oggetto,
            'tipo_documento' => (string) ($regola['tipo_documento'] ?? ''), 'azione' => (string) ($regola['azione'] ?? ''), 'esito' => $esito,
            'nrecord' => $nrecord, 'num_prot' => $numProt, 'url' => $url, 'errore' => $errore, 'file_principale' => $r->principale->nomeFile, 'utente_id' => $r->utenteId,
        ];
    }

    /**
     * Controlla il collegamento senza creare nulla in Titulus (carica il WSDL e verifica le operazioni). Salva l'esito in configurazione.
     *
     * @return array{ok: bool, messaggio: string}
     */
    public function provaConnessione(): array
    {
        try {
            $c = $this->config();
            if (($manca = $c->incompleta()) !== null) {
                throw new \RuntimeException($manca);
            }
            $password = $this->cifratura->decifra($this->repo->passwordCifrata());
            if ($password === null) {
                throw new \RuntimeException('La password non si legge: salvala di nuovo (serve CIFRATURA_CHIAVE nel .env).');
            }
            $messaggio = $this->fabbrica->crea($c, $password)->prova();
            $this->repo->registraProva('OK: ' . $messaggio);

            return ['ok' => true, 'messaggio' => $messaggio];
        } catch (Throwable $e) {
            $this->repo->registraProva('ERRORE: ' . $e->getMessage());

            return ['ok' => false, 'messaggio' => $e->getMessage()];
        }
    }

    /**
     * Il file di un documento già protocollato, ripreso da Titulus (sola lettura: non scrive in Titulus né nel registro). Parte dal registro degli
     * invii (solo gli invii riusciti, l'ultimo per primo): si usa il numero di record, se manca il numero di protocollo. Non lancia mai eccezioni.
     *
     * @return array{ok: bool, messaggio: string, contenuto: string, titolo: string, id: string}
     */
    public function scaricaFile(string $rifTipo, int $rifId): array
    {
        try {
            foreach ($this->repo->invii(50, $rifTipo, $rifId) as $i) {
                if ($this->inviatoEConNumero($i)) {
                    return $this->leggiFile($this->idDelloInvio($i), (string) ($i['file_principale'] ?? ''));
                }
            }

            return $this->nonLetto('Titulus non ha ancora ricevuto questo documento: nel registro degli invii non c\'è un invio riuscito.');
        } catch (Throwable $e) {
            return $this->nonLetto($e->getMessage());
        }
    }

    /**
     * Il file di UNO specifico invio del registro (la riga del registro degli invii nel pannello): stesse regole di scaricaFile().
     *
     * @return array{ok: bool, messaggio: string, contenuto: string, titolo: string, id: string}
     */
    public function scaricaFileDelloInvio(int $invioId): array
    {
        try {
            $invio = $this->repo->invio($invioId);
            if ($invio === null || !$this->inviatoEConNumero($invio)) {
                return $this->nonLetto('Questo invio non è riuscito o non ha un numero di record né di protocollo: non c\'è nulla da riprendere da Titulus.');
            }

            return $this->leggiFile($this->idDelloInvio($invio), (string) ($invio['file_principale'] ?? ''));
        } catch (Throwable $e) {
            return $this->nonLetto($e->getMessage());
        }
    }

    /**
     * Il file di un documento protocollato a mano, dal suo numero di protocollo scritto da chi lavora (non c'è un invio nel registro).
     * Se i file sono più di uno serve il titolo del principale, altrimenti non si sa quale prendere.
     *
     * @return array{ok: bool, messaggio: string, contenuto: string, titolo: string, id: string}
     */
    public function scaricaFilePerNumero(string $numero, ?string $titoloPrincipale = null): array
    {
        $numero = trim($numero);
        if ($numero === '' || preg_match('/^[A-Za-z0-9._\/-]{1,100}$/', $numero) !== 1) {
            return $this->nonLetto('Il numero di protocollo non è scritto in modo valido (lettere, cifre, trattini e barre, senza spazi).');
        }

        return $this->leggiFile($numero, (string) $titoloPrincipale);
    }

    /**
     * L'indirizzo per aprire il documento di un invio in Titulus: quello già salvato, altrimenti lo chiede a Titulus (getDocumentURL) e lo salva.
     *
     * @return array{ok: bool, messaggio: string, url: string}
     */
    public function indirizzoDelloInvio(int $invioId): array
    {
        try {
            $invio = $this->repo->invio($invioId);
            if ($invio === null || (string) $invio['esito'] !== 'ok') {
                return ['ok' => false, 'messaggio' => 'Invio non trovato o non riuscito.', 'url' => ''];
            }
            if ($this->indirizzoSicuro((string) $invio['url']) !== null) {
                return ['ok' => true, 'messaggio' => 'Indirizzo già salvato.', 'url' => trim((string) $invio['url'])];
            }
            if ((string) $invio['nrecord'] === '' && (string) $invio['num_prot'] === '') {
                return ['ok' => false, 'messaggio' => 'L\'invio non ha né il numero di record né il numero di protocollo.', 'url' => ''];
            }
            $cliente = $this->clienteInLettura();
            if (is_string($cliente)) {
                return ['ok' => false, 'messaggio' => $cliente, 'url' => ''];
            }
            $url = trim((string) ($cliente->urlDocumento($this->idDelloInvio($invio)) ?? ''));
            if ($url === '') {
                return ['ok' => false, 'messaggio' => 'Titulus non ha dato l\'indirizzo del documento.', 'url' => ''];
            }
            if ($this->indirizzoSicuro($url) === null) {
                return ['ok' => false, 'messaggio' => 'L\'indirizzo dato da Titulus non è verso il server di Titulus configurato: non lo salvo.', 'url' => ''];
            }
            $this->repo->salvaUrl($invioId, $url);

            return ['ok' => true, 'messaggio' => 'Indirizzo preso da Titulus e salvato.', 'url' => $url];
        } catch (Throwable $e) {
            return ['ok' => false, 'messaggio' => $e->getMessage(), 'url' => ''];
        }
    }

    /**
     * L'indirizzo, solo se è sicuro da proporre come link: https, senza utente e password nell'indirizzo e con l'host (non il testo) uguale a quello
     * configurato di Titulus oppure che finisce per «.cineca.it». Altrimenti null.
     */
    public function indirizzoSicuro(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 500 || preg_match('/[\x00-\x20\x7F\\\\<>"\']/', $url) === 1) {
            return null;
        }
        $p = parse_url($url);
        if ($p === false || strtolower((string) ($p['scheme'] ?? '')) !== 'https' || isset($p['user']) || isset($p['pass'])) {
            return null;
        }
        $host = strtolower((string) ($p['host'] ?? ''));
        if ($host === '') {
            return null;
        }
        $configurato = $this->hostConfigurato ??= strtolower((string) parse_url(trim($this->config()->urlWs), PHP_URL_HOST));

        return ($configurato !== '' && $host === $configurato) || str_ends_with($host, '.cineca.it') ? $url : null;
    }

    /** @param array<string, mixed> $invio */
    private function inviatoEConNumero(array $invio): bool
    {
        return (string) $invio['esito'] === 'ok' && ((string) $invio['nrecord'] !== '' || (string) $invio['num_prot'] !== '');
    }

    /** @param array<string, mixed> $invio */
    private function idDelloInvio(array $invio): string
    {
        return (string) $invio['nrecord'] !== '' ? (string) $invio['nrecord'] : (string) $invio['num_prot'];
    }

    /** @return array{ok: bool, messaggio: string, contenuto: string, titolo: string, id: string} */
    private function nonLetto(string $messaggio): array
    {
        return ['ok' => false, 'messaggio' => $messaggio, 'contenuto' => '', 'titolo' => '', 'id' => ''];
    }

    /**
     * Il cliente di Titulus per leggere, o il motivo (testo) per cui non si può: collegamento spento o incompleto, copia di prova con un
     * indirizzo che non è di pre-produzione, password che non si legge.
     */
    private function clienteInLettura(): ClienteTitulus|string
    {
        $errore = $this->nonPronto() ?? $this->vietatoInProva();
        if ($errore !== null) {
            return $errore;
        }
        $password = $this->cifratura->decifra($this->repo->passwordCifrata());
        if ($password === null) {
            return 'La password di Titulus non si legge: controlla che CIFRATURA_CHIAVE nel .env sia quella di quando è stata salvata, oppure salvala di nuovo.';
        }

        return $this->fabbrica->crea($this->config(), $password);
    }

    /**
     * @return array{ok: bool, messaggio: string, contenuto: string, titolo: string, id: string}
     */
    private function leggiFile(string $id, string $titoloPrincipale): array
    {
        try {
            $errore = $this->nonPronto() ?? $this->vietatoInProva();
            if ($errore !== null) {
                return $this->nonLetto($errore);
            }
            $password = $this->cifratura->decifra($this->repo->passwordCifrata());
            if ($password === null) {
                return $this->nonLetto('La password di Titulus non si legge: controlla che CIFRATURA_CHIAVE nel .env sia quella di quando è stata salvata, oppure salvala di nuovo.');
            }
            // Nessun registro: leggere non crea invii
            $titulus = new ServizioTitulus($this->config(), $password, $this->fabbrica, null, $this->xml);

            $elenco = $titulus->fileDelDocumento($id);
            if (!$elenco['ok']) {
                return $this->nonLetto($elenco['messaggio']);
            }
            $file = $elenco['file'][0];
            if (count($elenco['file']) > 1) {
                // Se Titulus segna un solo file come principale è quello; altrimenti il titolo inviato; mai uno a caso
                $principali = array_values(array_filter($elenco['file'], static fn (array $f): bool => ($f['principale'] ?? false) === true));
                $trovato = count($principali) === 1 ? $principali[0] : self::riconosciPrincipale($elenco['file'], $titoloPrincipale);
                if ($trovato === null) {
                    return $this->nonLetto('Il documento ha ' . count($elenco['file']) . ' file in Titulus e non riesco a capire quale è il principale'
                        . (trim($titoloPrincipale) !== '' ? ' (cercavo «' . mb_substr(trim($titoloPrincipale), 0, 120) . '»)' : ' (non so con che titolo è stato inviato)')
                        . ': non ne prendo uno a caso.');
                }
                $file = $trovato;
            }

            $letto = $titulus->scaricaFile($file['fileId']);
            if (!$letto['ok']) {
                return $this->nonLetto($letto['messaggio']);
            }
            if (strlen($letto['contenuto']) > self::MAX_BYTE_FILE) {
                return $this->nonLetto('Il file in Titulus è più grande di ' . (self::MAX_BYTE_FILE / 1048576) . ' MB: non lo scarico.');
            }
            // L'impronta che Titulus dichiara per quella versione deve essere quella del contenuto scaricato; se non la dichiara si va avanti
            $dichiarata = strtolower(trim((string) ($file['sha256'] ?? '')));
            if ($dichiarata !== '' && !hash_equals($dichiarata, hash('sha256', $letto['contenuto']))) {
                return $this->nonLetto('Il file «' . mb_substr($file['titolo'], 0, 120) . '» scaricato da Titulus non ha la stessa impronta (SHA-256) che Titulus dichiara: il download è corrotto o incompleto, non lo accetto. Riprova.');
            }
            if (!str_starts_with($letto['contenuto'], '%PDF-')) {
                return $this->nonLetto('Il file «' . mb_substr($file['titolo'], 0, 120) . '» in Titulus non è un PDF: come documento protocollato accetto solo un PDF.');
            }

            return ['ok' => true, 'messaggio' => 'File ripreso da Titulus.', 'contenuto' => $letto['contenuto'], 'titolo' => $file['titolo'], 'id' => $file['fileId']];
        } catch (Throwable $e) {
            return $this->nonLetto($e->getMessage());
        }
    }

    /**
     * Il file principale tra quelli di un documento: quello con il titolo inviato come principale (senza badare alle maiuscole), oppure lo
     * stesso titolo senza estensione se è uno solo. Se non si riconosce, o i titoli uguali sono due o più, null: mai uno a caso.
     *
     * @param list<array{titolo: string, fileId: string, principale?: bool, sha256?: ?string}> $file
     * @return array{titolo: string, fileId: string, principale?: bool, sha256?: ?string}|null
     */
    public static function riconosciPrincipale(array $file, string $titoloInviato): ?array
    {
        $cerca = mb_strtolower(trim($titoloInviato));
        if ($cerca === '') {
            return null;
        }
        $uguali = array_values(array_filter($file, static fn (array $f): bool => mb_strtolower(trim($f['titolo'])) === $cerca));
        if (count($uguali) === 1) {
            return $uguali[0];
        }
        if ($uguali !== []) {
            return null;
        }
        $senza = static fn (string $t): string => (string) preg_replace('/\.[a-z0-9]{1,5}$/', '', mb_strtolower(trim($t)));
        $simili = array_values(array_filter($file, static fn (array $f): bool => $senza($f['titolo']) === $senza($titoloInviato)));

        return count($simili) === 1 ? $simili[0] : null;
    }

    /**
     * Un'iter ha dato solo il record: quando in Titulus è concluso, qui si legge il numero di protocollo e lo si scrive nel registro.
     *
     * @return array{ok: bool, messaggio: string}
     */
    public function aggiornaNumero(int $invioId): array
    {
        $invio = $this->repo->invio($invioId);
        if ($invio === null || (string) $invio['nrecord'] === '') {
            return ['ok' => false, 'messaggio' => 'Invio non trovato o senza numero di record.'];
        }
        try {
            if (($errore = $this->nonPronto() ?? $this->vietatoInProva()) !== null) {
                throw new \RuntimeException($errore);
            }
            $password = $this->cifratura->decifra($this->repo->passwordCifrata());
            if ($password === null) {
                throw new \RuntimeException('La password non si legge: salvala di nuovo.');
            }
            $cliente = $this->fabbrica->crea($this->config(), $password);
            $info = $cliente->infoRecord((string) $invio['nrecord']);
            $num = (string) ($info['num_prot'] ?? '');
            if ($num === '') {
                return ['ok' => false, 'messaggio' => 'L\'iter non è ancora concluso: il documento non ha il numero di protocollo.'];
            }
            $this->repo->aggiornaNumero($invioId, $num, (string) ($cliente->urlDocumento($num) ?? ''));

            return ['ok' => true, 'messaggio' => "Protocollo assegnato: $num."];
        } catch (Throwable $e) {
            return ['ok' => false, 'messaggio' => $e->getMessage()];
        }
    }
}
