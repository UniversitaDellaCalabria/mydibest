<?php

declare(strict_types=1);

namespace App\Portale\Endpoint;

use App\Assistenza\Costanti;
use App\Core\Sito;
use App\Http\Richiesta;
use App\Http\Risposta;
use App\Portale\ComunicazioniRepository;
use App\Portale\TestoSemplice;

/**
 * Feed RSS delle ultime notizie visibili (comunicazioni_feed.php). ?per=studenti|scuole|aziende|docenti per un solo pubblico:
 * qualunque altro valore vale «tutti». Gli indirizzi dei collegamenti li dà App\Core\Sito.
 */
final class FeedComunicazioni
{
    public function __construct(
        private ComunicazioniRepository $comunicazioni,
        private Sito $sito,
    ) {
    }

    public function risposta(Richiesta $r): Risposta
    {
        $per = $r->query('per');
        $per = is_string($per) && isset(Costanti::PUBBLICI[$per]) ? $per : '';
        $notizie = $this->comunicazioni->notizie($per, '', 30);
        $base = rtrim($this->sito->urlBase(), '/');
        $x = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . "<rss version=\"2.0\"><channel>\n"
            . "<title>Notizie del Dipartimento</title>\n"
            . '<link>' . $x($base . '/comunicazioni.php') . "</link>\n"
            . "<description>Le ultime comunicazioni del Dipartimento</description>\n"
            . "<language>it</language>\n";
        foreach ($notizie as $n) {
            $u = $base . '/comunicazione.php?id=' . (int) $n['id'];
            $xml .= '<item><title>' . $x($n['titolo']) . '</title><link>' . $x($u) . '</link><guid isPermaLink="true">' . $x($u) . "</guid>\n"
                . '<pubDate>' . $x(date(DATE_RSS, (int) strtotime((string) ($n['data_pubblicazione'] ?: $n['creato_il'])))) . "</pubDate>\n"
                . '<description>' . $x($n['sommario'] !== '' ? $n['sommario'] : TestoSemplice::estratto((string) $n['testo'], 400)) . "</description></item>\n";
        }

        return Risposta::contenuto($xml . "</channel></rss>\n", 'application/rss+xml; charset=utf-8');
    }
}
