<?php

declare(strict_types=1);

namespace App\Protocollo;

use App\Infrastructure\Sicurezza\Cifratura;
use Throwable;

/**
 * Invio di un documento a Titulus secondo la regola della richiesta (porta di services.py del connettore Python: protocolla_arrivo,
 * protocolla_partenza, protocolla_interno e le versioni «avvia iter»). Non lancia mai eccezioni: ogni invio, riuscito o no, finisce nel
 * registro (titulus_invii) e chi chiama riceve un EsitoProtocollo con il motivo già scritto in italiano.
 */
final class ServizioProtocollo
{
    public function __construct(
        private ProtocolloRepository $repo,
        private Cifratura $cifratura,
        private FabbricaClienteTitulus $fabbrica,
        private GeneratoreXml $xml,
    ) {
    }

    /** Il collegamento è acceso e completo? (testo del problema, null se si può inviare) */
    public function nonPronto(): ?string
    {
        $c = ConfigTitulus::daRiga($this->repo->configurazione());
        if (!$c->attivo) {
            return 'Il collegamento a Titulus non è attivo (si accende da Gestione del portale → Protocollo).';
        }

        return $c->incompleta();
    }

    public function protocolla(RichiestaProtocollo $r): EsitoProtocollo
    {
        $regola = $r->regolaId !== null ? $this->repo->regola($r->regolaId) : $this->repo->regolaPer($r->applicaA);
        // Documento tra uffici senza mittente indicato da chi chiama: si usa l'ufficio della regola (e il suo RPA, se c'è)
        if ($regola !== null && (string) $regola['tipo_documento'] === 'interno' && $r->mittenteInterno === null && trim((string) $regola['uo']) !== '') {
            $r = $r->conMittenteInterno([
                'nome' => trim((string) $regola['rpa_nome']) !== '' ? (string) $regola['rpa_nome'] : 'Portale Didattica DiBEST',
                'uff_nome' => CatalogoTitulus::UFFICI[(string) $regola['uo']] ?? (string) $regola['uo'],
                'uff_cod' => (string) $regola['uo'],
            ]);
        }

        return $this->esegui($r, $regola);
    }

    /** @param array<string, mixed>|null $regola */
    private function esegui(RichiestaProtocollo $r, ?array $regola): EsitoProtocollo
    {
        $tipo = (string) ($regola['tipo_documento'] ?? '');
        $azione = (string) ($regola['azione'] ?? '');
        try {
            $errore = $this->nonPronto() ?? $this->controlla($r, $regola);
            if ($errore !== null) {
                return $this->fallito($r, $regola, $errore);
            }
            \assert($regola !== null);
            $config = ConfigTitulus::daRiga($this->repo->configurazione());
            $password = $this->cifratura->decifra($this->repo->passwordCifrata());
            if ($password === null) {
                return $this->fallito($r, $regola, 'La password di Titulus non si legge: controlla che CIFRATURA_CHIAVE nel .env sia quella di quando è stata salvata, oppure salvala di nuovo.');
            }

            $bozza = $azione === 'iter';
            $uoNome = CatalogoTitulus::UFFICI[(string) $regola['uo']] ?? (string) $regola['uo'];
            $voce = $bozza ? (string) ($this->repo->voceIndice((int) $regola['voce_indice_id'])['voce'] ?? '') : '';
            $rep = $regola['repertorio_id'] ? (string) ($this->repo->repertorio((int) $regola['repertorio_id'])['codice'] ?? '') : '';
            $allegati = [$r->principale, ...$r->allegati];
            $esterno = $r->esterno + ['nome' => '', 'cognome' => '', 'codice_fiscale' => '', 'email' => '', 'telefono' => '', 'fax' => '', 'indirizzo' => ''];

            $xml = $this->xml->documento([
                'tipo' => $tipo,
                'bozza' => $bozza,
                'aoo' => $config->aoo,
                'autore' => $config->autore !== '' ? $config->autore : 'Portale Didattica DiBEST',
                // Come nel connettore: oggetto in maiuscolo e lungo almeno 20 caratteri
                'oggetto' => sprintf('%-20s', mb_strtoupper(trim($r->oggetto))),
                'num_allegati' => count($allegati),
                'cod_repertorio' => $rep !== '' ? $rep : null,
                'voce_indice' => $voce !== '' ? $voce : null,
                'cod_titolario' => (string) $regola['cod_titolario'],
                'classif' => '',
                'rpa_nome' => (string) $regola['rpa_nome'],
                'uo' => (string) $regola['uo'],
                'uo_nome' => $uoNome,
                'op' => $regola['op_uo'] !== '' ? [
                    'uo' => (string) $regola['op_uo'], 'nome' => (string) $regola['op_nome'], 'matricola' => (string) $regola['op_matricola'],
                    'uo_nome' => CatalogoTitulus::UFFICI[(string) $regola['op_uo']] ?? (string) $regola['op_uo'],
                ] : null,
                'cc' => array_map(static fn (array $c): array => [
                    'uo' => (string) $c['uo'], 'nome' => (string) $c['nome'], 'matricola' => (string) $c['matricola'],
                    'uo_nome' => CatalogoTitulus::UFFICI[(string) $c['uo']] ?? (string) $c['uo'],
                ], $this->repo->copieConoscenza((int) $regola['id'])),
                'esterno' => [
                    'nome' => trim($esterno['nome'] . ' ' . $esterno['cognome']),
                    'codice_fiscale' => $esterno['codice_fiscale'], 'email' => $esterno['email'], 'telefono' => $esterno['telefono'],
                    'fax' => $esterno['fax'], 'indirizzo' => $esterno['indirizzo'],
                ],
                'mittente_interno' => $r->mittenteInterno,
            ]);

            $cliente = $this->fabbrica->crea($config, $password);
            $salvato = $cliente->salvaDocumento($xml, $allegati, (int) $regola['invia_email_rpa'] === 1);
            if ($bozza && $salvato['nrecord'] === '') {
                return $this->fallito($r, $regola, 'Titulus ha salvato la bozza ma non ha restituito il numero di record: controllala in Titulus.');
            }
            if (!$bozza && $salvato['num_prot'] === '') {
                return $this->fallito($r, $regola, 'Titulus non ha assegnato il numero di protocollo: controlla in Titulus se il documento è stato registrato.');
            }

            // Fascicolazione separata: un'eventuale rifiuto non annulla il protocollo già assegnato, ma si segnala
            $nota = '';
            if ($config->fascicolazioneSeparata && (string) $regola['fascicolo_numero'] !== '') {
                try {
                    $cliente->fascicola($this->xml->fascicolo((string) $regola['fascicolo_numero'], $salvato['nrecord'], $salvato['num_prot']), (string) $regola['rpa_matricola']);
                } catch (Throwable $e) {
                    $nota = ' Il documento NON è stato inserito nel fascicolo ' . $regola['fascicolo_numero'] . ': ' . $e->getMessage();
                }
            }

            $msg = ($bozza ? 'Bozza salvata in Titulus (record ' . $salvato['nrecord'] . '): l\'iter di approvazione è partito.' : 'Protocollato in Titulus: ' . $salvato['num_prot'] . '.') . $nota;
            $id = $this->repo->registraInvio($this->riga($r, $regola, 'ok', $salvato['nrecord'], $salvato['num_prot'], $salvato['url'], $nota !== '' ? trim($nota) : null));

            return new EsitoProtocollo(true, $msg, $salvato['nrecord'], $salvato['num_prot'], $salvato['url'], $id);
        } catch (Throwable $e) {
            return $this->fallito($r, $regola, $e->getMessage());
        }
    }

    /**
     * Controlli prima dell'invio (gli stessi del connettore: dati del documento secondo il verso, fascicolo con la matricola dell'RPA, voce d'indice per l'iter).
     *
     * @param array<string, mixed>|null $regola
     */
    private function controlla(RichiestaProtocollo $r, ?array $regola): ?string
    {
        if ($regola === null) {
            return 'Nessuna regola di Titulus per questa richiesta (e nessuna regola generale): si crea in Gestione del portale → Protocollo.';
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
            $id = $this->repo->registraInvio($this->riga($r, $regola, 'errore', '', '', '', mb_substr($motivo, 0, 2000)));
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
            'nrecord' => $nrecord, 'num_prot' => $numProt, 'url' => $url, 'errore' => $errore, 'utente_id' => $r->utenteId,
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
            $c = ConfigTitulus::daRiga($this->repo->configurazione());
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
            if (($errore = $this->nonPronto()) !== null) {
                throw new \RuntimeException($errore);
            }
            $password = $this->cifratura->decifra($this->repo->passwordCifrata());
            if ($password === null) {
                throw new \RuntimeException('La password non si legge: salvala di nuovo.');
            }
            $cliente = $this->fabbrica->crea(ConfigTitulus::daRiga($this->repo->configurazione()), $password);
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
