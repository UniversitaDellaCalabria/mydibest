<?php

declare(strict_types=1);

namespace App\Protocollo;

/** Configurazione globale di collegamento a Titulus (una sola per il portale): dove collegarsi, con quale utenza e per quale AOO. */
final class ConfigTitulus
{
    public function __construct(
        public readonly bool $attivo,
        public readonly string $nome,
        public readonly string $urlWs,
        public readonly string $namespaceNs2,
        public readonly string $username,
        public readonly string $aoo,
        public readonly string $agd,
        public readonly string $autore,
        public readonly string $emailDefault,
        public readonly bool $fascicolazioneSeparata,
    ) {
    }

    /** @param array<string, mixed> $r riga di titulus_config */
    public static function daRiga(array $r): self
    {
        return new self(
            (int) ($r['attivo'] ?? 0) === 1,
            (string) ($r['nome'] ?? ''),
            (string) ($r['url_ws'] ?? ''),
            (string) ($r['namespace_ns2'] ?? ''),
            (string) ($r['username'] ?? ''),
            (string) ($r['aoo'] ?? ''),
            (string) ($r['agd'] ?? ''),
            (string) ($r['autore'] ?? ''),
            (string) ($r['email_default'] ?? ''),
            (int) ($r['fascicolazione_separata'] ?? 1) === 1,
        );
    }

    /** Mancano i dati per collegarsi? (testo dell'avviso, null se è completa) */
    public function incompleta(): ?string
    {
        foreach (['urlWs' => 'l\'indirizzo del servizio', 'username' => 'l\'username', 'aoo' => 'l\'AOO'] as $campo => $nome) {
            if (trim($this->$campo) === '') {
                return "Manca $nome.";
            }
        }

        return null;
    }
}
