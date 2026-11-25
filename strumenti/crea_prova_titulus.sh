#!/usr/bin/env bash
# =========================================================================
# Crea prova_file_titulus.tgz: la prova di lettura dei file da Titulus (strumenti/prova_file_titulus.php) con tutto ciò che le serve per girare
# da sola sul server, SENZA caricare il codice nuovo nel portale:
#   prova_file_titulus.php            lo strumento (solo riga di comando, solo lettura)
#   php-titulus-utility/src/*.php     la copia del connettore Titulus (con la lettura dei file): lo strumento carica questa, prima di quella del portale
#   pdf/FirmePades.php, VerificaFirme.php   le due classi che contano le firme di un PDF
#   LEGGIMI.txt                       i comandi
#   SHA256SUMS.txt                    somme di controllo (sha256sum -c SHA256SUMS.txt)
# Si copia nella home del server, si estrae in ~/prova_titulus e si lancia (vedi LEGGIMI.txt). I file si prendono dalla cartella di lavoro, come
# sono adesso (anche se non ancora committati). Il .tgz non va in git (.gitignore: *.tgz).
#   bash strumenti/crea_prova_titulus.sh            → prova_file_titulus.tgz nella cartella del portale
# =========================================================================
set -euo pipefail
cd "$(dirname "$0")/.."
NOME="${1:-prova_file_titulus.tgz}"

for f in strumenti/prova_file_titulus.php src/Infrastructure/Pdf/FirmePades.php src/Infrastructure/Pdf/VerificaFirme.php protocollo/php-titulus-utility/src/ClienteSoapTitulus.php protocollo/php-titulus-utility/src/LetturaFileTitulus.php; do
    [ -f "$f" ] || { echo "Manca $f: non posso creare il pacchetto." >&2; exit 1; }
done

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/php-titulus-utility/src" "$TMP/pdf"
cp strumenti/prova_file_titulus.php "$TMP/"
cp protocollo/php-titulus-utility/src/*.php "$TMP/php-titulus-utility/src/"
cp src/Infrastructure/Pdf/FirmePades.php src/Infrastructure/Pdf/VerificaFirme.php "$TMP/pdf/"

# Controllo di sintassi, se c'è PHP su questo computer
if command -v php >/dev/null 2>&1; then
    for f in "$TMP"/prova_file_titulus.php "$TMP"/php-titulus-utility/src/*.php "$TMP"/pdf/*.php; do
        php -l "$f" >/dev/null || { echo "Errore di sintassi in $f" >&2; exit 1; }
    done
fi

cat > "$TMP/LEGGIMI.txt" <<'TESTO'
Prova di lettura dei file da Titulus (solo lettura: non scrive in Titulus né nel database).

1) Estrarre (una volta):
     mkdir -p ~/prova_titulus && tar xzf ~/prova_file_titulus.tgz -C ~/prova_titulus && cd ~/prova_titulus && sha256sum -c SHA256SUMS.txt

2) Lanciare (PHP 8.4 del server; --sito è la cartella del portale da cui leggere configurazione e password di Titulus, senza modificare nulla):
     /opt/php84/bin/php ~/prova_titulus/prova_file_titulus.php --sito=/opt/lampp/htdocs/mydibest_pp --nrecord=NUMERO_DI_RECORD

   Con un documento protocollato a mano si dà il numero di protocollo:   --numero=2026-UCALPRG-0001234
   Dati tutti e due, si provano entrambi. Per vedere se il file coincide con quello inviato:   --confronta=/percorso/file.pdf
   Per tenere i file letti (fuori dalla cartella web, cartella 0700):   --salva=$HOME/file_da_titulus

3) Cosa guardare:
   - «loadDocument sì» e «getAttachment sì»: il servizio offre le operazioni;
   - «Permesso negato»: l'utente di Titulus non può leggere i file: l'Ufficio Protocollo deve abilitarle;
   - «PDF: sì» e «firme»: il file è un PDF, con quante firme;
   - «confronto: COINCIDE» (stesso file di quello inviato) o «DIVERSO» (Titulus lo ha riscritto, per esempio con la stampigliatura);
   - un documento protocollato a mano si legge con --numero?

Lo strumento rifiuta un indirizzo che non sia di pre-produzione (.pp.cineca.it) salvo --produzione. Non stampa mai la password.
TESTO

(cd "$TMP" && find . -type f ! -name SHA256SUMS.txt | sort | sed 's|^\./||' | xargs sha256sum > SHA256SUMS.txt)

rm -f "$NOME"
tar czf "$NOME" -C "$TMP" prova_file_titulus.php php-titulus-utility pdf LEGGIMI.txt SHA256SUMS.txt
echo "Creato $NOME: $(tar tzf "$NOME" | grep -vc '/$') file, $(du -h "$NOME" | cut -f1)."
tar tzf "$NOME" | grep -v '/$' | sed 's/^/  /'
