<?php

/**
 * Structure normalisée (migration_2026_10_normalisation) : une seule identité par formateur,
 * mots de passe toujours hachés, téléphone unique, colonnes dérivées calculées par la base,
 * dates des sessions et évaluations contrôlées.
 */
final class NormalisationTest extends ControllerTestCase
{
    /* ── Formateurs : l'identité vit dans le compte ─────────────────────── */

    public function testLaVueReunitFicheEtCompte(): void
    {
        $row = Database::getInstance()->query('SELECT * FROM v_formateurs WHERE id = 1')->fetch();
        self::assertSame('Camara', $row['nom']);
        self::assertSame('FOR-T-001', $row['matricule']);
        self::assertSame(1, (int) $row['actif']);
    }

    public function testCreerUnFormateurCreeLeCompteEtUneFicheSansDoublon(): void
    {
        [$code, $json] = $this->request(fn() => (new FormateurController())->create(), [
            'matricule' => 'FOR-T-009', 'nom' => 'Keita', 'prenom' => 'Awa', 'password' => 'secret-initial',
            'email' => 'awa.keita@test.local', 'telephone' => '621 11 22 33',
        ]);
        self::assertSame(201, $code, json_encode($json, JSON_UNESCAPED_UNICODE));

        $user = Database::getInstance()->query("SELECT * FROM users WHERE id = {$json['data']['user_id']}")->fetch();
        self::assertSame('Keita', $user['nom']);
        self::assertTrue(password_verify('secret-initial', $user['password']), 'Le mot de passe est haché');
        $columns = Database::getInstance()->query('SHOW COLUMNS FROM formateurs')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([], array_intersect(['nom', 'prenom', 'password', 'email', 'telephone', 'actif'], $columns));

        [$codeList, $list] = $this->request(fn() => (new FormateurController())->getAll());
        self::assertSame(200, $codeList);
        $created = current(array_filter($list['data'], fn($f) => (int) $f['id'] === (int) $json['data']['id']));
        self::assertSame('Awa', $created['prenom'], 'L’API renvoie toujours les mêmes champs');
        self::assertArrayNotHasKey('password', $created);
    }

    public function testModifierUnFormateurModifieSonCompte(): void
    {
        [$code] = $this->request(fn() => (new FormateurController())->update(1), ['nom' => 'Camara-Diallo', 'password' => 'nouveau-secret']);
        self::assertSame(200, $code);
        $user = Database::getInstance()->query('SELECT nom, password, token_version FROM users WHERE id = 4')->fetch();
        self::assertSame('Camara-Diallo', $user['nom']);
        self::assertTrue(password_verify('nouveau-secret', $user['password']));
        self::assertSame(1, (int) $user['token_version'], 'Un nouveau mot de passe ferme les sessions du compte');
    }

    public function testDesactiverUnFormateurDesactiveSonCompte(): void
    {
        [$code, $json] = $this->request(fn() => (new FormateurController())->toggleActif(1));
        self::assertSame(200, $code);
        self::assertSame(0, $json['data']['actif']);
        self::assertSame(0, (int) $this->scalar('SELECT actif FROM users WHERE id = 4'));
    }

    public function testSupprimerLeCompteSupprimeLaFicheSansPlanning(): void
    {
        $this->scalar("INSERT INTO users (id, nom, prenom, email, password, role_id) VALUES (20, 'Sans', 'Planning', 'sp@test.local', 'x', 4)");
        $this->scalar("INSERT INTO formateurs (id, user_id, matricule) VALUES (20, 20, 'FOR-T-020')");
        $this->scalar('DELETE FROM users WHERE id = 20');
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM formateurs WHERE id = 20'));
    }

    /* ── Comptes ────────────────────────────────────────────────────────── */

    public function testUnCompteCreeEnregistreUnMotDePasseHache(): void
    {
        [$code, $json] = $this->request(fn() => (new UserController())->create(), [
            'nom' => 'Sylla', 'prenom' => 'Ibrahima', 'email' => 'i.sylla@test.local', 'password' => 'mot-de-passe-1', 'role_id' => 2,
        ]);
        self::assertSame(201, $code, json_encode($json, JSON_UNESCAPED_UNICODE));
        $hash = (string) $this->scalar('SELECT password FROM users WHERE id = ?', [$json['data']['id']]);
        self::assertNotSame('mot-de-passe-1', $hash);
        self::assertTrue(password_verify('mot-de-passe-1', $hash));
    }

    public function testUnNumeroDeTelephoneNAppartientQuAUnCompte(): void
    {
        [$code, $json] = $this->request(fn() => (new UserController())->create(), [
            'nom' => 'Doublon', 'prenom' => 'Tel', 'telephone' => '620-00-00-02', 'password' => 'mot-de-passe-1', 'role_id' => 2,
        ]);
        self::assertSame(409, $code);
        self::assertStringContainsString('téléphone', $json['error']);

        $this->expectException(PDOException::class);
        $this->scalar("INSERT INTO users (nom, prenom, telephone, password, role_id) VALUES ('X', 'Y', '620000002', 'x', 2)");
    }

    /* ── Colonnes dérivées et déclencheurs ──────────────────────────────── */

    public function testLeNombreDePassagesSuitLInstantaneDeConfiguration(): void
    {
        $this->scalar("UPDATE sessions SET config_formation = JSON_OBJECT('passages_semaine', 3) WHERE id = 1");
        self::assertSame(3, (int) $this->scalar('SELECT passages_semaine FROM sessions WHERE id = 1'));
    }

    public function testUneInscriptionPrendToujoursLaSessionDeSonCreneau(): void
    {
        $this->scalar("INSERT INTO inscriptions (id_auditeur, id_annee, id_session, id_emploi_du_temps, id_departement, date_inscription) VALUES (4, 1, 2, 1, 1, '2026-09-10')");
        $row = Database::getInstance()->query('SELECT id_session, id_annee FROM inscriptions WHERE id_auditeur = 4')->fetch();
        self::assertSame(1, (int) $row['id_session'], 'La session saisie (2) est remplacée par celle du créneau (1)');
    }

    public function testUnCreneauPrendToujoursLaSessionDeSonGroupe(): void
    {
        $this->scalar("INSERT INTO groupe_passages (id_groupe, jour_semaine, heure_debut, heure_fin) VALUES (1, 3, '08:00:00', '12:00:00')");
        $this->scalar("INSERT INTO emplois_du_temps (id_annee, id_session, id_groupe, id_salle, id_formateur, jour_semaine, heure_debut, heure_fin) VALUES (1, 2, 1, 1, 1, 3, '08:00:00', '12:00:00')");
        self::assertSame(1, (int) $this->scalar('SELECT id_session FROM emplois_du_temps WHERE id_groupe = 1 AND jour_semaine = 3'));
    }

    public function testUneSessionHorsDeSonAnneeEstRefusee(): void
    {
        [$code, $json] = $this->request(fn() => (new SessionController())->create(), [
            'id_annee' => 1, 'id_type_formation' => 1, 'id_niveau' => 3, 'code' => 'S-HORS', 'libelle' => 'Hors année',
            'date_debut' => '2027-05-03', 'date_fin' => '2027-09-30', 'reprendre_configuration' => false,
        ]);
        self::assertSame(409, $code);
        self::assertStringContainsString('comprises dans l’année scolaire', $json['error']);

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('année scolaire');
        $this->scalar("UPDATE sessions SET date_fin = '2027-09-30' WHERE id = 2");
    }

    public function testUneSessionNePeutPasLaisserSesEvaluationsDehors(): void
    {
        [$code, $json] = $this->request(fn() => (new SessionController())->update(1), ['date_debut' => '2026-09-07', 'date_fin' => '2026-11-30']);
        self::assertSame(409, $code);
        self::assertStringContainsString('évaluation', $json['error']);
    }

    public function testUneAnneeNePeutPasEtreRaccourcieSousSesSessions(): void
    {
        [$code, $json] = $this->request(fn() => (new AnneeScolaireController())->update(1), ['date_debut' => '2026-09-01', 'date_fin' => '2027-03-01']);
        self::assertSame(409, $code);
        self::assertStringContainsString('S2-INT', $json['error']);
    }

    public function testUneEvaluationHorsDeSaSessionEstRefusee(): void
    {
        [$code, $json] = $this->request(fn() => (new EvaluationController())->update(1), ['date_evaluation' => '2027-02-01']);
        self::assertSame(409, $code);
        self::assertStringContainsString('période de la session', $json['error']);

        $this->expectException(PDOException::class);
        $this->scalar("UPDATE evaluations SET date_evaluation = '2027-02-01' WHERE id = 1");
    }

    public function testLesJoursAutorisesSontUneListeFermee(): void
    {
        $this->expectException(PDOException::class);
        $this->scalar("UPDATE types_formations SET jours_autorises = '1,6' WHERE id = 1");
    }

    public function testLesColonnesInutilesOntDisparu(): void
    {
        $db = Database::getInstance();
        $has = fn(string $table, string $column) => (bool) $db->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'")->fetch();
        self::assertFalse($has('presences', 'jour_semaine_presence'));
        self::assertFalse($has('regles_evaluation', 'passage_automatique'));
        self::assertFalse($has('types_formations', 'config_updated_at'));
        self::assertFalse($has('refresh_tokens', 'last_used_at'));
    }
}
