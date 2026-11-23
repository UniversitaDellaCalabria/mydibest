#!/usr/bin/env bash
# =========================================================================
# Crea il pacchetto COMPLETO da caricare sul server: tutti i file del portale come sono nell'ultimo commit (git), senza ciò che sul server non
# deve mai essere sovrascritto o non serve (.env, uploads, cache, backups, tests, strumenti, documentazione, materiali, file di sviluppo).
# Dentro il pacchetto c'è SHA256SUMS_pacchetto.txt: sul server `sha256sum -c` dice se manca o è diverso qualche file.
#   bash strumenti/crea_pacchetto.sh            → aggiornamento_completo.tgz nella cartella del portale
# Il pacchetto non include i file non ancora committati: se git status non è pulito lo script lo segnala.
# =========================================================================
set -e
cd "$(dirname "$0")/.."
NOME="${1:-aggiornamento_completo.tgz}"
if [ -n "$(git status --porcelain | grep -v '^??' )" ]; then
    echo "ATTENZIONE: ci sono modifiche non committate (non entrano nel pacchetto):"; git status --short | grep -v '^??' | head -10
fi

ELENCO="$(mktemp)"
git ls-files \
  | grep -v -E '^(tests|strumenti|documentazione|difetti|materiali|database|uploads|cache|backups|vendor)/' \
  | awk '!/^protocollo\// || /^protocollo\/\.htaccess$/ || /^protocollo\/php-titulus-utility\/src\//' \
  | grep -v -E '(^|/)(desktop\.ini|\.gitkeep|\.gitattributes|\.gitignore|\.php-cs-fixer\.dist\.php)$' \
  | grep -v -E '^(README\.md|CLAUDE\.md|MIGRATION_PLAN\.md|LICENSE|composer\.json|composer\.lock|phpunit\.xml\.dist|phpstan\.neon\.dist|\.env\.example|\.htaccess|install\.php)$' \
  | grep -v -E '\.tgz$' > "$ELENCO"
N=$(wc -l < "$ELENCO")

# Elenco di controllo: somma di ogni file (si verifica sul server dopo aver estratto)
SOMME="SHA256SUMS_pacchetto.txt"
: > "$SOMME"
while IFS= read -r f; do sha256sum "$f" >> "$SOMME"; done < "$ELENCO"
echo "$SOMME" >> "$ELENCO"

# Versione del pacchetto: commit, data e ora di creazione, ramo. Il file nasce in una cartella temporanea e entra solo nel pacchetto
# (inc/versione_portale.txt, dentro inc/ che il .htaccess chiude al web): la cartella di lavoro non ne conserva copie vecchie.
# La pagina Sistema lo mostra come «Versione caricata».
VERS="$(mktemp -d)"
mkdir -p "$VERS/inc"
{
    echo "commit=$(git rev-parse --short HEAD)"
    echo "data=$(date '+%d/%m/%Y %H:%M')"
    echo "ramo=$(git rev-parse --abbrev-ref HEAD)"
} > "$VERS/inc/versione_portale.txt"

tar czf "$NOME" -T "$ELENCO" -C "$VERS" inc/versione_portale.txt
rm -rf "$ELENCO" "$SOMME" "$VERS"
echo "Creato $NOME: $N file, $(du -h "$NOME" | cut -f1)."
echo "Commit incluso: $(git log --oneline -1)"
