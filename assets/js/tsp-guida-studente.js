/* tsp-guida-studente.js - Contenuti della guida interattiva «Tirocinio curriculare sulla piattaforma TSP 2.0» per lo studente.
   Fonte dei passaggi: documentazione/guide_tsp/Guida_TSP_Studente (Ufficio Tirocini DiBEST). Le schermate sono ricostruzioni schematiche, con nomi di fantasia. */
(function () {
    'use strict';
    var U = TspGuida.ui;
    var LINK = 'https://unical.tsp.esse3.cineca.it';
    var MENU = ['Home', 'I miei stage', 'Ricerca azienda', 'Ricerca opportunità', 'Le mie opportunità salvate', 'Le mie candidature', 'Le mie ricerche salvate', 'Proposte di stage'];
    function app(schermi, fuori) { return U.app(MENU, schermi, { fuori: fuori }); }
    function tile(key, ic, t) { return '<div class="m-tile" data-k="t:' + key + '"><i class="fa ' + ic + '"></i>' + t + '</div>'; }
    var HOME = '<p class="m-nota" style="margin:0 0 10px">Riepilogo delle principali azioni</p><div class="m-tiles">' +
        tile('miei', 'fa-folder-open', 'I miei stage') + tile('az', 'fa-building', 'Ricerca azienda') + tile('prop', 'fa-file-circle-plus', 'Proposte di stage') +
        tile('opp', 'fa-magnifying-glass', 'Ricerca opportunità') + tile('salv', 'fa-bookmark', 'Opportunità salvate') + tile('cand', 'fa-paper-plane', 'Le mie candidature') + '</div>';
    function miei(stato, cls, pf, val, per) {
        return U.pan('I miei stage', '<table class="m-tb"><tr><th>Stato</th><th>Azienda</th><th>Inizio</th><th>Progetto formativo</th><th>Allegati</th><th>Valutazione</th><th>Periodi</th></tr><tr>' +
            '<td>' + U.st(stato, cls, 'stato') + '</td><td>Azienda Esempio</td><td>15/03/2027</td>' +
            '<td>' + U.dot(pf, 'd_pf') + '<span class="m-link" data-k="pf">Vedi</span></td>' +
            '<td><span class="m-link" data-k="all">Vedi (0)</span></td>' +
            '<td>' + U.dot(val, 'd_val') + '<span class="m-link" data-k="val">Vedi</span></td>' +
            '<td>' + U.dot(per, 'd_per') + '<span class="m-link" data-k="per">Vedi</span></td></tr></table>');
    }
    function periodi(statoReg, conInvia) {
        return (conInvia ? '<div style="display:flex;justify-content:flex-end;gap:6px;margin-bottom:8px">' + U.ico('invia', 'fa-paper-plane') + U.ico('stampa', 'fa-print') + '</div>' : '') +
            U.pan('Periodi di stage', '<table class="m-tb"><tr><th>Sede</th><th>Tutor</th><th>Inizio</th><th>Fine</th><th>Stato</th><th>Ore</th><th>Registro presenze</th><th>Azioni</th></tr><tr>' +
                '<td>Laboratorio di Biologia 1</td><td>ROSSI MARIA</td><td>15/03/2027</td><td>15/05/2027</td><td>Attivo</td><td data-k="ore"></td><td>' + U.st(statoReg, 'att', 'sreg') + '</td>' +
                '<td><span class="m-link" data-k="libro"><i class="fa fa-book-open"></i></span> &nbsp;<i class="fa fa-print" style="color:#8793a0"></i></td></tr></table>');
    }
    function toast(testo, cls) { return '<div class="m-toast hid ' + (cls || '') + '" data-k="toast"><i class="fa fa-circle-check"></i> ' + testo + '</div>'; }

    /* ---------- passo 2: l'iter ---------- */
    var iter = '<div class="sw"><div class="sw-cols">' +
        '<div><div class="sw-h"><i class="fa fa-rocket"></i> Per iniziare</div>' +
        U.nodo('n1', 'Tu', 'tu', 'Inoltri una proposta di stage', 1) + U.nodo('n2', 'Ufficio Tirocini', 'uf', 'Approva la proposta', 2) +
        U.nodo('n3', 'Tutor', 'tt', 'Compila il progetto formativo', 3) + U.nodo('n4', 'Tu', 'tu', 'Confermi il progetto formativo', 4) +
        U.nodo('n5', 'Tutor accademico', 'ta', 'Conferma il progetto (solo tirocini esterni)', 5) + U.nodo('n6', 'Ufficio Tirocini', 'uf', 'Conferma il progetto formativo', 6) +
        '<div class="sw-fin" data-k="fin1">Stato: «Avviato» → puoi iniziare</div></div>' +
        '<div><div class="sw-h"><i class="fa fa-flag-checkered"></i> Alla fine</div>' +
        U.nodo('m1', 'Tu', 'tu', 'Invii il registro al tutor', 1) + U.nodo('m2', 'Tu', 'tu', 'Compili il questionario di valutazione', 2) +
        U.nodo('m3', 'Tu', 'tu', 'Alleghi la relazione finale firmata dal tutor accademico', 3) + U.nodo('m4', 'Tutor', 'tt', 'Approva il registro delle presenze', 4) +
        U.nodo('m5', 'Tutor', 'tt', 'Compila il questionario di valutazione', 5) + U.nodo('m6', 'Ufficio Tirocini', 'uf', 'Approva il riconoscimento dei crediti', 6) +
        U.nodo('m7', 'Tutor accademico', 'ta', 'Conferma il riconoscimento dei crediti', 7) +
        '<div class="sw-fin" data-k="fin2">In esse3: tirocinio «sostenuto»</div></div></div></div>';
    var azIter = [['cap', 'Prima parte: come si avvia uno stage. Il pallino colorato dice chi è di turno.', 0, 900]];
    ['n1', 'n2', 'n3', 'n4', 'n5', 'n6'].forEach(function (k) { azIter.push(['add', '@' + k, 'lit', 850]); });
    azIter.push(['add', '@fin1', 'on', 1100]);
    azIter.push(['cap', 'Seconda parte: alla fine, tocca a te per tre cose; poi il tutor e gli uffici chiudono la pratica.', 1, 900]);
    ['m1', 'm2', 'm3', 'm4', 'm5', 'm6', 'm7'].forEach(function (k) { azIter.push(['add', '@' + k, 'lit', 800]); });
    azIter.push(['add', '@fin2', 'on', 900]);

    /* ---------- passo 3: il menu ---------- */
    var azHome = [
        ['cap', 'Dal link di accesso entri nella «Homepage Stage»: il riepilogo di tutte le azioni.', 0, 900],
        ['cap', '«I miei stage»: i tuoi tirocini Confermati, Avviati o Conclusi, con tutti i dettagli.', 1, 300],
        ['move', '@m:I miei stage'], ['ring', '@m:I miei stage', 200], ['tip', '@m:I miei stage', 'Qui seguirai tutto il tirocinio', 'giu', '', 1500], ['unring'], ['untip'],
        ['cap', '«Ricerca azienda»: le aziende convenzionate con il Dipartimento e/o con l\'Ateneo.', 2, 300],
        ['move', '@m:Ricerca azienda'], ['ring', '@m:Ricerca azienda', 1100], ['unring'],
        ['cap', '«Proposte di stage»: da qui inoltri la proposta, cioè inizia tutto.', 3, 300],
        ['move', '@m:Proposte di stage'], ['ring', '@m:Proposte di stage', 1300], ['unring'],
        ['cap', 'Opportunità salvate e candidature non sono usate dal DiBEST: le puoi ignorare.', 4, 400],
        ['add', '@m:Ricerca opportunità', 'tg-ring', 200], ['add', '@m:Le mie opportunità salvate', 'tg-ring', 200], ['add', '@m:Le mie candidature', 'tg-ring', 900],
        ['rm', '@m:Ricerca opportunità', 'tg-ring', 0], ['rm', '@m:Le mie opportunità salvate', 'tg-ring', 0], ['rm', '@m:Le mie candidature', 'tg-ring', 0],
        ['add', '@t:opp', 'dis', 0], ['add', '@t:salv', 'dis', 0], ['add', '@t:cand', 'dis', 1200]
    ];

    /* ---------- passo 4: cerca azienda ---------- */
    function card(k, nome, sub) { return '<div class="m-card"><b class="m-link" data-k="' + k + '">' + nome + '</b><small>' + sub + '</small></div>'; }
    var cerca = app([
        { n: 'home', titolo: 'Homepage Stage', menu: 'Home', html: HOME },
        { n: 'ricerca', titolo: 'Ricerca azienda', menu: 'Ricerca azienda', html: U.pan('Cerca un\'azienda convenzionata', U.riga('Ragione sociale', U.inp('rs', 'Facoltativo')) + U.riga('Provincia', U.inp('pr', 'Facoltativo')) + '<div style="text-align:right;margin-top:8px">' + U.btn('cerca', '<i class="fa fa-search"></i> Cerca') + '</div>', 'filtri') },
        { n: 'elenco', titolo: 'Ricerca azienda', menu: 'Ricerca azienda', html: '<div style="font-size:15px;margin-bottom:8px">Aziende recuperate</div><div class="m-2">' +
            card('c1', 'Azienda Agricola Esempio', 'Agricoltura · COSENZA') + card('c2', 'Laboratorio Analisi Esempio S.r.l.', 'Sanità · CATANZARO') + card('c3', 'Studio Veterinario Esempio', 'Sanità · REGGIO CALABRIA') + card('c4', 'Cooperativa Ambiente Esempio', 'Ambiente · COSENZA') +
            '</div><div class="m-pag"><b>1</b> &nbsp;2 &nbsp;3 &nbsp;4 &nbsp;5 &nbsp;… &nbsp;›<br>Aziende 1 - 8 di tutte quelle convenzionate</div>' },
        { n: 'dettaglio', titolo: 'Dettaglio azienda', menu: 'Ricerca azienda', html: U.pan('Azienda Agricola Esempio', U.riga('Settore', '<span>Agricoltura</span>') + U.riga('Sede', '<span>Via dell\'Esempio 1, Cosenza</span>') + U.riga('Convenzione', U.st('Attiva con il Dipartimento', 'ok')) + '<p class="m-nota" style="margin:8px 0 0 130px">Qui trovi i dati dell\'azienda: se ti interessa, contattala e chiedi la disponibilità.</p>') }
    ]);
    var azCerca = [
        ['cap', 'Nel menu clicca «Ricerca azienda».', 0, 300], ['click', '@m:Ricerca azienda'], ['screen', 'ricerca'],
        ['cap', 'Non serve compilare nulla: clicca direttamente «Cerca».', 1, 300],
        ['tip', '@filtri', 'Puoi lasciare tutti i campi vuoti', 'giu', '', 1500], ['click', '@cerca'], ['screen', 'elenco'],
        ['cap', 'Il sistema mostra l\'elenco completo delle aziende convenzionate con il DiBEST e/o con l\'Ateneo.', 2, 300],
        ['tip', '@c2', 'Scorri le pagine in fondo all\'elenco', 'giu', '', 1700],
        ['cap', 'Clicca sul nome di un\'azienda per vederne il dettaglio.', 3, 300], ['click', '@c1'], ['screen', 'dettaglio', 900]
    ];

    /* ---------- passo 5: proposte di stage e carriera ---------- */
    var inizio = app([
        { n: 'home', titolo: 'Homepage Stage', menu: 'Home', html: HOME },
        { n: 'carriera', titolo: 'Proposte di stage', menu: 'Proposte di stage', html: U.pan('Scegli la carriera', U.riga('Carriera', U.sel('car', 'Selezionare una carriera', ['Corso di laurea · carriera 1', 'Corso di laurea · carriera 2']), true) + '<p class="m-nota" style="margin:6px 0 90px 130px">Compare solo se hai più di una carriera.</p>') },
        { n: 'proposta', titolo: 'Proposta di stage', menu: 'Proposte di stage', html: U.pan('Azienda', U.rad('x', 'Azienda esistente', true) + '<div style="height:6px"></div>' + U.rad('y', 'Nuova azienda') + '<div style="margin-top:8px">' + U.ico('', 'fa-ellipsis') + '</div>') }
    ]);
    var azInizio = [
        ['cap', 'Nel menu clicca «Proposte di stage».', 0, 300], ['click', '@m:Proposte di stage'], ['screen', 'carriera', 700],
        ['cap', 'Se hai più carriere (per esempio laurea magistrale dopo la triennale, o una nuova immatricolazione) scegli per quale vuoi il tirocinio.', 1, 300],
        ['tip', '@car', 'Scegli la carriera giusta', 'giu', '', 900], ['menu', '@car', 0],
        ['cap', 'Si apre il modulo della proposta di stage: lo compili al passo successivo.', 2, 300], ['screen', 'proposta', 1100]
    ];

    /* ---------- passo 6: compila la proposta ---------- */
    var prop = app([
        { n: 'proposta', titolo: 'Proposta di stage', menu: 'Proposte di stage', html:
            U.pan('Azienda', U.rad('x', 'Azienda esistente <em style="color:#e0245e">*</em>', true) + '<div style="margin:6px 0 0 21px">' + U.sel('az', 'Ricerca tra le aziende (almeno tre caratteri)', ['Azienda Agricola Esempio', 'Azienda Esempio S.r.l.']) + '</div>' +
                '<p class="m-nota" style="margin:8px 0 4px">Seleziona la sede dell\'azienda (o il laboratorio UNICAL, se il tirocinio è interno)</p>' + U.riga('Sede', U.sel('sede', 'Selezionare una sede', ['Sede operativa · Cosenza', 'Sede legale · Cosenza'])), 'pa') +
            U.pan('Contatto aziendale', U.riga('Nome', U.inp('nome', ''), true) + U.riga('Cognome', U.inp('cognome', ''), true) + U.riga('E-mail', U.inp('mail', ''), true) +
                '<p class="m-nota" style="margin:2px 0 0 130px">Meglio non usare mail generiche (es. info@azienda.com).</p>', 'pc') +
            U.pan('Stage proposto', U.riga('Periodo', U.inp('dal', 'Data inizio') + U.inp('al', 'Data fine'), true) + U.riga('Attività', U.area('att', ''), true), 'ps') +
            '<div style="text-align:right;margin-bottom:12px">' + U.btn('salva', '<i class="fa fa-floppy-disk"></i> Salva') + '</div>' }
    ], toast('Proposta di stage inoltrata'));
    var azProp = [
        ['cap', 'Scegli l\'azienda dall\'elenco (scrivi almeno tre lettere del nome).', 0, 300], ['menu', '@az', 0],
        ['cap', 'Indica la sede dell\'azienda; per un tirocinio interno scegli il laboratorio UNICAL.', 1, 300], ['menu', '@sede', 0],
        ['scroll', '@nome'],
        ['cap', 'Inserisci i contatti del tutor aziendale (o del tutor accademico, nel tirocinio interno).', 2, 300],
        ['type', '@nome', 'Maria'], ['type', '@cognome', 'Rossi'], ['type', '@mail', 'm.rossi@aziendaesempio.it'],
        ['scroll', '@dal'],
        ['cap', 'Metti le date indicative di inizio e fine e le attività che si svolgeranno.', 3, 300],
        ['type', '@dal', '15/03/2027'], ['type', '@al', '15/05/2027'], ['type', '@att', 'Analisi di campioni in laboratorio'],
        ['scroll', '@salva'],
        ['cap', 'Infine clicca «Salva»: la proposta è inoltrata.', 4, 300], ['click', '@salva'], ['show', '@toast'], ['tip', '@salva', 'Fatto: ora si aspetta la conferma', 'su', 'ok', 1500]
    ];

    /* ---------- passo 7: attesa ---------- */
    var attesa = '<div class="sw"><div class="sw-cols uno"><div class="sw-h" style="justify-content:center"><i class="fa fa-hourglass-half"></i> Dopo aver salvato la proposta</div>' +
        U.nodo('a1', 'Ufficio Tirocini', 'uf', 'Conferma la proposta di stage', 1) + U.nodo('a2', 'Tutor', 'tt', 'Compila il progetto formativo', 2) +
        U.nodo('a3', 'Tu', 'tu', 'La domanda compare in «I miei stage», con il pallino verde sotto «Progetto formativo»', 3) +
        '<div class="sw-fin" data-k="afin">Ora tocca a te: conferma il progetto formativo</div></div></div>';
    var azAttesa = [['cap', 'L\'Ufficio Tirocini del Dipartimento conferma la proposta.', 0, 300], ['add', '@a1', 'lit', 1300],
        ['cap', 'Poi il tutor compila il progetto formativo.', 1, 300], ['add', '@a2', 'lit', 1300],
        ['cap', 'Solo allora la domanda diventa visibile in «I miei stage».', 2, 300], ['add', '@a3', 'lit', 1300], ['add', '@afin', 'on', 1200]];

    /* ---------- passo 8: conferma il progetto formativo ---------- */
    var conf = app([
        { n: 'miei', titolo: 'I miei stage', menu: 'I miei stage', html: miei('Confermato', '', 'ver', 'gri', 'gia') },
        { n: 'pf', titolo: 'Progetto formativo', menu: 'I miei stage', html:
            U.pan('Progetto formativo compilato dall\'azienda', U.sk(4), 'ppf') +
            U.pan('La tua conferma', U.riga('Tutor accademico', U.sel('tut', 'Scegli un docente del tuo corso', ['Prof.ssa Esempio', 'Prof. Esempio']), true) +
                '<div style="margin:8px 0 8px 130px">' + U.chk('sic', 'Ho superato il corso base sulla sicurezza') + '</div>' +
                U.riga('Ente', U.inp('ente', 'Ente presso cui hai superato il corso'), true) + U.riga('Data', U.inp('data', 'gg/mm/aaaa'), true), 'pconf') +
            '<div style="text-align:right;margin-bottom:12px">' + U.btn('acc', '<i class="fa fa-check"></i> Accetta il progetto formativo', 've') + '</div>' }
    ], toast('Progetto formativo confermato'));
    var azConf = [
        ['cap', 'In «I miei stage» il pallino sotto «Progetto formativo» è verde: tocca a te.', 0, 300], ['ring', '@d_pf', 900], ['tip', '@pf', 'Pallino verde = puoi agire', 'giu', '', 1300],
        ['cap', 'Clicca «Vedi» accanto al progetto formativo.', 0, 300], ['click', '@pf'], ['screen', 'pf'],
        ['cap', 'Scorri fino in fondo alla schermata.', 1, 300], ['scroll', '@tut'],
        ['cap', 'Scegli il tutor accademico tra i docenti del tuo corso di laurea: serve per l\'iter del tirocinio.', 2, 300], ['menu', '@tut', 0],
        ['cap', 'Spunta la casella del corso base sulla sicurezza.', 3, 300], ['click', '@sic'],
        ['cap', 'Scrivi dove hai superato il corso (per esempio Università della Calabria).', 4, 300], ['type', '@ente', 'Università della Calabria'],
        ['cap', 'Inserisci la data di superamento del corso.', 5, 300], ['type', '@data', '10/12/2026'],
        ['scroll', '@acc'], ['cap', 'Infine clicca «Accetta il progetto formativo».', 6, 300], ['click', '@acc'], ['show', '@toast'], ['wait', 1600]
    ];

    /* ---------- passo 9: stato Avviato ---------- */
    var avv = '<div class="sw"><div class="sw-cols"><div><div class="sw-h"><i class="fa fa-list-check"></i> Le conferme del progetto</div>' +
        U.nodo('v1', 'Tu', 'tu', 'Hai confermato il progetto formativo', 1) + U.nodo('v2', 'Tutor accademico', 'ta', 'Conferma il progetto (solo per i tirocini esterni)', 2) + U.nodo('v3', 'Ufficio Tirocini', 'uf', 'Conferma il progetto formativo', 3) + '</div>' +
        '<div class="sw-big"><div class="sw-h" style="justify-content:center">Lo stato in «I miei stage»</div><div class="sw-stato" data-k="stt"><i class="fa fa-lock"></i> Confermato</div>' +
        '<p class="sw-msg" data-k="msg">Non puoi ancora iniziare le attività di tirocinio.</p></div></div></div>';
    var azAvv = [
        ['cap', 'Hai fatto la tua parte: ora servono le altre conferme del progetto formativo.', 0, 300], ['add', '@v1', 'lit', 900],
        ['cap', 'Per i tirocini esterni conferma anche il tutor accademico.', 1, 300], ['add', '@v2', 'lit', 1100],
        ['cap', 'Per ultimo conferma l\'Ufficio Tirocini del DiBEST.', 1, 300], ['add', '@v3', 'lit', 1100],
        ['cap', 'Lo stato passa da «Confermato» ad «Avviato»: solo adesso puoi cominciare.', null, 300],
        ['txt', '@stt', '<i class="fa fa-lock-open"></i> Avviato'], ['add', '@stt', 'on', 300],
        ['txt', '@msg', '<b style="color:#166534">Puoi iniziare le attività</b> nel laboratorio UNICAL o nell\'azienda.'], ['wait', 1400]
    ];

    /* ---------- passo 10: registro delle presenze ---------- */
    function riga(k, giorno) { return '<tr data-k="r_' + k + '"><td>' + giorno + '</td><td style="width:62px">' + U.inp('in_' + k, 'hh:mm') + '</td><td style="width:62px">' + U.inp('out_' + k, 'hh:mm') + '</td><td>' + U.inp('att_' + k, 'Attività svolta') + '</td></tr>'; }
    var reg = app([
        { n: 'miei', titolo: 'I miei stage', menu: 'I miei stage', html: miei('Avviato', 'ok', 'ver', 'gri', 'gia') },
        { n: 'periodi', titolo: 'Periodi di stage', menu: 'I miei stage', html: periodi('In bozza', false) },
        { n: 'registro', titolo: 'Registro presenze', menu: 'I miei stage', html: U.pan('Lunedì 15 marzo 2027', '<table class="m-tb"><tr><th>Giorno</th><th>Entrata</th><th>Uscita</th><th>Attività svolta</th></tr>' + riga('1', 'lun 15') + '</table>' +
            '<div style="display:flex;gap:6px;align-items:center;justify-content:flex-end;margin-top:10px"><span style="margin-right:auto;font-size:11px;color:#6b7785">Ore inserite: <b data-k="tot">0</b></span>' + U.ico('piu', 'fa-plus', 'gr') + U.ico('dup', 'fa-copy', 'gr') + U.ico('del', 'fa-trash', 'gr') + U.btn('salva', '<i class="fa fa-floppy-disk"></i> Salva') + '</div>') }
    ]);
    var azReg = [
        ['cap', 'In «I miei stage» clicca «Vedi» nella colonna «Periodi».', 0, 300], ['click', '@per'], ['screen', 'periodi'],
        ['cap', 'Clicca l\'icona del libro nella colonna «Azioni».', 1, 300], ['click', '@libro'], ['screen', 'registro'],
        ['cap', 'Inserisci l\'orario di entrata e di uscita e l\'attività svolta.', 2, 300], ['type', '@in_1', '09:00'], ['type', '@out_1', '13:00'], ['type', '@att_1', 'Preparazione dei campioni'],
        ['cap', 'Poi clicca «Salva». Lo fai ogni giorno di tirocinio.', 3, 300], ['click', '@salva'], ['txt', '@tot', '4'], ['add', '@r_1', 'nuova', 500],
        ['cap', 'Le tre icone accanto al giorno ti aiutano a compilare più in fretta.', 4, 300],
        ['tip', '@piu', 'Aggiungi un nuovo orario nella stessa giornata', 'su', '', 1600], ['tip', '@dup', 'Duplica dalla riga precedente', 'su', '', 1500], ['tip', '@del', 'Elimina le ore inserite', 'su', '', 1500], ['untip']
    ];

    /* ---------- passo 11: invia il registro ---------- */
    var invia = app([
        { n: 'miei', titolo: 'I miei stage', menu: 'I miei stage', html: miei('Avviato', 'ok', 'ver', 'gri', 'gia') },
        { n: 'periodi', titolo: 'Periodi di stage', menu: 'I miei stage', html: periodi('In bozza', true) }
    ], toast('Registro inviato al tutor'));
    var azInvia = [
        ['cap', 'A fine tirocinio, in «I miei stage» clicca «Vedi» sotto «Periodi».', 0, 300], ['click', '@per'], ['screen', 'periodi'],
        ['cap', 'Clicca il pulsante «Invia» (l\'aeroplano di carta) in alto a destra.', 1, 300], ['click', '@invia'],
        ['txt', '@sreg', 'Inviato al tutor'], ['add', '@sreg', 'ok', 300], ['show', '@toast'],
        ['tip', '@sreg', 'Il tutor ora può approvarlo', 'giu', 'ok', 1700]
    ];

    /* ---------- passo 12: questionario ---------- */
    function dom(k, t) { return '<tr><td style="padding:6px 0">' + t + '</td><td style="width:150px">' + U.rad(k + '1', '') + '</td></tr>'; }
    var quest = app([
        { n: 'miei', titolo: 'I miei stage', menu: 'I miei stage', html: miei('Avviato', 'ok', 'ver', 'gri', 'gia') },
        { n: 'valutazione', titolo: 'Valutazione', menu: 'I miei stage', html: U.pan('Questionari di valutazione', '<table class="m-tb"><tr><th>Questionario</th><th>Stato</th></tr><tr><td><span class="m-link" data-k="qn">Questionario di valutazione del tirocinio</span></td><td>' + U.st('Da compilare', 'att') + '</td></tr></table>') },
        { n: 'quest', titolo: 'Questionario di valutazione', menu: 'I miei stage', html: U.pan('Esempio di questionario', '<p class="m-nota" style="margin:0 0 6px">Le domande reali possono essere diverse: rispondi a tutte.</p><table class="m-tb"><tr><th>Domanda</th><th>Risposta</th></tr>' +
            '<tr><td>L\'esperienza è stata utile?</td><td><span style="display:flex;gap:10px">' + U.rad('q1a', '1') + U.rad('q1b', '2') + U.rad('q1c', '3') + U.rad('q1d', '4') + '</span></td></tr>' +
            '<tr><td>Il tutor ti ha seguito?</td><td><span style="display:flex;gap:10px">' + U.rad('q2a', '1') + U.rad('q2b', '2') + U.rad('q2c', '3') + U.rad('q2d', '4') + '</span></td></tr></table>' +
            '<div style="text-align:right;margin-top:10px">' + U.btn('conf', 'Conferma') + '</div>') }
    ], toast('Questionario compilato'));
    var azQuest = [
        ['cap', 'In «I miei stage» clicca «Vedi» sotto «Valutazione».', 0, 300], ['click', '@val'], ['screen', 'valutazione'],
        ['cap', 'Clicca sul nome del questionario.', 1, 300], ['click', '@qn'], ['screen', 'quest'],
        ['cap', 'Rispondi alle domande...', 2, 300], ['click', '@q1d'], ['click', '@q2d'],
        ['cap', '...e conferma.', 2, 300], ['click', '@conf'], ['show', '@toast'], ['wait', 1300]
    ];

    /* ---------- passo 13: relazione finale ---------- */
    var rel = app([
        { n: 'miei', titolo: 'I miei stage', menu: 'I miei stage', html: miei('Avviato', 'ok', 'ver', 'gri', 'gia') },
        { n: 'allegati', titolo: 'Allegati', menu: 'I miei stage', html: '<div style="display:flex;justify-content:flex-end;margin-bottom:8px">' + U.ico('piu', 'fa-plus') + '</div>' +
            U.pan('Allegati del tirocinio', '<table class="m-tb"><tr><th>Titolo</th><th>Tipologia</th><th>File</th></tr><tr class="hid" data-k="rig"><td>Relazione finale</td><td>Relazione finale</td><td><span class="m-file"><i class="fa fa-file-pdf" style="color:#c0392b"></i> relazione_firmata.pdf</span></td></tr></table><p class="m-nota" data-k="vuoto">Nessun allegato caricato.</p>') }
    ], '<div class="m-modal hid" data-k="modal"><div><h4>Nuovo allegato</h4>' +
        U.riga('Titolo', U.inp('tit', '')) + U.riga('Descrizione', U.inp('desc', '')) + U.riga('Tipologia', U.sel('tipo', 'Seleziona', ['Altro allegato', 'Relazione finale'])) +
        U.riga('File', '<span class="m-btn gr" data-k="file">Scegli file…</span><span class="m-file hid" data-k="fname"><i class="fa fa-file-pdf" style="color:#c0392b"></i> relazione_firmata.pdf</span>') +
        '<div style="text-align:right;margin-top:8px">' + U.btn('carica', 'Carica') + '</div></div></div>');
    var azRel = [
        ['cap', 'In «I miei stage» clicca «Vedi» sotto «Allegati».', 0, 300], ['click', '@all'], ['screen', 'allegati'],
        ['cap', 'Clicca il pulsante «+» per aggiungere un allegato.', 1, 300], ['click', '@piu'], ['show', '@modal'],
        ['cap', 'Scrivi un titolo e una descrizione.', 2, 300], ['type', '@tit', 'Relazione finale del tirocinio'], ['type', '@desc', 'Relazione firmata dal tutor'],
        ['cap', 'Nella tipologia scegli proprio «Relazione finale»: da questa scelta dipende il corretto iter di fine tirocinio.', 3, 300],
        ['menu', '@tipo', 1], ['tip', '@tipo', 'Scelta fondamentale: «Relazione finale»', 'su', 'warn', 1500],
        ['cap', 'Seleziona dal tuo computer il file: deve essere un PDF firmato digitalmente dal tutor accademico.', 4, 300],
        ['click', '@file'], ['hide', '@file'], ['show', '@fname'], ['tip', '@fname', 'PDF con la firma digitale del tutor accademico', 'su', 'warn', 1700],
        ['cap', 'Infine clicca «Carica».', 5, 300], ['click', '@carica'], ['hide', '@modal'], ['hide', '@vuoto'], ['show', '@rig'], ['add', '@rig', 'nuova', 1200]
    ];

    /* ---------- passo 14: crediti ---------- */
    var cfu = '<div class="sw"><div class="sw-cols uno"><div class="sw-h" style="justify-content:center"><i class="fa fa-graduation-cap"></i> Dopo che hai inviato tutto</div>' +
        U.nodo('k1', 'Tutor', 'tt', 'Approva il registro delle presenze', 1) + U.nodo('k2', 'Tutor', 'tt', 'Compila il questionario di valutazione', 2) +
        U.nodo('k3', 'Ufficio Tirocini', 'uf', 'Autorizza il riconoscimento dei crediti in carriera', 3) + U.nodo('k4', 'Tutor accademico', 'ta', 'Conferma il riconoscimento dei crediti in carriera', 4) +
        '<div class="sw-fin" data-k="kfin">In esse3 il tirocinio risulta «sostenuto»: crediti in carriera</div></div></div>';
    var azCfu = [['cap', 'Ho finito tutto: quando vedo i crediti? Servono quattro passaggi, e non dipendono più da te.', null, 1000],
        ['cap', 'Il tutor approva il registro delle presenze.', 0, 300], ['add', '@k1', 'lit', 1000],
        ['cap', 'Il tutor compila il questionario di valutazione.', 1, 300], ['add', '@k2', 'lit', 1000],
        ['cap', 'L\'Ufficio Tirocini autorizza il riconoscimento dei crediti.', 2, 300], ['add', '@k3', 'lit', 1000],
        ['cap', 'Il tutor accademico conferma il riconoscimento.', 3, 300], ['add', '@k4', 'lit', 1000],
        ['cap', 'Il tirocinio risulta «sostenuto» nella tua carriera in esse3.', 3, 300], ['add', '@kfin', 'on', 1400]];

    var contatti = '<div class="tg-cont">' +
        '<div class="tg-riga"><i class="fa fa-location-dot" aria-hidden="true"></i><div><b>Ufficio Tirocini DiBEST</b><br>Cubo 6C · Ponte Carrabile</div></div>' +
        '<div class="tg-riga"><i class="fa fa-envelope" aria-hidden="true"></i><div><b>Scrivi all\'Ufficio</b><br><a href="mailto:tirocini.dibest@unical.it">tirocini.dibest@unical.it</a></div></div>' +
        '<div class="tg-riga"><i class="fa fa-ticket" aria-hidden="true"></i><div><b>Apri una richiesta di assistenza</b><br><a href="http://ticket.unical.it/tickets/new/13/435/" target="_blank" rel="noopener noreferrer">Modulo delle richieste (ticket) dell\'Ateneo</a></div></div>' +
        '<div class="tg-riga"><i class="fa fa-building-columns" aria-hidden="true"></i><div><b>In questo portale</b><br><a href="tirocini.php">Aziende e laboratori dove fare il tirocinio</a> · <a href="domande_frequenti.php?argomento=tirocini">Domande frequenti</a> · <a href="assistenza.php">Assistenza e chat</a></div></div></div>';

    TspGuida.avvia({
        radice: '#tsp-guida', chiave: 'tsp_guida_studente_v1', colore: '#0e7490',
        fineLink: 'percorso_tirocini.php', fineEt: 'Torna alla pagina dei tirocini',
        fasi: [
            { id: 'prima', nome: 'Prima di cominciare', icona: 'fa-clipboard-check' },
            { id: 'piattaforma', nome: 'La piattaforma', icona: 'fa-desktop' },
            { id: 'avvio', nome: 'Avviare lo stage', icona: 'fa-rocket' },
            { id: 'durante', nome: 'Durante lo stage', icona: 'fa-calendar-check' },
            { id: 'fine', nome: 'Alla fine', icona: 'fa-flag-checkered' },
            { id: 'aiuto', nome: 'Verifica e aiuto', icona: 'fa-circle-question' }
        ],
        passi: [
            { fase: 'prima', titolo: 'Cosa ti serve prima di inoltrare la domanda', testo: 'Prima di fare qualsiasi cosa sulla piattaforma ci sono <b>quattro cose da sistemare</b>. Spunta quelle che hai già fatto: il sito si ricorda a che punto sei.',
                elenco: [
                    { t: 'Completa il corso di formazione generale sulla sicurezza sul lavoro', d: 'Va concluso con esito positivo. È il corso online dell\'Ateneo: ti serve la chiave di iscrizione qui sotto.', copia: 'sicurezza.2023' },
                    { t: 'Individua l\'ente o l\'azienda esterna, oppure un laboratorio interno UNICAL', d: 'Per cercare puoi usare l\'elenco delle aziende convenzionate e dei laboratori dell\'Ateneo.', link: ['Aziende e laboratori', 'tirocini.php'] },
                    { t: 'Contatta l\'ente o il laboratorio e chiedi la disponibilità ad accoglierti', d: 'Parlane direttamente con loro: la proposta di stage la inoltri solo dopo.' },
                    { t: 'Trova un tutor accademico (solo per i tirocini esterni)', d: 'Deve essere un docente che afferisce al tuo corso di laurea: chiedigli se è disponibile a farti da tutor.' }
                ], fine: 'Perfetto: hai tutto per iniziare. Passa al passo successivo!',
                info: 'Il corso sulla sicurezza serve anche più avanti: quando confermi il progetto formativo dovrai indicare dove e quando l\'hai superato.' },
            { fase: 'prima', titolo: 'Il percorso in breve: chi fa cosa', testo: 'Un tirocinio è un lavoro a <b>più mani</b>: tu, il tutor, l\'Ufficio Tirocini del DiBEST. Guarda la sequenza completa, dall\'inizio ai crediti: i colori dicono a chi tocca.',
                fai: ['<b>Per iniziare</b>: inoltri la proposta e confermi il progetto formativo; puoi iniziare solo quando lo stato diventa «Avviato».', '<b>Alla fine</b>: tre cose sono tue (registro, questionario, relazione); il resto lo fanno tutor e uffici.'],
                info: 'Il tutor «aziendale» è chi ti segue in azienda; nei tirocini interni, in laboratorio, il tutor è un docente. Il tutor accademico conferma il progetto solo per i tirocini esterni.',
                scena: { frame: 'libero', h: 420, html: iter, azioni: azIter } },
            { fase: 'piattaforma', titolo: 'Entra nella piattaforma e riconosci il menu', testo: 'Il tirocinio si gestisce sulla piattaforma <b>TSP 2.0</b> di Ateneo (<a href="' + LINK + '" target="_blank" rel="noopener noreferrer">unical.tsp.esse3.cineca.it</a>), non in questo portale. Accedi con le credenziali Unical: la «Homepage Stage» riassume tutte le azioni.',
                fai: ['Apri la piattaforma e accedi con le credenziali Unical.', '«<b>I miei stage</b>»: dove segui il tuo tirocinio.', '«<b>Ricerca azienda</b>»: le aziende convenzionate.', '«<b>Proposte di stage</b>»: da qui si comincia.', 'Le voci sulle <b>opportunità e candidature non si usano</b> al DiBEST.'],
                scena: { h: 400, html: app([{ n: 'home', titolo: 'Homepage Stage', menu: 'Home', html: HOME }]), azioni: azHome } },
            { fase: 'piattaforma', titolo: 'Cerca un\'azienda già convenzionata', testo: 'Vuoi sapere con quali aziende l\'Ateneo ha già una convenzione? Puoi vedere l\'elenco completo direttamente dalla piattaforma.',
                fai: ['Clicca «<b>Ricerca azienda</b>» nel menu.', 'Clicca «<b>Cerca</b>» senza inserire altri parametri.', 'Ottieni l\'<b>elenco completo</b> delle aziende convenzionate con il DiBEST e/o con l\'Ateneo.', 'Clicca sul <b>nome</b> di un\'azienda per il dettaglio.'],
                info: 'Se l\'azienda che ti interessa non è nell\'elenco scrivi all\'Ufficio Tirocini: verificano se è già convenzionata o come attivare la convenzione.',
                scena: { h: 420, html: cerca, azioni: azCerca } },
            { fase: 'avvio', titolo: 'Apri la proposta di stage', testo: 'Hai già concordato il tirocinio con l\'azienda (o il laboratorio) e hai il tutor accademico? Allora inizia la proposta di stage dal menu.',
                fai: ['Nel menu clicca «<b>Proposte di stage</b>».', 'Se hai <b>più carriere</b>, scegli per quale vuoi il tirocinio.', 'Si apre il modulo da compilare: è il passo successivo.'],
                scena: { h: 360, html: inizio, azioni: azInizio } },
            { fase: 'avvio', titolo: 'Compila e salva la proposta', testo: 'Nella schermata della proposta inserisci le informazioni sull\'azienda, sul tutor e sul periodo. Controlla bene i dati, poi salva.',
                fai: ['Scegli <b>l\'azienda</b> presso cui farai il tirocinio.', 'Indica la <b>sede</b> (o il laboratorio UNICAL, se il tirocinio è interno).', 'Inserisci i <b>contatti del tutor</b> aziendale o accademico.', 'Metti le <b>date indicative</b> di inizio e fine e le <b>attività</b> che si svolgeranno.', 'Clicca <b>«Salva»</b>.'],
                attenzione: 'Per l\'e-mail del tutor usa un indirizzo personale, non una casella generica tipo info@azienda.com.',
                scena: { h: 430, html: prop, azioni: azProp } },
            { fase: 'avvio', titolo: 'Aspetta: tocca a Ufficio e tutor', testo: 'Dopo il salvataggio <b>non devi fare nulla</b>. La proposta passa nelle mani dell\'Ufficio Tirocini e del tutor; la vedrai in «I miei stage» quando è il tuo turno.',
                fai: ['L\'Ufficio Tirocini del Dipartimento <b>conferma</b> la proposta.', 'Il tutor <b>compila il progetto formativo</b>.', 'La domanda compare in «<b>I miei stage</b>».'],
                attenzione: 'Solo dopo la compilazione del progetto formativo da parte del tutor la domanda sarà visibile in «I miei stage». Se non la vedi ancora, è normale: controlla più tardi.',
                scena: { frame: 'libero', h: 310, html: attesa, azioni: azAttesa } },
            { fase: 'avvio', titolo: 'Conferma il progetto formativo', testo: 'Quando il tutor ha compilato il progetto formativo, il pallino sotto la voce «Progetto formativo» diventa <b>verde</b>: ora devi confermarlo tu.',
                fai: ['Il pallino è verde: clicca «<b>Vedi</b>» sul progetto formativo.', 'Scorri fino in fondo alla schermata.', 'Scegli il <b>tutor accademico</b> tra i docenti del corso di laurea.', 'Spunta la casella del <b>corso base sulla sicurezza</b>.', 'Scrivi l\'<b>ente</b> in cui l\'hai superato (es. Università della Calabria).', 'Inserisci la <b>data</b> di superamento.', 'Clicca «<b>Accetta il progetto formativo</b>».'],
                attenzione: 'Il tutor accademico è necessario per l\'iter del tirocinio: scegli un docente del tuo corso di laurea che ti ha già dato la disponibilità.',
                scena: { h: 430, html: conf, azioni: azConf } },
            { fase: 'avvio', titolo: 'Quando puoi davvero iniziare', testo: 'Il progetto formativo ha bisogno di tutte le conferme. Quando le hai tutte, lo stato diventa <b>«Avviato»</b> (lo vedi sempre in «I miei stage»).',
                fai: ['Tu hai già confermato il progetto.', 'Il <b>tutor accademico</b> lo conferma (solo tirocini esterni).', 'L\'<b>Ufficio Tirocini</b> del DiBEST conferma il progetto.'],
                attenzione: '<b>Non iniziare prima</b> che lo stato sia «Avviato»: solo allora è possibile cominciare le attività nel laboratorio UNICAL o nell\'azienda esterna.',
                scena: { frame: 'libero', h: 200, html: avv, azioni: azAvv } },
            { fase: 'durante', titolo: 'Compila ogni giorno il registro delle presenze', testo: 'Durante il tirocinio devi riempire, <b>giorno per giorno</b>, il registro delle presenze: orario di entrata, di uscita e attività svolta.',
                fai: ['In «I miei stage» clicca «<b>Vedi</b>» nella colonna «Periodi».', 'Clicca l\'<b>icona del libro</b> sotto «Azioni».', 'Inserisci <b>entrata, uscita e attività</b> svolta.', 'Clicca il pulsante <b>«Salva»</b>.', 'Usa le <b>tre icone</b> a destra del giorno: aggiungi un orario, duplica la riga, elimina le ore.'],
                info: 'Se in un giorno hai fatto due turni (mattina e pomeriggio) usa «+» per aggiungere un secondo orario nella stessa giornata.',
                scena: { h: 420, html: reg, azioni: azReg } },
            { fase: 'fine', titolo: 'Fine stage (1/3): invia il registro', testo: 'Terminato il tirocinio devi fare tre cose. La prima: <b>inviare il registro</b> al docente/tutor per l\'approvazione.',
                fai: ['In «I miei stage» clicca «<b>Vedi</b>» sotto «Periodi».', 'Clicca il pulsante <b>«Invia»</b> (aeroplano di carta).'],
                scena: { h: 400, html: invia, azioni: azInvia } },
            { fase: 'fine', titolo: 'Fine stage (2/3): il questionario di valutazione', testo: 'La seconda cosa: compila il questionario di valutazione del tirocinio.',
                fai: ['In «I miei stage» clicca «<b>Vedi</b>» sotto «Valutazione».', 'Clicca sul <b>nome del questionario</b>.', 'Rispondi alle domande e conferma.'],
                scena: { h: 400, html: quest, azioni: azQuest } },
            { fase: 'fine', titolo: 'Fine stage (3/3): la relazione finale', testo: 'La terza: carica la <b>relazione finale</b>, in PDF e firmata digitalmente dal tutor accademico.',
                fai: ['In «I miei stage» clicca «<b>Vedi</b>» sotto «Allegati».', 'Clicca il pulsante <b>«+»</b> per aggiungere un allegato.', 'Scrivi <b>titolo e descrizione</b>.', 'Nella <b>tipologia</b> scegli «Relazione finale».', 'Seleziona il <b>file</b> dal computer: PDF firmato digitalmente dal tutor accademico.', 'Clicca <b>«Carica»</b>.'],
                attenzione: 'La scelta della tipologia è determinante per il corretto iter di fine tirocinio: seleziona proprio «Relazione finale». Il file deve essere un <b>PDF firmato digitalmente</b> dal tutor accademico.',
                scena: { h: 420, html: rel, azioni: azRel } },
            { fase: 'fine', titolo: 'E i crediti? Quando compaiono in carriera', testo: 'Hai terminato tutti i passaggi: i CFU compaiono quando <b>altri</b> completano la loro parte. Non devi più fare niente, ma sai cosa sta succedendo.',
                fai: ['Il <b>tutor</b> approva il registro delle presenze.', 'Il <b>tutor</b> compila il questionario di valutazione.', 'L\'<b>Ufficio Tirocini</b> autorizza il riconoscimento dei crediti.', 'Il <b>tutor accademico</b> conferma il riconoscimento.'],
                info: 'Se i crediti tardano, controlla in «I miei stage» a che punto è la pratica, poi scrivi all\'Ufficio Tirocini.',
                scena: { frame: 'libero', h: 355, html: cfu, azioni: azCfu } },
            { fase: 'aiuto', titolo: 'Metti alla prova quello che hai imparato', testo: 'Cinque domande veloci per controllare di avere chiare le cose importanti.',
                quiz: [
                    { d: 'Prima di inoltrare la domanda di tirocinio, cosa devi aver fatto?', o: ['Niente, si può fare tutto dopo', 'Completato con esito positivo il corso generale sulla sicurezza sul lavoro', 'Già svolto un altro tirocinio'], g: 1, s: 'Il corso va completato con esito positivo prima di inoltrare la domanda; poi ne indicherai ente e data nel progetto formativo.' },
                    { d: 'Da quando puoi iniziare le attività di tirocinio?', o: ['Appena salvi la proposta di stage', 'Quando il tutor ti dà il via a voce', 'Quando lo stato del tirocinio passa ad «Avviato»'], g: 2, s: 'Lo stato passa da «Confermato» ad «Avviato» (lo vedi in «I miei stage»): solo allora è possibile iniziare.' },
                    { d: 'Cosa significa il pallino verde sotto «Progetto formativo»?', o: ['Che il tutor ha compilato il progetto e devi confermarlo tu', 'Che il tirocinio è concluso', 'Che l\'azienda ha rifiutato'], g: 0, s: 'Il pallino verde dice che il progetto formativo è compilato: clicca «Vedi» e accettalo.' },
                    { d: 'Allegando la relazione finale, quale tipologia scegli e come deve essere il file?', o: ['Tipologia «Relazione finale», PDF firmato digitalmente dal tutor accademico', 'Qualsiasi tipologia, file Word', 'Tipologia «Relazione finale», foto del foglio firmato a mano'], g: 0, s: 'La tipologia è determinante per l\'iter di fine tirocinio, e il file deve essere un PDF firmato digitalmente dal tutor accademico.' },
                    { d: 'Chi autorizza il riconoscimento dei crediti dopo l\'approvazione del registro e del questionario?', o: ['Tu, da «I miei stage»', 'L\'Ufficio Tirocini, poi il tutor accademico conferma', 'Nessuno: i crediti arrivano da soli'], g: 1, s: 'L\'Ufficio Tirocini autorizza il riconoscimento dei crediti in carriera e il tutor accademico lo conferma; poi il tirocinio risulta «sostenuto» in esse3.' }
                ] },
            { fase: 'aiuto', titolo: 'Se ti blocchi: a chi chiedere', testo: 'Per dubbi sul tuo caso specifico scrivi all\'Ufficio Tirocini del DiBEST o apri una richiesta. Qui sotto trovi anche le pagine di questo portale che possono servirti.',
                html: contatti, info: 'Quando scrivi indica il tuo nome, la matricola e a che punto sei (per esempio: «ho salvato la proposta ma non vedo il progetto formativo»).' }
        ]
    });
})();
