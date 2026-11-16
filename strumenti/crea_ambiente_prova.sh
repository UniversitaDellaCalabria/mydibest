#!/usr/bin/env bash
# =========================================================================
# Ambiente di prova di MyDiBEST: una copia del portale in /opt/lampp/htdocs/mydibest_pp
# (https://dibest2.unical.it/mydibest_pp) con un suo database, il suo .env, i suoi uploads e la sua cache.
# Si aggiorna prima lì; quando va bene, si ripete nel sito vero. Piano: documentazione/piano_ambiente_prova.md
#
# Si lancia sul server, con sudo, dalla cartella degli strumenti del sito vero:
#
#   sudo bash /opt/lampp/htdocs/mydibest/strumenti/crea_ambiente_prova.sh crea [opzioni]
#   sudo bash .../crea_ambiente_prova.sh aggiorna-dati [--anonimizza] [--tieni LISTA] [--si]
#   sudo bash .../crea_ambiente_prova.sh stato
#   sudo bash .../crea_ambiente_prova.sh elimina
#
# Opzioni di «crea»:
#   --email-prova INDIRIZZO   l'unico indirizzo che riceve le email della copia (se manca, lo chiede)
#   --senza-uploads           uploads/ parte vuota (restano solo i file .htaccess)
#   --copia-uploads-nas       le cartelle che nel sito vero stanno sul NAS (collegamenti a /mnt/...) vengono
#                             create come cartelle VERE e riempite con una copia dei file (di base restano vuote)
#   --anonimizza              al posto di nomi, email, codici fiscali, matricole e telefoni mette dati finti
#                             (@esempio.invalid). Implica --senza-uploads: i file caricati non si possono anonimizzare.
#   --tieni LISTA             con --anonimizza: email, codici fiscali o matricole (separati da virgola) di persone
#                             da lasciare come sono. I Super Amministratori restano sempre come sono (servono per entrare).
#   --con-didacticus          copia anche le chiavi dell'IA di Didacticus (LLM_*, EMB_*); di base restano vuote
#   --si                      non chiede conferme (l'email di prova va data con --email-prova)
#
# Cosa NON fa mai: non tocca il sito vero (lo legge soltanto), non tocca il crontab, non scrive sul NAS,
# non cambia la configurazione di SimpleSAMLphp (la controlla e dice cosa fare), non crea collegamenti.
# Ogni passo si può ripetere: se qualcosa si ferma a metà, basta rilanciare lo stesso comando.
# =========================================================================
set -Eeuo pipefail
umask 022
export LC_ALL=C

HT="${HTDOCS:-/opt/lampp/htdocs}"
NOME="${NOME_PROVA:-mydibest_pp}"
VERO="${SITO_VERO:-$HT/mydibest}"
PP="${SITO_PROVA:-$HT/$NOME}"
DB_VERO="${DB_VERO:-eventi_dibest}"
DB_PP="${DB_PROVA:-eventi_dibest_pp}"
UTENTE_PP="${UTENTE_DB_PROVA:-portale_dibest_pp}"
WEB="${WEB:-daemon}"                                   # utente con cui gira il sito (come in sposta_su_nas.sh)
CONF="${CONF_APACHE:-/etc/apache2/dibest2/$NOME.conf}"
SSP_CONFIG="${SSP_CONFIG:-/opt/simplesamlphp/config}"
SERVER="${SERVER_WEB:-https://dibest2.unical.it}"
RETE_ATENEO="${RETE_ATENEO:-160.97.0.0/16}"
MYSQL_CMD="${MYSQL_CMD:-mysql}"                        # sul server: root entra dal socket, senza password
MYSQLDUMP_CMD="${MYSQLDUMP_CMD:-mysqldump}"
SEGNO="$PP/.env.ambiente_prova"                        # appunti dello script (il nome comincia per «.env»: il web non lo serve)

# Cartelle di uploads che nel sito vero sono collegamenti al NAS (come in sposta_su_nas.sh)
CARTELLE_NAS="verbali pratiche convenzioni convenzioni_compilate convenzioni_tesi autorizzazioni incarichi allegati_prenotazioni"

MSG() { printf '%s\n' "$*"; }
PASSO() { printf '\n== %s\n' "$*"; }
AVVISO() { printf '!! %s\n' "$*" >&2; }
die() { printf '\nERRORE: %s\n' "$*" >&2; exit 1; }

usa_aiuto() { sed -n '2,/^set -/p' "$0" | grep -v '^set -' | grep -v '^# ====' | sed 's/^# \{0,1\}//'; }

# ---------------------------------------------------------------------------------------------
# Aiuti
# ---------------------------------------------------------------------------------------------
sql() { $MYSQL_CMD --default-character-set=utf8mb4 "$@"; }

solo_nomi_sicuri() {
    [[ "$DB_VERO" =~ ^[A-Za-z0-9_]+$ && "$DB_PP" =~ ^[A-Za-z0-9_]+$ && "$UTENTE_PP" =~ ^[A-Za-z0-9_]+$ && "$NOME" =~ ^[A-Za-z0-9_]+$ ]] \
        || die "I nomi di cartella, database e utente possono avere solo lettere, numeri e «_»."
}

controlli_di_sicurezza() {
    [ "$PP" != "$VERO" ] || die "La cartella di prova coincide con quella del sito vero."
    [ "$DB_PP" != "$DB_VERO" ] || die "Il database di prova coincide con quello del sito vero."
    [ -f "$VERO/config.php" ] || die "Non trovo il portale vero in $VERO (manca config.php)."
    [ -f "$VERO/.env" ] || die "Non trovo $VERO/.env."
}

serve_root() { [ "$(id -u)" -eq 0 ] || die "Va lanciato con sudo (serve per cartelle, database e Apache)."; }

serve_strumenti() {
    command -v rsync >/dev/null 2>&1 || die "Manca rsync: sudo apt install rsync"
    command -v openssl >/dev/null 2>&1 || die "Manca openssl."
    command -v "${MYSQL_CMD%% *}" >/dev/null 2>&1 || die "Manca il programma «mysql»."
    command -v "${MYSQLDUMP_CMD%% *}" >/dev/null 2>&1 || die "Manca il programma «mysqldump»."
}

conferma() {   # $1 = domanda; con --si risponde sì da solo
    [ "${SI:-0}" = 1 ] && return 0
    [ -t 0 ] || die "Serve una conferma ma qui non si può rispondere: usa --si."
    local r; read -r -p "$1 [s/N] " r
    [[ "$r" =~ ^[sSyY]$ ]]
}

leggi_env() {   # $1 = file, $2 = chiave → valore com'è scritto (anche con le virgolette), vuoto se manca
    [ -f "$1" ] || return 0
    { grep -E "^[[:space:]]*$2[[:space:]]*=" "$1" || true; } | tail -n1 | sed -E 's/^[^=]*=[[:space:]]*//; s/[[:space:]]+$//; s/\r$//'
}

ha_riga_env() { [ -f "$1" ] && grep -qE "^[[:space:]]*$2[[:space:]]*=" "$1"; }

segna() {   # appunti: $1 = chiave, $2 = valore
    touch "$SEGNO"
    sed -i "/^$1=/d" "$SEGNO"
    printf '%s=%s\n' "$1" "$2" >> "$SEGNO"
}
leggi_segno() { [ -f "$SEGNO" ] || return 0; { grep -m1 "^$1=" "$SEGNO" || true; } | cut -d= -f2-; }

db_esiste() { [ -n "$(sql -N -e "SELECT 1 FROM information_schema.schemata WHERE schema_name='$1'")" ]; }
db_tabelle() { sql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$1'"; }

casuale() { openssl rand -hex "$1"; }

email_valida() { [[ "$1" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]]; }

# ---------------------------------------------------------------------------------------------
# Codice, uploads, cache
# ---------------------------------------------------------------------------------------------
copia_codice() {
    PASSO "Copio il codice del portale (senza .env, uploads, cache, strumenti e pacchetti)"
    mkdir -p "$PP"
    rsync -a --safe-links \
        --include='/.env.example' \
        --exclude='/.env' --exclude='/.env.*' \
        --exclude='/uploads/' --exclude='/cache/' --exclude='/backups/' \
        --exclude='/.git/' --exclude='/.claude/' --exclude='/strumenti/' \
        --exclude='/*.tgz' --exclude='/*.zip' --exclude='/*.tar.gz' --exclude='/error_log' \
        "$VERO"/ "$PP"/
    touch "$SEGNO"
    segna creata_il "$(date '+%Y-%m-%d %H:%M:%S')"
}

# Copia solo i file che proteggono le cartelle (.htaccess, index.html, .gitkeep), con tutte le sottocartelle
copia_protezioni() {   # $1 = origine, $2 = destinazione
    rsync -a -m --safe-links --include='*/' --include='.htaccess' --include='index.html' --include='index.php' --include='.gitkeep' --exclude='*' "$1"/ "$2"/
}

prepara_cartelle() {
    PASSO "Preparo uploads/ e cache/ (cartelle vere: niente collegamenti, niente NAS)"
    mkdir -p "$PP/uploads" "$PP/cache"

    # --- uploads ---
    if [ ! -d "$VERO/uploads" ]; then
        MSG "Nel sito vero non c'è uploads/: la copia parte con uploads/ vuota."
        segna uploads "vuoti"
    elif [ "${SENZA_UPLOADS:-0}" = 1 ]; then
        MSG "uploads/ parte vuota (copio solo i file .htaccess che proteggono le cartelle)"
        copia_protezioni "$VERO/uploads" "$PP/uploads"
        segna uploads "vuoti"
    else
        MSG "Copio uploads/ dal sito vero (i collegamenti non si copiano)"
        rsync -a --safe-links "$VERO/uploads"/ "$PP/uploads"/ || AVVISO "rsync ha segnalato qualcosa su uploads/ (vedi sopra): controlla con «stato»."
        segna uploads "copiati"
    fi

    # Ogni collegamento dentro uploads/ (di solito verso il NAS) diventa una cartella VERA
    local rel
    while IFS= read -r rel; do
        [ -n "$rel" ] || continue
        if [ -L "$PP/uploads/$rel" ]; then rm -f "$PP/uploads/$rel"; fi   # se per caso fosse arrivato un collegamento
        mkdir -p "$PP/uploads/$rel"
        copia_protezioni "$VERO/uploads/$rel" "$PP/uploads/$rel"
        if [ "${COPIA_NAS:-0}" = 1 ]; then
            MSG "Copio il contenuto di uploads/$rel (era sul NAS) in una cartella vera"
            rsync -a --safe-links "$VERO/uploads/$rel"/ "$PP/uploads/$rel"/ || AVVISO "rsync ha segnalato qualcosa su uploads/$rel."
        else
            MSG "uploads/$rel: cartella vera e vuota (nel sito vero porta sul NAS)"
        fi
    done < <(find "$VERO/uploads" -type l -printf '%P\n' 2>/dev/null | sort)
    # Anche le cartelle note che nel sito vero non esistono ancora devono esserci (vuote e vere)
    for rel in $CARTELLE_NAS; do mkdir -p "$PP/uploads/$rel"; done
    [ "${COPIA_NAS:-0}" = 1 ] && segna uploads_nas "copiati" || segna uploads_nas "vuoti"

    # --- cache (e backups, se esiste nel sito vero): sempre vuote, con le sole protezioni ---
    if [ -d "$VERO/cache" ]; then copia_protezioni "$VERO/cache" "$PP/cache"; fi
    if [ -d "$VERO/backups" ]; then
        mkdir -p "$PP/backups"
        copia_protezioni "$VERO/backups" "$PP/backups"
    fi
    rm -f "$PP"/cache/schema_v*.ok                     # così al primo accesso lo schema si aggiorna da solo

    # --- proprietario: lo stesso con cui gira il sito (scrive uploads e cache) ---
    chown -R "$WEB" "$PP/uploads" "$PP/cache"
    [ -d "$PP/backups" ] && chown -R "$WEB" "$PP/backups"
    chmod -R u+rwX,g+rX,o-rwx "$PP/uploads" "$PP/cache"
    [ -d "$PP/backups" ] && chmod -R u+rwX,g+rX,o-rwx "$PP/backups"
    controlla_collegamenti
}

controlla_collegamenti() {
    local nas altri
    nas="$(find "$PP" -type l -lname '/mnt*' 2>/dev/null || true)"
    if [ -n "$nas" ]; then
        printf '%s\n' "$nas" >&2
        die "Nella copia ci sono collegamenti verso il NAS (elenco sopra). Non si va avanti."
    fi
    altri="$(find "$PP" -type l 2>/dev/null || true)"
    if [ -n "$altri" ]; then
        AVVISO "Nella copia ci sono dei collegamenti (non verso il NAS). Controlla se servono:"
        printf '%s\n' "$altri" >&2
    fi
}

# ---------------------------------------------------------------------------------------------
# Database
# ---------------------------------------------------------------------------------------------
crea_utente_db() {   # $1 = password
    PASSO "Database $DB_PP e utente $UTENTE_PP (diritti solo su quel database)"
    local h
    sql -e "CREATE DATABASE IF NOT EXISTS \`$DB_PP\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
    for h in localhost 127.0.0.1; do
        sql -e "CREATE USER IF NOT EXISTS '$UTENTE_PP'@'$h' IDENTIFIED BY '$1'"
        sql -e "ALTER USER '$UTENTE_PP'@'$h' IDENTIFIED BY '$1'"
        sql -e "GRANT ALL PRIVILEGES ON \`$DB_PP\`.* TO '$UTENTE_PP'@'$h'"
    done
    sql -e "FLUSH PRIVILEGES"
}

ricarica_dati() {
    PASSO "Copio i dati: $DB_VERO → $DB_PP (il database di prova si svuota e si riempie di nuovo)"
    sql -e "DROP DATABASE IF EXISTS \`$DB_PP\`; CREATE DATABASE \`$DB_PP\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
    # Niente --events e niente --routines: la copia non deve avere processi suoi dentro il database
    $MYSQLDUMP_CMD --single-transaction --default-character-set=utf8mb4 --max-allowed-packet=256M "$DB_VERO" \
        | sql --max-allowed-packet=256M "$DB_PP"
    MSG "Tabelle copiate: $(db_tabelle "$DB_PP")"
}

# Cose da sistemare in ogni copia di dati
dopo_i_dati() {
    PASSO "Sistemo la copia dei dati (notifiche push, Titulus, marcatori di schema)"
    esiste() { [ -n "$(sql -N -e "SELECT 1 FROM information_schema.tables WHERE table_schema='$DB_PP' AND table_name='$1'")" ]; }

    if esiste push_sottoscrizioni; then
        sql "$DB_PP" -e "DELETE FROM push_sottoscrizioni"
        MSG "Notifiche push: sottoscrizioni azzerate (i telefoni veri non ricevono nulla dalla copia)"
    fi

    if esiste titulus_config; then
        local url host nuovo nuovohost
        url="$(sql -N "$DB_PP" -e "SELECT url_ws FROM titulus_config LIMIT 1")"
        if [ -n "$url" ]; then
            host="$(printf '%s' "$url" | sed -E 's#^[A-Za-z][A-Za-z0-9+.-]*://([^/@]*@)?([^/:?\#]+).*#\2#' | tr 'A-Z' 'a-z')"
            if [[ "$host" == *.pp.cineca.it ]]; then
                MSG "Titulus: l'indirizzo è già di pre-produzione ($host)"
            elif [[ "$host" == *.cineca.it ]] && nuovohost="${host%.cineca.it}.pp.cineca.it" && nuovo="${url/$host/$nuovohost}" && [ "$nuovo" != "$url" ]; then
                sql "$DB_PP" -e "UPDATE titulus_config SET url_ws='${nuovo//\'/\'\'}', ultima_prova_il=NULL, ultima_prova_esito=''"
                AVVISO "Titulus: nel sito vero l'indirizzo era di produzione ($host). Nella copia l'ho messo a $nuovohost: CONTROLLA in Gestione del portale → Protocollo che sia quello giusto."
            else
                sql "$DB_PP" -e "UPDATE titulus_config SET attivo=0, ultima_prova_il=NULL, ultima_prova_esito=''"
                AVVISO "Titulus: l'indirizzo ($host) non è di Cineca e non so come portarlo in pre-produzione. Ho SPENTO il protocollo nella copia: inserisci tu l'indirizzo di pre-produzione."
            fi
        fi
    fi

    # I marcatori «schema_vNNN.ok» dicono «lo schema è già aggiornato»: con dati appena copiati dal sito vero (che può avere
    # uno schema più vecchio del codice di prova) vanno tolti, così il primo accesso aggiorna lo schema.
    rm -f "$PP"/cache/schema_v*.ok 2>/dev/null || true
    segna dati_caricati_il "$(date '+%Y-%m-%d %H:%M:%S')"
}

# ---------------------------------------------------------------------------------------------
# Anonimizzazione
# Quali dati personali ci sono lo dicono le colonne delle tabelle (src/Core/Schema/Definizioni.php e database/schema.sql).
# Nomi, cognomi, email, codici fiscali, matricole e telefoni diventano dati finti, sempre uguali per la stessa persona
# (stessa email → stesso nome finto in tutte le tabelle). I campi liberi e i JSON delle pratiche, delle convenzioni, degli
# incarichi e dei log, che possono contenere qualunque cosa, vengono svuotati.
# ---------------------------------------------------------------------------------------------
COLFILE=""
ha_col() { grep -qiE "^$1\.$2\.(YES|NO)$" "$COLFILE"; }
null_ok() { grep -iE "^$1\.$2\.(YES|NO)$" "$COLFILE" | head -n1 | cut -d. -f3; }
ha_tabella() { grep -qiE "^$1\." "$COLFILE"; }

A_TAB=""; A_CORPO=(); A_FIN=(); SQLCORPO=""

tab() { A_TAB="$1"; A_CORPO=(); A_FIN=(); }
fine() {
    ha_tabella "$A_TAB" || return 0
    local tutti=("${A_CORPO[@]}" "${A_FIN[@]}") lista="" x
    [ "${#tutti[@]}" -gt 0 ] || return 0
    for x in "${tutti[@]}"; do lista+="${lista:+, }$x"; done
    SQLCORPO+="UPDATE \`$A_TAB\` SET $lista;"$'\n'
}
azzera() { if ha_tabella "$1"; then SQLCORPO+="DELETE FROM \`$1\`;"$'\n'; fi; }

id_espr() { if ha_col "$A_TAB" id; then printf "CONCAT('#',\`id\`)"; else printf "'-'"; fi; }
seme() {   # $1 = colonna email (può mancare): l'email di prima, altrimenti il numero della riga
    if [ -n "$1" ] && ha_col "$A_TAB" "$1"; then
        printf "COALESCE(NULLIF(LOWER(TRIM(\`%s\`)),''),%s)" "$1" "$(id_espr)"
    else
        id_espr
    fi
}
persona() {   # email nome cognome  (le email si cambiano per ULTIME: i nomi usano ancora l'email di prima)
    local s; s="$(seme "$1")"
    if [ -n "$2" ] && ha_col "$A_TAB" "$2"; then A_CORPO+=("\`$2\`=an_nome($s,\`$2\`)"); fi
    if [ -n "$3" ] && ha_col "$A_TAB" "$3"; then A_CORPO+=("\`$3\`=an_cognome($s,\`$3\`)"); fi
    if [ -n "$1" ] && ha_col "$A_TAB" "$1"; then A_FIN+=("\`$1\`=an_email(\`$1\`)"); fi
}
nominativo() {   # colonna [colonna email per avere lo stesso nome finto della persona]
    local c="$1" e="${2:-}" s
    ha_col "$A_TAB" "$c" || return 0
    if [ -n "$e" ] && ha_col "$A_TAB" "$e"; then
        s="COALESCE(NULLIF(LOWER(TRIM(\`$e\`)),''),LOWER(TRIM(\`$c\`)))"
    else
        s="LOWER(TRIM(\`$c\`))"
    fi
    A_CORPO+=("\`$c\`=an_nominativo($s,\`$c\`)")
}
per_ogni_colonna() {   # $1 = funzione SQL, poi le colonne
    local f="$1" c; shift
    for c in "$@"; do if ha_col "$A_TAB" "$c"; then A_CORPO+=("\`$c\`=$f(\`$c\`)"); fi; done
}
cf() { per_ogni_colonna an_cf "$@"; }
matr() { per_ogni_colonna an_matr "$@"; }
tel() { per_ogni_colonna an_tel "$@"; }
ip() { per_ogni_colonna an_ip "$@"; }
emailsolo() { local c; for c in "$@"; do if ha_col "$A_TAB" "$c"; then A_FIN+=("\`$c\`=an_email(\`$c\`)"); fi; done; }
svuota() {
    local c v
    for c in "$@"; do
        ha_col "$A_TAB" "$c" || continue
        if [ "$(null_ok "$A_TAB" "$c")" = YES ]; then v="NULL"
        elif [[ "$c" == *_json ]]; then v="'{}'"
        else v="''"; fi
        A_CORPO+=("\`$c\`=$v")
    done
}
sqlgrezzo() { A_CORPO+=("$1"); }

sql_funzioni() {
    cat <<'FINE'
DROP FUNCTION IF EXISTS an_h;
DROP FUNCTION IF EXISTS an_vuoto;
DROP FUNCTION IF EXISTS an_tieni;
DROP FUNCTION IF EXISTS an_email;
DROP FUNCTION IF EXISTS an_nome;
DROP FUNCTION IF EXISTS an_cognome;
DROP FUNCTION IF EXISTS an_nominativo;
DROP FUNCTION IF EXISTS an_l;
DROP FUNCTION IF EXISTS an_cf;
DROP FUNCTION IF EXISTS an_matr;
DROP FUNCTION IF EXISTS an_tel;
DROP FUNCTION IF EXISTS an_ip;
CREATE FUNCTION an_h(seed TEXT, parte VARCHAR(10)) RETURNS CHAR(32) NO SQL
    RETURN MD5(CONCAT(IFNULL(@sale,''),'|',parte,'|',LOWER(TRIM(IFNULL(seed,'')))));
CREATE FUNCTION an_vuoto(v TEXT) RETURNS TINYINT(1) NO SQL
    RETURN IF(v IS NULL OR TRIM(v)='',1,0);
CREATE FUNCTION an_tieni(seed TEXT) RETURNS TINYINT(1) READS SQL DATA
    RETURN IF(EXISTS(SELECT 1 FROM _anon_tieni WHERE chiave = LOWER(TRIM(IFNULL(seed,'')))),1,0);
CREATE FUNCTION an_email(v VARCHAR(255)) RETURNS VARCHAR(255) READS SQL DATA
    RETURN IF(an_vuoto(v) OR an_tieni(v), v, CONCAT('p', SUBSTR(an_h(v,'e'),1,12), '@esempio.invalid'));
CREATE FUNCTION an_nome(seed TEXT, v VARCHAR(255)) RETURNS VARCHAR(255) READS SQL DATA
    RETURN IF(an_vuoto(v) OR an_tieni(seed), v,
        ELT(1 + CAST(CONV(SUBSTR(an_h(seed,'n'),1,6),16,10) AS UNSIGNED) MOD 30,
            'Anna','Luca','Marta','Paolo','Giulia','Marco','Sara','Andrea','Elena','Matteo',
            'Chiara','Davide','Laura','Simone','Irene','Fabio','Silvia','Stefano','Elisa','Roberto',
            'Francesca','Daniele','Valentina','Giorgio','Alice','Nicola','Martina','Enrico','Ilaria','Tommaso'));
CREATE FUNCTION an_cognome(seed TEXT, v VARCHAR(255)) RETURNS VARCHAR(255) READS SQL DATA
    RETURN IF(an_vuoto(v) OR an_tieni(seed), v,
        ELT(1 + CAST(CONV(SUBSTR(an_h(seed,'c'),1,6),16,10) AS UNSIGNED) MOD 40,
            'Rossi','Russo','Ferrari','Esposito','Bianchi','Romano','Colombo','Ricci','Marino','Greco',
            'Bruno','Gallo','Conti','De Luca','Mancini','Costa','Giordano','Rizzo','Lombardi','Moretti',
            'Barbieri','Fontana','Santoro','Mariani','Rinaldi','Caruso','Ferrara','Galli','Martini','Leone',
            'Longo','Gentile','Martinelli','Vitale','Lombardo','Serra','Coppola','De Santis','D''Angelo','Marchetti'));
CREATE FUNCTION an_nominativo(seed TEXT, v VARCHAR(255)) RETURNS VARCHAR(255) READS SQL DATA
    RETURN IF(an_vuoto(v) OR an_tieni(seed), v, CONCAT(an_nome(seed,'x'),' ',an_cognome(seed,'x')));
CREATE FUNCTION an_l(h CHAR(32), i INT) RETURNS CHAR(1) NO SQL
    RETURN CHAR(65 + CAST(CONV(SUBSTR(h,i*2-1,2),16,10) AS UNSIGNED) MOD 26);
CREATE FUNCTION an_cf(v VARCHAR(255)) RETURNS VARCHAR(255) READS SQL DATA
    RETURN IF(an_vuoto(v) OR an_tieni(v), v,
        CONCAT(an_l(an_h(v,'f'),1),an_l(an_h(v,'f'),2),an_l(an_h(v,'f'),3),an_l(an_h(v,'f'),4),an_l(an_h(v,'f'),5),an_l(an_h(v,'f'),6),
               LPAD(CAST(CONV(SUBSTR(an_h(v,'f'),13,2),16,10) AS UNSIGNED) MOD 100,2,'0'),'A',
               LPAD(CAST(CONV(SUBSTR(an_h(v,'f'),15,2),16,10) AS UNSIGNED) MOD 100,2,'0'),'Z',
               LPAD(CAST(CONV(SUBSTR(an_h(v,'f'),17,3),16,10) AS UNSIGNED) MOD 1000,3,'0'),an_l(an_h(v,'f'),10)));
CREATE FUNCTION an_matr(v VARCHAR(255)) RETURNS VARCHAR(255) READS SQL DATA
    RETURN IF(an_vuoto(v) OR an_tieni(v), v,
        CONCAT('9', LPAD(CAST(CONV(SUBSTR(an_h(v,'m'),1,7),16,10) AS UNSIGNED) MOD 100000000, 8, '0')));
CREATE FUNCTION an_tel(v VARCHAR(255)) RETURNS VARCHAR(255) NO SQL
    RETURN IF(an_vuoto(v), v,
        CONCAT('+39 000 ', LPAD(CAST(CONV(SUBSTR(an_h(v,'t'),1,6),16,10) AS UNSIGNED) MOD 1000000, 6, '0')));
CREATE FUNCTION an_ip(v VARCHAR(255)) RETURNS VARCHAR(255) NO SQL
    RETURN IF(an_vuoto(v), v, '0.0.0.0');
FINE
}

sql_tieni() {   # $1 = lista --tieni (virgole)
    local v
    cat <<'FINE'
DROP TABLE IF EXISTS _anon_t0;
DROP TABLE IF EXISTS _anon_u;
DROP TABLE IF EXISTS _anon_tieni;
CREATE TABLE _anon_t0 (chiave VARCHAR(190) NOT NULL PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE _anon_u (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE _anon_tieni (chiave VARCHAR(190) NOT NULL PRIMARY KEY) ENGINE=InnoDB;
-- chi resta com'è: i Super Amministratori (ruolo 1) e le persone indicate con --tieni
INSERT IGNORE INTO _anon_u SELECT id FROM utenti WHERE ruolo_id = 1;
FINE
    local IFS=','
    for v in $1; do
        v="$(printf '%s' "$v" | tr 'A-Z' 'a-z' | tr -d '[:space:]')"
        [ -n "$v" ] || continue
        printf "INSERT IGNORE INTO _anon_t0 VALUES ('%s');\n" "$v"
    done
    unset IFS
    cat <<'FINE'
INSERT IGNORE INTO _anon_u SELECT id FROM utenti
    WHERE LOWER(TRIM(IFNULL(email,''))) IN (SELECT chiave FROM _anon_t0)
       OR LOWER(TRIM(IFNULL(codice_fiscale,''))) IN (SELECT chiave FROM _anon_t0)
       OR LOWER(TRIM(IFNULL(matricola,''))) IN (SELECT chiave FROM _anon_t0)
       OR LOWER(TRIM(IFNULL(matricola_studente,''))) IN (SELECT chiave FROM _anon_t0)
       OR LOWER(TRIM(IFNULL(matricola_dipendente,''))) IN (SELECT chiave FROM _anon_t0);
INSERT IGNORE INTO _anon_tieni SELECT LOWER(TRIM(email)) FROM utenti WHERE id IN (SELECT id FROM _anon_u) AND TRIM(IFNULL(email,'')) <> '';
INSERT IGNORE INTO _anon_tieni SELECT LOWER(TRIM(codice_fiscale)) FROM utenti WHERE id IN (SELECT id FROM _anon_u) AND TRIM(IFNULL(codice_fiscale,'')) <> '';
INSERT IGNORE INTO _anon_tieni SELECT LOWER(TRIM(matricola)) FROM utenti WHERE id IN (SELECT id FROM _anon_u) AND TRIM(IFNULL(matricola,'')) <> '';
INSERT IGNORE INTO _anon_tieni SELECT LOWER(TRIM(matricola_studente)) FROM utenti WHERE id IN (SELECT id FROM _anon_u) AND TRIM(IFNULL(matricola_studente,'')) <> '';
INSERT IGNORE INTO _anon_tieni SELECT LOWER(TRIM(matricola_dipendente)) FROM utenti WHERE id IN (SELECT id FROM _anon_u) AND TRIM(IFNULL(matricola_dipendente,'')) <> '';
FINE
}

costruisci_aggiornamenti() {
    SQLCORPO=""

    # --- persone con account e anagrafe ---
    tab utenti;                    persona email nome cognome; cf codice_fiscale; matr matricola matricola_studente matricola_dipendente; fine
    tab personale_ateneo;          persona email nome cognome; tel telefono; svuota dettaglio_json; fine
    tab personale_modifiche;       tel telefono; fine
    tab abilitazioni_attesa;       nominativo nominativo email; emailsolo email; fine
    tab avvisi_iscrizioni;         emailsolo email; fine
    tab verifiche_email;           emailsolo email; fine
    tab riepilogo_persone;         emailsolo email; fine

    # --- uffici, consigli, sedute, responsabili ---
    tab uffici_persone;            nominativo nominativo email; emailsolo email; fine
    tab uffici_ateneo_persone;     nominativo nominativo email; emailsolo email; fine
    tab ufficio_didattica;         nominativo nominativo email; emailsolo email; fine
    tab risorse_responsabili;      nominativo nominativo email; emailsolo email; fine
    tab didattica_consigli_persone; nominativo nominativo email; emailsolo email; fine
    tab didattica_convocazioni;    emailsolo email; fine
    tab didattica_sedute;          emailsolo segretario_email coordinatore_email; fine
    tab didattica_sedute_presenze; nominativo nominativo; fine
    tab risorse;                   nominativo referente; svuota email_notifiche; fine
    tab eventi;                    svuota email_notifiche_extra; fine
    tab progetti_dettagli;         svuota referenti_json; fine
    tab impostazioni_sistema;      nominativo conv_direttore_nome; nominativo tesi_direttore_nome; cf conv_direttore_cf; emailsolo conv_direttore_email; fine

    # --- pratiche, prenotazioni, risorse ---
    tab pratiche;                  persona email nome cognome; matr matricola; ip ip_invio; svuota richiesta_json risposte_json accesso_json; fine
    tab pratiche_conferme;         persona email nome ''; svuota accesso_json; fine
    tab pratiche_firme;            persona email nome ''; nominativo firmato_da; fine
    tab pratiche_eventi;           nominativo autore_nome; svuota testo; fine
    tab prenotazioni;              persona email nome cognome; matr matricola; nominativo autorizzazione_nome; svuota dati_custom_json; fine
    tab prenotazioni_risorse;      persona email nome cognome; svuota co_conducenti_json risposte_json; fine
    tab prenotazioni_risorse_attesa; persona email nome cognome; fine
    tab partecipanti_prenotazione; persona '' nome cognome; fine

    # --- convenzioni, tirocini, tutorato ---
    tab convenzioni_compilate;     emailsolo email; nominativo firmatario; nominativo firmatario_direttore; svuota dati_json; fine
    tab convenzioni_tesi;          emailsolo email; nominativo firmatario; nominativo firmatario_direttore; svuota dati_json modifiche_json; fine
    tab convenzioni_scuole;        svuota docenti_json; fine
    tab convenzioni_messaggi;      svuota testo; fine
    tab tirocini_motorie_periodi;  matr matricola; nominativo studente; fine
    tab tutorato_incarichi;        persona email nome cognome; persona docente_email docente_nome docente_cognome
                                   cf codice_fiscale docente_cf; tel telefono
                                   svuota luogo_nascita comune_residenza indirizzo studente_firma_json nota_studente
                                   if ha_col tutorato_incarichi data_nascita; then sqlgrezzo "\`data_nascita\`=IF(\`data_nascita\` IS NULL,NULL,'1990-01-01')"; fi
                                   fine
    tab tutorato_bandi;            nominativo direttore_nome direttore_email; cf direttore_cf; emailsolo direttore_email; fine
    tab tutorato_dichiarazioni;    svuota dati_cifrati iban_finale; fine
    tab tutorato_accessi_dati;     emailsolo utente_email; fine
    tab tutorato_registro;         svuota nota_docente; fine
    tab tutorato_eventi;           ip ip; svuota testo; fine

    # --- registri ---
    tab log_accessi;               persona email nome cognome; ip ip; fine
    tab log_attivita;              ip indirizzo_ip; svuota dettagli_json; fine
    tab verbali_accessi_log;       ip ip; fine

    # --- da svuotare del tutto ---
    azzera assistenza_messaggi
    azzera assistenza_conversazioni
    azzera push_sottoscrizioni
    azzera rate_limit_attempts
}

esegui_sql() {   # $1 = file; ferma lo script se c'è un errore
    sql "$DB_PP" < "$1"
}

anonimizza_dati() {   # $1 = database da anonimizzare, $2 = lista --tieni
    local db="$1" tieni="${2:-}" f saldo nu nk resto
    PASSO "Anonimizzo i dati in $db"
    COLFILE="$(mktemp)"
    sql -N -e "SELECT CONCAT(table_name,'.',column_name,'.',is_nullable) FROM information_schema.columns WHERE table_schema='$db'" > "$COLFILE"
    ha_tabella utenti || die "Nel database $db non c'è la tabella utenti: non anonimizzo."

    if [ -n "$tieni" ] && ! [[ "$tieni" =~ ^[A-Za-z0-9@._+,\ -]+$ ]]; then die "--tieni accetta solo email, codici fiscali o matricole separati da virgola."; fi

    costruisci_aggiornamenti
    saldo="$(casuale 16)"
    f="$(mktemp)"
    {
        echo "SET NAMES utf8mb4;"
        echo "SET @sale = '$saldo';"
        sql_tieni "$tieni"
        sql_funzioni
        printf '%s' "$SQLCORPO"
    } > "$f"
    sql "$db" < "$f" || { rm -f "$f" "$COLFILE"; die "L'anonimizzazione si è fermata con un errore: il sito di prova NON deve essere usato. Rilancia il comando."; }

    # Controllo: non deve restare nessuna email vera tra gli utenti che non dovevano restare com'erano
    resto="$(sql -N "$db" -e "SELECT COUNT(*) FROM utenti u WHERE TRIM(IFNULL(u.email,'')) <> '' AND u.email NOT LIKE '%@esempio.invalid' AND u.id NOT IN (SELECT id FROM _anon_u)")"
    nu="$(sql -N "$db" -e "SELECT COUNT(*) FROM utenti WHERE email LIKE '%@esempio.invalid'")"
    nk="$(sql -N "$db" -e "SELECT COUNT(*) FROM _anon_u")"
    # Pulizia: funzioni e tabelle di appoggio
    sql "$db" -e "DROP FUNCTION IF EXISTS an_h; DROP FUNCTION IF EXISTS an_vuoto; DROP FUNCTION IF EXISTS an_tieni; DROP FUNCTION IF EXISTS an_email; DROP FUNCTION IF EXISTS an_nome; DROP FUNCTION IF EXISTS an_cognome; DROP FUNCTION IF EXISTS an_nominativo; DROP FUNCTION IF EXISTS an_l; DROP FUNCTION IF EXISTS an_cf; DROP FUNCTION IF EXISTS an_matr; DROP FUNCTION IF EXISTS an_tel; DROP FUNCTION IF EXISTS an_ip; DROP TABLE IF EXISTS _anon_t0; DROP TABLE IF EXISTS _anon_u; DROP TABLE IF EXISTS _anon_tieni"
    rm -f "$f" "$COLFILE"
    [ "$resto" = 0 ] || die "Dopo l'anonimizzazione restano $resto utenti con email vera: il sito di prova NON deve essere usato."
    MSG "Utenti resi anonimi: $nu. Utenti lasciati come sono (amministratori e --tieni): $nk."
    MSG "Svuotati: risposte e JSON di pratiche/convenzioni/incarichi/prenotazioni, testi dei messaggi, log di attività, chat di assistenza, notifiche push."
    MSG "Restano com'erano: aziende e scuole (dati pubblici), strutture, corsi, contatti pubblici degli uffici, regole degli uffici, impostazioni di posta."
}

# ---------------------------------------------------------------------------------------------
# .env della copia
# ---------------------------------------------------------------------------------------------
scrivi_env() {   # $1 = password db, $2 = email di prova
    PASSO "Scrivo il file .env della copia"
    local vero="$VERO/.env" nuovo="$PP/.env" k v tmp
    tmp="$(mktemp)"
    {
        echo "# .env dell'AMBIENTE DI PROVA — creato il $(date '+%Y-%m-%d %H:%M') da strumenti/crea_ambiente_prova.sh"
        echo "# Non copiare mai questo file nel sito vero (e non copiare il .env del sito vero qui: ha la riga AMBIENTE=prova in meno)."
        echo "AMBIENTE=prova"
        echo "EMAIL_PROVA=$2"
        echo ";CRON_PROVA=1"
        echo
        echo "DB_HOST=$(leggi_env "$vero" DB_HOST)"
        echo "DB_USER=$UTENTE_PP"
        echo "DB_PASS=$1"
        echo "DB_NAME=$DB_PP"
        echo
        echo "URL_SITO=$SERVER/$NOME"
        echo "CRON_KEY=$(casuale 24)"
        v="$(leggi_env "$vero" SIMPLESAML_PATH)"; [ -n "$v" ] && echo "SIMPLESAML_PATH=$v"
        echo
        echo "# Uguale a quella del sito vero: serve per leggere la password di Titulus salvata nel database"
        echo "CIFRATURA_CHIAVE=$(leggi_env "$vero" CIFRATURA_CHIAVE)"
        echo
        echo "# Firme remote Aruba e notifiche push: vuote di proposito (dalla copia non parte nulla di vero)"
        echo "ARUBA_ARSS_URL="
        echo "ARUBA_ARSS_DOMINIO=frRESIGN"
        echo "ARUBA_ARSS_CERTID=AS0"
        echo "PUSH_VAPID_PUBLIC="
        echo "PUSH_VAPID_PRIVATE="
        echo "PUSH_VAPID_SUBJECT="
        echo
        echo "# Nessun backup su NAS né per email dalla copia"
        echo "BACKUP_NAS_PATH="
        echo "BACKUP_EMAIL="
        echo "BACKUP_PASSWORD="
        echo
        echo "# Regole di funzionamento: come nel sito vero (se ci sono)"
        for k in INCARICHI_SOLO_SPID_CIE FIRME_GIORNI_SOLLECITO RIEPILOGO_PERSONE ASSISTENZA_WIDGET \
                 CONSERVAZIONE_LOG_MESI CONSERVAZIONE_AUDIT_MESI CONSERVAZIONE_PRENOTAZIONI_MESI \
                 CONSERVAZIONE_UTENTI_MESI CONSERVAZIONE_INCARICHI_MESI CONSERVAZIONE_CONVOCAZIONI_MESI; do
            if ha_riga_env "$vero" "$k"; then echo "$k=$(leggi_env "$vero" "$k")"; fi
        done
        if [ "${CON_DIDACTICUS:-0}" = 1 ]; then
            echo
            echo "# Didacticus con l'IA esterna: copiata dal sito vero perché hai usato --con-didacticus"
            for k in LLM_URL LLM_MODELLO LLM_TIMEOUT LLM_MODALITA LLM_CHIAVE LLM_MAX_GIORNO LLM_RAGIONAMENTO \
                     EMB_URL EMB_MODELLO EMB_TIMEOUT EMB_SOGLIA EMB_SOGLIA_SUGGERISCI; do
                if ha_riga_env "$vero" "$k"; then echo "$k=$(leggi_env "$vero" "$k")"; fi
            done
        else
            echo
            echo "# Didacticus: senza IA esterna (nel sito vero ci sono LLM_* ed EMB_*; qui no). Per averle: rifare con --con-didacticus"
        fi
    } > "$tmp"
    mv "$tmp" "$nuovo"
    # Stesso proprietario e permessi del .env del sito vero (il sito deve poterlo leggere)
    chown --reference="$vero" "$nuovo"
    chmod --reference="$vero" "$nuovo"
    MSG "Scritto $nuovo (proprietario e permessi come $vero)"
}

# ---------------------------------------------------------------------------------------------
# Apache e SimpleSAMLphp
# ---------------------------------------------------------------------------------------------
scrivi_apache() {
    PASSO "Apache: la copia si apre solo dalla rete di Ateneo e dal server"
    command -v apache2ctl >/dev/null 2>&1 || die "Non trovo apache2ctl."
    [ -d "$(dirname "$CONF")" ] || die "Non esiste la cartella $(dirname "$CONF")."
    local mie ips
    ips="127.0.0.1 ::1"
    mie="$(hostname -I 2>/dev/null || true)"
    [ -n "$mie" ] && ips="$ips $mie"
    local prima=""
    [ -f "$CONF" ] && prima="$(cat "$CONF")"
    cat > "$CONF" <<FINE
# $CONF — scritto da strumenti/crea_ambiente_prova.sh
# Copia di prova di MyDiBEST: si apre solo dalla rete di Ateneo e dal server stesso.
# AuthMerging And: la regola si AGGIUNGE a quelle del portale (.htaccess che nascondono .env, config.php, uploads...),
# non le sostituisce.
<Location /$NOME>
    AuthMerging And
    Require ip $RETE_ATENEO $ips
</Location>
FINE
    if ! apache2ctl configtest >/dev/null 2>&1; then
        apache2ctl configtest || true
        if [ -n "$prima" ]; then printf '%s\n' "$prima" > "$CONF"; else rm -f "$CONF"; fi
        die "apache2ctl configtest non è contento: ho rimesso la configurazione com'era. Niente è stato ricaricato."
    fi
    systemctl reload apache2
    MSG "Scritto $CONF e ricaricato Apache."
}

togli_apache() {
    if [ -f "$CONF" ]; then
        rm -f "$CONF"
        if apache2ctl configtest >/dev/null 2>&1; then systemctl reload apache2; MSG "Tolto $CONF e ricaricato Apache."; else AVVISO "configtest non va dopo aver tolto $CONF: guarda a mano."; fi
    fi
}

controlla_sso() {
    PASSO "SimpleSAMLphp: la copia può usare lo stesso accesso?"
    local c="$SSP_CONFIG/config.php" a="$SSP_CONFIG/authsources.php" righe
    if [ ! -r "$c" ]; then AVVISO "Non riesco a leggere $c: controlla tu (vedi sotto)."; else
        righe="$(grep -nE "trusted\.url\.(domains|regex)" "$c" | grep -vE '^[0-9]+:[[:space:]]*(//|#|\*)' || true)"
        if [ -z "$righe" ]; then
            MSG "In $c non ci sono limiti sugli indirizzi di ritorno (trusted.url.*): /$NOME sullo stesso sito è accettato."
        else
            MSG "In $c ci sono queste righe sugli indirizzi di ritorno:"
            printf '   %s\n' "$righe"
            MSG "Se non c'è già «dibest2.unical.it» (o un'espressione che copre /$NOME), aggiungilo A MANO: lo script non tocca SimpleSAMLphp."
        fi
    fi
    if [ -r "$a" ]; then
        if grep -q "'default-sp'" "$a"; then MSG "In $a c'è il servizio «default-sp» (quello che usa il portale): serve un solo accesso per sito vero e copia."
        else AVVISO "In $a non trovo «default-sp»: controlla quale servizio usa il portale (src/Auth/Saml/AccessoSso.php)."; fi
    fi
    MSG "Il ritorno dall'accesso torna alla pagina da cui si parte, quindi per la copia torna a /$NOME. La prova vera è entrare dal browser."
}

verifica_dal_server() {
    PASSO "Controllo dal server (se non riesco, provalo dal browser)"
    command -v curl >/dev/null 2>&1 || { MSG "curl non c'è: salto."; return 0; }
    local codice pagina
    codice="$(curl -sk -o /dev/null -m 15 -w '%{http_code}' "$SERVER/$NOME/.env" || true)"
    case "$codice" in
        403|404) MSG "OK: $SERVER/$NOME/.env risponde $codice (non si legge dal web)." ;;
        000|"")  MSG "Non riesco a raggiungere $SERVER dal server: controlla dal browser che /.env di prova dia 403 o 404." ;;
        *)       AVVISO "ATTENZIONE: $SERVER/$NOME/.env risponde $codice. Non deve essere raggiungibile: guarda subito." ;;
    esac
    pagina="$(curl -sk -L -m 20 "$SERVER/$NOME/" || true)"
    if printf '%s' "$pagina" | grep -q 'fascia-ambiente-prova'; then MSG "OK: la fascia arancione «AMBIENTE DI PROVA» è nella pagina."
    elif [ -n "$pagina" ]; then AVVISO "La pagina risponde ma senza la fascia arancione: vedi se il codice copiato è quello con l'ambiente di prova."
    else MSG "La pagina iniziale non ha risposto (può essere normale se il primo accesso aggiorna lo schema): riprova dal browser."; fi
    if runuser -u "$WEB" -- test -w "$PP/cache" 2>/dev/null && runuser -u "$WEB" -- test -w "$PP/uploads" 2>/dev/null; then
        MSG "OK: l'utente «$WEB» può scrivere in cache/ e uploads/."
    else
        AVVISO "L'utente «$WEB» NON riesce a scrivere in cache/ o uploads/ della copia: guarda proprietario e permessi."
    fi
}

# ---------------------------------------------------------------------------------------------
# Comandi
# ---------------------------------------------------------------------------------------------
cmd_crea() {
    serve_root; solo_nomi_sicuri; controlli_di_sicurezza; serve_strumenti

    if [ -e "$PP" ] && [ ! -f "$SEGNO" ]; then die "$PP esiste già e non l'ho creata io (manca $(basename "$SEGNO")): non la tocco."; fi
    if [ -f "$PP/.env" ] && grep -qiE '^[[:space:]]*AMBIENTE[[:space:]]*=[[:space:]]*"?prova' "$PP/.env"; then
        MSG "L'ambiente di prova esiste già ($PP). Per ricaricare i dati: aggiorna-dati. Per vedere com'è messo: stato."
        return 0
    fi
    if db_esiste "$DB_PP" && [ "$(db_tabelle "$DB_PP")" != 0 ] && [ ! -f "$SEGNO" ]; then
        die "Il database $DB_PP esiste già ed è pieno, e non l'ho creato io: non lo tocco."
    fi

    if [ "${ANONIMIZZA:-0}" = 1 ]; then
        [ "${COPIA_NAS:-0}" = 0 ] || die "--anonimizza e --copia-uploads-nas non vanno insieme: i file caricati non si possono anonimizzare."
        SENZA_UPLOADS=1
    fi

    if [ -z "${EMAIL_PROVA:-}" ]; then
        [ -t 0 ] || die "Manca l'email di prova: usa --email-prova indirizzo@..."
        read -r -p "Email di prova (tutte le email del portale di prova arriveranno SOLO a questo indirizzo): " EMAIL_PROVA
    fi
    email_valida "$EMAIL_PROVA" || die "«$EMAIL_PROVA» non sembra un indirizzo email."

    MSG "Sto per creare l'ambiente di prova:"
    MSG "  cartella   $PP"
    MSG "  indirizzo  $SERVER/$NOME   (solo dalla rete di Ateneo e dal server)"
    MSG "  database   $DB_PP   utente $UTENTE_PP"
    MSG "  email      tutte a $EMAIL_PROVA"
    MSG "  uploads    $([ "${SENZA_UPLOADS:-0}" = 1 ] && echo vuoti || echo copiati)$([ "${COPIA_NAS:-0}" = 1 ] && echo ', con il contenuto del NAS')"
    MSG "  dati       $([ "${ANONIMIZZA:-0}" = 1 ] && echo 'ANONIMIZZATI (dati finti)' || echo 'copia IDENTICA dei dati veri (persone vere!)')"
    MSG "  Didacticus $([ "${CON_DIDACTICUS:-0}" = 1 ] && echo 'con la chiave dell IA del sito vero' || echo 'senza IA esterna')"
    MSG "  Il sito vero non viene toccato."
    conferma "Procedo?" || die "Annullato."

    if [ "${COPIA_NAS:-0}" = 1 ]; then
        MSG "Calcolo quanto spazio serve per il contenuto delle cartelle che nel sito vero stanno sul NAS (può volerci un po')..."
        local l
        while IFS= read -r l; do
            [ -n "$l" ] || continue
            MSG "  uploads/$l: $(du -shL "$VERO/uploads/$l" 2>/dev/null | cut -f1)"
        done < <(find "$VERO/uploads" -type l -printf '%P\n' 2>/dev/null | sort)
        MSG "Spazio libero su $HT: $(df -h --output=avail "$HT" | tail -n1 | tr -d ' ')"
        conferma "C'è spazio a sufficienza e voglio copiarle davvero?" || die "Annullato."
    fi

    local pw; pw="$(casuale 16)"
    trap 'printf "\nSi è fermato alla riga %s. Il sito di prova non è pronto (manca .env). Si può rilanciare lo stesso comando.\n" "$LINENO" >&2' ERR

    copia_codice
    prepara_cartelle
    crea_utente_db "$pw"
    ricarica_dati
    if [ "${ANONIMIZZA:-0}" = 1 ]; then anonimizza_dati "$DB_PP" "${TIENI:-}"; segna dati "anonimizzati"; else segna dati "veri"; fi
    dopo_i_dati
    scrivi_env "$pw" "$EMAIL_PROVA"
    controlla_collegamenti
    scrivi_apache
    controlla_sso
    verifica_dal_server
    trap - ERR

    printf '\n=========================================================================\n'
    MSG "AMBIENTE DI PROVA PRONTO: $SERVER/$NOME"
    MSG "Password dell'utente del database $UTENTE_PP (è già nel .env; la vedi qui una volta sola): $pw"
    MSG "Email di prova: $EMAIL_PROVA — i processi automatici (cron) NON partono (per provarli: CRON_PROVA=1 nel .env di prova)."
    MSG "Crontab: non l'ho toccato. NON aggiungere righe cron per $NOME se non vuoi che la copia lavori da sola."
    MSG "Il primo accesso a una pagina del pannello aggiorna lo schema del database da solo (marcatori cache/schema_v*.ok tolti)."
    printf '=========================================================================\n'
}

cmd_aggiorna_dati() {
    serve_root; solo_nomi_sicuri; controlli_di_sicurezza; serve_strumenti
    [ -f "$SEGNO" ] && [ -f "$PP/.env" -o -f "$PP/.env.sospeso" ] || die "L'ambiente di prova non c'è (o non l'ho creato io): usa «crea»."
    MSG "Sto per SOSTITUIRE tutti i dati di $DB_PP con una copia nuova di $DB_VERO$([ "${ANONIMIZZA:-0}" = 1 ] && echo ' (anonimizzata)' || echo ' (IDENTICA: persone vere!)')."
    MSG "Quello che hai provato nella copia (pratiche, utenti di prova, impostazioni) va perso. uploads/ e .env non cambiano."
    conferma "Procedo?" || die "Annullato."
    # Mentre i dati cambiano la copia resta spenta (senza .env non parte); riparte solo se tutto va bene
    [ -f "$PP/.env" ] && mv "$PP/.env" "$PP/.env.sospeso"
    trap 'printf "\nSi è fermato alla riga %s. La copia di prova resta SPENTA (il .env è in %s/.env.sospeso). Rilancia il comando.\n" "$LINENO" "$PP" >&2' ERR
    ricarica_dati
    if [ "${ANONIMIZZA:-0}" = 1 ]; then anonimizza_dati "$DB_PP" "${TIENI:-}"; segna dati "anonimizzati"; else segna dati "veri"; fi
    dopo_i_dati
    mv "$PP/.env.sospeso" "$PP/.env"
    trap - ERR
    MSG "Dati aggiornati. La copia è di nuovo accesa: $SERVER/$NOME"
    MSG "Il primo accesso al pannello aggiorna lo schema del database (se il codice è più nuovo del sito vero)."
}

cmd_stato() {
    solo_nomi_sicuri
    local x
    printf 'Ambiente di prova «%s»\n' "$NOME"
    if [ -d "$PP" ]; then MSG "  cartella      presente: $PP"; else MSG "  cartella      NON c'è ($PP)"; fi
    if [ -f "$SEGNO" ]; then
        MSG "  creata il     $(leggi_segno creata_il)"
        MSG "  dati          $(leggi_segno dati) (caricati il $(leggi_segno dati_caricati_il))"
        MSG "  uploads       $(leggi_segno uploads), cartelle del NAS: $(leggi_segno uploads_nas)"
    fi
    if [ -f "$PP/.env" ]; then
        if grep -qiE '^[[:space:]]*AMBIENTE[[:space:]]*=[[:space:]]*"?prova' "$PP/.env"; then MSG "  .env          presente, AMBIENTE=prova"; else MSG "  .env          presente MA SENZA AMBIENTE=prova: NON è un ambiente di prova!"; fi
        MSG "  email di prova $(leggi_env "$PP/.env" EMAIL_PROVA)"
        x="$(leggi_env "$PP/.env" CRON_PROVA)"; MSG "  cron          $([ "$x" = 1 ] && echo 'ACCESI (CRON_PROVA=1)' || echo 'spenti')"
    elif [ -f "$PP/.env.sospeso" ]; then MSG "  .env          SOSPESO (.env.sospeso): la copia è spenta, rilancia aggiorna-dati"
    else MSG "  .env          manca"; fi
    if command -v "${MYSQL_CMD%% *}" >/dev/null 2>&1 && [ "$(id -u)" -eq 0 ]; then
        if db_esiste "$DB_PP"; then
            MSG "  database      $DB_PP: $(db_tabelle "$DB_PP") tabelle, $(sql -N "$DB_PP" -e 'SELECT COUNT(*) FROM utenti' 2>/dev/null || echo '?') utenti"
            MSG "                di cui con email finta (@esempio.invalid): $(sql -N "$DB_PP" -e "SELECT COUNT(*) FROM utenti WHERE email LIKE '%@esempio.invalid'" 2>/dev/null || echo '?')"
        else MSG "  database      $DB_PP NON c'è"; fi
        x="$(sql -N -e "SELECT COUNT(*) FROM mysql.user WHERE user='$UTENTE_PP'" 2>/dev/null || echo '?')"; MSG "  utente db     $UTENTE_PP: $x"
    else MSG "  database      (per vederlo serve sudo)"; fi
    if [ -f "$CONF" ]; then MSG "  Apache        $CONF presente"; else MSG "  Apache        $CONF NON c'è"; fi
    if [ -d "$PP" ]; then
        x="$(find "$PP" -type l 2>/dev/null | wc -l)"
        MSG "  collegamenti  $x dentro la copia (devono essere 0 verso il NAS: $(find "$PP" -type l -lname '/mnt*' 2>/dev/null | wc -l))"
        for x in uploads cache; do
            if [ -d "$PP/$x" ]; then MSG "  $x$(printf '%*s' $((12 - ${#x})) '')proprietario $(stat -c '%U:%G' "$PP/$x"), $(du -sh "$PP/$x" 2>/dev/null | cut -f1)"; fi
        done
    fi
}

cmd_elimina() {
    serve_root; solo_nomi_sicuri; controlli_di_sicurezza
    [ -d "$PP" ] || [ -f "$CONF" ] || db_esiste "$DB_PP" || { MSG "Non c'è niente da eliminare."; return 0; }
    if [ -d "$PP" ] && [ ! -f "$SEGNO" ]; then die "$PP non è stata creata da questo script (manca $(basename "$SEGNO")): non la elimino."; fi
    if [ -f "$PP/.env" ] && ! grep -qiE '^[[:space:]]*AMBIENTE[[:space:]]*=[[:space:]]*"?prova' "$PP/.env"; then die "Il .env di $PP non ha AMBIENTE=prova: non elimino nulla."; fi
    MSG "Sto per ELIMINARE per sempre:"
    MSG "  la cartella $PP (con i suoi uploads)"
    MSG "  il database $DB_PP e l'utente $UTENTE_PP"
    MSG "  la configurazione Apache $CONF"
    MSG "Il sito vero ($VERO) e il suo database ($DB_VERO) non si toccano."
    local r=""
    [ -t 0 ] || die "Serve scrivere ELIMINA a mano: qui non si può."
    read -r -p "Per confermare scrivi ELIMINA (maiuscole): " r
    [ "$r" = "ELIMINA" ] || die "Annullato."
    if [ -d "$PP" ]; then
        local nas; nas="$(find "$PP" -type l -lname '/mnt*' 2>/dev/null || true)"
        [ -z "$nas" ] || die "Dentro $PP ci sono collegamenti verso il NAS: controllali a mano prima."
    fi
    togli_apache
    [ -d "$PP" ] && rm -rf --one-file-system -- "$PP" && MSG "Cartella $PP eliminata."
    if db_esiste "$DB_PP"; then sql -e "DROP DATABASE \`$DB_PP\`"; MSG "Database $DB_PP eliminato."; fi
    local h
    for h in localhost 127.0.0.1; do sql -e "DROP USER IF EXISTS '$UTENTE_PP'@'$h'"; done
    sql -e "FLUSH PRIVILEGES"
    MSG "Utente $UTENTE_PP eliminato. Fatto."
}

# ---------------------------------------------------------------------------------------------
principale() {
    local cmd="${1:-}"
    [ $# -gt 0 ] && shift
    while [ $# -gt 0 ]; do
        case "$1" in
            --senza-uploads) SENZA_UPLOADS=1 ;;
            --copia-uploads-nas) COPIA_NAS=1 ;;
            --anonimizza) ANONIMIZZA=1 ;;
            --con-didacticus) CON_DIDACTICUS=1 ;;
            --si) SI=1 ;;
            --tieni) shift; [ $# -gt 0 ] || die "--tieni vuole una lista."; TIENI="$1" ;;
            --tieni=*) TIENI="${1#*=}" ;;
            --email-prova) shift; [ $# -gt 0 ] || die "--email-prova vuole un indirizzo."; EMAIL_PROVA="$1" ;;
            --email-prova=*) EMAIL_PROVA="${1#*=}" ;;
            -h|--help) usa_aiuto; exit 0 ;;
            *) die "Opzione sconosciuta: $1 (usa --help)" ;;
        esac
        shift
    done
    if [ "${SENZA_UPLOADS:-0}" = 1 ] && [ "${COPIA_NAS:-0}" = 1 ]; then die "--senza-uploads e --copia-uploads-nas non vanno insieme."; fi
    if [ "${ANONIMIZZA:-0}" = 1 ] && [ "${COPIA_NAS:-0}" = 1 ]; then die "--anonimizza e --copia-uploads-nas non vanno insieme: i file caricati non si possono anonimizzare."; fi
    case "$cmd" in
        crea) cmd_crea ;;
        aggiorna-dati) cmd_aggiorna_dati ;;
        stato) cmd_stato ;;
        elimina) cmd_elimina ;;
        ""|-h|--help|help) usa_aiuto ;;
        *) die "Comando sconosciuto: $cmd (crea, aggiorna-dati, stato, elimina)" ;;
    esac
}

# Si può caricare con «source» per provare le singole funzioni (nei test locali)
if [ "${BASH_SOURCE[0]}" = "$0" ]; then principale "$@"; fi
