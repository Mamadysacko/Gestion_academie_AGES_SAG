<?php
/**
 * Migration 2026-10 : hachage des mots de passe encore stockés en clair (audit S1).
 *
 *   php database/apply_migration.php migration_2026_10_hachage_mots_de_passe.php
 *
 * Chaque mot de passe en clair est remplacé par son empreinte password_hash() : la connexion reste
 * identique pour l'utilisateur, mais le mot de passe n'est plus lisible en base.
 * Irréversible par nature : seul un retour à la sauvegarde rend les anciennes valeurs.
 */

return static function (PDO $db): string {
    $rows = $db->query('SELECT id, password FROM users')->fetchAll(PDO::FETCH_ASSOC);
    $update = $db->prepare('UPDATE users SET password = :hash WHERE id = :id');
    $hashed = 0;
    foreach ($rows as $row) {
        if (password_get_info((string) $row['password'])['algo'] !== null) {
            continue; // déjà haché
        }
        $update->execute(['hash' => password_hash((string) $row['password'], PASSWORD_DEFAULT), 'id' => (int) $row['id']]);
        $hashed++;
    }
    return "{$hashed} mot(s) de passe haché(s) sur " . count($rows) . ' compte(s)';
};
