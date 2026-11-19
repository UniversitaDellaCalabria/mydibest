<?php

declare(strict_types=1);

namespace App\Pubblicazioni;

/**
 * I sette passi della richiesta di pubblicazione del docente e a quale passo appartiene ogni campo: serve alla pagina per riaprirsi sul primo passo
 * con un errore e per mettere l'errore accanto al campo giusto. Senza stato: si prova da sola.
 */
final class PassiRichiesta
{
    /** Numero del passo => titolo. */
    public const PASSI = [
        1 => 'Prima di iniziare',
        2 => 'L\'evento',
        3 => 'I testi',
        4 => 'A chi è rivolto',
        5 => 'Allegati e immagini',
        6 => 'Pubblicazione',
        7 => 'Riepilogo',
    ];

    /** Il passo di ogni campo che può avere un errore (le chiavi sono quelle di RegolePubblicazione::valida() più «ho_letto»). */
    private const CAMPI = [
        'ho_letto' => 1,
        'data_evento' => 2, 'modalita' => 2, 'url_docente' => 2, 'altri_link_docente' => 2,
        'titolo' => 3, 'sottotitolo' => 3, 'descrizione' => 3,
        'destinatari' => 4,
        'allegati' => 5, 'immagini' => 5,
        'tag' => 6, 'data_inizio_pubblicazione' => 6, 'note' => 6,
    ];

    /** Il passo del campo (il riepilogo, l'ultimo, per gli errori generali). */
    public static function passoDi(string $campo): int
    {
        return self::CAMPI[$campo] ?? count(self::PASSI);
    }

    /**
     * Il primo passo (il più basso) che ha un errore, null se non ce ne sono.
     *
     * @param array<string, string> $errori campo => messaggio
     */
    public static function primoConErrore(array $errori): ?int
    {
        if ($errori === []) {
            return null;
        }

        return min(array_map(static fn (string $campo): int => self::passoDi((string) $campo), array_keys($errori)));
    }

    /**
     * La casella «Ho letto le note operative» del passo 1: l'invio la richiede anche se il browser non l'ha controllata.
     *
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    public static function erroreHoLetto(array $post): array
    {
        return empty($post['ho_letto']) ? ['ho_letto' => 'Per inviare la richiesta spunta «Ho letto le note operative».'] : [];
    }
}
