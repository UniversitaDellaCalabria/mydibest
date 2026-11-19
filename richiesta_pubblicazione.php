<?php
// richiesta_pubblicazione.php - Richiesta di pubblicazione su pagina web e canali social del DiBEST: pagina pubblica per i docenti, a sette passi.
// Senza accesso: «Accedi per compilare» (ritorno a questa pagina). Chi non è docente: «riservata ai docenti». Il controllo vero dei dati è sul server
// (ServizioPubblicazioni::valida / invia); il browser aiuta soltanto (assets/js/richiesta-pubblicazione.js: passi, contatori, riepilogo). Senza JavaScript
// i sette passi si vedono uno sotto l'altro e il modulo funziona lo stesso. Dopo l'invio c'è un rimando alla pagina (F5 non rimanda la richiesta).
// Le email (avviso all'ufficio comunicazione e ricevuta al docente) le manda il servizio; i file stanno in uploads/pubblicazioni/ (bloccata al web).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
sync_sso_user($conn); // accesso automatico dalla sessione SSO di Ateneo, come fa header.php (serve prima di decidere cosa mostrare)

use App\Pubblicazioni\AllegatiPubblicazioni;
use App\Pubblicazioni\Costanti;
use App\Pubblicazioni\ImpostazioniPubblicazioni;
use App\Pubblicazioni\LimitiCaricamento;
use App\Pubblicazioni\PassiRichiesta;
use App\Pubblicazioni\ServizioPubblicazioni;

$app = \App\Core\App::per($conn);
$sv = $app->get(ServizioPubblicazioni::class);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$utente = !empty($_SESSION['utente_id']) ? $app->get(\App\Auth\UtenteRepository::class)->perId((int)$_SESSION['utente_id']) : null;
$loggato = $utente !== null;
$puo = $loggato && $sv->puoInviare($utente);
$pagina = 'richiesta_pubblicazione.php';
$limiti = LimitiCaricamento::dalServer();
$oggi = $app->get(\App\Core\Orologio::class)->adesso()->format('Y-m-d');

// ── Invio ──
$errori = [];
$passo_iniziale = 1;
if ($puo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($limiti->invioTroppoGrande($_SERVER, $_POST, $_FILES)) {
        // PHP ha scartato tutto perché l'invio supera post_max_size: nessun dato da controllare, si riparte dal primo passo con un messaggio chiaro
        $errori = ['_' => $limiti->messaggioTroppoGrande()];
    } else {
        csrf_verify($_POST['csrf_token'] ?? '');
        $file = [
            'allegati' => AllegatiPubblicazioni::elenco($_FILES['allegati'] ?? []),
            'immagini' => AllegatiPubblicazioni::elenco($_FILES['immagini'] ?? []),
        ];
        $errori = PassiRichiesta::erroreHoLetto($_POST) + $sv->valida($_POST, $file);
        if (!$errori) {
            $esito = $sv->invia($utente, $_POST, $file);
            if ($esito['id'] > 0) {
                $quando = $app->get(\App\Core\Orologio::class)->adesso();
                $_SESSION['pubblicazione_inviata'] = ['id' => $esito['id'], 'quando' => $quando->format('Y-m-d H:i:s')];
                flash_set('Richiesta inviata il ' . $quando->format('d/m/Y \a\l\l\e H:i') . ': la riceve l\'ufficio comunicazione.', 'success');
                header('Location: ' . $pagina . '?inviata=1');
                exit;
            }
            $errori = $esito['errori'];
        }
        $passo_iniziale = PassiRichiesta::primoConErrore($errori) ?? 1;
    }
}
$e_post = $_SERVER['REQUEST_METHOD'] === 'POST' && $puo;
$appena_inviata = $puo && isset($_GET['inviata']) && isset($_SESSION['pubblicazione_inviata']);

// Errore accanto al campo (se c'è) e posto per quello che mette il browser mentre si compila
$err = fn(string $campo) => '<div class="rp-errore" role="alert" data-errore-per="' . $h($campo) . '">' . (isset($errori[$campo]) ? $h($errori[$campo]) : '') . '</div>';
$p = fn(string $k) => $e_post ? $h($_POST[$k] ?? '') : '';
$spuntato = fn(string $k) => $e_post && !empty($_POST[$k]);
$modalita_scelta = $e_post ? (string)($_POST['modalita'] ?? '') : '';

$page_cfg['titolo'] = 'Richiesta di pubblicazione';
require_once 'header.php';
$url_accedi = 'saml_login.php?redirect=' . urlencode($pagina);
$notifiche = $puo ? $app->get(ImpostazioniPubblicazioni::class)->indirizzi() : [];
?>
<style>
    .rp-errore { color: #b91c1c; font-weight: 700; font-size: .875rem; margin-top: .25rem; }
    .rp-errore:empty { display: none; }
    .rp-oltre { color: #b91c1c; font-weight: 700; }
    .rp-note { background: #f1f5f9; border-radius: 8px; padding: 12px 16px; }
    .rp-passo { margin-bottom: 1.75rem; padding-bottom: 1.25rem; border-bottom: 1px solid #e2e8f0; }
    .rp-passo:last-of-type { border-bottom: 0; }
    .rp-campo { margin-bottom: 1.1rem; }
    .rp-riep-sezione { border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px; margin-bottom: 10px; }
    .rp-riep-sezione dt { color: #475569; font-weight: 600; font-size: .85rem; }
    .rp-riep-sezione dd { margin: 0 0 .5rem 0; overflow-wrap: anywhere; }
    .rp-invalido { border-color: #b91c1c !important; }
</style>
<div class="container my-4" style="max-width: 860px;">
    <nav aria-label="Percorso" class="mb-3 small"><a href="index.php">Home</a> <span class="text-secondary mx-1">/</span> <span class="text-secondary">Richiesta di pubblicazione</span></nav>
    <h1 class="fw-bold h2 mb-2">Richiesta di pubblicazione</h1>
    <p class="text-secondary mb-3">Richiesta di pubblicazione su pagina web e canali social istituzionali DiBEST, indirizzata al Direttore.</p>
    <?php echo flash_html(); ?>

    <?php if (!$loggato): ?>
        <div class="alert alert-info d-flex flex-wrap align-items-center gap-2" role="status">
            <i class="fa fa-key" aria-hidden="true"></i>
            <span>Per compilare la richiesta devi fare l'accesso con le tue credenziali di Ateneo.</span>
            <a class="btn btn-sm btn-primary fw-bold ms-auto" href="<?php echo $h($url_accedi); ?>"><i class="fa fa-key me-1" aria-hidden="true"></i>Accedi per compilare</a>
        </div>

    <?php elseif (!$puo): ?>
        <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2" role="status">
            <i class="fa fa-lock" aria-hidden="true"></i>
            <span>Questa richiesta è riservata ai docenti.</span>
            <a class="btn btn-sm btn-outline-secondary fw-bold ms-auto" href="area_personale.php">La mia area personale</a>
        </div>

    <?php elseif ($appena_inviata): ?>
        <div class="card border-0 shadow-sm p-3 p-md-4 mb-3" style="border-radius:12px;">
            <div class="fw-bold fs-5 mb-1"><i class="fa fa-circle-check text-success me-1" aria-hidden="true"></i>Richiesta inviata</div>
            <p class="mb-2">La richiesta è arrivata all'ufficio comunicazione.<?php echo trim((string)($utente['email'] ?? '')) !== '' ? ' Ti abbiamo mandato la ricevuta per email, con la copia di quello che hai inviato.' : ''; ?></p>
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

        <form method="POST" action="<?php echo $h($pagina); ?>" enctype="multipart/form-data" id="rp-form" class="card border-0 shadow-sm p-3 p-md-4" style="border-radius:12px;"
              data-passo-iniziale="<?php echo (int)$passo_iniziale; ?>" data-oggi="<?php echo $h($oggi); ?>"
              data-max-allegati="<?php echo (int)Costanti::MAX_ALLEGATI; ?>" data-max-immagini="<?php echo (int)Costanti::MAX_IMMAGINI; ?>"
              data-max-byte="<?php echo (int)$limiti->fileMax(); ?>" data-max-descrizione="<?php echo (int)Costanti::MAX_DESCRIZIONE; ?>"
              data-max-sottotitolo="<?php echo (int)Costanti::MAX_SOTTOTITOLO; ?>" data-max-titolo="<?php echo (int)Costanti::MAX_TITOLO; ?>"
              data-tinymce-base="<?php echo $h(url_vendor('tinymce/6.8.6')); ?>" data-tinymce-it="<?php echo $h(url_vendor('tinymce/6.8.6/langs/it.js')); ?>">
            <?php csrf_field(); ?>

            <!-- Barra di avanzamento e pulsanti: li accende JavaScript; senza, i passi si vedono tutti uno sotto l'altro -->
            <div class="mb-3" id="rp-avanzamento" hidden>
                <div class="d-flex justify-content-between small fw-bold mb-1"><span id="rp-avanz-testo">Passo 1 di <?php echo count(PassiRichiesta::PASSI); ?></span><span id="rp-avanz-titolo" class="text-secondary"></span></div>
                <div class="progress" style="height:10px;" role="progressbar" aria-label="Avanzamento della richiesta" aria-valuemin="1" aria-valuemax="<?php echo count(PassiRichiesta::PASSI); ?>" aria-valuenow="1"><div class="progress-bar" id="rp-barra" style="width:14%"></div></div>
            </div>

            <!-- Passo 1 -->
            <section class="rp-passo" id="rp-passo-1" data-passo="1" data-titolo="<?php echo $h(PassiRichiesta::PASSI[1]); ?>" aria-labelledby="rp-tit-1">
                <h2 class="h4 fw-bold mb-3" id="rp-tit-1">1. <?php echo $h(PassiRichiesta::PASSI[1]); ?></h2>
                <div class="rp-note mb-3">
                    <div class="fw-bold mb-1">Note operative</div>
                    <ul class="mb-0">
                        <li><strong>Invio richieste:</strong> le richieste di pubblicazione su pagine web e social istituzionali devono essere inviate esclusivamente da questo modulo.</li>
                        <li><strong>Compilazione:</strong> compila con attenzione tutti i campi. Le informazioni che invii saranno pubblicate così come arrivano: controlla che il testo sia corretto e completo prima di inviare.</li>
                        <li><strong>Materiale illustrativo:</strong> le immagini da mettere insieme al testo devono essere in formato .jpg oppure .png. Non saranno estratte né pubblicate le immagini contenute dentro i file .pdf.</li>
                        <li><strong>Allegati:</strong> i file PDF che invii saranno pubblicati come allegati con il nome «Locandina/Brochure».</li>
                    </ul>
                </div>
                <div class="rp-campo">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="ho_letto" id="ho_letto" value="1" <?php echo $spuntato('ho_letto') ? 'checked' : ''; ?> required>
                        <label class="form-check-label fw-bold" for="ho_letto">Ho letto le note operative <span class="text-danger">*</span></label>
                    </div>
                    <?php echo $err('ho_letto'); ?>
                </div>
            </section>

            <!-- Passo 2 -->
            <section class="rp-passo" id="rp-passo-2" data-passo="2" data-titolo="<?php echo $h(PassiRichiesta::PASSI[2]); ?>" aria-labelledby="rp-tit-2">
                <h2 class="h4 fw-bold mb-3" id="rp-tit-2">2. <?php echo $h(PassiRichiesta::PASSI[2]); ?></h2>
                <div class="rp-campo">
                    <label class="form-label fw-bold" for="richiedente">Richiedente</label>
                    <input type="text" class="form-control" id="richiedente" value="<?php echo $h(ServizioPubblicazioni::nomeCognome($utente)); ?>" readonly aria-readonly="true">
                    <div class="form-text">Cognome e nome, presi dal tuo account: non si possono cambiare.</div>
                </div>
                <div class="rp-campo">
                    <label class="form-label fw-bold" for="data_evento">Data dell'evento <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" style="max-width:220px;" name="data_evento" id="data_evento" value="<?php echo $p('data_evento'); ?>" required>
                    <?php echo $err('data_evento'); ?>
                </div>
                <fieldset class="rp-campo">
                    <legend class="form-label fw-bold fs-6 mb-1">Modalità di svolgimento <span class="text-danger">*</span></legend>
                    <?php foreach (Costanti::MODALITA as $chiave => $etichetta): ?>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="modalita" id="modalita_<?php echo $h($chiave); ?>" value="<?php echo $h($chiave); ?>" <?php echo $modalita_scelta === $chiave ? 'checked' : ''; ?> required>
                            <label class="form-check-label" for="modalita_<?php echo $h($chiave); ?>"><?php echo $h($etichetta); ?></label>
                        </div>
                    <?php endforeach; ?>
                    <?php echo $err('modalita'); ?>
                </fieldset>
                <div class="rp-campo">
                    <label class="form-label fw-bold" for="url_docente">URL <span class="text-danger">*</span></label>
                    <input type="url" class="form-control" name="url_docente" id="url_docente" value="<?php echo $p('url_docente'); ?>" placeholder="https://" required>
                    <div class="form-text">L'indirizzo completo della pagina di cui chiedi la pubblicazione: deve iniziare con http:// o https://.</div>
                    <?php echo $err('url_docente'); ?>
                </div>
                <div class="rp-campo">
                    <label class="form-label fw-bold" for="altri_link_docente">URL/Link ad altre pagine <span class="fw-normal text-secondary">(facoltativo)</span></label>
                    <textarea class="form-control" name="altri_link_docente" id="altri_link_docente" rows="3" placeholder="https://"><?php echo $p('altri_link_docente'); ?></textarea>
                    <div class="form-text">Copia e incolla per esteso il link (URL) di ogni altra pagina di interesse: uno per riga.</div>
                    <?php echo $err('altri_link_docente'); ?>
                </div>
            </section>

            <!-- Passo 3 -->
            <section class="rp-passo" id="rp-passo-3" data-passo="3" data-titolo="<?php echo $h(PassiRichiesta::PASSI[3]); ?>" aria-labelledby="rp-tit-3">
                <h2 class="h4 fw-bold mb-3" id="rp-tit-3">3. <?php echo $h(PassiRichiesta::PASSI[3]); ?></h2>
                <div class="rp-campo">
                    <label class="form-label fw-bold" for="titolo">Titolo <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="titolo" id="titolo" value="<?php echo $p('titolo'); ?>" required>
                    <div class="form-text">Il titolo della notizia: compare sulla Homepage in anteprima.</div>
                    <?php echo $err('titolo'); ?>
                </div>
                <div class="rp-campo">
                    <label class="form-label fw-bold" for="sottotitolo">Sottotitolo <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="sottotitolo" id="sottotitolo" value="<?php echo $p('sottotitolo'); ?>" aria-describedby="aiuto-sottotitolo conta-sottotitolo" required>
                    <div class="form-text" id="aiuto-sottotitolo">Una frase brevissima ma che spieghi l'evento: al massimo <?php echo (int)Costanti::MAX_SOTTOTITOLO; ?> battute, spazi inclusi.</div>
                    <div class="small fw-bold" id="conta-sottotitolo" aria-live="polite" hidden><span class="rp-n">0</span> / <?php echo (int)Costanti::MAX_SOTTOTITOLO; ?></div>
                    <?php echo $err('sottotitolo'); ?>
                </div>
                <div class="rp-campo">
                    <label class="form-label fw-bold" for="descrizione">Breve descrizione <span class="text-danger">*</span></label>
                    <textarea class="form-control" name="descrizione" id="descrizione" rows="4" aria-describedby="aiuto-descrizione conta-descrizione" required><?php echo $p('descrizione'); ?></textarea>
                    <div class="form-text" id="aiuto-descrizione">Il testo che compare quando si apre la notizia. Scrivilo già formattato (corsivo, grassetto, punteggiatura…) e controlla che tutte le informazioni siano corrette. Al massimo <?php echo (int)Costanti::MAX_DESCRIZIONE; ?> battute, spazi inclusi.</div>
                    <div class="small fw-bold" id="conta-descrizione" aria-live="polite" hidden><span class="rp-n">0</span> / <?php echo (int)Costanti::MAX_DESCRIZIONE; ?></div>
                    <?php echo $err('descrizione'); ?>
                </div>
            </section>

            <!-- Passo 4 -->
            <section class="rp-passo" id="rp-passo-4" data-passo="4" data-titolo="<?php echo $h(PassiRichiesta::PASSI[4]); ?>" aria-labelledby="rp-tit-4">
                <h2 class="h4 fw-bold mb-3" id="rp-tit-4">4. <?php echo $h(PassiRichiesta::PASSI[4]); ?></h2>
                <fieldset class="rp-campo">
                    <legend class="form-label fw-bold fs-6 mb-1">Evento rivolto a <span class="text-danger">*</span> <span class="fw-normal text-secondary">(scegline almeno uno)</span></legend>
                    <?php foreach (['per_cittadinanza' => 'Cittadinanza', 'per_studenti' => 'Studenti universitari', 'per_docenti' => 'Docenti', 'per_pta' => 'PTA (personale tecnico amministrativo)'] as $chiave => $etichetta): ?>
                        <div class="form-check">
                            <input class="form-check-input rp-destinatario" type="checkbox" name="<?php echo $h($chiave); ?>" id="<?php echo $h($chiave); ?>" value="1" <?php echo $spuntato($chiave) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="<?php echo $h($chiave); ?>"><?php echo $h($etichetta); ?></label>
                        </div>
                    <?php endforeach; ?>
                    <?php echo $err('destinatari'); ?>
                </fieldset>
                <div class="alert alert-info" id="rp-pe" role="note">
                    <div class="fw-bold mb-1"><i class="fa fa-people-group me-1" aria-hidden="true"></i>Public Engagement</div>
                    <p class="mb-2">Se l'evento è rivolto alla cittadinanza, il docente (organizzatore o partecipante a iniziative su invito di altri enti) deve registrare l'evento come Public Engagement a questo indirizzo:
                        <a href="https://pe.unical.it" target="_blank" rel="noopener noreferrer">https://pe.unical.it</a>.</p>
                    <p class="small mb-0">Il Public Engagement è l'insieme delle attività organizzate dall'Ateneo o dalle sue strutture, senza scopo di lucro, con valore educativo, culturale e di sviluppo della società, rivolte a un pubblico non accademico.</p>
                </div>
            </section>

            <!-- Passo 5 -->
            <section class="rp-passo" id="rp-passo-5" data-passo="5" data-titolo="<?php echo $h(PassiRichiesta::PASSI[5]); ?>" aria-labelledby="rp-tit-5">
                <h2 class="h4 fw-bold mb-3" id="rp-tit-5">5. <?php echo $h(PassiRichiesta::PASSI[5]); ?></h2>
                <p class="small text-secondary"><i class="fa fa-weight-hanging me-1" aria-hidden="true"></i><?php echo $h($limiti->frase()); ?></p>
                <div class="alert <?php echo $errori ? 'alert-warning fw-bold' : 'alert-light border small'; ?>" role="note"><i class="fa fa-paperclip me-1" aria-hidden="true"></i>I file non restano scelti se la pagina si riapre con un errore: <?php echo $errori ? 'devi scegliere di nuovo gli allegati e le immagini qui sotto.' : 'in quel caso vanno scelti di nuovo.'; ?></div>
                <div class="rp-campo">
                    <label class="form-label fw-bold" for="allegati">Allegati da pubblicare <span class="text-danger">*</span></label>
                    <input type="file" class="form-control" name="allegati[]" id="allegati" multiple accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" aria-describedby="aiuto-allegati" required>
                    <div class="form-text" id="aiuto-allegati">Per esempio la locandina o le foto. File PDF, JPG o PNG, da 1 a <?php echo (int)Costanti::MAX_ALLEGATI; ?>. Ti consigliamo di dare al file lo stesso nome con cui vuoi che compaia nella notizia (per esempio «Locandina Notte dei ricercatori.pdf»).</div>
                    <?php echo $err('allegati'); ?>
                </div>
                <div class="rp-campo">
                    <label class="form-label fw-bold" for="immagini">Ulteriori immagini <span class="fw-normal text-secondary">(facoltativo)</span></label>
                    <input type="file" class="form-control" name="immagini[]" id="immagini" multiple accept=".jpg,.jpeg,.png,image/jpeg,image/png" aria-describedby="aiuto-immagini">
                    <div class="form-text" id="aiuto-immagini">Se vuoi che nell'anteprima compaia un'immagine, allega una foto (file .jpg o .png), fino a <?php echo (int)Costanti::MAX_IMMAGINI; ?>. Altrimenti sarà mostrata una parte della locandina.</div>
                    <?php echo $err('immagini'); ?>
                </div>
            </section>

            <!-- Passo 6 -->
            <section class="rp-passo" id="rp-passo-6" data-passo="6" data-titolo="<?php echo $h(PassiRichiesta::PASSI[6]); ?>" aria-labelledby="rp-tit-6">
                <h2 class="h4 fw-bold mb-3" id="rp-tit-6">6. <?php echo $h(PassiRichiesta::PASSI[6]); ?></h2>
                <div class="rp-campo">
                    <label class="form-label fw-bold" for="tag">Tag <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="tag" id="tag" value="<?php echo $p('tag'); ?>" placeholder="DIBEST, notizie, avvisi" required>
                    <div class="form-text">Scrivi i tag che aiutano a trovare la notizia, separati da virgola. Per esempio: DIBEST, notizie, avvisi.</div>
                    <?php echo $err('tag'); ?>
                </div>
                <div class="rp-campo">
                    <label class="form-label fw-bold" for="data_inizio_pubblicazione">Data inizio pubblicazione <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" style="max-width:220px;" name="data_inizio_pubblicazione" id="data_inizio_pubblicazione" value="<?php echo $p('data_inizio_pubblicazione'); ?>" min="<?php echo $h($oggi); ?>" required>
                    <div class="form-text">Da quando la notizia può essere pubblicata: non può essere una data passata.</div>
                    <?php echo $err('data_inizio_pubblicazione'); ?>
                </div>
                <div class="rp-campo">
                    <label class="form-label fw-bold" for="note">Note <span class="text-danger">*</span></label>
                    <textarea class="form-control" name="note" id="note" rows="3" required><?php echo $p('note'); ?></textarea>
                    <div class="form-text">Quello che l'ufficio comunicazione deve sapere. Se non hai nulla da aggiungere, scrivi «nessuna».</div>
                    <?php echo $err('note'); ?>
                </div>
            </section>

            <!-- Passo 7 -->
            <section class="rp-passo" id="rp-passo-7" data-passo="7" data-titolo="<?php echo $h(PassiRichiesta::PASSI[7]); ?>" aria-labelledby="rp-tit-7">
                <h2 class="h4 fw-bold mb-3" id="rp-tit-7">7. <?php echo $h(PassiRichiesta::PASSI[7]); ?></h2>
                <p class="mb-2">Controlla quello che hai scritto nei passi qui sopra. Una volta inviata, la richiesta non si può più modificare dal portale<?php echo $notifiche ? ': per correzioni scrivi a ' . $h($notifiche[0]) : ''; ?>.</p>
                <div id="rp-riepilogo" aria-live="polite"></div>
                <?php echo $err('_'); ?>
                <button type="submit" class="btn btn-success fw-bold mt-2" id="rp-invia"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Invia la richiesta</button>
            </section>

            <div class="d-flex gap-2 align-items-center" id="rp-nav" hidden>
                <button type="button" class="btn btn-outline-secondary fw-bold" id="rp-indietro"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Indietro</button>
                <button type="button" class="btn btn-primary fw-bold ms-auto" id="rp-avanti">Avanti<i class="fa fa-arrow-right ms-1" aria-hidden="true"></i></button>
            </div>
            <div class="form-text mt-2">Inviando la richiesta dichiari di aver letto l'<a href="privacy.php" target="_blank" rel="noopener">informativa sul trattamento dei dati personali</a>.</div>
        </form>
        <script src="<?php echo $h(url_vendor('tinymce/6.8.6/tinymce.min.js')); ?>"></script>
        <script src="assets/js/richiesta-pubblicazione.js?v=2"></script>
    <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
