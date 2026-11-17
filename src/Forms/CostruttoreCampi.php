<?php

declare(strict_types=1);

namespace App\Forms;

use App\Didattica\CampiModulo;
use App\Didattica\Costanti;

/**
 * Campi letti dal costruttore condiviso del pannello (admin/parziali/costruttore_campi.php): stessa forma attesa da
 * App\Didattica\CampiModulo::da(), senza «ufficio» (non esiste fuori dalla Didattica). Stesso principio di
 * App\Didattica\ServizioModuli::campiDaForm(), con il proprio prefisso invece del fisso 'c_'.
 * I tipi (di campo e, nelle tabelle a righe, di colonna) sono controllati anche qui lato server, non solo nella
 * tendina del costruttore: un tipo non ammesso diventa 'text' (colonna: 'testo'), non quello scritto nel POST.
 */
final class CostruttoreCampi
{
    /** Tipi di colonna ammessi nelle tabelle a righe fuori dalla Didattica (senza le anagrafi di Ateneo). */
    public const TIPI_COLONNA = ['testo' => 'Testo', 'numero' => 'Numero', 'data' => 'Data', 'ora' => 'Ora', 'scelta' => 'Tendina'];

    /**
     * Tipi di campo ammessi fuori dalla Didattica: tutti quelli di Costanti::TIPI_CAMPO_PRATICA tranne il gruppo
     * «Dalle anagrafi» (corso di studio, insegnamenti, docente, anno accademico, azienda convenzionata per la tesi),
     * che richiedono le anagrafi di Ateneo e non hanno senso fuori dalla Didattica.
     *
     * @return list<string>
     */
    public static function tipiAmmessi(): array
    {
        $out = [];
        foreach (Costanti::GRUPPI_TIPI_CAMPO as $gruppo => $tipi) {
            if ($gruppo !== 'Dalle anagrafi') {
                $out = array_merge($out, $tipi);
            }
        }

        return $out;
    }

    /**
     * Campi del form dalle righe del costruttore (JSON, null se non ce n'è nessuno).
     *
     * @param array<string, mixed> $post
     * @param list<string> $tipiAmmessi chiavi di Costanti::TIPI_CAMPO_PRATICA da accettare (altrimenti il campo diventa 'text')
     * @param list<string> $tipiColonnaAmmessi chiavi di self::TIPI_COLONNA da accettare nelle tabelle (vuoto = tutte quelle di self::TIPI_COLONNA)
     */
    public static function daPost(array $post, string $prefisso, array $tipiAmmessi, array $tipiColonnaAmmessi = []): ?string
    {
        $tipiColonnaAmmessi = $tipiColonnaAmmessi ?: array_keys(self::TIPI_COLONNA);
        $campi = [];
        foreach ((array) ($post["{$prefisso}_etichetta"] ?? []) as $i => $et) {
            $et = trim((string) $et);
            if ($et === '') {
                continue;
            }
            $tipo = (string) ($post["{$prefisso}_tipo"][$i] ?? 'text');
            if (!in_array($tipo, $tipiAmmessi, true)) {
                $tipo = 'text';
            }
            $opzioni = (string) ($post["{$prefisso}_opzioni"][$i] ?? '');
            if ($tipo === 'tabella') {
                $opzioni = self::colonneAmmesse($opzioni, $tipiColonnaAmmessi);
            }
            $campo = ['etichetta' => $et, 'tipo' => $tipo, 'opzioni' => $opzioni,
                      'obbligatorio' => ($post["{$prefisso}_obbl"][$i] ?? '0') === '1', 'aiuto' => (string) ($post["{$prefisso}_aiuto"][$i] ?? '')];
            // Logica: «mostra solo se» e «compila in automatico se» (l'altro campo è indicato con la sua domanda)
            $rif = trim((string) ($post["{$prefisso}_cond_campo"][$i] ?? ''));
            if ($rif !== '' && isset(Costanti::OPERATORI_CONDIZIONE[$post["{$prefisso}_cond_op"][$i] ?? ''])) {
                $campo['cond'] = ['campo' => $rif, 'op' => $post["{$prefisso}_cond_op"][$i], 'valore' => (string) ($post["{$prefisso}_cond_val"][$i] ?? '')];
            }
            $rif = trim((string) ($post["{$prefisso}_auto_campo"][$i] ?? ''));
            if ($rif !== '' && isset(Costanti::OPERATORI_CONDIZIONE[$post["{$prefisso}_auto_op"][$i] ?? ''])) {
                $campo['auto'] = ['campo' => $rif, 'op' => $post["{$prefisso}_auto_op"][$i], 'valore' => (string) ($post["{$prefisso}_auto_val"][$i] ?? ''), 'imposta' => (string) ($post["{$prefisso}_auto_imposta"][$i] ?? '')];
            }
            $campi[] = $campo;
        }

        return $campi ? (string) json_encode($campi, JSON_UNESCAPED_UNICODE) : null;
    }

    /**
     * Colonne di una tabella a righe ("Nome:tipo"), con il tipo riportato a 'testo' quando non è tra quelli ammessi
     * (anche quando il tipo è stato indovinato dal nome della colonna da CampiModulo::colonne(), non solo quando è
     * scritto esplicitamente: es. una colonna chiamata "Docente" diventerebbe altrimenti di tipo 'docente' da sola).
     *
     * @param list<string> $tipiColonnaAmmessi
     */
    private static function colonneAmmesse(string $opzioni, array $tipiColonnaAmmessi): string
    {
        $spec = array_values(array_filter(array_map('trim', preg_split('/[,;\n]/', $opzioni)), static fn (string $s): bool => $s !== ''));
        if (!$spec) {
            return $opzioni;
        }
        $colonne = CampiModulo::colonne($spec);
        foreach ($colonne as &$c) {
            if (!in_array($c['tipo'], $tipiColonnaAmmessi, true)) {
                $c['tipo'] = 'testo';
            }
        }
        unset($c);

        return CampiModulo::testoColonne($colonne);
    }
}
