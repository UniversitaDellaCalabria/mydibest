#!/usr/bin/env bash
# =========================================================================
# Prova con un Apache VERO (quello di XAMPP, su una porta di prova) del .htaccess del portale e del Nucleo (regola 3b, nucleo.php).
#   bash strumenti/prove/apache_nucleo.sh
# Non usa il portale né il database: mette in una cartella temporanea il .htaccess vero e dei file «finti» (ognuno risponde col proprio nome),
# così si vede quale file risponde a quale indirizzo. Tre scenari:
#   A. com'è oggi nel repository: .htaccess vero + nucleo.php
#   B. senza il Nucleo: nucleo.php cancellato E la regola 3b tolta dal .htaccess
#   C. nucleo.php cancellato ma la regola lasciata (il peggio che può capitare: un file sparito per sbaglio)
# Si pretende che: ogni indirizzo di oggi risponda uguale in A, B e C; gli indirizzi nuovi (con una «/» e senza file) siano del Nucleo solo in A,
# e negli altri due scenari siano un 404 come prima del Nucleo.
# =========================================================================
set -u
SITO="$(cd "$(dirname "$0")/../.." && pwd)"
XAMPP="${XAMPP:-/c/xampp}"
HTTPD="$XAMPP/apache/bin/httpd.exe"
PORTA="${PORTA_PROVA:-8099}"
[ -x "$HTTPD" ] || { echo "Apache di XAMPP non trovato ($HTTPD): prova saltata"; exit 0; }
TMP="$(mktemp -d)"
win() { cygpath -m "$1" 2>/dev/null || echo "$1"; }
FALLITE=0
APACHE_PID=""
ferma() { # spegne Apache con il pid scritto da lui (quello di Windows, non quello della shell)
    local p=""
    [ -f "$TMP/httpd.pid" ] && p=$(tr -d '\r\n' < "$TMP/httpd.pid")
    [ -n "$p" ] && taskkill //F //T //PID "$p" >/dev/null 2>&1
    [ -n "$APACHE_PID" ] && kill "$APACHE_PID" 2>/dev/null
    APACHE_PID=""; rm -f "$TMP/httpd.pid"
    return 0
}
trap '[ -n "${DEBUG_APACHE:-}" ] && tail -15 "$TMP/errori.log"; ferma; rm -rf "$TMP"' EXIT

# Gli indirizzi: quelli di oggi (devono rispondere come sempre) e quelli che il Nucleo aggiunge (404 senza Nucleo)
OGGI=(contatti.php contatti admin/contatti.php admin/contatti stampa_ricevuta.php stampa_ricevuta index.php area_personale openlab openlab.php slug-che-non-esiste
      contatti/ admin/non_esiste admin/non_esiste.php assets/manca.png uploads/manca.pdf src/Http/Nucleo.php robots.txt guida.php)
NUOVI=(pagine/nuova pagine/nuova/sotto qualcosa/di_nuovo.html)

crea_docroot() { # $1 = cartella, $2 = con o senza nucleo (si|no), $3 = con o senza regola 3b (si|no)
    local d="$1"
    mkdir -p "$d/admin" "$d/assets" "$d/uploads" "$d/src/Http"
    for f in index.php contatti.php stampa_ricevuta.php area_personale.php area.php guida.php sitemap.php robots.php admin/contatti.php; do echo "FILE:$f" > "$d/$f"; done
    echo "FILE:src/Http/Nucleo.php" > "$d/src/Http/Nucleo.php"
    [ "$2" = "si" ] && echo "FILE:nucleo.php" > "$d/nucleo.php"
    if [ "$3" = "si" ]; then
        cp "$SITO/.htaccess" "$d/.htaccess"
    else
        # toglie tutto il blocco «3b» (dal commento «# 3b.» fino alla riga prima di «# 4.»)
        awk '/^# 3b\./{salta=1} /^# 4\./{salta=0} !salta' "$SITO/.htaccess" > "$d/.htaccess"
    fi
}

avvia() { # $1 = docroot
    local conf="$TMP/httpd.conf" d="$(win "$1")"
    cat > "$conf" <<EOF
ServerRoot "$(win "$XAMPP/apache")"
Listen 127.0.0.1:$PORTA
ServerName 127.0.0.1:$PORTA
PidFile "$(win "$TMP")/httpd.pid"
ErrorLog "$(win "$TMP")/errori.log"
LogLevel warn
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule access_compat_module modules/mod_access_compat.so
LoadModule alias_module modules/mod_alias.so
LoadModule dir_module modules/mod_dir.so
LoadModule env_module modules/mod_env.so
LoadModule filter_module modules/mod_filter.so
LoadModule headers_module modules/mod_headers.so
LoadModule expires_module modules/mod_expires.so
LoadModule deflate_module modules/mod_deflate.so
LoadModule mime_module modules/mod_mime.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule setenvif_module modules/mod_setenvif.so
DocumentRoot "$d"
DirectoryIndex index.php
<Directory "$d">
    AllowOverride All
    Require all granted
</Directory>
EOF
    "$HTTPD" -f "$(win "$conf")" >"$TMP/uscita.log" 2>&1 &
    APACHE_PID=$!
    for _ in $(seq 1 40); do curl -s -o /dev/null "http://127.0.0.1:$PORTA/" && return 0; sleep 0.25; done
    echo "Apache non parte:"; cat "$TMP/uscita.log" "$TMP/errori.log" 2>/dev/null | head -20; return 1
}

# Risposta di un indirizzo: «codice corpo» (il corpo dei file finti è il loro nome; per un 404 solo «404»)
risposta() {
    local codice corpo
    codice=$(curl -s -o "$TMP/corpo" -w '%{http_code}' --max-time 10 "http://127.0.0.1:$PORTA/$1")
    corpo=$(tr -d '\r\n' < "$TMP/corpo" | cut -c1-40)
    [ "$codice" = "200" ] && echo "200 $corpo" || echo "$codice"
}

scenario() { # $1 = nome, $2 = nucleo si/no, $3 = regola si/no  → scrive in $TMP/risp_$1.txt
    local d="$TMP/doc_$1"
    crea_docroot "$d" "$2" "$3"
    avvia "$d" || { FALLITE=$((FALLITE+1)); return; }
    : > "$TMP/risp_$1.txt"
    for u in "${OGGI[@]}" "${NUOVI[@]}"; do echo "$u => $(risposta "$u")" >> "$TMP/risp_$1.txt"; done
    ferma
    sleep 1
}

scenario A si si
scenario B no no
scenario C no si

for s in A B C; do [ -s "$TMP/risp_$s.txt" ] || { echo "Scenario $s: nessuna risposta (Apache non ha risposto)"; exit 1; }; done

leggi() { awk -F' => ' -v u="$2" '$1 == u { print $2; exit }' "$1"; }

esito() { # $1 = descrizione, $2 = 0 se ok
    if [ "$2" = "0" ]; then echo "  OK  $1"; else echo "  KO  $1"; FALLITE=$((FALLITE+1)); fi
}

# (/contatti/ con la barra finale dà un 500 per rinvii interni di Apache in tutti e tre gli scenari: succede anche senza il Nucleo, non dipende da lui)
echo "Gli indirizzi di oggi (devono rispondere uguale con e senza il Nucleo):"
for u in "${OGGI[@]}"; do
    a=$(leggi "$TMP/risp_A.txt" "$u"); b=$(leggi "$TMP/risp_B.txt" "$u"); c=$(leggi "$TMP/risp_C.txt" "$u")
    [ "$a" = "$b" ] && [ "$b" = "$c" ]; esito "/$u → $a" $?
    [ "$a" = "$b" ] || echo "        A: $a   B (senza Nucleo): $b   C (regola senza file): $c"
done

echo "Gli indirizzi nuovi del Nucleo (con il Nucleo: nucleo.php; senza: 404 come prima):"
for u in "${NUOVI[@]}"; do
    a=$(leggi "$TMP/risp_A.txt" "$u"); b=$(leggi "$TMP/risp_B.txt" "$u"); c=$(leggi "$TMP/risp_C.txt" "$u")
    atteso_a="200 FILE:nucleo.php" # anche un indirizzo con il punto (.html) passa dal Nucleo
    [ "$a" = "$atteso_a" ]; esito "/$u: con il Nucleo risponde nucleo.php ($a)" $?
    # senza Nucleo, un indirizzo a due pezzi era un 404 di Apache (o area.php se a un pezzo): qui sono tutti a due o tre pezzi
    [ "$b" = "404" ]; esito "/$u: senza il Nucleo (regola e file tolti) è un 404 come prima ($b)" $?
    # regola lasciata e nucleo.php sparito: Apache riscrive in «nucleo.php», che non c'è, e la regola 4 lo manda ad area.php?slug=nucleo:
    # risponde come per uno slug che non esiste (la pagina «non trovata» del portale), mai un errore di Apache
    [ "$c" = "$(leggi "$TMP/risp_C.txt" slug-che-non-esiste)" ]; esito "/$u: regola lasciata e nucleo.php sparito: risponde come uno slug sconosciuto, nessun 500 ($c)" $?
done

echo
if [ "$FALLITE" = "0" ]; then echo "Prova Apache del Nucleo: tutto in ordine."; else echo "Prova Apache del Nucleo: $FALLITE controlli falliti."; fi
exit $([ "$FALLITE" = "0" ] && echo 0 || echo 1)
