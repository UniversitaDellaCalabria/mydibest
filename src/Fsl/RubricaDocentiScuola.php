<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\Database;

/**
 * I docenti referenti di una scuola aggiunti o corretti dall'ufficio nella scheda «Scuole» (tabella scuole_docenti, una riga per istituto e email).
 *
 * È solo una rubrica: aggiungere un docente NON gli dà nessun diritto sulla convenzione (il proprietario di una compilazione resta chi apre il link
 * con l'email verificata: ConvenzioneCompilataRepository::rivendica) e non cambia le prenotazioni. Serve a scegliere a chi mandare il link della
 * stipula e a scrivere ai docenti. Per correggere il nome o il telefono di un docente che viene da una prenotazione si salva una riga con la sua email.
 */
final class RubricaDocentiScuola
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return list<array{email: string, nome: string, telefono: string}>
     */
    public function dellIstituto(string $istituto): array
    {
        return array_map(
            static fn (array $r): array => ['email' => (string) $r['email'], 'nome' => (string) $r['nome'], 'telefono' => (string) $r['telefono']],
            $this->db->righe('SELECT email, nome, telefono FROM scuole_docenti WHERE istituto_codice = ? ORDER BY nome, email', [$istituto])
        );
    }

    /** Aggiunge o corregge (per email) un docente; il messaggio d'errore, null se è andata bene. */
    public function salva(string $istituto, string $email, string $nome, string $telefono, string $da): ?string
    {
        $email = strtolower(trim($email));
        $nome = trim((string) preg_replace('/\s+/u', ' ', $nome));
        $telefono = trim((string) preg_replace('/[^0-9+()\/ .-]/', '', $telefono));
        if (preg_match('/^[A-Z0-9]{10}$/', $istituto) !== 1) {
            return 'Scuola non valida.';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 255) {
            return 'Scrivi un indirizzo email valido per il docente.';
        }
        if ($nome === '') {
            return 'Scrivi il nome e il cognome del docente.';
        }
        $this->db->esegui(
            'INSERT INTO scuole_docenti (istituto_codice, email, nome, telefono, creato_da) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE nome = VALUES(nome), telefono = VALUES(telefono), modificato_il = NOW()',
            [$istituto, $email, mb_substr($nome, 0, 200), mb_substr($telefono, 0, 60), mb_substr($da, 0, 255)]
        );

        return null;
    }
}
