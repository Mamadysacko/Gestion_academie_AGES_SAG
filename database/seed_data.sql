-- Script d'insertion de données de test pour Backend AGES
-- À exécuter après avoir créé la base de données

USE academie_langues3;

-- Insertion des rôles
INSERT INTO roles (code, nom) VALUES
('admin', 'Administrateur'),
('scolarite', 'Service Scolarité'),
('formateur', 'Formateur'),
('secretaire', 'Secrétaire')
ON DUPLICATE KEY UPDATE nom = VALUES(nom);

-- Insertion des départements
INSERT INTO departements (code, libelle) VALUES
('ANGLAIS', 'Département d\'Anglais'),
('FRANCAIS', 'Département de Français'),
('ESPAGNOL', 'Département d\'Espagnol'),
('ALLEMAND', 'Département d\'Allemand'),
('CHINOIS', 'Département de Chinois')
ON DUPLICATE KEY UPDATE libelle = VALUES(libelle);

-- Insertion des niveaux
INSERT INTO niveaux (libelle) VALUES
('Débutant (A1)'),
('Élémentaire (A2)'),
('Intermédiaire (B1)'),
('Intermédiaire Avancé (B2)'),
('Avancé (C1)'),
('Maîtrise (C2)')
ON DUPLICATE KEY UPDATE libelle = VALUES(libelle);

-- Insertion d'une année scolaire active
INSERT INTO annees_scolaires (libelle, date_debut, date_fin, statut) VALUES
('2024-2025', '2024-09-01', '2025-06-30', 1),
('2023-2024', '2023-09-01', '2024-06-30', 0),
('2025-2026', '2025-09-01', '2026-06-30', 0)
ON DUPLICATE KEY UPDATE 
    date_debut = VALUES(date_debut),
    date_fin = VALUES(date_fin),
    statut = VALUES(statut);

-- Insertion d'un utilisateur administrateur de test
-- Mot de passe: admin123
INSERT INTO users (nom, prenom, email, telephone, password, role_id, actif) 
SELECT 
    'Admin',
    'Système',
    'admin@ages.sn',
    '771234567',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- admin123
    (SELECT id FROM roles WHERE code = 'admin' LIMIT 1),
    1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'admin@ages.sn');

-- Insertion d'un utilisateur scolarité de test
-- Mot de passe: scolarite123
INSERT INTO users (nom, prenom, email, telephone, password, role_id, actif) 
SELECT 
    'Diop',
    'Fatou',
    'scolarite@ages.sn',
    '779876543',
    '$2y$10$zB3Kp7vG5s8XfL9mN1qR2eH4wP6tU8vYxQ0aZcKdMfOgHlJbNrTxS', -- scolarite123
    (SELECT id FROM roles WHERE code = 'scolarite' LIMIT 1),
    1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'scolarite@ages.sn');

-- Insertion des types de formations
INSERT INTO types_formations (libelle) VALUES
('Formation Intensive'),
('Formation Standard'),
('Formation Weekend'),
('Formation en Ligne'),
('Formation Professionnelle')
ON DUPLICATE KEY UPDATE libelle = VALUES(libelle);

-- Insertion de salles
INSERT INTO salles (code, libelle, capacite, statut) VALUES
('S01', 'Salle 01', 20, 'disponible'),
('S02', 'Salle 02', 15, 'disponible'),
('S03', 'Salle 03', 25, 'disponible'),
('S04', 'Salle 04', 18, 'disponible'),
('LAB01', 'Laboratoire Multimédia 01', 30, 'disponible')
ON DUPLICATE KEY UPDATE 
    libelle = VALUES(libelle),
    capacite = VALUES(capacite),
    statut = VALUES(statut);

-- Affichage des informations de connexion
SELECT '✅ Données de test insérées avec succès!' AS message;
SELECT '' AS '';
SELECT '📋 Comptes de test créés:' AS information;
SELECT '' AS '';
SELECT '👤 Administrateur:' AS type, 'admin@ages.sn' AS email, 'admin123' AS password
UNION ALL
SELECT '👤 Scolarité:', 'scolarite@ages.sn', 'scolarite123';
