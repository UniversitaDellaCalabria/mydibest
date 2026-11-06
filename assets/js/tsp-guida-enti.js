/* tsp-guida-enti.js - Contenuti della guida interattiva «Ospitare tirocinanti sulla piattaforma TSP 2.0» per enti e aziende.
   Fonte dei passaggi: documentazione/guide_tsp/Guida_TSP_Enti_Aziende (Ufficio Tirocini DiBEST). Le schermate sono ricostruzioni schematiche, con nomi di fantasia. */
(function () {
    'use strict';
    var U = TspGuida.ui;
    var LINK = 'https://unical.tsp.esse3.cineca.it';
    var PRE = ['Home', 'Login', 'Richiesta accredito'];
    var AZ = ['Home', 'Dati azienda', 'Contatti aziendali', 'Elenco convenzioni aziendali', 'Presentazioni aziendali', 'Opportunità', 'I miei candidati', 'I miei stagisti', 'Avvio stage'];
    function pre(schermi, fuori) { return U.app(PRE, schermi, { fuori: fuori }); }
    function az(schermi, fuori) { return U.app(AZ, schermi, { fuori: fuori }); }
    function toast(testo, cls, key) { return '<div class="m-toast hid ' + (cls || '') + '" data-k="' + (key || 'toast') + '"><i class="fa fa-circle-check"></i> ' + testo + '</div>'; }
    function tile(key, ic, t) { return '<div class="m-tile" data-k="' + key + '"><i class="fa ' + ic + '"></i>' + t + '</div>'; }
    var HOME_AZ = { n: 'home_az', titolo: 'Azienda: Azienda Esempio S.r.l.', menu: 'Home', html: U.pan('Area azienda', '<p class="m-nota" style="margin:0 0 8px">Dal menu a sinistra gestisci convenzioni, opportunità, candidati e stagisti.</p>' + U.sk(3)) };
    var NUOVA_CONV = '<div style="display:flex;justify-content:flex-start;margin:6px 0">' + tile('nuova', 'fa-plus', 'Proponi nuova convenzione') + '</div>';

    /* ---------- passo 2: il quadro generale ---------- */
    function blk(k, ic, t, d, c) { return '<div class="sw-blk" data-k="' + k + '" style="--c:' + c + '"><i class="fa ' + ic + '"></i><b>' + t + '</b>' + d + '</div>'; }
    var flusso = '<div class="sw" style="padding-top:34px"><div class="sw-flow">' +
        blk('b1', 'fa-id-card', '1. Accredito', 'Registri l\'ente e aspetti l\'approvazione', '#0e7490') + '<span class="sw-arr"><i class="fa fa-chevron-right"></i></span>' +
        blk('b2', 'fa-file-signature', '2. Convenzione', 'Proponi, firmi, carichi: «Istituita»', '#7c3aed') + '<span class="sw-arr"><i class="fa fa-chevron-right"></i></span>' +
        blk('b3', 'fa-people-arrows', '3. Lo studente', 'Pubblichi un\'opportunità o avvii uno stage', '#15803d') + '<span class="sw-arr"><i class="fa fa-chevron-right"></i></span>' +
        blk('b4', 'fa-clipboard-list', '4. Progetto', 'Compili il progetto formativo', '#c2410c') + '<span class="sw-arr"><i class="fa fa-chevron-right"></i></span>' +
        blk('b5', 'fa-flag-checkered', '5. Chiusura', 'Il tutor approva registro e questionario', '#be123c') + '</div>' +
        '<div class="sw-fin" data-k="bfin" style="margin-top:26px">Fatto una volta sola: accredito e convenzione. Per ogni studente: dal punto 3.</div></div>';
    var azFlusso = [['cap', 'Il percorso è fatto di cinque tappe: le prime due si fanno una volta sola.', null, 900],
        ['cap', 'Registri l\'ente e attendi che l\'Ateneo approvi l\'accredito.', 0, 300], ['add', '@b1', 'lit', 1300],
        ['cap', 'Proponi una convenzione, la firmi digitalmente e la carichi; l\'Ateneo la rende «Istituita».', 1, 300], ['add', '@b2', 'lit', 1300],
        ['cap', 'Poi trovi lo studente: pubblichi un\'opportunità oppure avvii direttamente lo stage.', 2, 300], ['add', '@b3', 'lit', 1300],
        ['cap', 'Compili il progetto formativo e aspetti l\'avvio.', 3, 300], ['add', '@b4', 'lit', 1300],
        ['cap', 'A fine tirocinio il tutor aziendale approva il registro e compila il questionario.', 4, 300], ['add', '@b5', 'lit', 1300], ['add', '@bfin', 'on', 1300]];

    /* ---------- passo 3: richiesta di accredito ---------- */
    var WZ = ['Nazione della azienda', 'Identificativo azienda', 'Dati dell\'azienda', 'Dati utente', 'Dati sede operativa', 'Rappresentante legale', 'Richiesta consensi', 'Aree disciplinari', 'Allegati', 'Inserimento username'];
    function vaiWz(n, pane) {
        var a = [['rm', '.m-wzs div', 'act', 0]];
        if (n > 1) a.push(['add', '@s' + (n - 1), 'fat', 0]);
        a.push(['add', '@s' + n, 'act', 0]); a.push(['txt', '@wzt', WZ[n - 1]]);
        if (pane) a.push(['pane', pane]);
        return a;
    }
    var wzs = WZ.map(function (n, i) { return '<div data-n="' + (i + 1) + '" data-k="s' + (i + 1) + '">' + n + '</div>'; }).join('');
    var aree = ['Biologia, Ecologia e Scienze della Terra', 'Chimica e Tecnologie Chimiche', 'Culture, Educazione e Società', 'Economia, Statistica e Finanza', 'Ingegneria Civile'];
    var accr = pre([
        { n: 'home', titolo: 'Home', menu: 'Home', nomenu: false, html: U.pan('Piattaforma tirocini e stage', '<p class="m-nota" style="margin:0 0 8px">Accedi se hai già le credenziali, oppure richiedi l\'accredito della tua azienda o del tuo ente.</p>' + U.sk(3)) },
        { n: 'accr', titolo: 'Richiesta accredito', menu: 'Richiesta accredito', html: U.pan('Richiesta accredito', '<p style="margin:0 0 10px">Registra la tua azienda o il tuo ente per poter attivare convenzioni e tirocini.</p>' + U.btn('reg', '<i class="fa fa-user-plus"></i> Registra')) },
        { n: 'wiz', titolo: 'Richiesta accredito: nuova azienda', menu: 'Richiesta accredito', html: '<div class="m-wz"><div>' +
            '<section class="m-pan" data-k="w_gen" data-g="wz"><h4 data-k="wzt">Nazione della azienda</h4>' + U.sk(5) + '</section>' +
            '<section class="m-pan hid" data-k="w_aree" data-g="wz"><h4>Aree disciplinari</h4><div style="background:#eaf5fd;border:1px solid #bfe0f7;padding:6px 10px;margin-bottom:6px;border-radius:3px">Selezionare le aree disciplinari dell\'azienda</div><div class="m-areas">' +
            aree.map(function (x, i) { return '<div>' + U.chk('ar' + (i + 1), x) + '</div>'; }).join('') + '<div>' + U.chk('arT', 'Tutto l\'Ateneo') + '</div></div></section>' +
            '<section class="m-pan hid" data-k="w_all" data-g="wz"><h4>Allegati</h4><div style="display:flex;gap:10px;align-items:center;margin:6px 0">Manifestazione di interesse ' + U.btn('carica', '<i class="fa fa-upload"></i> Carica', 'gr sm') + ' <span class="m-file hid" data-k="mfile"><i class="fa fa-file-pdf" style="color:#c0392b"></i> manifestazione_interesse.pdf</span></div></section>' +
            '<section class="m-pan hid" data-k="w_user" data-g="wz"><h4>Inserimento username</h4><p style="margin:0">Registrazione completata. Questi sono i dati per accedere:</p><div class="m-credbox hid" data-k="cred"><div><b>Username</b><span>azienda.esempio</span></div><div><b>Password</b><span>••••••••••</span></div></div></section>' +
            '</div><div class="m-wzs">' + wzs + '</div></div>' }
    ]);
    var azAccr = [
        ['cap', 'Sulla home clicca «Richiesta accredito».', 0, 300], ['click', '@m:Richiesta accredito'], ['screen', 'accr'],
        ['cap', 'Poi clicca «Registra».', 0, 300], ['click', '@reg'], ['screen', 'wiz', 700],
        ['cap', 'La procedura è guidata: a destra vedi tutte le pagine. Compila i dati richiesti, una pagina dopo l\'altra.', 1, 300]
    ];
    for (var n = 1; n <= 7; n++) azAccr = azAccr.concat(vaiWz(n), [['wait', 520]]);
    azAccr = azAccr.concat(vaiWz(8, '@w_aree'), [
        ['cap', 'Scegli l\'Area disciplinare giusta: corrisponde al Dipartimento con cui vuoi convenzionarti.', 2, 300],
        ['click', '@ar1'], ['tip', '@ar1', 'Per il DiBEST: «Biologia, Ecologia e Scienze della Terra»', 'su', '', 1800],
        ['tip', '@arT', 'Interesse su più di tre aree? Seleziona «Tutto l\'Ateneo»', 'su', 'warn', 2000], ['untip']
    ], vaiWz(9, '@w_all'), [
        ['cap', 'Negli allegati inserisci la Manifestazione di interesse compilata secondo il modello.', 3, 300],
        ['click', '@carica'], ['show', '@mfile'], ['tip', '@mfile', 'Usa il modello della Manifestazione di interesse', 'su', 'warn', 1800]
    ], vaiWz(10, '@w_user'), [
        ['cap', 'Al termine il sistema ti dà username e password: conservali.', 4, 300],
        ['show', '@cred'], ['tip', '@cred', 'Conservali: ti serviranno per accedere dopo l\'approvazione', 'giu', 'warn', 2200]
    ]);

    /* ---------- passo 4: accesso ---------- */
    var login = U.app(AZ, [
        { n: 'login', titolo: 'Accesso', menu: '', nomenu: true, html: '<div style="max-width:320px;margin:40px auto">' + U.pan('Accedi', U.riga('Username', U.inp('u', '')) + U.riga('Password', U.inp('p', '')) + '<div style="text-align:right;margin-top:8px">' + U.btn('entra', 'Accedi') + '</div>') + '</div>' },
        { n: 'home_az', titolo: 'Azienda: Azienda Esempio S.r.l.', menu: 'Home', html: U.pan('Area azienda', '<p class="m-nota" style="margin:0 0 10px">Il menu a sinistra è il tuo punto di partenza.</p><div class="m-tiles">' +
            tile('t1', 'fa-building', 'Dati azienda') + tile('t2', 'fa-address-book', 'Contatti aziendali') + tile('t3', 'fa-file-contract', 'Convenzioni') + tile('t4', 'fa-bullhorn', 'Opportunità') + tile('t5', 'fa-users', 'I miei candidati') + tile('t6', 'fa-user-graduate', 'I miei stagisti') + '</div>') }
    ]);
    var azLogin = [
        ['cap', 'Fai il login con le tue credenziali personali.', 0, 300], ['type', '@u', 'azienda.esempio'], ['type', '@p', '••••••••••'], ['click', '@entra'], ['screen', 'home_az', 800],
        ['cap', 'Eccoti nell\'area azienda: il menu a sinistra raccoglie tutto quello che ti serve.', 1, 300]
    ];
    ['Dati azienda', 'Contatti aziendali', 'Elenco convenzioni aziendali', 'Opportunità', 'I miei candidati', 'I miei stagisti', 'Avvio stage'].forEach(function (m) { azLogin.push(['move', '@m:' + m]); azLogin.push(['ring', '@m:' + m, 420]); azLogin.push(['unring']); });

    /* ---------- passo 5: proponi la convenzione ---------- */
    var conv = az([
        HOME_AZ,
        { n: 'elenco_c', titolo: 'Elenco convenzioni aziendali', menu: 'Elenco convenzioni aziendali', html: U.pan('Convenzioni aziendali', '<p class="m-nota" style="margin:0">Non hai ancora convenzioni.</p>' + NUOVA_CONV) },
        { n: 'guidata', titolo: 'Proponi nuova convenzione', menu: 'Elenco convenzioni aziendali', html:
            U.pan('Dati della convenzione', U.sk(3)) +
            U.pan('Area disciplinare', '<p class="m-nota" style="margin:0 0 6px">Seleziona <b>una sola</b> area con cui vuoi convenzionarti</p><div class="m-areas">' + [aree[0], aree[1], aree[4], 'Tutto l\'Ateneo'].map(function (x, i) { return '<div>' + U.rad('a' + (i + 1), x) + '</div>'; }).join('') + '</div>', 'aree') +
            '<div style="text-align:right;margin-bottom:12px">' + U.btn('prop', '<i class="fa fa-paper-plane"></i> Conferma la proposta') + '</div>' }
    ], toast('Proposta inviata all\'Ateneo'));
    var azConv = [
        ['cap', 'Nel menu clicca «Elenco convenzioni aziendali».', 0, 300], ['click', '@m:Elenco convenzioni aziendali'], ['screen', 'elenco_c'],
        ['cap', 'Clicca il riquadro con il segno + «Proponi nuova convenzione».', 1, 300], ['click', '@nuova'], ['screen', 'guidata', 800],
        ['cap', 'Compila i campi richiesti e segui la procedura guidata.', 2, 300], ['ring', '@aree', 600], ['unring'],
        ['cap', 'Seleziona una sola area disciplinare.', 3, 300], ['click', '@a1'], ['tip', '@a1', 'Una sola area per ogni convenzione', 'su', 'warn', 1800],
        ['cap', 'Se vuoi più aree, dovrai proporre più convenzioni, una per ogni area.', 4, 300], ['scroll', '@prop'], ['click', '@prop'], ['show', '@toast'], ['wait', 1300]
    ];

    /* ---------- passo 6: la convenzione arriva via PEC e si firma ---------- */
    var pec = '<div class="sw"><div class="sw-cols uno"><div class="sw-h" style="justify-content:center"><i class="fa fa-envelope-circle-check"></i> Dopo che hai proposto la convenzione</div>' +
        U.nodo('p1', 'Ateneo', 'at', 'L\'Ufficio d\'Ateneo accoglie la proposta', 1) + U.nodo('p2', 'Ateneo', 'at', 'Ti invia il file della convenzione all\'indirizzo PEC dell\'ente', 2) +
        U.nodo('p3', 'Ente', 'en', 'Firmi digitalmente il file della convenzione', 3) + '<div class="sw-fin" data-k="pfin">Poi caricalo sulla piattaforma (passo successivo)</div></div></div>';
    var azPec = [['cap', 'L\'Ufficio d\'Ateneo valuta e accoglie la proposta.', 0, 300], ['add', '@p1', 'lit', 1200],
        ['cap', 'Il file della convenzione arriva alla PEC dell\'ente.', 1, 300], ['add', '@p2', 'lit', 1300],
        ['cap', 'Tu (o il legale rappresentante) lo firmi digitalmente.', 2, 300], ['add', '@p3', 'lit', 1300], ['add', '@pfin', 'on', 1300]];

    /* ---------- passo 7: carica la convenzione firmata ---------- */
    var carica = az([
        HOME_AZ,
        { n: 'conv', titolo: 'Convenzione: Convenzione di tirocinio', menu: 'Elenco convenzioni aziendali', html:
            U.pan('Dati convenzione aziendale', U.riga('Descrizione', U.inp('d', '').replace('class="m-in "', 'class="m-in ro "')) + U.riga('Stato', '<span>' + U.st('Proposta accolta', 'att', 'stc') + '</span>')) +
            '<div class="m-tabs"><span class="act" data-k="tab1">Aree disciplinari</span><span data-k="tab2">Allegati della convenzione</span></div>' +
            '<section class="m-pan" style="border-top:0;border-radius:0" data-k="tb1" data-g="tb"><div class="m-chk on" style="margin:4px 0"><i class="m-box"></i><span>Biologia, Ecologia e Scienze della Terra</span></div></section>' +
            '<section class="m-pan hid" style="border-top:0;border-radius:0" data-k="tb2" data-g="tb"><div style="display:flex;justify-content:flex-end;margin-bottom:8px">' + U.btn('up', '<i class="fa fa-upload"></i> Allega file', 'gr sm') + '</div><p class="m-nota" data-k="vuoto" style="margin:0">Nessun allegato.</p><span class="m-file hid" data-k="fc"><i class="fa fa-file-signature" style="color:#7c3aed"></i> convenzione_firmata.pdf</span></section>' }
    ]);
    var azCarica = [
        ['cap', 'Apri la convenzione dall\'«Elenco convenzioni aziendali».', 0, 300], ['click', '@m:Elenco convenzioni aziendali'], ['screen', 'conv', 800],
        ['cap', 'Vai nella scheda «Allegati della convenzione».', 1, 300], ['click', '@tab2'], ['rm', '@tab1', 'act', 0], ['add', '@tab2', 'act', 0], ['pane', '@tb2'],
        ['cap', 'Carica il file della convenzione firmato digitalmente.', 2, 300], ['click', '@up'], ['hide', '@vuoto'], ['show', '@fc'], ['tip', '@fc', 'File firmato digitalmente', 'su', 'warn', 1500],
        ['cap', 'Ora aspetta: è l\'Ateneo a portare la convenzione allo stato «Istituita».', 3, 300],
        ['wait', 900], ['txt', '@stc', 'Istituita'], ['add', '@stc', 'ok', 300], ['tip', '@stc', 'Da qui puoi inserire opportunità o avviare stage', 'giu', 'ok', 2000]
    ];

    /* ---------- passo 9 (strada A): pubblica un'opportunità ---------- */
    var opp = az([
        HOME_AZ,
        { n: 'opp_l', titolo: 'Opportunità', menu: 'Opportunità', html: U.pan('Le tue opportunità', '<p class="m-nota" style="margin:0">Nessuna opportunità inserita.</p><div style="display:flex;margin:6px 0">' + tile('nuovaop', 'fa-plus', 'Nuova opportunità') + '</div>') },
        { n: 'opp_d', titolo: 'Opportunità', menu: 'Opportunità', html: '<div style="display:flex;justify-content:flex-end;gap:6px;margin-bottom:8px">' + U.btn('gest', '<i class="fa fa-pen-to-square"></i> Gestione opportunità') + '</div>' + U.pan('Nuova opportunità', U.sk(3)) },
        { n: 'opp_f', titolo: 'Gestione opportunità', menu: 'Opportunità', html: U.pan('Dati dell\'opportunità', U.riga('Titolo', U.inp('tit', ''), true) + U.riga('Descrizione', U.area('des', ''), true) + U.riga('Posti', U.inp('pos', ''), true), 'dati') +
            '<div style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:12px">' + U.btn('salvaop', 'Salva', 'gr') + U.btn('pubb', '<i class="fa fa-bullhorn"></i> Pubblica') + '</div>' }
    ], toast('Opportunità salvata', '', 'toast1') + toast('Pubblicata: in attesa di approvazione', 'att', 'toast2'));
    var azOpp = [
        ['cap', 'Entra nella maschera «Opportunità».', 0, 300], ['click', '@m:Opportunità'], ['screen', 'opp_l'],
        ['cap', 'Clicca il riquadro con il segno + per inserire una nuova opportunità.', 1, 300], ['click', '@nuovaop'], ['screen', 'opp_d'],
        ['cap', 'Poi clicca «Gestione opportunità».', 2, 300], ['click', '@gest'], ['screen', 'opp_f', 700],
        ['cap', 'Inserisci le informazioni richieste e rileggi bene le caratteristiche dell\'opportunità.', 3, 300],
        ['type', '@tit', 'Tirocinio in laboratorio di analisi'], ['type', '@des', 'Supporto alle analisi di campioni'], ['type', '@pos', '2'],
        ['cap', 'Clicca «Salva» e poi «Pubblica».', 4, 300], ['click', '@salvaop'], ['show', '@toast1'], ['wait', 900], ['hide', '@toast1'], ['click', '@pubb'], ['show', '@toast2'],
        ['tip', '@pubb', 'Dopo l\'approvazione dell\'Ufficio d\'Ateneo gli studenti potranno candidarsi', 'su', '', 2400]
    ];

    /* ---------- passo 10 (strada A): scegli il candidato ---------- */
    var cand = az([
        HOME_AZ,
        { n: 'cand_l', titolo: 'I miei candidati', menu: 'I miei candidati', html: U.pan('Candidature ricevute', '<table class="m-tb"><tr><th>Studente</th><th>Opportunità</th><th>Candidato il</th><th></th></tr>' +
            '<tr><td>Studente A</td><td>Tirocinio in laboratorio di analisi</td><td>02/02/2027</td><td><span class="m-link" data-k="vedi">Vedi</span></td></tr>' +
            '<tr><td>Studente B</td><td>Tirocinio in laboratorio di analisi</td><td>04/02/2027</td><td><span class="m-link">Vedi</span></td></tr></table>') },
        { n: 'cand_d', titolo: 'Candidatura', menu: 'I miei candidati', html: '<div style="display:flex;justify-content:flex-end;margin-bottom:8px">' + U.btn('scegli', '<i class="fa fa-user-check"></i> Scegli (con notifica)') + '</div>' + U.pan('Candidatura di Studente A', U.sk(4)) }
    ], toast('Studente scelto: lo avvisiamo con una notifica'));
    var azCand = [
        ['cap', 'Nella sezione «I miei candidati» controlli chi si è candidato.', 0, 300], ['click', '@m:I miei candidati'], ['screen', 'cand_l'],
        ['cap', 'Apri la candidatura che ti interessa.', 1, 300], ['click', '@vedi'], ['screen', 'cand_d', 700],
        ['cap', 'Per sceglierla clicca «Scegli (con notifica)».', 2, 300], ['click', '@scegli'], ['show', '@toast'],
        ['tip', '@scegli', 'Ora devi compilare il progetto formativo', 'giu', 'ok', 2200]
    ];

    /* ---------- passo 11 (strada B): avvio diretto ---------- */
    var avvio = az([
        HOME_AZ,
        { n: 'avvio', titolo: 'Avvio stage', menu: 'Avvio stage', html: U.pan('Cerca lo studente', U.riga('Matricola', U.inp('mat', '') + U.btn('cercast', '<i class="fa fa-search"></i> Cerca studente')), 'p1') +
            '<div class="m-card hid" data-k="ris" style="margin-bottom:10px"><b>Studente di esempio</b><small>Matricola 123456 · requisiti di carriera verificati</small></div>' +
            U.pan('Dati dello stage', U.riga('Sede', U.sel('sede', 'Seleziona la sede dello stage', ['Sede operativa · Cosenza'])) + U.riga('Tipologia', U.sel('tip', 'Tipologia di opportunità', ['Tirocinio curriculare'])) +
                U.riga('Area', U.sel('area', 'Area disciplinare', ['Biologia, Ecologia e Scienze della Terra'])) + U.riga('Convenzione', U.sel('cv', 'Convenzione di riferimento', ['Convenzione di tirocinio'])), 'p2') +
            '<div style="text-align:right;margin-bottom:12px">' + U.btn('avviast', '<i class="fa fa-play"></i> Avvia stage') + '</div>' }
    ], toast('Stage avviato: ora compila il progetto formativo'));
    var azAvvio = [
        ['cap', 'Nel menu clicca «Avvio stage».', 0, 300], ['click', '@m:Avvio stage'], ['screen', 'avvio'],
        ['cap', 'Inserisci il numero di matricola dello studente e clicca «Cerca studente».', 1, 300], ['type', '@mat', '123456'], ['click', '@cercast'], ['show', '@ris'],
        ['cap', 'Dai menu a tendina scegli la sede, la tipologia di opportunità, l\'area disciplinare e la convenzione.', 2, 300],
        ['scroll', '@sede'], ['menu', '@sede', 0], ['menu', '@tip', 0], ['menu', '@area', 0], ['menu', '@cv', 0],
        ['scroll', '@avviast'], ['cap', 'Con tutte le informazioni inserite, clicca «Avvia stage».', 3, 300], ['click', '@avviast'], ['show', '@toast'], ['wait', 1400]
    ];

    /* ---------- passo 12: tutor e sede ---------- */
    var prepara = az([
        HOME_AZ,
        { n: 'contatti', titolo: 'Contatti aziendali', menu: 'Contatti aziendali', html: U.pan('Contatti', '<table class="m-tb"><tr><th>Nome</th><th>Ruolo</th></tr><tr><td>Legale rappresentante</td><td>Referente</td></tr><tr class="hid" data-k="nuovo_c"><td>Maria Rossi</td><td>Tutor aziendale</td></tr></table>') +
            '<div style="display:flex;margin:6px 0">' + tile('piuc', 'fa-plus', 'Nuovo contatto') + '</div>' },
        { n: 'contatto_f', titolo: 'Nuovo contatto', menu: 'Contatti aziendali', html: U.pan('Dati del contatto', U.riga('Nome', U.inp('cn', ''), true) + U.riga('Cognome', U.inp('cc', ''), true) + U.riga('E-mail', U.inp('ce', ''), true)) + '<div style="text-align:right">' + U.btn('salvac', 'Salva') + '</div>' },
        { n: 'dati', titolo: 'Dati azienda', menu: 'Dati azienda', html: '<div class="m-tabs"><span class="act" data-k="td">Dati generali</span><span data-k="ts">Sedi</span></div>' +
            '<section class="m-pan" style="border-top:0;border-radius:0" data-k="pd" data-g="tb">' + U.sk(4) + '</section>' +
            '<section class="m-pan hid" style="border-top:0;border-radius:0" data-k="ps" data-g="tb"><table class="m-tb"><tr><th>Sede</th><th>Indirizzo</th></tr><tr><td>Sede legale</td><td>Via dell\'Esempio 1, Cosenza</td></tr><tr class="hid" data-k="nuova_s"><td>Sede operativa del tirocinio</td><td>Via del Laboratorio 5, Rende</td></tr></table><div style="margin-top:8px">' + U.btn('piuS', '<i class="fa fa-plus"></i> Nuova sede', 'gr sm') + '</div></section>' }
    ], toast('Contatto salvato', '', 'toast1') + toast('Sede aggiunta', '', 'toast2'));
    var azPrepara = [
        ['cap', 'In «Contatti aziendali» aggiungi il tutor aziendale.', 0, 300], ['click', '@m:Contatti aziendali'], ['screen', 'contatti'], ['click', '@piuc'], ['screen', 'contatto_f'],
        ['type', '@cn', 'Maria'], ['type', '@cc', 'Rossi'], ['type', '@ce', 'm.rossi@aziendaesempio.it'], ['click', '@salvac'], ['show', '@toast1'], ['wait', 700], ['hide', '@toast1'],
        ['screen', 'contatti'], ['show', '@nuovo_c'], ['wait', 800],
        ['cap', 'Poi, nei dati dell\'azienda, aggiungi la sede operativa del tirocinio.', 1, 300], ['click', '@m:Dati azienda'], ['screen', 'dati'],
        ['click', '@ts'], ['rm', '@td', 'act', 0], ['add', '@ts', 'act', 0], ['pane', '@ps'], ['click', '@piuS'], ['show', '@nuova_s'], ['show', '@toast2'], ['wait', 1400]
    ];

    /* ---------- passo 13: compila il progetto formativo ---------- */
    var pf = az([
        HOME_AZ,
        { n: 'stag', titolo: 'I miei stagisti', menu: 'I miei stagisti', html: U.pan('I miei stagisti', '<table class="m-tb"><tr><th>Stato</th><th>Studente</th><th>Opportunità</th><th>Inizio</th><th>Progetto formativo</th><th>Allegati</th><th>Periodi</th></tr><tr>' +
            '<td>Confermata</td><td>Studente A</td><td>Tirocinio in laboratorio</td><td>15/03/2027</td><td>' + U.dot('ros', 'd_pf') + '<span class="m-link" data-k="pf">Vedi</span></td><td><span class="m-link">Vedi (0)</span></td><td>' + U.dot('gia') + '<span class="m-link">Vedi</span></td></tr></table>') },
        { n: 'pform', titolo: 'Progetto formativo', menu: 'I miei stagisti', html: U.pan('Progetto formativo', U.riga('Tutor aziendale', U.sel('tut', 'Seleziona il tutor', ['Maria Rossi']), true) + U.riga('Sede operativa', U.sel('sd', 'Seleziona la sede', ['Sede operativa del tirocinio']), true) +
            U.riga('Periodo', U.inp('dal', 'Data inizio') + U.inp('al', 'Data fine'), true) + U.riga('Attività', U.area('att', ''), true) + U.riga('Obiettivi', U.area('ob', ''), true), 'pf_f') +
            '<div style="text-align:right;margin-bottom:12px">' + U.btn('invia', '<i class="fa fa-paper-plane"></i> Salva e invia') + '</div>' }
    ], toast('Progetto formativo inviato: in attesa di accettazione'));
    var azPf = [
        ['cap', 'In «I miei stagisti» clicca «Vedi» sul progetto formativo dello studente.', 0, 300], ['click', '@m:I miei stagisti'], ['screen', 'stag'], ['ring', '@pf', 700], ['click', '@pf'], ['screen', 'pform', 700],
        ['cap', 'Scegli il tutor aziendale e la sede operativa inseriti prima.', 1, 300], ['menu', '@tut', 0], ['menu', '@sd', 0],
        ['cap', 'Compila gli altri campi richiesti: periodo, attività, obiettivi.', 2, 300], ['type', '@dal', '15/03/2027'], ['type', '@al', '15/05/2027'], ['type', '@att', 'Analisi di campioni in laboratorio'], ['type', '@ob', 'Acquisire competenze operative'],
        ['scroll', '@invia'], ['cap', 'Infine clicca «Salva e invia».', 3, 300], ['click', '@invia'], ['show', '@toast'], ['wait', 1500]
    ];

    /* ---------- passo 14: attesa dell'avvio ---------- */
    var attesa = '<div class="sw"><div class="sw-cols uno"><div class="sw-h" style="justify-content:center"><i class="fa fa-hourglass-half"></i> Dopo «Salva e invia»</div>' +
        U.nodo('w1', 'Studente', 'tu', 'Accetta il progetto formativo', 1) + U.nodo('w2', 'Tutor accademico', 'ta', 'Accetta il progetto formativo', 2) + U.nodo('w3', 'Ateneo', 'at', 'L\'Ufficio d\'Ateneo porta il tirocinio allo stato «Avviato»', 3) +
        '<div class="sw-fin" data-k="wfin">Da questo momento lo studente può avviare il tirocinio</div></div></div>';
    var azAttesa = [['cap', 'Tu hai finito: ora aspetti l\'accettazione dello studente.', 0, 300], ['add', '@w1', 'lit', 1300],
        ['cap', 'Poi quella del tutor accademico.', 1, 300], ['add', '@w2', 'lit', 1300],
        ['cap', 'Infine l\'Ufficio d\'Ateneo porta lo stage allo stato «Avviato».', 2, 300], ['add', '@w3', 'lit', 1300], ['add', '@wfin', 'on', 1300]];

    /* ---------- passo 15: fine tirocinio ---------- */
    var fine = az([
        HOME_AZ,
        { n: 'stag', titolo: 'I miei stagisti', menu: 'I miei stagisti', html: '<div style="display:flex;justify-content:flex-end;margin-bottom:8px"><span class="m-btn" data-k="regappr"><i class="fa fa-book-open"></i> Registri approvabili <b style="background:#e0342a;border-radius:99px;padding:0 6px;margin-left:4px">1</b></span></div>' +
            U.pan('I miei stagisti', '<table class="m-tb"><tr><th>Stato</th><th>Studente</th><th>Inizio</th><th>Allegati</th><th>Valutazione</th><th>Periodi</th></tr><tr><td>Avviato</td><td>Studente A</td><td>15/03/2027</td><td><span class="m-link">Vedi (1)</span></td>' +
                '<td>' + U.dot('ros', 'd_val') + '<span class="m-link" data-k="ve">Vedi</span></td><td>' + U.dot('gia', 'd_per') + '<span class="m-link" data-k="pe">Vedi</span></td></tr></table>') },
        { n: 'per', titolo: 'Periodi di stage', menu: 'I miei stagisti', html: '<div style="display:flex;justify-content:flex-end;margin-bottom:8px">' + U.btn('appr', '<i class="fa fa-check"></i> Approva il registro', 've') + '</div>' +
            U.pan('Registro delle presenze', '<table class="m-tb"><tr><th>Periodo</th><th>Ore inserite</th><th>Stato registro</th></tr><tr><td>15/03/2027 - 15/05/2027</td><td>120</td><td>' + U.st('Inviato dallo studente', 'att', 'sreg') + '</td></tr></table>') },
        { n: 'val', titolo: 'Valutazione', menu: 'I miei stagisti', html: U.pan('Questionario di valutazione finale', '<p class="m-nota" style="margin:0 0 6px">Esempio: le domande reali possono essere diverse.</p><table class="m-tb"><tr><th>Domanda</th><th>Risposta</th></tr>' +
            '<tr><td>Competenze dello studente</td><td><span style="display:flex;gap:10px">' + U.rad('v1a', '1') + U.rad('v1b', '2') + U.rad('v1c', '3') + U.rad('v1d', '4') + '</span></td></tr>' +
            '<tr><td>Puntualità e impegno</td><td><span style="display:flex;gap:10px">' + U.rad('v2a', '1') + U.rad('v2b', '2') + U.rad('v2c', '3') + U.rad('v2d', '4') + '</span></td></tr></table><div style="text-align:right;margin-top:10px">' + U.btn('conf', 'Conferma') + '</div>') }
    ], toast('Registro approvato', '', 'toast1') + toast('Questionario compilato', '', 'toast2'));
    var azFine = [
        ['cap', 'A fine tirocinio, in «I miei stagisti» trovi il registro e il questionario.', 0, 300], ['click', '@m:I miei stagisti'], ['screen', 'stag'],
        ['tip', '@regappr', 'Scorciatoia per i registri da approvare (solo a fine stage)', 'giu', '', 2000],
        ['cap', 'Per approvare il registro presenze clicca «Vedi» sotto «Periodi».', 0, 300], ['click', '@pe'], ['screen', 'per'], ['click', '@appr'], ['txt', '@sreg', 'Approvato'], ['add', '@sreg', 'ok', 200], ['show', '@toast1'], ['wait', 1300], ['hide', '@toast1'],
        ['screen', 'stag'],
        ['cap', 'Poi compila il questionario di valutazione: «Vedi» sotto «Valutazione».', 1, 300], ['click', '@ve'], ['screen', 'val'], ['click', '@v1d'], ['click', '@v2d'], ['click', '@conf'], ['show', '@toast2'], ['wait', 1400]
    ];

    var aiuto = '<div class="tg-cont">' +
        '<div class="tg-riga"><i class="fa fa-envelope" aria-hidden="true"></i><div><b>Ufficio Tirocini DiBEST</b><br><a href="mailto:tirocini.dibest@unical.it">tirocini.dibest@unical.it</a><br><small>Cubo 6C · Ponte Carrabile, Università della Calabria</small></div></div>' +
        '<div class="tg-riga"><i class="fa fa-ticket" aria-hidden="true"></i><div><b>Apri una richiesta di assistenza</b><br><a href="http://ticket.unical.it/tickets/new/13/435/" target="_blank" rel="noopener noreferrer">Modulo delle richieste (ticket) dell\'Ateneo</a></div></div>' +
        '<div class="tg-riga"><i class="fa fa-arrow-up-right-from-square" aria-hidden="true"></i><div><b>Piattaforma TSP 2.0</b><br><a href="' + LINK + '" target="_blank" rel="noopener noreferrer">unical.tsp.esse3.cineca.it</a></div></div>' +
        '<div class="tg-riga"><i class="fa fa-user-graduate" aria-hidden="true"></i><div><b>Cosa vede lo studente?</b><br><a href="tsp_studente.php">La guida per gli studenti</a> · <a href="contatti.php">Chi contattare</a> · <a href="assistenza.php">Assistenza</a></div></div></div>';

    TspGuida.avvia({
        radice: '#tsp-guida', chiave: 'tsp_guida_enti_v1', colore: '#9a3412',
        fineLink: 'percorso_tirocini.php', fineEt: 'Torna alla pagina dei tirocini',
        fasi: [
            { id: 'prepara', nome: 'Prepararsi', icona: 'fa-clipboard-check' },
            { id: 'accredito', nome: 'Accredito e convenzione', icona: 'fa-file-signature' },
            { id: 'studente', nome: 'Trovare lo studente', icona: 'fa-people-arrows' },
            { id: 'progetto', nome: 'Progetto formativo', icona: 'fa-clipboard-list' },
            { id: 'chiusura', nome: 'Chiusura del tirocinio', icona: 'fa-flag-checkered' },
            { id: 'aiuto', nome: 'Verifica e aiuto', icona: 'fa-circle-question' }
        ],
        passi: [
            { fase: 'prepara', titolo: 'Cosa preparare prima di iniziare', testo: 'Per accreditarti e convenzionarti conviene avere <b>a portata di mano alcune cose</b>. Spunta quelle che hai già: il sito si ricorda a che punto sei.',
                elenco: [
                    { t: 'La «Manifestazione di interesse» compilata', d: 'Va allegata alla richiesta di accredito, compilata secondo l\'apposito modello.' },
                    { t: 'Sapere con quale Area disciplinare (Dipartimento) vuoi convenzionarti', d: 'Se l\'interesse è su più di tre aree si seleziona «tutto l\'Ateneo».' },
                    { t: 'Un indirizzo PEC e la firma digitale', d: 'La convenzione arriva alla PEC dell\'ente: va firmata digitalmente e caricata sulla piattaforma.' },
                    { t: 'Il nome del tutor aziendale e la sede operativa del tirocinio', d: 'Servono più avanti per compilare il progetto formativo: puoi inserirli in piattaforma quando vuoi.' }
                ], fine: 'Ottimo: sei pronto per la richiesta di accredito!',
                info: 'Il link della piattaforma è <a href="' + LINK + '" target="_blank" rel="noopener noreferrer">unical.tsp.esse3.cineca.it</a>. Le credenziali sono personali: puoi aggiungere altri contatti (per esempio un tutor) in qualsiasi momento.' },
            { fase: 'prepara', titolo: 'Il percorso in cinque tappe', testo: 'Prima il quadro generale: sono cinque tappe e solo le ultime tre si ripetono per ogni studente.',
                fai: ['<b>Accredito</b>: registri l\'ente e attendi l\'approvazione.', '<b>Convenzione</b>: la proponi, la firmi e la carichi.', '<b>Lo studente</b>: pubblichi un\'opportunità oppure avvii direttamente uno stage.', '<b>Progetto formativo</b>: lo compili e attendi l\'avvio.', '<b>Chiusura</b>: il tutor approva registro e questionario.'],
                scena: { frame: 'libero', h: 250, html: flusso, azioni: azFlusso } },
            { fase: 'accredito', titolo: 'Richiedi l\'accredito dell\'ente', testo: 'Il primo passo è registrare l\'azienda sulla piattaforma TSP 2.0. Dopo l\'approvazione dell\'Ufficio preposto potrai accedere.',
                fai: ['Sulla home clicca «<b>Richiesta accredito</b>», poi «<b>Registra</b>».', 'Compila i dati richiesti, <b>pagina per pagina</b>.', 'Scegli la corretta <b>Area disciplinare</b> (il Dipartimento con cui vuoi convenzionarti).', 'Negli allegati inserisci la <b>Manifestazione di interesse</b> compilata secondo il modello.', '<b>Conserva username e password</b> forniti dal sistema.'],
                attenzione: 'Se hai interesse per <b>più di tre Aree</b>, seleziona «tutto l\'Ateneo». Username e password che ti dà il sistema <b>serviranno per accedere</b> dopo che l\'Ufficio avrà approvato la richiesta: non perderli.',
                scena: { h: 430, html: accr, azioni: azAccr } },
            { fase: 'accredito', titolo: 'Accedi alla piattaforma', testo: 'Quando l\'Ufficio d\'Ateneo approva la richiesta di accredito puoi entrare con le credenziali ricevute.',
                fai: ['Fai il <b>login</b> con le tue credenziali.', 'Conosci il <b>menu dell\'area azienda</b>: dati, contatti, convenzioni, opportunità, candidati, stagisti, avvio stage.'],
                info: 'Le credenziali sono <b>personali</b>, legate al singolo utente o contatto: in qualsiasi momento puoi aggiungere un nuovo contatto (per esempio un tutor).',
                scena: { h: 400, html: login, azioni: azLogin } },
            { fase: 'accredito', titolo: 'Proponi la convenzione', testo: 'Per ospitare tirocinanti serve una convenzione con l\'Ateneo. La proponi tu, dalla piattaforma.',
                fai: ['Clicca «<b>Elenco convenzioni aziendali</b>».', 'Clicca il riquadro «<b>+ Proponi nuova convenzione</b>».', 'Compila i campi richiesti e <b>segui la procedura guidata</b>.', 'Seleziona <b>una sola</b> Area disciplinare.', 'Più aree? Proponi <b>più convenzioni</b>, una per ogni area.'],
                attenzione: 'Ogni convenzione riguarda <b>una sola area disciplinare</b>: per convenzionarti con più aree devi proporre più convenzioni.',
                scena: { h: 420, html: conv, azioni: azConv } },
            { fase: 'accredito', titolo: 'La convenzione arriva alla PEC: firmala', testo: 'Quando l\'Ufficio d\'Ateneo accoglie la proposta, ti invia il file della convenzione. Va firmato digitalmente.',
                fai: ['L\'Ufficio d\'Ateneo <b>accoglie la proposta</b>.', 'Ricevi il file della convenzione all\'<b>indirizzo PEC</b> dell\'ente.', '<b>Firma digitalmente</b> il file.'],
                scena: { frame: 'libero', h: 300, html: pec, azioni: azPec } },
            { fase: 'accredito', titolo: 'Carica la convenzione firmata', testo: 'Il file firmato va caricato in piattaforma. Poi aspetti che l\'Ateneo renda la convenzione «Istituita».',
                fai: ['Apri la convenzione dall\'«<b>Elenco convenzioni aziendali</b>».', 'Vai nella scheda «<b>Allegati della convenzione</b>».', '<b>Carica</b> il file firmato digitalmente.', 'Attendi lo stato «<b>Istituita</b>».'],
                info: 'Da quando la convenzione è «Istituita» puoi inserire opportunità di tirocinio o avviare direttamente uno stage con una studentessa o uno studente.',
                scena: { h: 420, html: carica, azioni: azCarica } },
            { fase: 'studente', id: 'bivio', titolo: 'Quale strada scegli per trovare lo studente?', testo: 'Ci sono <b>due modi</b>, secondo che tu conosca già il tirocinante oppure no. Scegli quello che ti serve: puoi sempre tornare indietro.',
                bivio: [
                    { icona: 'fa-bullhorn', t: 'Non conosco ancora il tirocinante', d: 'Pubblichi un\'opportunità: gli studenti si candidano in autonomia e tu scegli tra i candidati.', vai: 'opp', vai_t: 'Strada A: pubblica un\'opportunità' },
                    { icona: 'fa-user-check', t: 'Ho già in mente uno studente', d: 'Lo hai già incontrato e ha i requisiti di carriera: avvii direttamente lo stage con la sua matricola.', vai: 'avvio', vai_t: 'Strada B: avvio diretto' }
                ] },
            { fase: 'studente', id: 'opp', opzionale: true, titolo: 'Strada A · Pubblica un\'opportunità di tirocinio', testo: 'Scegli questa strada se <b>non conosci già il nome del tirocinante</b> e vuoi rendere visibile un\'opportunità alla quale gli studenti potranno candidarsi in autonomia.',
                fai: ['Entra nella maschera «<b>Opportunità</b>».', 'Clicca il riquadro con il <b>segno +</b> per una nuova opportunità.', 'Clicca «<b>Gestione opportunità</b>».', 'Inserisci le informazioni richieste e <b>rileggi</b> le caratteristiche.', 'Clicca «<b>Salva</b>» e poi «<b>Pubblica</b>».'],
                info: 'Dopo l\'approvazione dell\'opportunità da parte dell\'Ufficio d\'Ateneo, studentesse e studenti potranno candidarsi.',
                scena: { h: 420, html: opp, azioni: azOpp } },
            { fase: 'studente', id: 'cand', opzionale: true, avanti: 'tutor', titolo: 'Strada A · Scegli tra i candidati', testo: 'Quando gli studenti si candidano, li trovi nella sezione «I miei candidati»: da qui scegli chi ospitare.',
                fai: ['Controlla le candidature in «<b>I miei candidati</b>».', 'Apri la <b>singola candidatura</b>.', 'Cliccando «<b>Scegli (con notifica)</b>» scegli lo studente.', 'Ora compili il <b>progetto formativo</b> (passi successivi).'],
                scena: { h: 380, html: cand, azioni: azCand } },
            { fase: 'studente', id: 'avvio', opzionale: true, titolo: 'Strada B · Avvia direttamente lo stage', testo: 'Se sei già in contatto con uno studente <b>con i requisiti di carriera</b> al quale vuoi proporre lo stage, lo puoi avviare senza pubblicare opportunità.',
                fai: ['Nel menu clicca «<b>Avvio stage</b>».', 'Inserisci il <b>numero di matricola</b> e clicca «Cerca studente».', 'Seleziona <b>sede, tipologia di opportunità, area disciplinare e convenzione</b>.', 'Clicca «<b>Avvia stage</b>».'],
                info: 'Subito dopo dovrai compilare il progetto formativo (passi successivi), come nella strada A.',
                scena: { h: 430, html: avvio, azioni: azAvvio } },
            { fase: 'progetto', id: 'tutor', titolo: 'Prima: inserisci tutor e sede', testo: 'Prima di compilare il progetto formativo è <b>consigliabile</b> aver inserito il tutor aziendale e la sede operativa del tirocinio: così li trovi già pronti.',
                fai: ['Inserisci il <b>tutor aziendale</b> in «Area azienda · Contatti».', 'Inserisci la <b>sede operativa</b> del tirocinio in «Area azienda · Sedi».'],
                scena: { h: 400, html: prepara, azioni: azPrepara } },
            { fase: 'progetto', titolo: 'Compila il progetto formativo', testo: 'Confermata la candidatura (o scelto lo studente con «Avvio stage») va compilato il progetto formativo: è il documento che descrive il tirocinio.',
                fai: ['In «<b>I miei stagisti</b>» clicca «Vedi» sul progetto formativo.', 'Scegli il <b>tutor aziendale</b> e la <b>sede operativa</b>.', 'Compila gli <b>altri campi richiesti</b>.', 'Clicca «<b>Salva e invia</b>».'],
                scena: { h: 430, html: pf, azioni: azPf } },
            { fase: 'progetto', titolo: 'Aspetta le conferme: poi si parte', testo: 'Dopo «Salva e invia» tocca agli altri. <b>Non devi fare niente</b> finché lo stato non diventa «Avviato».',
                fai: ['Lo <b>studente</b> accetta il progetto formativo.', 'Il <b>tutor accademico</b> lo accetta.', 'L\'<b>Ufficio d\'Ateneo</b> porta lo stage allo stato «Avviato».'],
                info: 'Da quel momento la studentessa o lo studente può avviare il tirocinio.',
                scena: { frame: 'libero', h: 300, html: attesa, azioni: azAttesa } },
            { fase: 'chiusura', titolo: 'A fine tirocinio: registro e questionario', testo: 'Al termine del periodo il <b>tutor aziendale</b> ha due cose da fare. Entrambe si trovano in «I miei stagisti».',
                fai: ['<b>Verifica e approva il registro presenze</b> (dalla voce «Periodi», oppure dal pulsante «Registri approvabili» alla fine dello stage).', '<b>Compila il questionario di valutazione finale</b> (dalla voce «Valutazione»).'],
                attenzione: 'Il pulsante «Registri approvabili» è una scorciatoia disponibile solo al termine dello stage; dalla voce «Periodi» il registro si vede sempre.',
                scena: { h: 420, html: fine, azioni: azFine } },
            { fase: 'aiuto', titolo: 'Metti alla prova quello che hai imparato', testo: 'Cinque domande veloci per controllare di avere chiare le cose importanti.',
                quiz: [
                    { d: 'Quante aree disciplinari puoi scegliere in una singola proposta di convenzione?', o: ['Fino a tre', 'Una sola: per più aree si propongono più convenzioni', 'Tutte quelle che vuoi'], g: 1, s: 'Ogni convenzione riguarda una sola area disciplinare: se vuoi convenzionarti con più aree devi proporre più convenzioni.' },
                    { d: 'Nella richiesta di accredito hai interesse per più di tre aree: cosa selezioni?', o: ['Le prime tre', '«Tutto l\'Ateneo»', 'Nessuna area'], g: 1, s: 'Quando l\'interesse è su più di tre aree si seleziona tutto l\'Ateneo.' },
                    { d: 'Da quando puoi inserire opportunità o avviare uno stage?', o: ['Subito dopo aver proposto la convenzione', 'Quando carichi la convenzione firmata', 'Quando l\'Ateneo porta la convenzione allo stato «Istituita»'], g: 2, s: 'Dopo il caricamento del file firmato devi attendere che l\'Ateneo passi la convenzione allo stato «Istituita».' },
                    { d: 'Non conosci ancora il tirocinante: cosa fai?', o: ['Pubblico un\'opportunità e scelgo tra i candidati', 'Uso «Avvio stage» con una matricola a caso', 'Aspetto che gli studenti mi scrivano'], g: 0, s: 'L\'opportunità si usa quando non si conosce già il nome del tirocinante; «Avvio stage» serve se hai già uno studente in mente.' },
                    { d: 'Cosa deve fare il tutor aziendale a fine tirocinio?', o: ['Niente: è tutto a carico dello studente', 'Approvare il registro presenze e compilare il questionario di valutazione finale', 'Caricare la relazione finale'], g: 1, s: 'Il tutor aziendale approva il registro delle presenze e compila il questionario di valutazione finale, da «I miei stagisti».' }
                ] },
            { fase: 'aiuto', titolo: 'Se ti blocchi: a chi chiedere', testo: 'Per dubbi sul tuo caso specifico scrivi all\'Ufficio Tirocini del DiBEST o apri una richiesta di assistenza.',
                html: aiuto, info: 'Quando scrivi indica il nome dell\'ente e a che punto sei (per esempio: «convenzione firmata e caricata, in attesa dello stato Istituita»).' }
        ]
    });
})();
