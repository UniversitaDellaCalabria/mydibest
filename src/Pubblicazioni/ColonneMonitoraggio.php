<?php

declare(strict_types=1);

namespace App\Pubblicazioni;

/**
 * L'elenco UNICO e ordinato delle colonne della «Tabella di monitoraggio» (DiBEST_Tabella di monitoraggio.xlsx, 22 colonne A–V) più due colonne
 * che nell'Excel non ci sono: «Sottotitolo iniziativa», subito dopo la colonna del titolo, e «Stato» (stato della richiesta in parole) in fondo,
 * dopo la V. Tutte le pagine (scheda, elenco) e l'export leggono da qui.
 *
 * Le intestazioni sono IDENTICHE alle celle dell'Excel (spazi compresi), con UNA sola eccezione decisa il 19/11/2026: la colonna G, che nell'Excel
 * si chiama «Titolo e sottotitolo iniziativa», qui si chiama «Titolo iniziativa», perché il sottotitolo ha ora la sua colonna. Il colore è il
 * riempimento della cella di intestazione nell'Excel (per Sottotitolo quello del titolo, per Stato un grigio).
 *
 * Per ogni colonna: chiave (campo della tabella pubblicazioni), lettera dell'Excel ('' per Sottotitolo e Stato, che nell'Excel non ci sono), intestazione,
 * a_cura_di ('' = la scrive il docente nel form), colore (esadecimale), operatori (true = colonna degli operatori, testo libero) e tipo del campo
 * nella scheda: 'testo', 'si_no' (tendina SI / NO / vuoto) o 'numero'.
 */
final class ColonneMonitoraggio
{
    private const VERDE = '#00B050';
    private const VERDE_CHIARO = '#92D050';
    private const GIALLO = '#FFFF00';
    private const ARANCIO = '#FFC000';
    private const GRIGIO = '#D9D9D9';

    private const FLORIANA = 'Floriana Mantovano';
    private const SILVANA = 'Silvana Geracitano';
    private const GIANNI = 'Gianni Giglio';
    private const MARCO_EMANUELE = 'Marco Bocci o Emanuele Dodaro';

    /** @var list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool, 6: string}> chiave, lettera, intestazione, a cura di, colore, operatori, tipo */
    private const COLONNE = [
        ['data_evento', 'A', 'Data svolgimento ', '', self::VERDE, false, 'testo'],
        ['richiedente', 'B', 'Persona che ha richiesto la pubblicazione', '', self::VERDE, false, 'testo'],
        ['c_altri_referenti', 'C', "Altri referenti (interni o esterni all'Unical) (a cura di Floriana Mantovano)", self::FLORIANA, self::GIALLO, true, 'testo'],
        ['d_url_pubblicazione', 'D', 'URL effettiva pubblicazione sito (a cura di Marco Bocci o Emanuele Dodaro)', self::MARCO_EMANUELE, self::VERDE, true, 'testo'],
        ['e_url_altre_pagine', 'E', 'URL/Link ad altre pagine effettiva pubblicazione sito  (a cura di Marco Bocci o Emanuele Dodaro)', self::MARCO_EMANUELE, self::VERDE, true, 'testo'],
        ['f_pubblicazione_social', 'F', 'Pubblicazione social (SI/NO) (a cura di Gianni Giglio)', self::GIANNI, self::GIALLO, true, 'si_no'],
        ['titolo', 'G', 'Titolo iniziativa', '', self::VERDE, false, 'testo'],
        ['sottotitolo', '', 'Sottotitolo iniziativa', '', self::VERDE, false, 'testo'],
        ['descrizione', 'H', 'Breve descrizione', '', self::VERDE, false, 'testo'],
        ['modalita', 'I', 'Modalità di svolgimento', '', self::VERDE, false, 'testo'],
        ['j_tipo_iniziativa', 'J', 'Tipo iniziativa (a cura di Floriana Mantovano)', self::FLORIANA, self::GIALLO, true, 'testo'],
        ['k_dimensione_geografica', 'K', 'Dimensione Geografica  (a cura di Floriana Mantovano)', self::FLORIANA, self::GIALLO, true, 'testo'],
        ['l_dest_cittadinanza', 'L', 'Destinatari Cittadinanza (a cura di Floriana Mantovano)', self::FLORIANA, self::VERDE_CHIARO, true, 'testo'],
        ['m_ind_dibest_01', 'M', 'I.DiBEST.01 [S.2] - Diffusione della comunicazione delle attività museali di PE (a cura di Floriana Mantovano)', self::FLORIANA, self::VERDE_CHIARO, true, 'testo'],
        ['n_ind_dibest_02', 'N', 'I.DiBEST.02 [S.2] - Diffusione della comunicazione delle attività di PE (a cura di Floriana Mantovano)', self::FLORIANA, self::VERDE_CHIARO, true, 'testo'],
        ['o_piattaforma_pe', 'O', 'Piattaforma PE (SI/NO) (a cura di Floriana Mantovano)', self::FLORIANA, self::VERDE_CHIARO, true, 'si_no'],
        ['p_dest_studenti', 'P', 'Destinatari STUDENTI /CdL (a cura di Silvana Geracitano)', self::SILVANA, self::ARANCIO, true, 'testo'],
        ['q_dest_docenti', 'Q', 'Destinatari Docenti (a cura di Silvana Geracitano)', self::SILVANA, self::ARANCIO, true, 'testo'],
        ['r_dest_pta', 'R', 'Destinatari PTA (a cura di Silvana Geracitano)', self::SILVANA, self::ARANCIO, true, 'testo'],
        ['s_ind_dibest_03', 'S', 'I.DiBEST.03 [P.1] - Efficacia comunicazione pagine social di Dipartimento (a cura di Silvana Geracitano)', self::SILVANA, self::ARANCIO, true, 'testo'],
        ['t_ind_dibest_04', 'T', 'I.DiBEST.04 [P.2] - Partecipazione del personale in attività di formazione DEI (Diversity, Equity and Inclusion) (a cura di Silvana Geracitano)', self::SILVANA, self::ARANCIO, true, 'testo'],
        ['u_produzione_locandina', 'U', 'Produzione locandina (Si - NO)  (a cura di Marco Bocci o Emanuele Dodaro)', self::MARCO_EMANUELE, self::VERDE, true, 'si_no'],
        ['v_n_partecipanti', 'V', 'N.  Partecipanti  (a cura di Floriana Mantovano o Silvana Geracitano)', self::FLORIANA . ' o ' . self::SILVANA, self::GIALLO, true, 'numero'],
        ['stato', '', 'Stato', '', self::GRIGIO, false, 'testo'],
    ];

    /**
     * Tutte le colonne nell'ordine dell'export.
     *
     * @return list<array{chiave: string, lettera: string, intestazione: string, a_cura_di: string, colore: string, operatori: bool, tipo: string}>
     */
    public static function tutte(): array
    {
        return array_map(static fn (array $c): array => [
            'chiave' => $c[0], 'lettera' => $c[1], 'intestazione' => $c[2], 'a_cura_di' => $c[3], 'colore' => $c[4], 'operatori' => $c[5], 'tipo' => $c[6],
        ], self::COLONNE);
    }

    /** @return list<string> le chiavi nell'ordine dell'export */
    public static function chiavi(): array
    {
        return array_column(self::COLONNE, 0);
    }

    /** @return list<string> le intestazioni nell'ordine dell'export, identiche all'Excel (+ «Sottotitolo») */
    public static function intestazioni(): array
    {
        return array_column(self::COLONNE, 2);
    }

    /** @return list<string> le chiavi delle colonne degli operatori (testo libero), nell'ordine dell'Excel */
    public static function colonneOperatori(): array
    {
        return array_values(array_map(static fn (array $c): string => $c[0], array_filter(self::COLONNE, static fn (array $c): bool => $c[5])));
    }

    /** @return array{chiave: string, lettera: string, intestazione: string, a_cura_di: string, colore: string, operatori: bool, tipo: string}|null */
    public static function perChiave(string $chiave): ?array
    {
        foreach (self::tutte() as $c) {
            if ($c['chiave'] === $chiave) {
                return $c;
            }
        }

        return null;
    }

    /** L'intestazione senza la parte «(a cura di …)» e senza spazi doppi: per le etichette della scheda. */
    public static function etichetta(string $chiave): string
    {
        $c = self::perChiave($chiave);
        if (!$c) {
            return $chiave;
        }

        return trim((string) preg_replace(['/\s*\(a cura di [^)]*\)\s*$/u', '/\s+/u'], ['', ' '], $c['intestazione']));
    }
}
