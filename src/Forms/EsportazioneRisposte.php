<?php

declare(strict_types=1);

namespace App\Forms;

use App\Didattica\CampiModulo;
use App\Didattica\Costanti;

/** Intestazioni e righe delle risposte di un form, pronte per TabellaEsportabile (Excel, CSV, PDF). */
final class EsportazioneRisposte
{
    /**
     * Una riga per risposta, una colonna per domanda (solo i campi con risposta, non i testi informativi): le scelte multiple
     * separate da "; ", le tabelle a righe in una sola cella (righe separate da « · », colonne da « | »), gli allegati con il nome del
     * file. Nei form anonimi non ci sono le colonne nome ed email.
     *
     * Con $neutralizza le celle che un foglio di calcolo leggerebbe come formula (iniziano con = + - @) ricevono un apostrofo davanti:
     * serve a CSV ed Excel, non al PDF. Le risposte le scrive chiunque, anche chi non ha fatto l'accesso.
     *
     * @param array<string, mixed> $form
     * @param list<array<string, mixed>> $risposte righe di forms_risposte, con «nome» e «cognome» già risolti (es. dall'anagrafe) quando c'è l'utente_id
     * @return array{intestazioni: list<string>, righe: list<list<string>>}
     */
    public static function tabella(array $form, array $risposte, bool $neutralizza = false): array
    {
        $campi = self::campiConRisposta($form);
        $anonima = !empty($form['anonima']);

        $intestazioni = ['Data e ora'];
        if (!$anonima) {
            $intestazioni[] = 'Nome';
            $intestazioni[] = 'Email';
        }
        $intestazioni[] = 'Profilo';
        foreach ($campi as $c) {
            $intestazioni[] = $c['etichetta'];
        }

        $righe = [];
        foreach ($risposte as $r) {
            $allineate = self::allinea($campi, self::decodifica($r));
            $riga = [self::dataOra((string) ($r['inviata_il'] ?? ''))];
            if (!$anonima) {
                $riga[] = trim((string) ($r['nome'] ?? '') . ' ' . (string) ($r['cognome'] ?? ''));
                $riga[] = (string) ($r['email'] ?? '');
            }
            $riga[] = (string) ($r['profilo'] ?? '');
            foreach ($campi as $i => $c) {
                $riga[] = self::cella($allineate[$i]);
            }
            $righe[] = $riga;
        }

        if ($neutralizza) {
            $intestazioni = array_map([self::class, 'neutralizza'], $intestazioni);
            $righe = array_map(static fn (array $riga): array => array_map([self::class, 'neutralizza'], $riga), $righe);
        }

        return ['intestazioni' => $intestazioni, 'righe' => $righe];
    }

    /**
     * Pesi relativi delle colonne nel PDF (stessa lista di intestazioni di tabella()): data, nome, profilo ed email hanno un peso proprio perché un
     * indirizzo email non va a capo e finirebbe sopra la colonna accanto; le domande pesano tutte uguale.
     *
     * @param array<string, mixed> $form
     * @return list<float>
     */
    public static function larghezzePdf(array $form): array
    {
        $pesi = [1.3];
        if (empty($form['anonima'])) {
            $pesi[] = 1.6; // nome
            $pesi[] = 2.4; // email
        }
        $pesi[] = 1.1; // profilo
        foreach (self::campiConRisposta($form) as $_) {
            $pesi[] = 1.5;
        }

        return $pesi;
    }

    /**
     * Rende innocua una cella che un foglio di calcolo leggerebbe come formula (inizia con = + - @, a capo o tabulazione): un apostrofo davanti
     * la fa restare testo. I numeri veri («-5», «+3,5») restano com'erano.
     */
    public static function neutralizza(string $valore): string
    {
        if ($valore === '' || !in_array($valore[0], ['=', '+', '-', '@', "\t", "\r", "\n"], true)) {
            return $valore;
        }

        return is_numeric($valore) ? $valore : "'" . $valore;
    }

    /**
     * I campi del form che hanno una risposta (tutti tranne i testi informativi), nell'ordine del form.
     *
     * @param array<string, mixed> $form
     * @return list<array<string, mixed>>
     */
    public static function campiConRisposta(array $form): array
    {
        return array_values(array_filter(CampiModulo::da((string) ($form['campi_json'] ?? '')), static fn (array $c): bool => !in_array($c['tipo'], Costanti::TIPI_SOLO_TESTO, true)));
    }

    /**
     * Le risposte salvate nella riga, come elenco (vuoto se illeggibili).
     *
     * @param array<string, mixed> $riga
     * @return list<array<string, mixed>>
     */
    public static function decodifica(array $riga): array
    {
        $d = json_decode((string) ($riga['risposte_json'] ?? ''), true);

        return is_array($d) ? array_values(array_filter($d, 'is_array')) : [];
    }

    /**
     * Mette ogni risposta salvata sotto la domanda giusta. Le risposte si salvano nell'ordine delle domande di quel momento: se dopo il form è
     * stato modificato (una domanda tolta o spostata) la posizione non basta e le colonne si scambierebbero. Si usa la posizione solo se la
     * domanda salvata è proprio quella (stessa etichetta); altrimenti si cerca per etichetta. Una domanda che non ha risposta salvata resta null.
     *
     * @param list<array<string, mixed>> $campi i campi di campiConRisposta()
     * @param list<array<string, mixed>> $salvate le risposte di decodifica()
     * @return list<array<string, mixed>|null> una voce per ogni campo, nello stesso ordine
     */
    public static function allinea(array $campi, array $salvate): array
    {
        $usate = [];
        $out = [];
        foreach ($campi as $i => $c) {
            $trovata = null;
            if (isset($salvate[$i]) && ($salvate[$i]['etichetta'] ?? null) === $c['etichetta'] && !isset($usate[$i])) {
                $trovata = $i;
            } else {
                foreach ($salvate as $j => $s) {
                    if (!isset($usate[$j]) && ($s['etichetta'] ?? null) === $c['etichetta']) {
                        $trovata = $j;
                        break;
                    }
                }
            }
            if ($trovata !== null) {
                $usate[$trovata] = true;
            }
            $out[] = $trovata !== null ? $salvate[$trovata] : null;
        }

        return $out;
    }

    /** @param array<string, mixed>|null $risposta un elemento dell'elenco di LetturaRisposte::leggi() */
    private static function cella(?array $risposta): string
    {
        $v = (string) ($risposta['valore'] ?? '');
        if ($v !== '' && ($risposta['tipo'] ?? '') === 'multicheck') {
            $v = implode('; ', explode(', ', $v));
        }

        return $v;
    }

    private static function dataOra(string $quando): string
    {
        $t = strtotime($quando);

        return $t ? date('d/m/Y H:i', $t) : '';
    }
}
