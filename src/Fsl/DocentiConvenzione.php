<?php

declare(strict_types=1);

namespace App\Fsl;

/**
 * I docenti di riferimento di una convenzione (Allegato A): le righe del modulo, quelle proposte all'ufficio prese da ciò che la scuola ha già
 * scritto, e l'elenco senza doppioni.
 */
final class DocentiConvenzione
{
    /** «Prof.», «Prof.ssa», «Dott./Dott.ssa»… all'inizio del nome (non «Profeta» o «Dottori»). */
    private const TITOLI = '/^(?:(?:prof(?:\.?\s*ssa|essoressa|essore)?|dott(?:\.?\s*ssa|oressa|ore)?|dr(?:\.?\s*ssa)?)(?:\.|\b)[\s\/.]*)+/iu';

    public function __construct(private PrenotazioneFslRepository $prenotazioni, private ConvenzioneCompilataRepository $compilate)
    {
    }

    /**
     * Le righe nome/email del modulo (doc_nome[], doc_email[]).
     *
     * @param array<string, mixed> $post
     * @return list<array{nome: string, email: string}>
     */
    public static function dalModulo(array $post): array
    {
        $nomi = (array) ($post['doc_nome'] ?? []);
        $email = (array) ($post['doc_email'] ?? []);
        $out = [];
        foreach (array_keys($nomi + $email) as $i) {
            $out[] = ['nome' => is_string($nomi[$i] ?? null) ? $nomi[$i] : '', 'email' => is_string($email[$i] ?? null) ? $email[$i] : ''];
        }

        return $out;
    }

    /**
     * I docenti da proporre per una convenzione compilata online: i «tutor» delle attività e i docenti delle prenotazioni della scuola.
     *
     * @param array<string, mixed> $cc riga di convenzioni_compilate
     * @return list<array{nome: string, email: string}>
     */
    public function proposti(array $cc): array
    {
        $dati = json_decode((string) ($cc['dati_json'] ?? ''), true) ?: [];
        $docenti = [];
        // solo le prenotazioni fatte con le email di chi è proprietario della compilazione (elenco esplicito, senza l'email scritta nel modulo): chi ha prenotato per la stessa scuola con un'altra email non è tra i docenti finché la sua attività non è confermata
        $ids = $this->prenotazioni->idPrenotateDallaScuola((int) ($cc['prenotazione_id'] ?? 0), (string) ($cc['scuola_codice'] ?? ''), $this->compilate->proprietari($cc));
        foreach ((array) ($dati['attivita'] ?? []) as $a) {
            $docenti[] = ['nome' => (string) ($a['tutor'] ?? ''), 'email' => ''];
            $ids[] = (int) ($a['pr'] ?? 0);
        }
        foreach ($this->prenotazioni->docenti($ids) as $p) {
            $docenti[] = ['nome' => trim($p['nome'] . ' ' . $p['cognome']), 'email' => (string) $p['email']];
        }

        return self::senzaDoppioni($docenti, true);
    }

    /**
     * Senza righe vuote e senza doppioni: si confronta l'email, poi il nome senza titoli (anche «Rossi Mario» e «Mario Rossi»). Le righe dello
     * stesso docente si completano a vicenda. Quelle proposte ($proposte) si uniscono se coincide una delle due cose; quelle scritte dall'ufficio
     * solo se l'altra non le distingue (due omonimi con email diverse, o due docenti con l'email della scuola, restano due).
     *
     * @param list<array<string, mixed>> $docenti
     * @return list<array{nome: string, email: string}>
     */
    public static function senzaDoppioni(array $docenti, bool $proposte = false): array
    {
        $out = [];
        foreach ($docenti as $d) {
            $nome = trim((string) preg_replace('/\s+/u', ' ', (string) ($d['nome'] ?? '')));
            $email = mb_strtolower(trim((string) ($d['email'] ?? '')));
            if ($nome === '' && $email === '') {
                continue;
            }
            foreach ($out as $i => $c) {
                $chiave = self::chiave($nome);
                $chiaveC = self::chiave($c['nome']);
                $stessaEmail = $email !== '' && $email === $c['email'];
                $stessoNome = $chiave !== '' && $chiave === $chiaveC;
                if ($stessaEmail && ($proposte || $stessoNome || $chiave === '' || $chiaveC === '') || $stessoNome && ($proposte || $email === '' || $c['email'] === '')) {
                    $out[$i] = ['nome' => $c['nome'] !== '' ? $c['nome'] : $nome, 'email' => $c['email'] !== '' ? $c['email'] : $email];
                    continue 2;
                }
            }
            $out[] = ['nome' => $nome, 'email' => $email];
        }

        return $out;
    }

    /** Il nome per il confronto: senza titoli, minuscolo, parole in ordine alfabetico. */
    private static function chiave(string $nome): string
    {
        $senza = (string) preg_replace(self::TITOLI, '', $nome);
        $parole = array_filter(explode(' ', mb_strtolower(trim((string) preg_replace('/[\s.,]+/u', ' ', $senza)))), static fn (string $p): bool => $p !== '');
        sort($parole);

        return implode(' ', $parole);
    }
}
