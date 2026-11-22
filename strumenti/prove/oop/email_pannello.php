<?php
// Prove dei testi delle email cambiati dal pannello (Sistema → Testi delle email), sul database di prova con lo schema vero:
// chi salva un testo lo trova nell'email che parte subito dopo, anche se a salvare e a spedire sono due richieste diverse (due oggetti nuovi,
// senza niente in comune se non il database), e dopo «Torna all'originale» riparte il testo del catalogo.
// Le email non partono: un finto Mailer le raccoglie. Si lancia da solo con: PROVE_SOLO=email_pannello php strumenti/prove/esegui.php
sezione("Email del pannello: il testo salvato è quello che parte, anche in una richiesta nuova");

$db_ep = \App\Core\Database::per($conn);
$conn->query("DELETE FROM email_modelli");
$sistema_ep = $conn->query("SELECT email_conferma_oggetto AS o, email_conferma_corpo AS c FROM impostazioni_sistema WHERE id = 1")->fetch_assoc();

// Un finto Mailer che ricorda le email; ogni «richiesta» costruisce ModelliEmail e InvioEmail da capo
$posta_ep = new class implements \App\Infrastructure\Mail\Mailer {
    /** @var list<array{a: string, oggetto: string, corpo: string}> */
    public array $inviate = [];
    public function invia(string $a, string $oggetto, string $corpoHtml, ?string $colore = null, array $allegati = []): bool
    {
        $this->inviate[] = ['a' => $a, 'oggetto' => $oggetto, 'corpo' => $corpoHtml];
        return true;
    }
    public function ultimoErrore(): string { return ''; }
};
$modelli_ep = fn() => new \App\Email\ModelliEmail($db_ep);
$spedisci_ep = function (callable $invia) use ($posta_ep, $modelli_ep): ?array {
    $posta_ep->inviate = [];
    $invia(new \App\Email\InvioEmail($posta_ep, $modelli_ep()));
    return $posta_ep->inviate[0] ?? null;
};
$dati_ep = ['tutor' => 'Maria', 'ore' => '38'];

// 1. Prima di ogni modifica parte il testo del catalogo
$m_ep = $spedisci_ep(fn($i) => $i->invia('maria@example.org', 'tutorato_attivita_completate', $dati_ep));
prova($m_ep !== null && $m_ep['oggetto'] === 'Tutorato: attività completate' && str_contains($m_ep['corpo'], 'Maria'), "senza modifiche: parte il testo originale del catalogo");

// 2. Richiesta A salva, richiesta B (oggetti nuovi) spedisce: nell'email c'è il testo salvato
$err_ep = $modelli_ep()->salva('tutorato_attivita_completate', 'Attività finite per {{tutor}}', "Ciao {{tutor}},\n\nhai fatto **{{ore}} ore**.", '', 'Admin Prova');
prova($err_ep === [], "salva un testo cambiato dal pannello senza errori");
prova((int) $conn->query("SELECT COUNT(*) FROM email_modelli WHERE chiave = 'tutorato_attivita_completate'")->fetch_row()[0] === 1, "il testo salvato sta nella tabella email_modelli (una riga)");
$m_ep = $spedisci_ep(fn($i) => $i->invia('maria@example.org', 'tutorato_attivita_completate', $dati_ep));
prova($m_ep !== null && $m_ep['oggetto'] === 'Attività finite per Maria' && $m_ep['corpo'] === '<p>Ciao Maria,</p><p>hai fatto <strong>38 ore</strong>.</p>', "l'email che parte subito dopo ha il testo salvato (oggetto e corpo), letto da un oggetto nuovo");
$mod_ep = $modelli_ep()->modello('tutorato_attivita_completate');
prova($mod_ep['modificato'] === true && $mod_ep['aggiornato_da'] === 'Admin Prova', "il modello risulta modificato e dice da chi");

// 3. Torna all'originale: la richiesta successiva spedisce il testo del catalogo
$modelli_ep()->ripristina('tutorato_attivita_completate');
$m_ep = $spedisci_ep(fn($i) => $i->invia('maria@example.org', 'tutorato_attivita_completate', $dati_ep));
prova($m_ep !== null && $m_ep['oggetto'] === 'Tutorato: attività completate', "dopo «Torna all'originale» riparte il testo del catalogo (richiesta nuova)");
prova((int) $conn->query("SELECT COUNT(*) FROM email_modelli WHERE chiave = 'tutorato_attivita_completate'")->fetch_row()[0] === 0, "e la riga della modifica non c'è più");

// 4. Email facoltativa degli eventi: mai attivata = non parte; attivata = parte col testo salvato; tolta la spunta = non parte più
$m_ep = $spedisci_ep(fn($i) => $i->inviaHtml('maria@example.org', 'evento_attestato', ['{NOME}' => 'Maria']));
prova($m_ep === null, "email facoltativa mai attivata: non parte");
$err_ep = $modelli_ep()->salva('evento_attestato', 'Attestato pronto', '<p>Ciao {NOME}</p>', '', 'Admin Prova', true);
$m_ep = $spedisci_ep(fn($i) => $i->inviaHtml('maria@example.org', 'evento_attestato', ['{NOME}' => 'Maria']));
prova($err_ep === [] && $m_ep !== null && $m_ep['oggetto'] === 'Attestato pronto' && $m_ep['corpo'] === '<p>Ciao Maria</p>', "email facoltativa attivata dal pannello: parte col testo salvato");
$modelli_ep()->salva('evento_attestato', 'Attestato pronto', '<p>Ciao {NOME}</p>', '', 'Admin Prova', false);
$m_ep = $spedisci_ep(fn($i) => $i->inviaHtml('maria@example.org', 'evento_attestato', ['{NOME}' => 'Maria']));
prova($m_ep === null, "tolta la spunta dal pannello: non parte più");
$modelli_ep()->ripristina('evento_attestato');

// 5. Il testo scritto prima nelle impostazioni di sistema si importa una volta; dopo «Torna all'originale» non torna
$conn->query("UPDATE impostazioni_sistema SET email_conferma_oggetto = 'Ok {TITOLO_EVENTO}', email_conferma_corpo = '<p>Ciao {NOME}!</p>' WHERE id = 1");
$conn->query("DELETE FROM email_modelli");
$m_ep = $spedisci_ep(fn($i) => $i->inviaHtml('maria@example.org', 'evento_conferma', ['{NOME}' => 'Maria', '{COGNOME}' => 'Verdi', '{TITOLO_EVENTO}' => 'Giornata']));
prova($m_ep !== null && $m_ep['oggetto'] === 'Ok Giornata' && $m_ep['corpo'] === '<p>Ciao Maria!</p>', "il testo già scritto nelle impostazioni di sistema vale la prima volta");
$modelli_ep()->ripristina('evento_conferma');
$m_ep = $spedisci_ep(fn($i) => $i->inviaHtml('maria@example.org', 'evento_conferma', ['{NOME}' => 'Maria', '{COGNOME}' => 'Verdi', '{TITOLO_EVENTO}' => 'Giornata']));
prova($m_ep !== null && $m_ep['oggetto'] === 'Conferma Prenotazione Eventi' && str_contains($m_ep['corpo'], 'Prenotazione confermata per <strong>Giornata</strong>'), "dopo «Torna all'originale» il testo vecchio di Sistema non torna: parte quello del catalogo");

// Si rimette tutto com'era
$ok_ep = $conn->prepare("UPDATE impostazioni_sistema SET email_conferma_oggetto = ?, email_conferma_corpo = ? WHERE id = 1");
$o_ep = (string) ($sistema_ep['o'] ?? ''); $c_ep = $sistema_ep['c'] ?? null;
$ok_ep->bind_param('ss', $o_ep, $c_ep);
$ok_ep->execute();
$conn->query("DELETE FROM email_modelli");
