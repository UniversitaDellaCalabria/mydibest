<?php

declare(strict_types=1);

namespace App\Forms;

use App\Auth\UtenteRepository;
use App\Core\Orologio;
use App\Infrastructure\Audit\AuditLog;
use DateTimeImmutable;

/**
 * I compiti automatici di MyForms, chiamati da cron_background.php:
 * 1) chiusura dei form aperti a fine periodo o al numero massimo di risposte, con un'email al proprietario (una volta sola per form);
 * 2) conservazione: le risposte più vecchie di «conserva_mesi» si cancellano (con gli allegati, tramite ServizioRisposte), ma solo dopo che il
 *    proprietario è stato avvisato almeno 7 giorni prima: l'avviso ricorda fino a quale data di invio le risposte erano incluse e solo quelle si
 *    cancellano (mai di più, anche se i mesi vengono cambiati nel frattempo, e mai alla prima accensione senza preavviso).
 * L'ora arriva dall'Orologio (così nei test si può fissare). Il cron non ha una sessione: nel registro delle attività l'operazione è intestata al
 * proprietario del form, con «automatico» nei dettagli.
 */
final class CronForms
{
    /** Giorni di preavviso prima di cancellare le risposte vecchie. */
    public const GIORNI_AVVISO = 7;

    public function __construct(
        private FormRepository $forms,
        private RispostaRepository $risposte,
        private ServizioRisposte $servizioRisposte,
        private NotificheForms $notifiche,
        private UtenteRepository $utenti,
        private AuditLog $audit,
        private Orologio $orologio,
    ) {
    }

    /** Esegue i due compiti. Ritorna le righe di resoconto da stampare (vuoto se non c'era nulla da fare). */
    public function cron(): string
    {
        $chiusi = $this->chiudiScaduti();
        $c = $this->conservazione();
        $out = '';
        if ($chiusi > 0) {
            $out .= "- MyForms: $chiusi moduli chiusi da soli (fine del periodo o numero massimo di risposte).\n";
        }
        if ($c['avvisi'] > 0 || $c['cancellate'] > 0) {
            $out .= "- MyForms, conservazione: {$c['avvisi']} avvisi ai proprietari, {$c['cancellate']} risposte cancellate.\n";
        }

        return $out;
    }

    /**
     * Chiude i form aperti il cui periodo è finito (aperta_al passata) o che hanno raggiunto il numero massimo di risposte, e avvisa il proprietario.
     * Ogni form si chiude da solo una sola volta: se il proprietario lo riapre, il cron non lo richiude.
     *
     * @return int quanti form ha chiuso
     */
    public function chiudiScaduti(): int
    {
        $adesso = $this->orologio->adesso()->format('Y-m-d H:i:s');
        $chiusi = 0;
        foreach ($this->forms->daChiudereSeScaduti() as $f) {
            $perche = null;
            if (!empty($f['aperta_al']) && $adesso > (string) $f['aperta_al']) {
                $perche = 'periodo';
            } elseif ((int) ($f['max_risposte'] ?? 0) > 0 && $this->risposte->conta((int) $f['id']) >= (int) $f['max_risposte']) {
                $perche = 'massimo';
            }
            if ($perche === null || !$this->forms->chiudiAutomaticamente((int) $f['id'], $adesso)) {
                continue;
            }
            ++$chiusi;
            $this->notifiche->formChiuso($f, $this->utenti->perId((int) $f['proprietario_id']), $this->risposte->conta((int) $f['id']), $perche);
        }

        return $chiusi;
    }

    /**
     * Conservazione delle risposte: cancella quelle già annunciate al proprietario da almeno 7 giorni e più vecchie di «conserva_mesi», poi avvisa
     * per quelle che si cancelleranno entro i prossimi 7 giorni (un avviso alla volta: il successivo solo dopo che il precedente è scaduto).
     *
     * @return array{avvisi: int, cancellate: int}
     */
    public function conservazione(): array
    {
        $adesso = $this->orologio->adesso();
        $avvisi = 0;
        $cancellate = 0;
        foreach ($this->forms->conConservazione() as $f) {
            $mesi = (int) $f['conserva_mesi'];
            $avvisata = !empty($f['conserva_avvisata_il']) ? new DateTimeImmutable((string) $f['conserva_avvisata_il']) : null;
            $matura = $avvisata?->modify('+' . self::GIORNI_AVVISO . ' days');
            $preavvisoFinito = $matura !== null && $adesso >= $matura;

            // 1) cancellazione: solo ciò che era nell'avviso e che è davvero più vecchio di «mesi»
            if ($preavvisoFinito && !empty($f['conserva_fino_al'])) {
                $taglio = min((string) $f['conserva_fino_al'], $adesso->modify('-' . $mesi . ' months')->format('Y-m-d H:i:s'));
                $n = $this->servizioRisposte->eliminaPrima($f, $taglio);
                if ($n > 0) {
                    $cancellate += $n;
                    $this->audit->registra((int) $f['proprietario_id'], 'MyForms: risposte cancellate (conservazione automatica)', [
                        'Form' => (int) $f['id'], 'Titolo' => (string) $f['titolo'], 'Risposte' => $n, 'Inviate prima del' => $taglio, 'Mesi di conservazione' => $mesi, 'automatico' => true,
                    ], 'cron');
                }
            }

            // 2) avviso: un solo avviso in attesa alla volta; poi si guarda cosa si cancellerebbe tra 7 giorni
            if ($avvisata === null || $preavvisoFinito) {
                $cancellazione = $adesso->modify('+' . self::GIORNI_AVVISO . ' days');
                $prima = $cancellazione->modify('-' . $mesi . ' months')->format('Y-m-d H:i:s');
                $quante = $this->risposte->contaInviatePrima((int) $f['id'], $prima);
                if ($quante > 0) {
                    $this->forms->segnaAvvisoConservazione((int) $f['id'], $adesso->format('Y-m-d H:i:s'), $prima);
                    $this->notifiche->avvisoConservazione($f, $this->utenti->perId((int) $f['proprietario_id']), $quante, $cancellazione->format('Y-m-d H:i:s'), $prima);
                    ++$avvisi;
                }
            }
        }

        return ['avvisi' => $avvisi, 'cancellate' => $cancellate];
    }
}
