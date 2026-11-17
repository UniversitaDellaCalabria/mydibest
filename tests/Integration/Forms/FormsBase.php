<?php

declare(strict_types=1);

namespace Tests\Integration\Forms;

use App\Anagrafi\ClientApiAteneo;
use App\Anagrafi\ServizioPersone;
use App\Auth\UtenteRepository;
use App\Core\Container;
use App\Core\Database;
use App\Core\Orologio;
use App\Core\Sito;
use App\Didattica\ScelteAnagrafe;
use App\Forms\FormRepository;
use App\Forms\RispostaRepository;
use App\Forms\ServizioForms;
use App\Infrastructure\Storage\Upload;
use App\Sistema\FileEnv;
use Tests\Doppi\AnagrafiClientApiFinto;
use Tests\Doppi\OrologioFisso;
use Tests\Integration\DatabaseDiProva;

/** Base dei test di integrazione di MyForms: le tabelle di forms, il contenitore e una cartella temporanea per gli allegati. */
abstract class FormsBase extends DatabaseDiProva
{
    protected Container $c;
    protected string $cartella;
    protected string $envFile;

    protected function tabelle(): array
    {
        return [
            'forms' => \App\Core\Schema\Definizioni::tabelle()['forms'],
            'forms_gestori' => \App\Core\Schema\Definizioni::tabelle()['forms_gestori'],
            'forms_risposte' => \App\Core\Schema\Definizioni::tabelle()['forms_risposte'],
            'utenti' => "CREATE TABLE utenti (id INT PRIMARY KEY, nome VARCHAR(100) DEFAULT '', cognome VARCHAR(100) DEFAULT '', email VARCHAR(150) DEFAULT '', ruolo_id INT DEFAULT 5, ruoli_secondari VARCHAR(100) DEFAULT '', matricola_studente VARCHAR(20) DEFAULT '', persona_id VARCHAR(80) DEFAULT NULL, ultimo_accesso DATETIME DEFAULT NULL)",
            'ruoli' => 'CREATE TABLE ruoli (id INT PRIMARY KEY, nome VARCHAR(100))',
            'personale_ateneo' => "CREATE TABLE personale_ateneo (id VARCHAR(80) PRIMARY KEY, nome VARCHAR(100) DEFAULT '', cognome VARCHAR(100) DEFAULT '', email VARCHAR(150) DEFAULT '', ruolo VARCHAR(100) DEFAULT '', docente TINYINT NOT NULL DEFAULT 0, attivo TINYINT NOT NULL DEFAULT 1)",
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Le colonne aggiunte dopo la creazione della tabella (v126: compiti del cron) si applicano come fa la migrazione, con gli ALTER veri
        foreach (\App\Core\Schema\Definizioni::colonne()['forms'] ?? [] as $alter) {
            $this->db->esegui('ALTER TABLE forms ' . (is_array($alter) ? $alter[0] : $alter));
        }
        $this->cartella = 'cache/prove_forms_' . bin2hex(random_bytes(4)) . '/';
        $radice = dirname(__DIR__, 3);
        $c = new Container();
        $c->istanza(Container::class, $c);
        $c->istanza(Database::class, $this->db);
        $c->istanza(Orologio::class, new OrologioFisso('2026-11-17 10:00:00'));
        $c->istanza(Sito::class, new Sito($radice, 'https://portale.test/eventi'));
        $c->istanza(ClientApiAteneo::class, new AnagrafiClientApiFinto());
        $this->envFile = tempnam(sys_get_temp_dir(), 'env') ?: '';
        file_put_contents($this->envFile, "CIFRATURA_CHIAVE=prova-chiave-di-almeno-16-caratteri\n");
        $c->istanza(FileEnv::class, new FileEnv($this->envFile));
        $c->set(ServizioForms::class, fn (Container $c): ServizioForms => new ServizioForms(
            $c->get(FormRepository::class), $c->get(RispostaRepository::class), $c->get(Upload::class), $c->get(Sito::class),
            $c->get(ScelteAnagrafe::class), $c->get(Database::class), $c->get(FileEnv::class), $c->get(Orologio::class),
            $c->get(ServizioPersone::class), $c->get(UtenteRepository::class), $c->get(\App\Auth\GruppiUtente::class), $this->cartella
        ));
        $this->c = $c;
        $this->db->esegui("INSERT INTO utenti (id, nome, cognome, email, ruolo_id, ruoli_secondari, matricola_studente) VALUES
            (1, 'Ada', 'Admin', 'admin@x.it', 1, '', ''),
            (2, 'Luca', 'Studente', 'luca@x.it', 3, '', '12345'),
            (3, 'Maria', 'Docente', 'maria@x.it', 5, '6', ''),
            (4, 'Paolo', 'Secondo', 'paolo@x.it', 3, '', '54321')");
    }

    protected function tearDown(): void
    {
        $dir = dirname(__DIR__, 3) . '/' . $this->cartella;
        foreach (glob($dir . '{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($dir);
        @unlink($this->envFile);
    }

    /**
     * @template T of object
     * @param class-string<T> $classe
     * @return T
     */
    protected function servizio(string $classe): object
    {
        return $this->c->get($classe);
    }

    /** Nuovo form (in «bozza»): titolo, campi e opzioni. @param list<array<string, mixed>> $campi @param array<string, mixed> $opzioni */
    protected function form(string $titolo, array $campi, array $opzioni = []): int
    {
        $dati = array_merge([
            'titolo' => $titolo, 'campi_json' => json_encode($campi, JSON_UNESCAPED_UNICODE), 'pubblico_json' => json_encode(['profili' => ['registrati']]),
            'una_per_persona' => 1, 'proprietario_id' => 1,
        ], $opzioni);

        return $this->servizio(ServizioForms::class)->crea($dati);
    }

    /** I dati di un form già salvato, nella forma che modifica() si aspetta, con le sovrascritture indicate (es. il periodo). @param array<string, mixed> $form @param array<string, mixed> $sovrascrivi */
    protected function datiModifica(array $form, array $sovrascrivi = []): array
    {
        return array_merge([
            'titolo' => $form['titolo'], 'descrizione' => $form['descrizione'], 'campi_json' => $form['campi_json'], 'pubblico_json' => $form['pubblico_json'],
            'aperta_dal' => $form['aperta_dal'], 'aperta_al' => $form['aperta_al'], 'una_per_persona' => $form['una_per_persona'], 'modificabile' => $form['modificabile'],
            'anonima' => $form['anonima'], 'max_risposte' => $form['max_risposte'], 'messaggio_fine' => $form['messaggio_fine'], 'email_notifica' => $form['email_notifica'],
            'ricevuta_email' => $form['ricevuta_email'], 'conserva_mesi' => $form['conserva_mesi'],
        ], $sovrascrivi);
    }
}
