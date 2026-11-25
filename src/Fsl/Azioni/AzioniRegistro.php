<?php

declare(strict_types=1);

namespace App\Fsl\Azioni;

use App\Anagrafi\ServizioScuole;
use App\Anagrafi\Testi;
use App\Core\Sito;
use App\Fsl\AllegatoConvenzioneRepository;
use App\Fsl\ConvenzioneRepository;
use App\Fsl\ServizioAllegatiConvenzione;
use App\Fsl\ServizioConvenzioni;
use App\Fsl\ServizioRegistroConvenzioni;
use App\Http\Richiesta;

/**
 * Le azioni sul registro delle convenzioni: registrare o modificare una convenzione con i suoi file (anche una già firmata fuori dal portale),
 * eliminarla, accettare o rifiutare l'Allegato A aggiornato dalla scuola, e la verifica delle iscrizioni senza convenzione.
 * Gli stessi servizi, testi e registro delle operazioni di admin/fsl.php, che le usa ancora finché il passo 9 non la toglie.
 */
final class AzioniRegistro
{
    public const AZIONI = ['conv_salva', 'conv_elimina', 'allegato_accetta', 'allegato_rifiuta', 'conv_verifica'];

    public function __construct(
        private readonly ServizioRegistroConvenzioni $registro,
        private readonly ServizioAllegatiConvenzione $allegatiServizio,
        private readonly AllegatoConvenzioneRepository $allegati,
        private readonly ConvenzioneRepository $convenzioni,
        private readonly ServizioConvenzioni $servizioConvenzioni,
        private readonly ServizioScuole $scuole,
        private readonly Sito $sito,
    ) {
    }

    public function conosce(string $azione): bool
    {
        return in_array($azione, self::AZIONI, true);
    }

    /**
     * @param array{id: int, email: string, nome: string} $operatore
     * @return array{messaggio: string, tono: string, audit: ?array{0: string, 1: array<string, mixed>}, ancora: string}
     */
    public function esegui(Richiesta $r, string $azione, string $istituto, array $operatore): array
    {
        return match ($azione) {
            'conv_salva' => $this->salva($r, $operatore['email']),
            'conv_elimina' => $this->elimina((int) $r->post('conv_elimina')),
            'allegato_accetta', 'allegato_rifiuta' => $this->allegato((int) $r->post('allegato_id', 0), $azione === 'allegato_accetta', (string) $r->post('nota', ''), (string) $r->post('protocollo', ''), $operatore['email']),
            'conv_verifica' => $this->verifica(),
        };
    }

    /** Il codice della scuola scelto nel modulo di registrazione (campo `scuola_codice[conv]`). */
    public static function scuolaDelModulo(Richiesta $r): string
    {
        $v = $r->post('scuola_codice');

        return strtoupper(trim((string) (is_array($v) ? ($v['conv'] ?? '') : '')));
    }

    private function salva(Richiesta $r, string $autore): array
    {
        $files = [];
        foreach (['file_convenzione', 'file_allegato'] as $campo) {
            if ($r->file($campo) !== null) {
                $files[$campo] = $r->file($campo);
            }
        }
        $es = $this->registro->salvaDalPannello($r->datiModulo(), $files, $autore);
        if (!$es->salvata) {
            return Esito::fa("Convenzione non salvata: controlla le date (\"valida fino al\" non può precedere \"valida dal\") e che la scuola sia quella giusta.", 'danger', null, 'convenzione');
        }
        $scuola = $this->scuole->perCodice($es->codiceScuola);
        $nome = $scuola ? Testi::etichettaScuola($scuola) : $es->codiceScuola;

        return Esito::fa('Convenzione ' . ($es->modifica ? 'aggiornata' : 'registrata') . ' per ' . $nome . '.'
            . ($es->prenotazioniAggiornate ? ' ' . $es->prenotazioniAggiornate . ' prenotazioni della scuola coperte dalla convenzione (chi era in attesa è stato avvisato per email).' : '')
            . ($es->fileNonCaricati ? ' File non caricati (serve un PDF; la convenzione deve essere firmata digitalmente in PAdES, i .p7m CAdES non sono accettati): ' . implode(', ', $es->fileNonCaricati) . '.' : ''),
            $es->fileNonCaricati ? 'warning' : 'success',
            [$es->modifica ? 'Convenzione modificata' : 'Convenzione registrata', ['Scuola' => $es->codiceScuola, 'Valida dal' => (string) $r->post('data_stipula', ''), 'Valida fino al' => (string) $r->post('scadenza', ''), 'Prenotazioni aggiornate' => $es->prenotazioniAggiornate]], 'convenzione');
    }

    private function elimina(int $id): array
    {
        $vecchia = $this->registro->elimina($id);
        if (!$vecchia) {
            return Esito::fa("Quella convenzione non c'è più.", 'warning');
        }

        return Esito::fa('Convenzione eliminata dal registro con i suoi file. Premi «Verifica ora» (Altro, nell\'elenco delle scuole) per aggiornare le iscrizioni della scuola.', 'warning',
            ['Convenzione eliminata dal registro', ['ID' => $id, 'Scuola' => $vecchia['scuola_codice']]], 'convenzione');
    }

    private function allegato(int $id, bool $accetta, string $nota, string $protocollo, string $autore): array
    {
        $riga = $this->allegati->perId($id);
        $conv = $riga ? $this->convenzioni->perId((int) $riga['convenzione_id']) : null;
        $scuola = $conv ? (string) ($this->scuole->perCodice((string) $conv['scuola_codice'])['denominazione'] ?? 'la scuola') : 'la scuola';
        $errore = $this->allegatiServizio->esamina($id, $accetta, $nota, $protocollo, $autore, $scuola, $this->sito->urlBase() . '/ricevi_link_convenzione.php');

        return Esito::daErrore($errore, $accetta ? 'Allegato A aggiornato accettato: la scuola è stata avvisata.' : 'Allegato A aggiornato rifiutato: la scuola ha ricevuto il motivo.',
            $accetta ? 'Allegato A aggiornato accettato' : 'Allegato A aggiornato rifiutato', ['Allegato' => $id, 'Convenzione' => $riga['convenzione_id'] ?? '', 'Motivo' => mb_substr($nota, 0, 200)], 'convenzione');
    }

    /** Ricalcola quali iscrizioni sono coperte da una convenzione (solo chi gestisce tutta la FSL: lo controlla chi chiama). */
    private function verifica(): array
    {
        $v = $this->servizioConvenzioni->verifica();

        return Esito::fa("Verifica completata: {$v['coperte']} iscrizioni coperte da una convenzione, {$v['da_stipulare']} senza convenzione valida per il periodo"
            . ($v['nuove_da_stipulare'] ? " ({$v['nuove_da_stipulare']} segnate ora come \"da stipulare\")" : '')
            . ($v['senza_codice'] ? ", {$v['senza_codice']} con la scuola scritta a mano (abbinala all'anagrafe per verificarla)" : '') . '.',
            $v['da_stipulare'] || $v['senza_codice'] ? 'warning' : 'success', ['Verifica convenzioni FSL', $v]);
    }
}
