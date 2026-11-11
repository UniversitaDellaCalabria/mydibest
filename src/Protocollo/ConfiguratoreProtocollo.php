<?php

declare(strict_types=1);

namespace App\Protocollo;

use App\Infrastructure\Sicurezza\Cifratura;

/** Salvataggio e controllo di ciò che l'amministratore inserisce nel pannello «Protocollo». Ogni metodo ritorna null (o l'id) se va bene, altrimenti il motivo. */
final class ConfiguratoreProtocollo
{
    public function __construct(private ProtocolloRepository $repo, private Cifratura $cifratura)
    {
    }

    /** Titolario con la sola riga di descrizione per codice: serve ai controlli e alla lista a tendina. @return array<string, string> */
    public static function titolarioPiatto(): array
    {
        $out = [];
        foreach (CatalogoTitulus::TITOLARIO as $voci) {
            $out += $voci;
        }

        return $out;
    }

    /** @param array<string, mixed> $p dati del modulo «Collegamento» */
    public function salvaCollegamento(array $p): ?string
    {
        $url = trim((string) ($p['url_ws'] ?? ''));
        $nome = mb_substr(trim((string) ($p['nome'] ?? '')), 0, 255);
        $user = mb_substr(trim((string) ($p['username'] ?? '')), 0, 255);
        $aoo = mb_substr(trim((string) ($p['aoo'] ?? '')), 0, 12);
        $attivo = !empty($p['attivo']);
        if ($nome === '') {
            return 'Dai un nome alla configurazione (es. «Titulus DiBEST»).';
        }
        if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true))) {
            return 'L\'indirizzo del servizio non è un indirizzo web valido (deve cominciare con https://).';
        }
        $nuova = trim((string) ($p['password'] ?? ''));
        $cifrata = null;
        if ($nuova !== '') {
            $cifrata = $this->cifratura->cifra($nuova);
            if ($cifrata === null) {
                return 'Non posso conservare la password in modo sicuro: manca CIFRATURA_CHIAVE nel file .env del server (almeno 16 caratteri casuali).';
            }
        }
        if ($attivo) {
            if (($manca = (new ConfigTitulus(true, $nome, $url, '', $user, $aoo, '', '', '', true))->incompleta()) !== null) {
                return "Per accendere il collegamento serve completarlo: $manca";
            }
            if ($nuova === '' && $this->repo->passwordCifrata() === '') {
                return 'Per accendere il collegamento serve la password dell\'utenza di Titulus.';
            }
        }
        $email = trim((string) ($p['email_default'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'L\'e-mail predefinita non è valida.';
        }
        $this->repo->salvaConfigurazione([
            'nome' => $nome, 'url_ws' => $url, 'namespace_ns2' => mb_substr(trim((string) ($p['namespace_ns2'] ?? '')), 0, 300), 'username' => $user, 'aoo' => $aoo,
            'agd' => mb_substr(trim((string) ($p['agd'] ?? '')), 0, 12), 'autore' => mb_substr(trim((string) ($p['autore'] ?? '')), 0, 150), 'email_default' => $email,
            'fascicolazione_separata' => !empty($p['fascicolazione_separata']) ? 1 : 0, 'attivo' => $attivo ? 1 : 0,
        ], $cifrata);

        return null;
    }

    /**
     * @param array<string, mixed> $p dati del modulo della regola (campi a elenco per le copie conoscenza: cc_uo[], cc_nome[], cc_username[], cc_matricola[])
     * @return array{0: int, 1: ?string} id della regola e motivo dell'errore
     */
    public function salvaRegola(int $id, array $p): array
    {
        $t = static fn (string $k, int $max = 255): string => mb_substr(trim((string) ($p[$k] ?? '')), 0, $max);
        $nome = $t('nome');
        $applica = $t('applica_a', 80);
        $tipo = $t('tipo_documento', 10);
        $azione = $t('azione', 10);
        $uo = $t('uo', 12);
        $titolario = $t('cod_titolario', 12);
        $voce = (int) ($p['voce_indice_id'] ?? 0) ?: null;
        $rep = (int) ($p['repertorio_id'] ?? 0) ?: null;
        $fascNum = $t('fascicolo_numero');
        $fascAnno = trim((string) ($p['fascicolo_anno'] ?? '')) !== '' ? (int) $p['fascicolo_anno'] : null;

        if ($nome === '') {
            return [0, 'Dai un nome alla configurazione.'];
        }
        if (!$this->contestoValido($applica)) {
            return [0, 'Scegli a quale richiesta si applica.'];
        }
        if (!isset(Costanti::TIPI_DOCUMENTO[$tipo])) {
            return [0, 'Scegli il tipo di documento (arrivo, partenza o tra uffici).'];
        }
        if (!isset(Costanti::AZIONI[$azione])) {
            return [0, 'Scegli se protocollare subito o avviare l\'iter.'];
        }
        if (!isset(CatalogoTitulus::UFFICI[$uo])) {
            return [0, 'Scegli l\'UO (ufficio) dall\'elenco di Titulus.'];
        }
        if (!isset(self::titolarioPiatto()[$titolario])) {
            return [0, 'Scegli il codice del titolario dall\'elenco.'];
        }
        if ($azione === 'iter' && ($voce === null || $this->repo->voceIndice($voce) === null)) {
            return [0, 'Per avviare l\'iter scegli la voce d\'indice (si aggiunge dalla scheda «Voci d\'indice»).'];
        }
        if ($rep !== null && $this->repo->repertorio($rep) === null) {
            return [0, 'Il repertorio scelto non esiste.'];
        }
        if ($fascNum !== '' && $t('rpa_matricola') === '') {
            return [0, 'Con un fascicolo serve la matricola dell\'RPA: Titulus inserisce il documento a suo nome.'];
        }
        if ($fascAnno !== null && ($fascAnno < 1990 || $fascAnno > 2100)) {
            return [0, 'L\'anno del fascicolo non è valido.'];
        }
        $opUo = $t('op_uo', 12);
        if ($opUo !== '' && !isset(CatalogoTitulus::UFFICI[$opUo])) {
            return [0, 'L\'UO dell\'operatore non è nell\'elenco di Titulus.'];
        }
        $email = $t('email');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [0, 'L\'e-mail non è valida.'];
        }
        $cc = [];
        foreach ((array) ($p['cc_uo'] ?? []) as $i => $ccUo) {
            $ccUo = trim((string) $ccUo);
            if ($ccUo === '') {
                continue;
            }
            if (!isset(CatalogoTitulus::UFFICI[$ccUo])) {
                return [0, 'Una copia conoscenza ha un\'UO che non è nell\'elenco di Titulus.'];
            }
            $cc[] = [
                'uo' => $ccUo,
                'nome' => mb_substr(trim((string) ($p['cc_nome'][$i] ?? '')), 0, 255),
                'username' => mb_substr(trim((string) ($p['cc_username'][$i] ?? '')), 0, 255),
                'matricola' => mb_substr(trim((string) ($p['cc_matricola'][$i] ?? '')), 0, 255),
            ];
        }
        if ($id > 0 && $this->repo->regola($id) === null) {
            return [0, 'La configurazione non esiste più.'];
        }

        $id = $this->repo->salvaRegola($id, [
            'nome' => $nome, 'applica_a' => $applica, 'attiva' => !empty($p['attiva']) ? 1 : 0, 'tipo_documento' => $tipo, 'azione' => $azione,
            'voce_indice_id' => $azione === 'iter' ? $voce : null, 'repertorio_id' => $rep, 'uo' => $uo,
            'rpa_nome' => $t('rpa_nome'), 'rpa_username' => $t('rpa_username'), 'rpa_matricola' => $t('rpa_matricola'),
            'invia_email_rpa' => !empty($p['invia_email_rpa']) ? 1 : 0, 'email' => $email, 'cod_titolario' => $titolario,
            'fascicolo_numero' => $fascNum, 'fascicolo_anno' => $fascAnno,
            'op_uo' => $opUo, 'op_nome' => $opUo !== '' ? $t('op_nome') : '', 'op_username' => $opUo !== '' ? $t('op_username') : '', 'op_matricola' => $opUo !== '' ? $t('op_matricola') : '',
        ]);
        if ($id === 0) {
            return [0, 'Non sono riuscito a salvare: riprova.'];
        }
        $this->repo->sostituisciCopieConoscenza($id, $cc);

        return [$id, null];
    }

    /** Richieste del portale a cui si può collegare una regola. @return array<string, string> contesto => descrizione */
    public function contesti(): array
    {
        $out = Costanti::CONTESTI_FISSI;
        foreach ($this->repo->moduliPratiche() as $m) {
            $out[Costanti::PREFISSO_PRATICA . $m['id']] = 'Pratica: ' . $m['titolo'];
        }
        foreach ($this->repo->moduliConUscita() as $m) {
            $out[Costanti::PREFISSO_USCITA . $m['id']] = 'Pratica, protocollo in uscita: ' . $m['titolo'];
        }

        return $out;
    }

    private function contestoValido(string $c): bool
    {
        return isset($this->contesti()[$c]);
    }

    public function salvaVoce(int $id, string $voce, bool $attiva): ?string
    {
        $voce = mb_substr(trim($voce), 0, 255);
        if ($voce === '') {
            return 'Scrivi la voce d\'indice esattamente come è in Titulus.';
        }
        if ($this->repo->vocePerNome($voce, $id)) {
            return 'Questa voce d\'indice c\'è già.';
        }
        $this->repo->salvaVoce($id, $voce, $attiva);

        return null;
    }

    public function salvaRepertorio(int $id, string $codice, string $nome, bool $attivo): ?string
    {
        $codice = mb_substr(trim($codice), 0, 15);
        if ($codice === '') {
            return 'Scrivi il codice del repertorio.';
        }
        if ($this->repo->repertorioPerCodice($codice, $id)) {
            return 'Questo repertorio c\'è già.';
        }
        $this->repo->salvaRepertorio($id, $codice, mb_substr(trim($nome), 0, 255), $attivo);

        return null;
    }
}
