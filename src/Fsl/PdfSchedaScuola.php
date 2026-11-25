<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Infrastructure\Pdf\Documento;

/**
 * «Scarica la scheda» della scuola in PDF (pagina «Scuole» del pannello FSL): data, anagrafe, convenzione, eventi e progetti, docenti referenti.
 *
 * Il PDF si può girare per email o stampare, quindi NON contiene: i nomi degli studenti (nemmeno il numero dell'elenco), i codici fiscali, i codici di
 * prenotazione, i link personali o con un token (compilazioni, elenco studenti, attestati, valutazioni) né le valutazioni. Si costruisce da ciò che
 * SchedaScuolaFsl dà già, scegliendo soltanto i campi che possono uscire: aggiungere un campo alla scheda non lo porta qui da solo.
 */
final class PdfSchedaScuola
{
    private const CONV = ['coperta' => 'coperta', 'non_coperta' => 'NON coperta', 'non_richiesta' => 'convenzione non richiesta'];

    /**
     * @param array<string, mixed> $scheda come la dà SchedaScuolaFsl::scheda()
     * @param string $data la data di stampa (gg/mm/aaaa)
     */
    public function pdf(array $scheda, string $data): string
    {
        $pdf = new Documento(['piede' => 'Scheda della scuola – stampata il ' . $data, 'info' => ['Title' => 'Scheda scuola ' . $scheda['nome'], 'Creator' => 'Portale DiBEST']]);
        $pdf->sx = $pdf->dx = 40;
        $pdf->alto = 40;
        $pdf->y = $pdf->alto;
        $t = static fn (mixed $v): string => trim((string) $v);

        $pdf->paragrafo($t($scheda['nome']), ['font' => 'H', 'b' => true, 'sz' => 16, 'al' => 'sinistra', 'dopo' => 2]);
        $pdf->paragrafo('Formazione Scuola Lavoro – scheda del ' . $data . ($scheda['codice'] !== '' ? ' – codice meccanografico ' . $scheda['codice'] : ' – scuola scritta a mano, non in anagrafe'), ['font' => 'H', 'sz' => 9, 'al' => 'sinistra', 'dopo' => 8]);

        $a = $scheda['anagrafe'] ?? null;
        if (is_array($a)) {
            $righe = [];
            foreach (['Denominazione' => 'denominazione', 'Comune' => 'comune', 'Tipo' => 'tipo', 'Indirizzo' => 'indirizzo', 'PEC' => 'pec', 'Email' => 'email', 'Telefono' => 'telefono'] as $etichetta => $k) {
                if ($t($a[$k] ?? '') !== '') {
                    $righe[] = [$etichetta, $t($a[$k])];
                }
            }
            if ($righe) {
                $pdf->tabella([], $righe, ['sz' => 9, 'larghezze' => [1, 4], 'col0' => '0.96 0.96 0.97']);
            }
        }

        $pdf->fascia('Convenzione', ['sz' => 10, 'colore' => '0.20 0.30 0.45']);
        $conv = [['Stato', $t($scheda['stato_testo']) . ($t($scheda['validita']) !== '' ? ' – ' . $t($scheda['validita']) : '')]];
        foreach ($scheda['registro'] as $c) {
            $conv[] = ['Convenzione n. ' . (int) $c['id'], $t($c['validita']) . ($t($c['protocollo']) !== '' ? ' – protocollo ' . $t($c['protocollo']) : '') . ' – ' . self::statoRegistro((string) $c['stato'])
                . (!empty($c['integrazioni']) ? ' – ' . count($c['integrazioni']) . ' integrazioni dell\'Allegato A' : '')];
        }
        if (!empty($scheda['iter'])) {
            $conv[] = ['In corso', $t($scheda['iter']['passo'])];
        }
        $pdf->tabella([], $conv, ['sz' => 9, 'larghezze' => [1.3, 4], 'col0' => '0.96 0.96 0.97']);

        $pdf->fascia('Eventi e progetti' . ((int) $scheda['anno'] > 0 ? ' – anno scolastico ' . (int) $scheda['anno'] . '/' . substr((string) ((int) $scheda['anno'] + 1), 2) : ' – tutti gli anni'), ['sz' => 10, 'colore' => '0.20 0.30 0.45']);
        $eventi = [];
        foreach ($scheda['eventi'] as $e) {
            $eventi[] = [
                $t($e['titolo']) . ($t($e['turno']) !== '' ? ' – ' . $t($e['turno']) : ''),
                $e['dal'] === $e['al'] ? date('d/m/Y', (int) strtotime((string) $e['dal'])) : date('d/m/Y', (int) strtotime((string) $e['dal'])) . ' – ' . date('d/m/Y', (int) strtotime((string) $e['al'])),
                $t($e['sede_nome']) !== '' ? $t($e['sede_nome']) : '—',
                $t($e['docente']),
                $t($e['stato_testo']),
                self::CONV[(string) $e['conv']] . ((string) $e['conv'] === 'non_coperta' && $t($e['perche']) !== '' ? ': ' . $t($e['perche']) : ''),
            ];
        }
        if ($eventi) {
            $pdf->tabella(['Evento o progetto', 'Periodo', 'Sede', 'Docente', 'Stato', 'Convenzione'], $eventi, ['sz' => 8, 'larghezze' => [3, 2, 2.4, 2, 1.4, 3]]);
        } else {
            $pdf->paragrafo('Nessun evento o progetto.', ['font' => 'H', 'sz' => 9, 'al' => 'sinistra']);
        }

        $pdf->fascia('Docenti referenti', ['sz' => 10, 'colore' => '0.20 0.30 0.45']);
        $docenti = [];
        foreach ($scheda['docenti'] as $d) {
            $docenti[] = [$t($d['nome']), $t($d['email']) !== '' ? $t($d['email']) : '—', $t($d['telefono']) !== '' ? $t($d['telefono']) : '—', (string) (int) $d['eventi'], !empty($d['in_allegato']) ? 'sì' : 'no'];
        }
        if ($docenti) {
            $pdf->tabella(['Nome', 'Email', 'Telefono', 'Eventi seguiti', 'In Allegato A'], $docenti, ['sz' => 8, 'larghezze' => [3, 3.5, 2, 1.4, 1.4]]);
        } else {
            $pdf->paragrafo('Nessun docente referente.', ['font' => 'H', 'sz' => 9, 'al' => 'sinistra']);
        }

        return $pdf->pdf();
    }

    private static function statoRegistro(string $stato): string
    {
        return ['valida' => 'valida', 'in_scadenza' => 'in scadenza', 'scaduta' => 'scaduta', 'futura' => 'non ancora valida'][$stato] ?? $stato;
    }
}
