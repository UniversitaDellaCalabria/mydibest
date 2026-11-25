<?php

declare(strict_types=1);

namespace App\Fsl\Azioni;

use App\Core\Database;
use App\Fsl\MessaggiConvenzioneRepository;
use App\Fsl\RubricaDocentiScuola;
use App\Fsl\ServizioDocumentiClasse;
use App\Fsl\ServizioMessaggiConvenzione;
use App\Fsl\ServizioValutazioniFsl;
use App\Http\Richiesta;
use App\Iscritti\Operatore;
use App\Iscritti\ServizioMessaggi;

/**
 * Le azioni sulla comunicazione con la scuola e con i docenti: messaggi e promemoria della convenzione, promemoria dell'elenco degli studenti e
 * della scheda di valutazione, e la rubrica dei docenti della scuola. Gli stessi servizi, testi e registro delle operazioni di admin/fsl.php.
 */
final class AzioniMessaggiPersone
{
    public const AZIONI = ['cv_letto', 'cv_ricorda', 'cv_ricorda_tutte', 'cv_messaggio', 'cv_scrivi_docenti', 'ricorda_elenco', 'ricorda_valutazione', 'ricorda_elenco_tutti', 'docente_salva'];

    /** Le azioni che riguardano TUTTE le scuole: si fanno solo dall'elenco, mai dalla scheda di una scuola. */
    /** Quanti docenti al massimo in un solo messaggio. */
    public const MAX_DOCENTI = 20;

    public const A_TUTTE = ['cv_ricorda_tutte', 'ricorda_elenco_tutti'];

    public function __construct(
        private readonly MessaggiConvenzioneRepository $messaggi,
        private readonly ServizioMessaggiConvenzione $messaggiServizio,
        private readonly ServizioDocumentiClasse $documentiClasse,
        private readonly ServizioValutazioniFsl $valutazioni,
        private readonly RubricaDocentiScuola $rubrica,
        private readonly ServizioMessaggi $messaggiIscritti,
        private readonly Database $db,
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
        $autore = trim($operatore['nome']) !== '' ? trim($operatore['nome']) : ($operatore['email'] !== '' ? $operatore['email'] : 'Ufficio');
        $id = (int) $r->post($azione);

        return match ($azione) {
            'cv_letto' => $this->letto($id),
            'cv_ricorda' => $this->ricordaPdf($id, $autore),
            'cv_ricorda_tutte' => $this->ricordaATutte($autore),
            'cv_messaggio' => $this->messaggio((int) ($r->post('cv_messaggio_scelta') ?? $id), (string) $r->post('testo', ''), $autore),
            'cv_scrivi_docenti' => $this->scriviAiDocenti(self::destinatariDocenti($r), (string) $r->post('testo', ''), $operatore),
            'ricorda_elenco' => $this->ricordaElenco($id),
            'ricorda_valutazione' => $this->ricordaValutazione($id),
            'ricorda_elenco_tutti' => $this->ricordaElencoATutti(),
            'docente_salva' => $this->docente($istituto, (string) $r->post('email', ''), (string) $r->post('nome', ''), (string) $r->post('telefono', ''), $operatore['email']),
        };
    }

    /**
     * Gli id di compilazione che l'azione tocca (per il controllo «è di questa scuola»).
     *
     * @return list<int>
     */
    public static function compilazioniDi(Richiesta $r, string $azione): array
    {
        return match ($azione) {
            'cv_messaggio' => [(int) ($r->post('cv_messaggio_scelta') ?? $r->post($azione))],
            'cv_letto', 'cv_ricorda' => [(int) $r->post($azione)],
            default => [],
        };
    }

    /**
     * Le prenotazioni che l'azione tocca: il promemoria dell'elenco e della valutazione sono per UNA prenotazione.
     *
     * @return list<int>
     */
    public static function prenotazioniDi(Richiesta $r, string $azione): array
    {
        if ($azione === 'cv_scrivi_docenti') {
            return self::destinatariDocenti($r);   // ognuna deve essere di questa scuola (AzioniScuolaFsl)
        }

        return in_array($azione, ['ricorda_elenco', 'ricorda_valutazione'], true) ? [(int) $r->post($azione)] : [];
    }

    /** Le prenotazioni (una per docente) scelte nel modulo «Scrivi ai docenti». @return list<int> */
    private static function destinatariDocenti(Richiesta $r): array
    {
        $scelti = $r->post('destinatari');
        $id = array_values(array_unique(array_filter(array_map('intval', is_array($scelti) ? $scelti : []), static fn (int $x): bool => $x > 0)));

        return array_slice($id, 0, self::MAX_DOCENTI);
    }

    /**
     * Quando la scuola non ha nessuna compilazione, il messaggio va per email ai docenti scelti tra chi ha prenotato (era «Scrivi alla scuola» di «Da stipulare»):
     * lo stesso servizio dei messaggi delle iscrizioni (il testo resta nella chat della prenotazione), un solo invio per indirizzo, gli stessi limiti e lo stesso
     * registro delle operazioni. Qui NON parte nessun link personale della convenzione: quello va solo all'email della compilazione.
     *
     * @param list<int> $prenotazioni
     * @param array{id: int, email: string, nome: string} $operatore
     */
    private function scriviAiDocenti(array $prenotazioni, string $testo, array $operatore): array
    {
        $testo = trim($testo);
        if ($prenotazioni === []) {
            return Esito::fa('Scegli almeno un docente a cui scrivere.', 'danger', null, 'messaggi');
        }
        if ($testo === '') {
            return Esito::fa('Scrivi il messaggio da mandare ai docenti.', 'danger', null, 'messaggi');
        }
        if (mb_strlen($testo) > ServizioMessaggiConvenzione::MAX_CARATTERI) {
            return Esito::fa('Il messaggio è troppo lungo (al massimo ' . ServizioMessaggiConvenzione::MAX_CARATTERI . ' caratteri).', 'danger', null, 'messaggi');
        }
        $righe = $this->db->righe('SELECT id, nome, cognome, email FROM prenotazioni WHERE id IN (' . implode(',', array_map('intval', $prenotazioni)) . ') ORDER BY id');
        $op = new Operatore((int) $operatore['id'], (string) $operatore['email'], 'Sconosciuto');
        $visti = [];
        $arrivati = [];
        foreach ($righe as $p) {
            $email = strtolower(trim((string) $p['email']));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || isset($visti[$email])) {
                continue;
            }
            $visti[$email] = true;
            if ($this->messaggiIscritti->inviaDaIscritti((int) $p['id'], $testo, $op)) {
                $arrivati[] = trim((string) $p['nome'] . ' ' . (string) $p['cognome']) . ' (' . $email . ')';
            }
        }
        if ($arrivati === []) {
            return Esito::fa('Email non inviata: controlla gli indirizzi (dettagli in Sistema → registro invii).', 'danger', null, 'messaggi');
        }

        return Esito::fa('Messaggio mandato a ' . count($arrivati) . (count($arrivati) === 1 ? ' docente: ' : ' docenti: ') . implode(', ', $arrivati) . '.', 'success',
            ['Messaggio alla scuola FSL (ai docenti delle prenotazioni)', ['Destinatari' => count($arrivati), 'Testo' => mb_substr($testo, 0, 200)]], 'messaggi');
    }

    private function letto(int $id): array
    {
        $this->messaggi->segnaLetti($id, MessaggiConvenzioneRepository::UFFICIO);

        return Esito::fa('Messaggi segnati come letti.', 'success', null, 'messaggi');
    }

    private function ricordaPdf(int $id, string $autore): array
    {
        $errore = $this->messaggiServizio->ricordaPdf($id, $autore);

        return Esito::daErrore($errore, "Promemoria mandato: la scuola riceve l'email con il link per caricare il PDF firmato.", 'Promemoria della convenzione FSL alla scuola', ['Compilazione' => $id], 'convenzione');
    }

    private function ricordaATutte(string $autore): array
    {
        [$partiti, $saltati] = $this->messaggiServizio->ricordaATutte($autore);

        return Esito::fa($partiti . ($partiti === 1 ? ' promemoria mandato' : ' promemoria mandati') . ($saltati ? " ($saltati scuole saltate: già avvisate nelle ultime 24 ore o senza email)" : '') . '.',
            $partiti ? 'success' : 'warning', ['Promemoria della convenzione FSL a tutte le scuole in attesa', ['Mandati' => $partiti, 'Saltati' => $saltati]]);
    }

    private function messaggio(int $id, string $testo, string $autore): array
    {
        $errore = $this->messaggiServizio->scriviAllaScuola($id, $testo, $autore);

        return Esito::daErrore($errore, 'Messaggio mandato: la scuola lo riceve per email e può rispondere dalla sua pagina.', 'Messaggio alla scuola sulla convenzione FSL',
            ['Compilazione' => $id, 'Testo' => mb_substr($testo, 0, 200)], 'messaggi');
    }

    private function ricordaElenco(int $id): array
    {
        $errore = $this->documentiClasse->ricordaElenco($id);

        return Esito::daErrore($errore, "Promemoria mandato: il docente riceve l'email con il link diretto per inserire l'elenco degli studenti.", "Promemoria dell'elenco studenti al docente", ['Prenotazione' => $id], 'eventi');
    }

    private function ricordaValutazione(int $id): array
    {
        $errore = $this->valutazioni->ricorda($id);

        return Esito::daErrore($errore, "Promemoria mandato: il docente riceve l'email con il link alla scheda di valutazione.", 'Promemoria della scheda di valutazione al docente', ['Prenotazione' => $id], 'valutazioni');
    }

    private function ricordaElencoATutti(): array
    {
        [$partiti, $saltati] = $this->documentiClasse->ricordaElencoATutti();

        return Esito::fa($partiti . ($partiti === 1 ? ' promemoria mandato' : ' promemoria mandati') . ($saltati ? " ($saltati saltati: già avvisati nelle ultime 24 ore o senza email)" : '') . '.',
            $partiti ? 'success' : 'warning', ["Promemoria dell'elenco studenti a tutti i docenti a cui manca", ['Mandati' => $partiti, 'Saltati' => $saltati]]);
    }

    private function docente(string $istituto, string $email, string $nome, string $telefono, string $da): array
    {
        $errore = $this->rubrica->salva($istituto, $email, $nome, $telefono, $da);

        return Esito::daErrore($errore, 'Docente salvato nella rubrica della scuola. Non ha nessun diritto sulla convenzione: per darglielo serve il link inviato al suo indirizzo.', 'Docente referente della scuola salvato',
            ['Scuola' => $istituto, 'Email' => strtolower(trim($email))], 'docenti');
    }
}
