<?php

declare(strict_types=1);

namespace App\Portale\Vista;

/**
 * I passi che il pannello mostra in cima alle schede (ui_passi() di inc/ui.php): di un'attività, di un bando di tutorato, di una pratica.
 * Solo regole: lo stato di ogni passo si ricava dai dati che ci sono già (turni, campi del modulo, decreti, lettere, seduta…); non si salva niente.
 * Il passo in corso è il primo non fatto; chi non è ancora arrivato è «da_fare»; un passo che non può andare avanti è «bloccato» (con la nota che dice perché).
 */
final class PassiScheda
{
    /**
     * Dai passi grezzi (titolo, fatto, bloccato, nota, link) ai passi per ui_passi() e al numero del passo in corso (-1 = tutto fatto).
     *
     * @param list<array{titolo: string, fatto: bool, bloccato?: bool, nota?: string, link?: string}> $grezzi
     * @return array{passi: list<array{titolo: string, stato: string, nota: string, link: string}>, corrente: int}
     */
    public static function chiudi(array $grezzi): array
    {
        $corrente = -1;
        foreach ($grezzi as $i => $g) {
            if (!$g['fatto']) {
                $corrente = $i;
                break;
            }
        }
        $passi = [];
        foreach ($grezzi as $i => $g) {
            $stato = $g['fatto'] ? 'fatto' : (!empty($g['bloccato']) ? 'bloccato' : ($i === $corrente ? 'corrente' : 'da_fare'));
            $passi[] = ['titolo' => $g['titolo'], 'stato' => $stato, 'nota' => $g['nota'] ?? '', 'link' => $g['link'] ?? ''];
        }

        return ['passi' => $passi, 'corrente' => $corrente];
    }

    /**
     * Attività (evento): Dati → Turni → Modulo di iscrizione → Pubblica.
     * «Modulo di iscrizione» è fatto quando l'evento ha campi suoi o dell'area, o quando non si prenota; «Pubblica» quando l'evento c'è, non è archiviato e l'area è visibile.
     *
     * @param array{ancora_dati?: string, ancora_turni?: string, link_modulo?: string, ancora_pubblica?: string} $link
     * @return array{passi: list<array{titolo: string, stato: string, nota: string, link: string}>, corrente: int}
     */
    public static function attivita(bool $salvata, int $nTurni, int $nCampiModulo, bool $richiedePrenotazione, bool $archiviata, bool $areaVisibile, array $link = []): array
    {
        $modulo_fatto = !$richiedePrenotazione || $nCampiModulo > 0;
        $pubblica_fatto = $salvata && !$archiviata && $areaVisibile;

        return self::chiudi([
            ['titolo' => 'Dati', 'fatto' => $salvata, 'nota' => $salvata ? '' : 'da compilare e salvare', 'link' => $link['ancora_dati'] ?? ''],
            ['titolo' => 'Turni', 'fatto' => $nTurni > 0, 'nota' => $nTurni > 0 ? ($nTurni === 1 ? '1 turno' : $nTurni . ' turni') : 'manca: almeno un turno', 'link' => $link['ancora_turni'] ?? ''],
            ['titolo' => 'Modulo di iscrizione', 'fatto' => $modulo_fatto,
                'nota' => !$richiedePrenotazione ? 'non serve: senza prenotazione' : ($nCampiModulo > 0 ? ($nCampiModulo === 1 ? '1 campo' : $nCampiModulo . ' campi') : 'manca: i campi del modulo (ora solo i dati base)'),
                'link' => $link['link_modulo'] ?? ''],
            ['titolo' => 'Pubblica', 'fatto' => $pubblica_fatto, 'bloccato' => $salvata && ($archiviata || !$areaVisibile),
                'nota' => $pubblica_fatto ? 'visibile al pubblico' : ($archiviata ? 'archiviato: non si vede' : ($salvata && !$areaVisibile ? 'manca: l\'area è nascosta al pubblico' : '')), 'link' => $link['ancora_pubblica'] ?? ''],
        ]);
    }

    /**
     * Bando di tutorato: Bando → Commissione → Graduatoria → Incarichi → Registro → Pagamenti.
     * Bando, Commissione e Graduatoria (approvazione atti) sono fatti col decreto e il suo PDF; Incarichi, Registro e Pagamenti quando tutte le lettere non annullate ci sono arrivate.
     *
     * @param array<string, mixed> $bando riga di tutorato_bandi (decreto_*, decreto_*_file, ufficio_ateneo_id)
     * @param list<array<string, mixed>> $lettere righe di tutorato_incarichi del bando (stato, fine_stato, pagamento_stato)
     * @param array{decreti?: string, lettere?: string, registro?: string, pagamenti?: string} $link
     * @return array{passi: list<array{titolo: string, stato: string, nota: string, link: string}>, corrente: int}
     */
    public static function bandoTutorato(array $bando, array $lettere, array $link = []): array
    {
        $vive = array_values(array_filter($lettere, static fn (array $x): bool => ($x['stato'] ?? '') !== 'annullata'));
        $n = count($vive);
        $conta = static fn (callable $f): int => count(array_filter($vive, $f));
        $decreto = static function (string $numero, string $file, string $nome): array {
            $ok = $numero !== '' && $file !== '';

            return [$ok, $ok ? '' : ($numero === '' ? 'manca: il numero del ' . $nome : 'manca: il PDF del ' . $nome)];
        };
        [$bando_ok, $bando_nota] = $decreto((string) ($bando['decreto_bando'] ?? ''), (string) ($bando['decreto_bando_file'] ?? ''), 'decreto del bando');
        [$comm_ok, $comm_nota] = $decreto((string) ($bando['decreto_commissione'] ?? ''), (string) ($bando['decreto_commissione_file'] ?? ''), 'decreto della commissione');
        [$atti_ok, $atti_nota] = $decreto((string) ($bando['decreto_atti'] ?? ''), (string) ($bando['decreto_atti_file'] ?? ''), 'decreto di approvazione atti');
        $prot = $conta(static fn (array $x): bool => ($x['stato'] ?? '') === 'protocollata');
        $fine = $conta(static fn (array $x): bool => in_array($x['fine_stato'] ?? '', ['firmata', 'protocollata'], true));
        $pagate = $conta(static fn (array $x): bool => ($x['pagamento_stato'] ?? '') === 'pagata');
        $senzaUfficio = (int) ($bando['ufficio_ateneo_id'] ?? 0) === 0;
        $pagamentiFatti = $n > 0 && $pagate === $n;
        $parziale = static fn (int $x): string => $n === 0 ? '' : $x . ' su ' . $n;

        return self::chiudi([
            ['titolo' => 'Bando', 'fatto' => $bando_ok, 'nota' => $bando_nota, 'link' => $link['decreti'] ?? ''],
            ['titolo' => 'Commissione', 'fatto' => $comm_ok, 'nota' => $comm_nota, 'link' => $link['decreti'] ?? ''],
            ['titolo' => 'Graduatoria', 'fatto' => $atti_ok, 'nota' => $atti_nota, 'link' => $link['decreti'] ?? ''],
            ['titolo' => 'Incarichi', 'fatto' => $n > 0 && $prot === $n, 'nota' => $n === 0 ? 'manca: i vincitori' : $parziale($prot) . ' lettere protocollate', 'link' => $link['lettere'] ?? ''],
            ['titolo' => 'Registro', 'fatto' => $n > 0 && $fine === $n, 'nota' => $n === 0 ? '' : $parziale($fine) . ' con la fine attività firmata', 'link' => $link['registro'] ?? ''],
            ['titolo' => 'Pagamenti', 'fatto' => $pagamentiFatti, 'bloccato' => $senzaUfficio && !$pagamentiFatti,
                'nota' => $senzaUfficio && !$pagamentiFatti ? 'manca: l\'ufficio che paga i tutor' : ($n === 0 ? '' : $parziale($pagate) . ' pagati'), 'link' => $link['pagamenti'] ?? ''],
        ]);
    }

    /**
     * Pratica di uno studente: Arrivata → Istruttoria → Seduta → Verbale → Protocollo.
     * Seduta e Verbale non servono se il modulo non va in verbale (in_verbale = 0) o se la pratica è chiusa senza essere passata in seduta.
     *
     * @param array<string, mixed> $p riga della pratica (stato, seduta_id, esito_seduta, protocollo, in_verbale)
     * @param string $fase fase di FasePratica::di()
     * @param array<string, mixed>|null $seduta riga della seduta a cui è portata (verbale_stato) o null
     * @param array{istruttoria?: string, seduta?: string, verbale?: string, protocollo?: string} $link
     * @return array{passi: list<array{titolo: string, stato: string, nota: string, link: string}>, corrente: int}
     */
    public static function pratica(array $p, string $fase, ?array $seduta = null, array $link = []): array
    {
        $conclusa = in_array($p['stato'] ?? '', ['accolta', 'respinta', 'chiusa'], true);
        $inSeduta = !empty($p['seduta_id']);
        $esito = $inSeduta && (string) ($p['esito_seduta'] ?? '') !== '';
        $senzaVerbale = (string) ($p['in_verbale'] ?? '1') === '0' || ($conclusa && !$inSeduta);
        $verbale = (string) ($seduta['verbale_stato'] ?? '');
        $istruita = $conclusa || $inSeduta || in_array($fase, ['consiglio', 'decisa', 'segreteria', 'conclusa'], true);
        $notaIstruttoria = match ($fase) {
            'integrazione' => 'manca: la risposta dello studente',
            'firma' => 'manca: una firma',
            'protocollare' => 'da protocollare',
            'smistare' => 'da assegnare a una persona',
            'prendere' => 'da prendere in carico',
            default => '',
        };
        $protocollo = (string) ($p['protocollo'] ?? '');

        return self::chiudi([
            ['titolo' => 'Arrivata', 'fatto' => true],
            ['titolo' => 'Istruttoria', 'fatto' => $istruita, 'bloccato' => !$istruita && in_array($fase, ['integrazione', 'firma'], true), 'nota' => $istruita ? '' : $notaIstruttoria, 'link' => $link['istruttoria'] ?? ''],
            ['titolo' => 'Seduta', 'fatto' => $senzaVerbale || $esito,
                'nota' => $senzaVerbale ? 'non serve' : ($esito ? 'decisa' : ($inSeduta ? 'manca: la decisione del consiglio' : 'manca: scegliere la seduta')), 'link' => $link['seduta'] ?? ''],
            ['titolo' => 'Verbale', 'fatto' => $senzaVerbale || $verbale === 'firmato',
                'nota' => $senzaVerbale ? 'non serve' : ($verbale === 'firmato' ? 'firmato' : (in_array($verbale, ['segretario', 'coordinatore'], true) ? 'manca: la firma del verbale' : 'manca: il verbale')), 'link' => $link['verbale'] ?? ''],
            ['titolo' => 'Protocollo', 'fatto' => $protocollo !== '', 'nota' => $protocollo !== '' ? $protocollo : 'manca: il numero di protocollo', 'link' => $link['protocollo'] ?? ''],
        ]);
    }
}
