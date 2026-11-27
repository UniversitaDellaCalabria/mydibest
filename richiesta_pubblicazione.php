<?php
// richiesta_pubblicazione.php - Richiesta di pubblicazione su pagina web e canali social del DiBEST: pagina pubblica per docenti e personale, a sette passi.
// Chi può inviarla, se è aperta, i testi e i limiti li dicono le impostazioni (ImpostazioniPubblicazioni, Amministrazione › Impostazioni): con la procedura
// chiusa si vede solo il messaggio delle impostazioni. Senza accesso: «Accedi per compilare» (ritorno a questa pagina). Chi non può: «riservata a: <profili>».
// Il controllo vero dei dati è sul server
// (ServizioPubblicazioni::valida / invia); il browser aiuta soltanto (assets/js/richiesta-pubblicazione.js: passi, contatori, riepilogo). Senza JavaScript
// i sette passi si vedono uno sotto l'altro e il modulo funziona lo stesso. I campi nuovi aggiunti dall'amministratore (x_<n>, Amministrazione › Campi e colonne)
// stanno in fondo al passo che ha scelto (1-7) e si controllano sul server con lo stesso codice di MyForms (ServizioPubblicazioni → CampiAggiunti). Dopo l'invio c'è un rimando alla pagina (F5 non rimanda la richiesta).
// Le email (avviso all'ufficio comunicazione e, se accesa, ricevuta a chi invia) le manda il servizio; i file stanno in uploads/pubblicazioni/ (bloccata al web).
// Passo 5: la LOCANDINA (un file, sempre obbligatoria), «Altri allegati» e «Ulteriori immagini» (facoltativi). Le note operative e l'elenco «Dove vuoi che si pubblichi?»
// sono impostazioni (Amministrazione › Impostazioni › Testi): con l'elenco vuoto l'URL è un indirizzo libero, con l'elenco pieno sono caselle più «Altra pagina del sito DiBEST».
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
sync_sso_user($conn); // accesso automatico dalla sessione SSO di Ateneo, come fa header.php (serve prima di decidere cosa mostrare)

use App\Pubblicazioni\AllegatiPubblicazioni;
use App\Pubblicazioni\ConfigurazioneCampi;
use App\Pubblicazioni\Costanti;
use App\Pubblicazioni\ImpostazioniPubblicazioni;
use App\Pubblicazioni\LimitiCaricamento;
use App\Pubblicazioni\PassiRichiesta;
use App\Pubblicazioni\RegolePubblicazione;
use App\Pubblicazioni\ServizioPubblicazioni;

$app = \App\Core\App::per($conn);
$sv = $app->get(ServizioPubblicazioni::class);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$utente = !empty($_SESSION['utente_id']) ? $app->get(\App\Auth\UtenteRepository::class)->perId((int)$_SESSION['utente_id']) : null;
$loggato = $utente !== null;
$imp = $app->get(ImpostazioniPubblicazioni::class);
$aperta = $imp->aperta();
$lim = $imp->limiti();
$puo = $loggato && $sv->puoInviare($utente);
$pagina = 'richiesta_pubblicazione.php';
$limiti = LimitiCaricamento::dalServer($imp->fileMassimi());
$oggi = $app->get(\App\Core\Orologio::class)->adesso()->format('Y-m-d');
$prima_inizio = RegolePubblicazione::primaDataDiInizio($oggi, $lim['giorni_minimi']);

// ── Invio ──
$errori = [];
$passo_iniziale = 1;
if ($puo && $aperta && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($limiti->invioTroppoGrande($_SERVER, $_POST, $_FILES)) {
        // PHP ha scartato tutto perché l'invio supera post_max_size: nessun dato da controllare, si riparte dal primo passo con un messaggio chiaro
        $errori = ['_' => $limiti->messaggioTroppoGrande()];
    } else {
        csrf_verify($_POST['csrf_token'] ?? '');
        $file = [
            'locandina' => AllegatiPubblicazioni::elenco($_FILES['locandina'] ?? []),
            'allegati' => AllegatiPubblicazioni::elenco($_FILES['allegati'] ?? []),
            'immagini' => AllegatiPubblicazioni::elenco($_FILES['immagini'] ?? []),
        ];
        $errori = PassiRichiesta::erroreHoLetto($_POST) + $sv->valida($_POST, $file);
        if (!$errori) {
            $esito = $sv->invia($utente, $_POST, $file);
            if ($esito['id'] > 0) {
                $quando = $app->get(\App\Core\Orologio::class)->adesso();
                $attesa = $sv->statoIniziale() === 'da_approvare'; // con l'approvazione accesa la riceve prima chi approva
                $_SESSION['pubblicazione_inviata'] = ['id' => $esito['id'], 'quando' => $quando->format('Y-m-d H:i:s'), 'attesa' => $attesa];
                flash_set('Richiesta inviata il ' . $quando->format('d/m/Y \a\l\l\e H:i') . ($attesa ? ': è in attesa di approvazione.' : ': la riceve l\'ufficio comunicazione.'), 'success');
                header('Location: ' . $pagina . '?inviata=1');
                exit;
            }
            $errori = $esito['errori'];
        }
        $passo_iniziale = PassiRichiesta::primoConErrore($errori, $app->get(ConfigurazioneCampi::class)) ?? 1;
    }
}
$e_post = $_SERVER['REQUEST_METHOD'] === 'POST' && $puo && $aperta;
$appena_inviata = $puo && isset($_GET['inviata']) && isset($_SESSION['pubblicazione_inviata']);

// Errore accanto al campo (se c'è) e posto per quello che mette il browser mentre si compila
$err = fn(string $campo) => '<div class="rp-errore" role="alert" data-errore-per="' . $h($campo) . '">' . (isset($errori[$campo]) ? $h($errori[$campo]) : '') . '</div>';
$p = fn(string $k) => $e_post ? $h(is_array($_POST[$k] ?? '') ? '' : ($_POST[$k] ?? '')) : '';
$spuntato = fn(string $k) => $e_post && !empty($_POST[$k]);
$pagine_rp = $imp->pagine(); // «Dove vuoi che si pubblichi?»: vuoto = campo libero come prima
$pagine_scelte = $e_post && is_array($_POST['pagine_scelte'] ?? null) ? array_map('strval', array_filter($_POST['pagine_scelte'], 'is_scalar')) : [];
$modalita_scelta = $e_post ? (string)($_POST['modalita'] ?? '') : '';

$page_cfg['titolo'] = 'Richiesta di pubblicazione';
require_once 'header.php';
echo ui_tema_script(); // tema chiaro o scuro (la scelta fatta nel profilo o quella del sistema)
$url_accedi = 'saml_login.php?redirect=' . urlencode($pagina);
$notifiche = $puo ? $imp->indirizzi() : [];
?>
<style>
    .rp-errore { color: var(--ui-azione-fg, #b91c1c); font-weight: 700; font-size: .875rem; margin-top: .25rem; }
    .rp-errore:empty { display: none; }
    .rp-oltre { color: var(--ui-azione-fg, #b91c1c); font-weight: 700; }
    .rp-note { background: var(--ui-lieve, #f1f5f9); border: 1px solid var(--ui-bordo, transparent); border-radius: 8px; padding: 12px 16px; }
    .rp-passo { margin-bottom: 1.75rem; padding-bottom: 1.25rem; border-bottom: 1px solid var(--ui-bordo, #e2e8f0); }
    .rp-passo:last-of-type { border-bottom: 0; }
    .rp-campo { margin-bottom: 1.1rem; }
    .rp-riep-sezione { border: 1px solid var(--ui-bordo, #e2e8f0); border-radius: 8px; padding: 10px 14px; margin-bottom: 10px; }
    .rp-riep-sezione dt { color: var(--ui-testo-2, #475569); font-weight: 600; font-size: .85rem; }
    .rp-riep-sezione dd { margin: 0 0 .5rem 0; overflow-wrap: anywhere; }
    .rp-invalido { border-color: #b91c1c !important; }
    .rp-testo > :last-child { margin-bottom: 0; }
    html[data-tema="scuro"] body.ui-scuro-ok #rp-form .form-text { color: var(--ui-testo-2); }
</style>
<div class="container my-4" style="max-width: 860px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span> <span class="text-secondary">Richiesta di pubblicazione</span></nav>
    <h1 class="fw-bold h2 mb-2">Richiesta di pubblicazione</h1>
    <?php $intro = \App\Portale\Vista\Testi::pulisciHtmlSemplice((string)$imp->tutte()['testo_introduttivo']); ?>
    <?php if ($intro !== ''): ?><div class="ui-intro-pagina rp-testo mb-3" role="note"><?php echo $intro; // HTML semplice già ripulito: sempre sopra il modulo, quindi visibile su tutti i passi ?></div><?php endif; ?>
    <?php echo flash_html(); ?>

    <?php if (!$aperta && !$appena_inviata): ?>
        <div class="alert alert-secondary d-flex flex-wrap align-items-center gap-2" role="status">
            <i class="fa fa-lock" aria-hidden="true"></i>
            <span><?php echo $h($imp->messaggioChiusa()); ?></span>
            <?php if ($loggato): ?><a class="btn btn-sm btn-outline-secondary fw-bold ms-auto" href="area_personale.php">La mia area personale</a><?php endif; ?>
        </div>

    <?php elseif (!$loggato): ?>
        <div class="alert alert-info d-flex flex-wrap align-items-center gap-2" role="status">
            <i class="fa fa-key" aria-hidden="true"></i>
            <span>Per compilare la richiesta devi fare l'accesso con le tue credenziali di Ateneo.</span>
            <a class="btn btn-sm btn-primary fw-bold ms-auto" href="<?php echo $h($url_accedi); ?>"><i class="fa fa-key me-1" aria-hidden="true"></i>Accedi per compilare</a>
        </div>

    <?php elseif (!$puo): ?>
        <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2" role="status">
            <i class="fa fa-lock" aria-hidden="true"></i>
            <span>Questa richiesta è riservata a: <?php echo $h($imp->chiInParole()); ?>.</span>
            <a class="btn btn-sm btn-outline-secondary fw-bold ms-auto" href="area_personale.php">La mia area personale</a>
        </div>

    <?php elseif ($appena_inviata): ?>
        <div class="card border-0 shadow-sm p-3 p-md-4 mb-3" style="border-radius:12px;">
            <div class="fw-bold fs-5 mb-1"><i class="fa fa-circle-check text-success me-1" aria-hidden="true"></i>Richiesta inviata</div>
            <?php
            // In attesa di approvazione il testo di partenza («è arrivata all'ufficio comunicazione») non sarebbe vero: si mostra solo la frase sull'attesa. Un testo riscritto dall'amministratore resta.
            $attesa = !empty($_SESSION['pubblicazione_inviata']['attesa']);
            if ($attesa): ?><p class="mb-2">La richiesta è in attesa di approvazione: quando è decisa ti scriviamo per email. Se approvata passa all'ufficio comunicazione.</p><?php endif; ?>
            <?php if (!$attesa || $imp->tutte()['testo_dopo_invio'] !== ImpostazioniPubblicazioni::INIZIALI['testo_dopo_invio']): ?>
            <div class="mb-2 rp-testo"><?php echo \App\Portale\Vista\Testi::pulisciHtmlSemplice((string)$imp->tutte()['testo_dopo_invio']); // HTML semplice già ripulito ?></div>
            <?php endif; ?>
            <?php if ($imp->ricevuta() && trim((string)($utente['email'] ?? '')) !== ''): ?><p class="mb-2">Ti abbiamo mandato la ricevuta per email, con la copia di quello che hai inviato.</p><?php endif; ?>
            <p class="small text-secondary mb-3">Una richiesta già inviata non si può modificare dal portale<?php echo $notifiche ? ': per correzioni scrivi a ' . $h($notifiche[0]) : ''; ?>. Lo stato lo vedi nella tua area personale.</p>
            <div class="d-flex flex-wrap gap-2">
                <a href="area_personale.php" class="btn btn-primary fw-bold"><i class="fa fa-house me-1" aria-hidden="true"></i>La mia area personale</a>
                <a href="<?php echo $h($pagina); ?>" class="btn btn-outline-secondary fw-bold"><i class="fa fa-plus me-1" aria-hidden="true"></i>Nuova richiesta</a>
            </div>
        </div>

    <?php else: ?>
        <?php if ($errori): ?>
            <div class="alert alert-danger" role="alert" id="rp-errori-server"><strong>Controlla i dati:</strong><ul class="mb-0"><?php foreach ($errori as $campo => $m): ?><li><?php echo $h($m); ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <?php if ($loggato && \App\Auth\Pubblico::ammesso(['profili' => []], $utente) && ($problemi = $limiti->problemi())): ?>
            <div class="alert alert-warning small" role="status"><strong>Per l'amministratore:</strong> la configurazione del server non basta per i limiti previsti. <?php echo $h(implode(' ', $problemi)); ?></div>
        <?php endif; ?>

        <?php
        // I campi, le etichette, gli aiuti e quali sono obbligatori li dice ConfigurazioneCampi (Amministrazione › Campi e colonne): senza personalizzazioni la pagina è quella di sempre.
        // Un passo che resta senza campi visibili non si mostra e la numerazione dei passi resta continua.
        $cfg_rp = $app->get(ConfigurazioneCampi::class);
        $passi_rp = $cfg_rp->passiVisibili();
        $n_rp = fn(int $passo): int => (int)array_search($passo, $passi_rp, true) + 1;
        $obb_rp = fn(string $k): bool => $k === 'locandina' ? true : ($k === 'allegati' ? (bool)$lim['allegati_obbligatori'] : ($k === 'immagini' ? false : $cfg_rp->obbligatorio($k)));
        $marca_rp = fn(bool $obbligatorio): string => $obbligatorio ? ' <span class="text-danger">*</span>' : ' <span class="fw-normal text-secondary">(facoltativo)</span>';
        $etic_rp = fn(string $k): string => $h($cfg_rp->etichetta($k));
        // L'aiuto scritto dall'amministratore (o quello di partenza) con, dove serve, la frase che dipende dalle Impostazioni (battute, numero di file, anticipo)
        $aiuto_rp = function (string $k, string $prima = '', string $dopo = '') use ($cfg_rp, $h): string {
            return $h(trim(implode(' ', array_filter([$prima, trim((string)($cfg_rp->campo($k)['aiuto'] ?? '')), $dopo], fn($t) => $t !== ''))));
        };
        $frase_inizio_rp = $lim['giorni_minimi'] > 0
            ? 'Da quando la notizia può essere pubblicata: almeno ' . RegolePubblicazione::giorniInParole($lim['giorni_minimi']) . ' dopo oggi, cioè dal ' . RegolePubblicazione::dataItaliana($prima_inizio) . ' in poi.'
            : 'Da quando la notizia può essere pubblicata: non può essere una data passata.';
        $H = []; // il codice HTML di ogni campo: ogni passo li stampa nell'ordine scelto

        ob_start(); ?>
                <div class="rp-campo">
                    <label class="form-label fw-bold" for="richiedente"><?php echo $etic_rp('richiedente'); ?></label>
                    <input type="text" class="form-control" id="richiedente" value="<?php echo $h(ServizioPubblicazioni::nomeCognome($utente)); ?>" readonly aria-readonly="true">
                    <?php if ($aiuto_rp('richiedente') !== ''): ?><div class="form-text"><?php echo $aiuto_rp('richiedente'); ?></div><?php endif; ?>
                </div>
        <?php $H['richiedente'] = ob_get_clean();

        ob_start(); ?>
                <div class="rp-campo">
                    <label class="form-label fw-bold" for="data_evento"><?php echo $etic_rp('data_evento'); ?> <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" style="max-width:220px;" name="data_evento" id="data_evento" value="<?php echo $p('data_evento'); ?>" required>
                    <?php if ($aiuto_rp('data_evento') !== ''): ?><div class="form-text"><?php echo $aiuto_rp('data_evento'); ?></div><?php endif; ?>
                    <?php echo $err('data_evento'); ?>
                </div>
        <?php $H['data_evento'] = ob_get_clean();

        ob_start(); ?>
                <fieldset class="rp-campo">
                    <legend class="form-label fw-bold fs-6 mb-1"><?php echo $etic_rp('modalita'); ?><?php echo $marca_rp($obb_rp('modalita')); ?></legend>
                    <?php foreach (Costanti::MODALITA as $chiave => $etichetta): ?>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="modalita" id="modalita_<?php echo $h($chiave); ?>" value="<?php echo $h($chiave); ?>" <?php echo $modalita_scelta === $chiave ? 'checked' : ''; ?><?php echo $obb_rp('modalita') ? ' required' : ''; ?>>
                            <label class="form-check-label" for="modalita_<?php echo $h($chiave); ?>"><?php echo $h($etichetta); ?></label>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($aiuto_rp('modalita') !== ''): ?><div class="form-text"><?php echo $aiuto_rp('modalita'); ?></div><?php endif; ?>
                    <?php echo $err('modalita'); ?>
                </fieldset>
        <?php $H['modalita'] = ob_get_clean();

        ob_start(); ?>
                <?php if ($pagine_rp === []): ?>
                <div class="rp-campo">
                    <label class="form-label fw-bold" for="url_docente"><?php echo $etic_rp('url_docente'); ?><?php echo $marca_rp($obb_rp('url_docente')); ?></label>
                    <input type="url" class="form-control" name="url_docente" id="url_docente" value="<?php echo $p('url_docente'); ?>" placeholder="https://"<?php echo $obb_rp('url_docente') ? ' required' : ''; ?>>
                    <?php if ($aiuto_rp('url_docente') !== ''): ?><div class="form-text"><?php echo $aiuto_rp('url_docente'); ?></div><?php endif; ?>
                    <?php echo $err('url_docente'); ?>
                </div>
                <?php else: ?>
                <?php $domanda_pagine = $cfg_rp->etichetta('url_docente') === 'URL' ? 'Dove vuoi che si pubblichi?' : $cfg_rp->etichetta('url_docente'); ?>
                <fieldset class="rp-campo" id="rp-pagine" data-etichetta="<?php echo $h($domanda_pagine); ?>">
                    <legend class="form-label fw-bold fs-6 mb-1"><?php echo $h($domanda_pagine); ?><?php echo $marca_rp($obb_rp('url_docente')); ?></legend>
                    <?php foreach ($pagine_rp as $i_pg => $pg): ?>
                        <div class="form-check">
                            <input class="form-check-input rp-pagina" type="checkbox" name="pagine_scelte[]" id="pagina_<?php echo (int)$i_pg; ?>" value="<?php echo $h($pg['indirizzo']); ?>" <?php echo in_array($pg['indirizzo'], $pagine_scelte, true) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="pagina_<?php echo (int)$i_pg; ?>"><?php echo $h($pg['nome']); ?></label>
                        </div>
                    <?php endforeach; ?>
                    <div class="mt-2">
                        <label class="form-label mb-1" for="altra_pagina">Altra pagina del sito DiBEST <span class="fw-normal text-secondary">(facoltativo)</span></label>
                        <input type="url" class="form-control" name="altra_pagina" id="altra_pagina" value="<?php echo $p('altra_pagina'); ?>" placeholder="https://dibest.unical.it/…" aria-describedby="aiuto-altra-pagina">
                        <div class="form-text" id="aiuto-altra-pagina">L'indirizzo completo, su dibest.unical.it o su un sito di Unical con «dibest» nel nome.</div>
                    </div>
                    <?php if ($aiuto_rp('url_docente') !== ''): ?><div class="form-text"><?php echo $aiuto_rp('url_docente'); ?></div><?php endif; ?>
                    <?php echo $err('url_docente'); ?>
                </fieldset>
                <?php endif; ?>
        <?php $H['url_docente'] = ob_get_clean();

        ob_start(); ?>
                <div class="rp-campo">
                    <label class="form-label fw-bold" for="altri_link_docente"><?php echo $etic_rp('altri_link_docente'); ?><?php echo $marca_rp($obb_rp('altri_link_docente')); ?></label>
                    <textarea class="form-control" name="altri_link_docente" id="altri_link_docente" rows="3" placeholder="https://"<?php echo $obb_rp('altri_link_docente') ? ' required' : ''; ?>><?php echo $p('altri_link_docente'); ?></textarea>
                    <?php if ($aiuto_rp('altri_link_docente') !== ''): ?><div class="form-text"><?php echo $aiuto_rp('altri_link_docente'); ?></div><?php endif; ?>
                    <?php echo $err('altri_link_docente'); ?>
                </div>
        <?php $H['altri_link_docente'] = ob_get_clean();

        ob_start(); ?>
                <div class="rp-campo">
                    <label class="form-label fw-bold" for="titolo"><?php echo $etic_rp('titolo'); ?> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="titolo" id="titolo" value="<?php echo $p('titolo'); ?>" required>
                    <?php if ($aiuto_rp('titolo') !== ''): ?><div class="form-text"><?php echo $aiuto_rp('titolo'); ?></div><?php endif; ?>
                    <?php echo $err('titolo'); ?>
                </div>
        <?php $H['titolo'] = ob_get_clean();

        ob_start(); ?>
                <div class="rp-campo">
                    <label class="form-label fw-bold" for="sottotitolo"><?php echo $etic_rp('sottotitolo'); ?><?php echo $marca_rp($obb_rp('sottotitolo')); ?></label>
                    <input type="text" class="form-control" name="sottotitolo" id="sottotitolo" value="<?php echo $p('sottotitolo'); ?>" aria-describedby="aiuto-sottotitolo conta-sottotitolo"<?php echo $obb_rp('sottotitolo') ? ' required' : ''; ?>>
                    <div class="form-text" id="aiuto-sottotitolo"><?php echo $aiuto_rp('sottotitolo', '', 'Al massimo ' . (int)$lim['max_sottotitolo'] . ' battute, spazi inclusi.'); ?></div>
                    <div class="small fw-bold" id="conta-sottotitolo" aria-live="polite" hidden><span class="rp-n">0</span> / <?php echo (int)$lim['max_sottotitolo']; ?></div>
                    <?php echo $err('sottotitolo'); ?>
                </div>
        <?php $H['sottotitolo'] = ob_get_clean();

        ob_start(); ?>
                <div class="rp-campo">
                    <label class="form-label fw-bold" for="descrizione"><?php echo $etic_rp('descrizione'); ?><?php echo $marca_rp($obb_rp('descrizione')); ?></label>
                    <textarea class="form-control" name="descrizione" id="descrizione" rows="4" aria-describedby="aiuto-descrizione conta-descrizione"<?php echo $obb_rp('descrizione') ? ' required' : ''; ?>><?php echo $p('descrizione'); ?></textarea>
                    <div class="form-text" id="aiuto-descrizione"><?php echo $aiuto_rp('descrizione', '', 'Al massimo ' . (int)$lim['max_descrizione'] . ' battute, spazi inclusi.'); ?></div>
                    <div class="small fw-bold" id="conta-descrizione" aria-live="polite" hidden><span class="rp-n">0</span> / <?php echo (int)$lim['max_descrizione']; ?></div>
                    <?php echo $err('descrizione'); ?>
                </div>
        <?php $H['descrizione'] = ob_get_clean();

        ob_start(); ?>
                <fieldset class="rp-campo">
                    <legend class="form-label fw-bold fs-6 mb-1"><?php echo $etic_rp('destinatari'); ?> <span class="text-danger">*</span> <span class="fw-normal text-secondary">(scegline almeno uno)</span></legend>
                    <?php foreach (['per_cittadinanza' => 'Cittadinanza', 'per_studenti' => 'Studenti universitari', 'per_docenti' => 'Docenti', 'per_pta' => 'PTA (personale tecnico amministrativo)'] as $chiave => $etichetta): ?>
                        <div class="form-check">
                            <input class="form-check-input rp-destinatario" type="checkbox" name="<?php echo $h($chiave); ?>" id="<?php echo $h($chiave); ?>" value="1" <?php echo $spuntato($chiave) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="<?php echo $h($chiave); ?>"><?php echo $h($etichetta); ?></label>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($aiuto_rp('destinatari') !== ''): ?><div class="form-text"><?php echo $aiuto_rp('destinatari'); ?></div><?php endif; ?>
                    <?php echo $err('destinatari'); ?>
                </fieldset>
        <?php $H['destinatari'] = ob_get_clean();

        ob_start(); ?>
                <div class="rp-campo">
                    <label class="form-label fw-bold" for="locandina"><?php echo $etic_rp('locandina'); ?> <span class="text-danger">*</span></label>
                    <input type="file" class="form-control" name="locandina" id="locandina" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" aria-describedby="aiuto-locandina" required>
                    <div class="form-text" id="aiuto-locandina"><?php echo $aiuto_rp('locandina'); ?></div>
                    <?php echo $err('locandina'); ?>
                </div>
        <?php $H['locandina'] = ob_get_clean();

        ob_start(); ?>
                <div class="rp-campo">
                    <label class="form-label fw-bold" for="allegati"><?php echo $etic_rp('allegati'); ?><?php echo $lim['allegati_obbligatori'] ? ' <span class="text-danger">*</span>' : ' <span class="fw-normal text-secondary">(facoltativo)</span>'; ?></label>
                    <input type="file" class="form-control" name="allegati[]" id="allegati" multiple accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" aria-describedby="aiuto-allegati"<?php echo $lim['allegati_obbligatori'] ? ' required' : ''; ?>>
                    <div class="form-text" id="aiuto-allegati"><?php echo $aiuto_rp('allegati', 'File PDF, JPG o PNG, ' . ($lim['allegati_obbligatori'] ? 'da 1 a ' : 'fino a ') . (int)$lim['max_allegati'] . '.'); ?></div>
                    <?php echo $err('allegati'); ?>
                </div>
        <?php $H['allegati'] = ob_get_clean();

        ob_start(); ?>
                <div class="rp-campo">
                    <label class="form-label fw-bold" for="immagini"><?php echo $etic_rp('immagini'); ?> <span class="fw-normal text-secondary">(facoltativo)</span></label>
                    <input type="file" class="form-control" name="immagini[]" id="immagini" multiple accept=".jpg,.jpeg,.png,image/jpeg,image/png" aria-describedby="aiuto-immagini">
                    <div class="form-text" id="aiuto-immagini"><?php echo $aiuto_rp('immagini', 'File JPG o PNG, fino a ' . (int)$lim['max_immagini'] . '.'); ?></div>
                    <?php echo $err('immagini'); ?>
                </div>
        <?php $H['immagini'] = ob_get_clean();

        ob_start(); ?>
                <div class="rp-campo">
                    <label class="form-label fw-bold" for="tag"><?php echo $etic_rp('tag'); ?><?php echo $marca_rp($obb_rp('tag')); ?></label>
                    <input type="text" class="form-control" name="tag" id="tag" value="<?php echo $p('tag'); ?>" placeholder="DIBEST, notizie, avvisi"<?php echo $obb_rp('tag') ? ' required' : ''; ?>>
                    <?php if ($aiuto_rp('tag') !== ''): ?><div class="form-text"><?php echo $aiuto_rp('tag'); ?></div><?php endif; ?>
                    <?php echo $err('tag'); ?>
                </div>
        <?php $H['tag'] = ob_get_clean();

        ob_start(); ?>
                <div class="rp-campo">
                    <label class="form-label fw-bold" for="data_inizio_pubblicazione"><?php echo $etic_rp('data_inizio_pubblicazione'); ?><?php echo $marca_rp($obb_rp('data_inizio_pubblicazione')); ?></label>
                    <input type="date" class="form-control" style="max-width:220px;" name="data_inizio_pubblicazione" id="data_inizio_pubblicazione" value="<?php echo $p('data_inizio_pubblicazione'); ?>" min="<?php echo $h($prima_inizio); ?>"<?php echo $obb_rp('data_inizio_pubblicazione') ? ' required' : ''; ?>>
                    <div class="form-text"><?php echo $aiuto_rp('data_inizio_pubblicazione', '', $frase_inizio_rp); ?></div>
                    <?php echo $err('data_inizio_pubblicazione'); ?>
                </div>
        <?php $H['data_inizio_pubblicazione'] = ob_get_clean();

        ob_start(); ?>
                <div class="rp-campo">
                    <label class="form-label fw-bold" for="note"><?php echo $etic_rp('note'); ?><?php echo $marca_rp($obb_rp('note')); ?></label>
                    <textarea class="form-control" name="note" id="note" rows="3"<?php echo $obb_rp('note') ? ' required' : ''; ?>><?php echo $p('note'); ?></textarea>
                    <?php if ($aiuto_rp('note') !== ''): ?><div class="form-text"><?php echo $aiuto_rp('note'); ?></div><?php endif; ?>
                    <?php echo $err('note'); ?>
                </div>
        <?php $H['note'] = ob_get_clean();

        // I campi nuovi dell'amministratore (x_<n>): ognuno nel suo passo, in fondo; i nascosti non si chiedono. Il tipo è uno di quelli di MyForms (senza file e senza condizioni).
        foreach ($cfg_rp->campiExtra() as $x_rp) {
            if (!$x_rp['visibile']) { continue; }
            $k = $x_rp['chiave'];
            $scelto = $e_post ? ($_POST[$k] ?? '') : '';
            $req = $x_rp['obbligatorio'] ? ' required' : '';
            ob_start(); ?>
                <?php if (in_array($x_rp['tipo'], ['radio', 'multicheck'], true)): ?>
                <fieldset class="rp-campo" data-extra="<?php echo $h($k); ?>" data-tipo="<?php echo $h($x_rp['tipo']); ?>">
                    <legend class="form-label fw-bold fs-6 mb-1"><?php echo $h($x_rp['etichetta']); ?><?php echo $marca_rp($x_rp['obbligatorio']); ?></legend>
                    <?php foreach ($x_rp['opzioni'] as $i_rp => $o_rp):
                        $id_rp = $k . '_' . ($i_rp + 1);
                        $on = $x_rp['tipo'] === 'radio' ? (!is_array($scelto) && (string)$scelto === $o_rp) : (is_array($scelto) && in_array($o_rp, array_map('strval', $scelto), true)); ?>
                        <div class="form-check">
                            <input class="form-check-input" type="<?php echo $x_rp['tipo'] === 'radio' ? 'radio' : 'checkbox'; ?>" name="<?php echo $h($k . ($x_rp['tipo'] === 'radio' ? '' : '[]')); ?>" id="<?php echo $h($id_rp); ?>" value="<?php echo $h($o_rp); ?>" <?php echo $on ? 'checked' : ''; ?><?php echo $x_rp['tipo'] === 'radio' ? $req : ''; ?>>
                            <label class="form-check-label" for="<?php echo $h($id_rp); ?>"><?php echo $h($o_rp); ?></label>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($aiuto_rp($k) !== ''): ?><div class="form-text"><?php echo $aiuto_rp($k); ?></div><?php endif; ?>
                    <?php echo $err($k); ?>
                </fieldset>
                <?php elseif ($x_rp['tipo'] === 'checkbox'): ?>
                <div class="rp-campo" data-extra="<?php echo $h($k); ?>" data-tipo="checkbox">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="<?php echo $h($k); ?>" id="<?php echo $h($k); ?>" value="1" <?php echo !is_array($scelto) && $scelto !== '' ? 'checked' : ''; ?><?php echo $req; ?>>
                        <label class="form-check-label fw-bold" for="<?php echo $h($k); ?>"><?php echo $h($x_rp['etichetta']); ?><?php echo $marca_rp($x_rp['obbligatorio']); ?></label>
                    </div>
                    <?php if ($aiuto_rp($k) !== ''): ?><div class="form-text"><?php echo $aiuto_rp($k); ?></div><?php endif; ?>
                    <?php echo $err($k); ?>
                </div>
                <?php else: ?>
                <div class="rp-campo" data-extra="<?php echo $h($k); ?>" data-tipo="<?php echo $h($x_rp['tipo']); ?>">
                    <label class="form-label fw-bold" for="<?php echo $h($k); ?>"><?php echo $h($x_rp['etichetta']); ?><?php echo $marca_rp($x_rp['obbligatorio']); ?></label>
                    <?php if ($x_rp['tipo'] === 'textarea'): ?>
                        <textarea class="form-control" name="<?php echo $h($k); ?>" id="<?php echo $h($k); ?>" rows="3"<?php echo $req; ?>><?php echo $p($k); ?></textarea>
                    <?php else: ?>
                        <input type="<?php echo $x_rp['tipo'] === 'date' ? 'date' : ($x_rp['tipo'] === 'number' ? 'number' : ($x_rp['tipo'] === 'url' ? 'url' : 'text')); ?>" class="form-control"<?php echo in_array($x_rp['tipo'], ['date', 'number'], true) ? ' style="max-width:220px;"' : ''; ?><?php echo $x_rp['tipo'] === 'number' ? ' step="any"' : ''; ?><?php echo $x_rp['tipo'] === 'url' ? ' placeholder="https://"' : ''; ?> name="<?php echo $h($k); ?>" id="<?php echo $h($k); ?>" value="<?php echo $p($k); ?>"<?php echo $req; ?>>
                    <?php endif; ?>
                    <?php if ($aiuto_rp($k) !== ''): ?><div class="form-text"><?php echo $aiuto_rp($k); ?></div><?php endif; ?>
                    <?php echo $err($k); ?>
                </div>
                <?php endif; ?>
            <?php $H[$k] = ob_get_clean();
        }
        // Quello che un passo mostra prima e dopo i suoi campi (peso dei file, Public Engagement)
        $prima_rp = [];
        $dopo_rp = [];
        ob_start(); ?>
                <p class="small text-secondary"><i class="fa fa-weight-hanging me-1" aria-hidden="true"></i><?php echo $h($limiti->frase()); ?></p>
                <div class="alert <?php echo $errori ? 'alert-warning fw-bold' : 'alert-light border small'; ?>" role="note"><i class="fa fa-paperclip me-1" aria-hidden="true"></i>I file non restano scelti se la pagina si riapre con un errore: <?php echo $errori ? 'devi scegliere di nuovo la locandina, gli altri allegati e le immagini qui sotto.' : 'in quel caso vanno scelti di nuovo.'; ?></div>
        <?php $prima_rp[5] = ob_get_clean();
        ob_start(); ?>
                <div class="alert alert-info" id="rp-pe" role="note">
                    <div class="fw-bold mb-1"><i class="fa fa-people-group me-1" aria-hidden="true"></i>Public Engagement</div>
                    <p class="mb-2">Se l'evento è rivolto alla cittadinanza, il docente (organizzatore o partecipante a iniziative su invito di altri enti) deve registrare l'evento come Public Engagement a questo indirizzo:
                        <a href="https://pe.unical.it" target="_blank" rel="noopener noreferrer">https://pe.unical.it</a>.</p>
                    <p class="small mb-0">Il Public Engagement è l'insieme delle attività organizzate dall'Ateneo o dalle sue strutture, senza scopo di lucro, con valore educativo, culturale e di sviluppo della società, rivolte a un pubblico non accademico.</p>
                </div>
        <?php $dopo_rp[4] = ob_get_clean();
        ?>
        <form method="POST" action="<?php echo $h($pagina); ?>" enctype="multipart/form-data" id="rp-form" class="card border-0 shadow-sm p-3 p-md-4" style="border-radius:12px;"
              data-passo-iniziale="<?php echo (int)$passo_iniziale; ?>" data-oggi="<?php echo $h($oggi); ?>"
              data-max-allegati="<?php echo (int)$lim['max_allegati']; ?>" data-max-immagini="<?php echo (int)$lim['max_immagini']; ?>"
              data-max-byte="<?php echo (int)$limiti->fileMax(); ?>" data-max-descrizione="<?php echo (int)$lim['max_descrizione']; ?>"
              data-max-sottotitolo="<?php echo (int)$lim['max_sottotitolo']; ?>" data-max-titolo="<?php echo (int)Costanti::MAX_TITOLO; ?>"
              data-allegati-obbligatori="<?php echo $lim['allegati_obbligatori'] ? '1' : '0'; ?>" data-prima-inizio="<?php echo $h($prima_inizio); ?>"
              data-messaggio-inizio="<?php echo $h(RegolePubblicazione::messaggioInizio($oggi, $lim['giorni_minimi'])); ?>"
              data-campi="<?php echo $h(json_encode($cfg_rp->regole(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>"
              data-tinymce-base="<?php echo $h(url_vendor('tinymce/8.10.2')); ?>" data-tinymce-it="<?php echo $h(url_vendor('tinymce/8.10.2/langs/it.js')); ?>">
            <?php csrf_field(); ?>

            <!-- Barra di avanzamento e pulsanti: li accende JavaScript; senza, i passi si vedono tutti uno sotto l'altro -->
            <div class="mb-3" id="rp-avanzamento" hidden>
                <div class="d-flex justify-content-between small fw-bold mb-1"><span id="rp-avanz-testo">Passo 1 di <?php echo count($passi_rp); ?></span><span id="rp-avanz-titolo" class="text-secondary"></span></div>
                <div class="progress" style="height:10px;" role="progressbar" aria-label="Avanzamento della richiesta" aria-valuemin="1" aria-valuemax="<?php echo count($passi_rp); ?>" aria-valuenow="1"><div class="progress-bar" id="rp-barra" style="width:<?php echo (int)round(100 / count($passi_rp)); ?>%"></div></div>
            </div>

            <!-- Passo 1 -->
            <section class="rp-passo" id="rp-passo-1" data-passo="1" data-titolo="<?php echo $h(PassiRichiesta::PASSI[1]); ?>" aria-labelledby="rp-tit-1">
                <h2 class="h4 fw-bold mb-3" id="rp-tit-1">1. <?php echo $h(PassiRichiesta::PASSI[1]); ?></h2>
                <div class="rp-note mb-3">
                    <div class="fw-bold mb-1">Note operative</div>
                    <ul class="mb-0">
                        <?php foreach ($imp->noteOperative() as $nota): ?>
                        <li><?php echo $nota['titolo'] !== '' ? '<strong>' . $h($nota['titolo']) . ':</strong> ' : ''; ?><?php echo $h($nota['testo']); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="rp-campo">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="ho_letto" id="ho_letto" value="1" <?php echo $spuntato('ho_letto') ? 'checked' : ''; ?> required>
                        <label class="form-check-label fw-bold" for="ho_letto">Ho letto le note operative <span class="text-danger">*</span></label>
                    </div>
                    <?php echo $err('ho_letto'); ?>
                </div>
                <?php foreach ($cfg_rp->campiDelForm(1) as $c_rp) { echo $H[$c_rp['chiave']]; } ?>
            </section>

            <!-- Passi 2-6: i campi nell'ordine scelto; un passo senza campi visibili non c'è -->
            <?php foreach ([2, 3, 4, 5, 6] as $nr): if (!in_array($nr, $passi_rp, true)) continue; ?>
            <section class="rp-passo" id="rp-passo-<?php echo $nr; ?>" data-passo="<?php echo $nr; ?>" data-titolo="<?php echo $h(PassiRichiesta::PASSI[$nr]); ?>" aria-labelledby="rp-tit-<?php echo $nr; ?>">
                <h2 class="h4 fw-bold mb-3" id="rp-tit-<?php echo $nr; ?>"><?php echo $n_rp($nr); ?>. <?php echo $h(PassiRichiesta::PASSI[$nr]); ?></h2>
                <?php echo $prima_rp[$nr] ?? ''; foreach ($cfg_rp->campiDelForm($nr) as $c_rp) { echo $H[$c_rp['chiave']]; } echo $dopo_rp[$nr] ?? ''; ?>
            </section>
            <?php endforeach; ?>

            <!-- Ultimo passo: riepilogo -->
            <section class="rp-passo" id="rp-passo-7" data-passo="7" data-titolo="<?php echo $h(PassiRichiesta::PASSI[7]); ?>" aria-labelledby="rp-tit-7">
                <h2 class="h4 fw-bold mb-3" id="rp-tit-7"><?php echo $n_rp(7); ?>. <?php echo $h(PassiRichiesta::PASSI[7]); ?></h2>
                <p class="mb-2">Controlla quello che hai scritto nei passi qui sopra. Una volta inviata, la richiesta non si può più modificare dal portale<?php echo $notifiche ? ': per correzioni scrivi a ' . $h($notifiche[0]) : ''; ?>.</p>
                <div id="rp-riepilogo" aria-live="polite"></div>
                <?php foreach ($cfg_rp->campiDelForm(7) as $c_rp) { echo $H[$c_rp['chiave']]; } ?>
                <?php echo $err('_'); ?>
                <button type="submit" class="btn btn-success fw-bold mt-2" id="rp-invia"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Invia la richiesta</button>
            </section>

            <div class="d-flex gap-2 align-items-center" id="rp-nav" hidden>
                <button type="button" class="btn btn-outline-secondary fw-bold" id="rp-indietro"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Indietro</button>
                <button type="button" class="btn btn-primary fw-bold ms-auto" id="rp-avanti">Avanti<i class="fa fa-arrow-right ms-1" aria-hidden="true"></i></button>
            </div>
            <div class="form-text mt-2">Inviando la richiesta dichiari di aver letto l'<a href="privacy.php" target="_blank" rel="noopener">informativa sul trattamento dei dati personali</a>.</div>
        </form>
        <script src="<?php echo $h(url_vendor('tinymce/8.10.2/tinymce.min.js')); ?>"></script>
        <script src="assets/js/richiesta-pubblicazione.js?v=6"></script>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
