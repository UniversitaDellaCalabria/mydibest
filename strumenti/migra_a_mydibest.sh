#!/usr/bin/env bash
# =========================================================================
# Sposta il portale da https://dibest2.unical.it/didattica a https://dibest2.unical.it/mydibest
# e lascia ai vecchi indirizzi (/didattica e /eventi) un rimando permanente (301) pagina per pagina,
# parametri compresi: continuano a funzionare i link già inviati per email e i QR degli attestati.
# Il rimando va TENUTO PER SEMPRE (il QR dell'attestato è stampato sui documenti).
#
#   sudo bash /opt/lampp/htdocs/didattica/strumenti/migra_a_mydibest.sh            esegue lo spostamento
#   sudo bash /opt/lampp/htdocs/mydibest/strumenti/migra_a_mydibest.sh --annulla   torna a /didattica
#
# Dopo lo spostamento (o l'annullamento) aggiornare il crontab dell'utente che lancia i cron:
# lo script stampa il comando da eseguire.
#
# I rimandi contengono anche un sw.js che NON viene rimandato: chi aveva installato l'app (PWA) o attivato
# le notifiche sul vecchio indirizzo riceve un service worker che si disinstalla (i browser rifiutano
# uno script di service worker che passa da un redirect). Dopo, le notifiche vanno riattivate una volta.
# =========================================================================
set -e
HT="${HTDOCS:-/opt/lampp/htdocs}"
VECCHIA="$HT/didattica"
NUOVA="$HT/mydibest"
EVENTI="$HT/eventi"
HOST="https://dibest2.unical.it"

imposta_url() {   # URL_SITO nel file .env (usato dai cron lanciati da riga di comando)
    local env="$1/.env" url="$2"
    [ -f "$env" ] || return 0
    if grep -q '^URL_SITO=' "$env"; then sed -i "s#^URL_SITO=.*#URL_SITO=$url#" "$env"; else printf '\nURL_SITO=%s\n' "$url" >> "$env"; fi
}

crea_rimando() {   # $1 = cartella del rimando, $2 = cartella di destinazione (nome), $3 = proprietario
    mkdir -p "$1"
    cat > "$1/.htaccess" <<EOF
# Il portale si è spostato in /$2: rimando permanente pagina per pagina (parametri compresi)
RewriteEngine On
# sw.js non si rimanda: serve a disinstallare il service worker del vecchio indirizzo
RewriteRule ^sw\.js\$ - [L]
RewriteRule ^(.*)\$ /$2/\$1 [R=301,L]
EOF
    cat > "$1/sw.js" <<'EOF'
// Il portale ha cambiato indirizzo. Questo service worker serve solo a chi aveva installato quello vecchio: si disinstalla.
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) {
    e.waitUntil(caches.keys().then(function (k) { return Promise.all(k.map(function (c) { return caches.delete(c); })); })
        .then(function () { return self.registration.unregister(); }));
});
EOF
    touch "$1/.rimando"
    chown -R "$3" "$1"
}

if [ "$1" = "--annulla" ]; then
    [ -d "$NUOVA" ] || { echo "Non trovo $NUOVA: niente da annullare."; exit 1; }
    if [ -e "$VECCHIA" ]; then
        [ -f "$VECCHIA/.rimando" ] || { echo "$VECCHIA esiste e non è la cartella del rimando: mi fermo."; exit 1; }
        rm -rf "$VECCHIA"
    fi
    PROPRIETARIO="$(stat -c '%U:%G' "$NUOVA")"
    mv "$NUOVA" "$VECCHIA"
    imposta_url "$VECCHIA" "$HOST/didattica"
    if [ -f "$EVENTI/.rimando" ]; then crea_rimando "$EVENTI" didattica "$PROPRIETARIO"; fi
    echo "Fatto: il portale è di nuovo su $HOST/didattica."
    echo "Aggiorna il crontab (senza sudo, con l'utente dei cron):"
    echo "  crontab -l | sed 's#/mydibest/#/didattica/#g' | crontab -"
    exit 0
fi

[ -d "$VECCHIA" ] || { echo "Non trovo $VECCHIA."; exit 1; }
[ -f "$VECCHIA/.rimando" ] && { echo "$VECCHIA è già la cartella del rimando: lo spostamento è già stato fatto."; exit 1; }
[ -e "$NUOVA" ] && { echo "$NUOVA esiste già: mi fermo per non sovrascrivere nulla."; exit 1; }

# Regole di Apache legate al nome della cartella: se ne esistono, vanno aggiornate (di solito non ce ne sono)
if grep -rnE "htdocs/(didattica|eventi)|[\"' ]/(didattica|eventi)[/\"' ]" /etc/apache2 --include=*.conf 2>/dev/null | grep -v '^\s*#' | grep -vE ':[0-9]+:\s*#'; then
    echo; echo "ATTENZIONE: le righe qui sopra di /etc/apache2 citano il nome della cartella. Controllale (in genere sono solo commenti)."
    echo "Premi Invio per continuare o Ctrl+C per fermarti."; read -r _
fi

PROPRIETARIO="$(stat -c '%U:%G' "$VECCHIA")"
mv "$VECCHIA" "$NUOVA"
imposta_url "$NUOVA" "$HOST/mydibest"

# Rimandi: /didattica e /eventi portano direttamente a /mydibest (un solo salto)
crea_rimando "$VECCHIA" mydibest "$PROPRIETARIO"
if [ -d "$EVENTI" ] && [ ! -f "$EVENTI/.rimando" ]; then
    echo "$EVENTI esiste e non è la cartella del rimando: non la tocco."
else
    crea_rimando "$EVENTI" mydibest "$PROPRIETARIO"
fi

echo "Fatto: il portale è su $HOST/mydibest e $HOST/didattica e $HOST/eventi rimandano lì."
echo
echo "1) Aggiorna il crontab (senza sudo, con l'utente dei cron; i curl non seguono i rimandi, senza questo i cron si fermano):"
echo "   crontab -l | sed -E 's#/(didattica|eventi)/#/mydibest/#g' | crontab -"
echo "2) Controlla il sito:"
echo "   cd $NUOVA && PHP_BIN=/opt/php84/bin/php bash strumenti/verifica_sito.sh $HOST/mydibest"
echo "   curl -sI $HOST/didattica/privacy.php | grep -i '^location'"
echo "   curl -sI $HOST/eventi/privacy.php | grep -i '^location'"
echo "3) Prova l'accesso con SSO, una pagina del pannello e un QR di attestato. Se qualcosa non va: sudo bash $NUOVA/strumenti/migra_a_mydibest.sh --annulla"
