<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/Database.php';

try {
    $db = Database::getInstance();

    // 1. Departements
    $departements = [
        ['code' => 'ANG', 'libelle' => 'Département d\'Anglais'],
        ['code' => 'FRA', 'libelle' => 'Département de Français'],
        ['code' => 'ESP', 'libelle' => 'Département d\'Espagnol'],
        ['code' => 'ALL', 'libelle' => 'Département d\'Allemand'],
        ['code' => 'CHI', 'libelle' => 'Département de Chinois'],
    ];
    $stmtDept = $db->prepare("INSERT INTO departements (code, libelle) VALUES (:code, :libelle) ON DUPLICATE KEY UPDATE libelle = VALUES(libelle)");
    foreach ($departements as $d) {
        $stmtDept->execute($d);
    }

    // 2. Niveaux
    $niveaux = [
        ['libelle' => 'Débutant (A1)'],
        ['libelle' => 'Élémentaire (A2)'],
        ['libelle' => 'Intermédiaire (B1)'],
        ['libelle' => 'Intermédiaire Avancé (B2)'],
        ['libelle' => 'Avancé (C1)'],
        ['libelle' => 'Maîtrise (C2)'],
    ];
    $stmtNiv = $db->prepare("INSERT INTO niveaux (libelle) VALUES (:libelle) ON DUPLICATE KEY UPDATE libelle = VALUES(libelle)");
    foreach ($niveaux as $n) {
        $stmtNiv->execute($n);
    }

    // 3. Types de formation
    $types = [
        ['libelle' => 'Formation Intensive'],
        ['libelle' => 'Formation Standard'],
        ['libelle' => 'Formation Weekend'],
        ['libelle' => 'Formation en Ligne'],
        ['libelle' => 'Formation Professionnelle'],
    ];
    $stmtType = $db->prepare("INSERT INTO types_formations (libelle) VALUES (:libelle) ON DUPLICATE KEY UPDATE libelle = VALUES(libelle)");
    foreach ($types as $t) {
        $stmtType->execute($t);
    }

    // 4. Annees scolaires
    $annees = [
        ['libelle' => '2024-2025', 'date_debut' => '2024-09-01', 'date_fin' => '2025-06-30', 'statut' => 1],
        ['libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'statut' => 0],
    ];
    $stmtAnnee = $db->prepare("INSERT INTO annees_scolaires (libelle, date_debut, date_fin, statut) VALUES (:libelle, :date_debut, :date_fin, :statut) ON DUPLICATE KEY UPDATE date_debut=VALUES(date_debut), date_fin=VALUES(date_fin), statut=VALUES(statut)");
    foreach ($annees as $a) {
        $stmtAnnee->execute($a);
    }

    // 5. Roles
    $roles = [
        ['code' => 'admin', 'nom' => 'Administrateur'],
        ['code' => 'scolarite', 'nom' => 'Service Scolarité'],
        ['code' => 'formateur', 'nom' => 'Formateur'],
        ['code' => 'secretaire', 'nom' => 'Secrétaire'],
    ];
    $stmtRole = $db->prepare("INSERT INTO roles (code, nom) VALUES (:code, :nom) ON DUPLICATE KEY UPDATE nom = VALUES(nom)");
    foreach ($roles as $r) {
        $stmtRole->execute($r);
    }

    // 6. Salles
    $salles = [
        ['code' => 'S01', 'libelle' => 'Salle 01', 'capacite' => 20, 'statut' => 'disponible'],
        ['code' => 'S02', 'libelle' => 'Salle 02', 'capacite' => 15, 'statut' => 'disponible'],
        ['code' => 'LAB01', 'libelle' => 'Laboratoire Multimédia 01', 'capacite' => 30, 'statut' => 'disponible'],
    ];
    $stmtSalle = $db->prepare("INSERT INTO salles (code, libelle, capacite, statut) VALUES (:code, :libelle, :capacite, :statut) ON DUPLICATE KEY UPDATE capacite=VALUES(capacite), statut=VALUES(statut)");
    foreach ($salles as $s) {
        $stmtSalle->execute($s);
    }

    // 7. Sessions
    $anneeId = $db->query("SELECT id FROM annees_scolaires WHERE statut = 1 LIMIT 1")->fetchColumn();
    $typeId1 = $db->query("SELECT id FROM types_formations ORDER BY id ASC LIMIT 1")->fetchColumn();
    $niveauId1 = $db->query("SELECT id FROM niveaux ORDER BY id ASC LIMIT 1")->fetchColumn();
    $niveauId2 = $db->query("SELECT id FROM niveaux ORDER BY id ASC LIMIT 1 OFFSET 1")->fetchColumn() ?: $niveauId1;

    $sessions = [
        [
            'id_annee' => $anneeId,
            'id_type_formation' => $typeId1,
            'id_niveau' => $niveauId1,
            'code' => 'ANG-A1-2425',
            'libelle' => 'Session Anglais Débutant Automne',
            'date_debut' => '2024-10-01',
            'date_fin' => '2025-01-15',
            'statut' => 'en_cours'
        ],
        [
            'id_annee' => $anneeId,
            'id_type_formation' => $typeId1,
            'id_niveau' => $niveauId2,
            'code' => 'ANG-A2-2425',
            'libelle' => 'Session Anglais Élémentaire Hiver',
            'date_debut' => '2025-01-20',
            'date_fin' => '2025-05-30',
            'statut' => 'planifiee'
        ]
    ];
    $stmtSess = $db->prepare("INSERT INTO sessions (id_annee, id_type_formation, id_niveau, code, libelle, date_debut, date_fin, statut) VALUES (:id_annee, :id_type_formation, :id_niveau, :code, :libelle, :date_debut, :date_fin, :statut) ON DUPLICATE KEY UPDATE libelle=VALUES(libelle), statut=VALUES(statut)");
    foreach ($sessions as $sess) {
        $stmtSess->execute($sess);
    }

    // 8. Evaluations
    $sessId = $db->query("SELECT id FROM sessions WHERE code = 'ANG-A1-2425' LIMIT 1")->fetchColumn();
    if ($sessId) {
        $evals = [
            ['id_session' => $sessId, 'libelle' => 'Évaluation Mi-Session', 'date_evaluation' => '2024-11-20'],
            ['id_session' => $sessId, 'libelle' => 'Examen Final de Session', 'date_evaluation' => '2025-01-10'],
        ];
        $stmtEval = $db->prepare("INSERT INTO evaluations (id_session, libelle, date_evaluation) VALUES (:id_session, :libelle, :date_evaluation)");
        $evalCount = $db->query("SELECT COUNT(*) FROM evaluations WHERE id_session = $sessId")->fetchColumn();
        if ($evalCount == 0) {
            foreach ($evals as $ev) {
                $stmtEval->execute($ev);
            }
        }
    }

    // 9. Parametres globaux + association aux evaluations
    $evalIds = $db->query("SELECT id FROM evaluations ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($evalIds)) {
        $paramDefs = [
            ['libelle' => 'Compréhension Orale', 'bareme' => 4.00],
            ['libelle' => 'Expression Écrite', 'bareme' => 4.00],
            ['libelle' => 'Vocabulaire & Grammaire', 'bareme' => 4.00],
        ];

        $stmtParam = $db->prepare(
            "INSERT INTO parametres (libelle, bareme) VALUES (:libelle, :bareme)
             ON DUPLICATE KEY UPDATE bareme = VALUES(bareme)"
        );
        foreach ($paramDefs as $p) {
            $stmtParam->execute($p);
        }

        $paramMap = [];
        foreach ($db->query("SELECT id, libelle FROM parametres ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $paramMap[$row['libelle']] = (int) $row['id'];
        }

        $stmtAttach = $db->prepare(
            "INSERT INTO evaluation_parametres (id_evaluation, id_parametre, ordre)
             VALUES (:id_evaluation, :id_parametre, :ordre)
             ON DUPLICATE KEY UPDATE ordre = VALUES(ordre)"
        );

        foreach ($evalIds as $evalId) {
            foreach ($paramDefs as $index => $paramDef) {
                $paramId = $paramMap[$paramDef['libelle']] ?? null;
                if ($paramId !== null) {
                    $stmtAttach->execute([
                        'id_evaluation' => (int) $evalId,
                        'id_parametre' => $paramId,
                        'ordre' => $index + 1,
                    ]);
                }
            }
        }
    }

    echo "Base de données initialisée avec succès!\n";
} catch (Exception $e) {
    echo "Erreur lors du seeding: " . $e->getMessage() . "\n";
}
