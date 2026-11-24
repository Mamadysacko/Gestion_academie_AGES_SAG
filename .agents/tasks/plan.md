# Plan d'implémentation — AGES Backend

> **Ordre d'exécution :** chaque item peut être fait indépendamment sauf dépendances explicites.
> **Test de non-régression :** `php vendor/bin/phpunit --testsuite Unitaires` après chaque item.
> **Contrainte globale :** requêtes préparées partout, format `{ success, data/error, message }`, notifications dans `try/catch` silencieux.

---

## Contexte de départ (ne pas recréer)

- `User::findByPhone()` ✅ présent dans `Model/User.php`
- `User::revokeSessions()` ✅ présent dans `Model/User.php`
- `Authorization::userId()` ✅ présent dans `Controller/Authorization.php`
- Exemption RBAC auditeur ✅ dans `Authorization::authorizeRequest()` (bloc `if ($resource === 'auditeur')`)
- `Route/routes/auditeur_espace.php` ✅ existe et est chargé dans `Route/api.php`
- La migration `migration_auditeur_comptes.sql` a déjà créé `first_login`, `notifications` et le rôle `auditeur` — les migrations SQL ci-dessous sont des **fichiers idempotents de référence** utilisant `IF NOT EXISTS` / `INSERT IGNORE`
- La table `notifications` existante a un ENUM `('info','alerte_note','alerte_presence','alerte_evaluation','bulletin')` pour la colonne `type` — les nouvelles méthodes utiliseront les valeurs `'alerte_note'` et `'bulletin'` (correspondances exactes dans l'ENUM existant)

---

- [ ] 1. **Créer `database/migration_first_login.sql`** — migration idempotente ajoutant `first_login` à `users` si absente.
      Utiliser `ALTER TABLE users ADD COLUMN IF NOT EXISTS first_login TINYINT(1) NOT NULL DEFAULT 0;` (MySQL 8+).
      Ajouter un commentaire documentant que `Authorization::currentUser()` la sélectionne déjà (ligne `u.first_login`).
      Files: `c:\xampp\htdocs\Backend_AGES\database\migration_first_login.sql`
      Verify: Ouvrir phpMyAdmin ou exécuter `php database/apply_migration.php migration_first_login.sql` — pas d'erreur SQL ; si la colonne existe déjà, l'instruction est un no-op.

- [ ] 2. **Créer `database/migration_notifications.sql`** — migration idempotente créant la table `notifications`.
      Utiliser `CREATE TABLE IF NOT EXISTS notifications (...)` avec les colonnes : `id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY`, `user_id INT UNSIGNED NOT NULL`, `type ENUM('info','alerte_note','alerte_presence','alerte_evaluation','bulletin') NOT NULL DEFAULT 'info'`, `titre VARCHAR(200) NOT NULL`, `message TEXT NOT NULL`, `lu TINYINT(1) NOT NULL DEFAULT 0`, `created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP`.
      Ajouter `KEY idx_notif_user_lu (user_id, lu)` et `CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE`.
      Utiliser charset `utf8mb4 COLLATE utf8mb4_unicode_ci`.
      Files: `c:\xampp\htdocs\Backend_AGES\database\migration_notifications.sql`
      Verify: Exécution sans erreur sur une base qui a déjà la table (IF NOT EXISTS garantit l'idempotence).

- [ ] 3. **Créer `database/migration_role_auditeur.sql`** — migration idempotente insérant le rôle auditeur.
      Contenu : `INSERT IGNORE INTO roles (code, nom) VALUES ('auditeur', 'Auditeur');`
      Ajouter les permissions minimales avec `INSERT IGNORE INTO role_permissions (role_id, module_code, action_code)` pour les actions `read` sur `evaluations`, `presences`, `emplois-temps` (via `@aud_role_id = SELECT id FROM roles WHERE code = 'auditeur'`).
      Files: `c:\xampp\htdocs\Backend_AGES\database\migration_role_auditeur.sql`
      Verify: Exécution sans erreur ; `INSERT IGNORE` est no-op si les lignes existent déjà.

- [ ] 4. **Ajouter `Notification::createNoteAdded()` dans `Model/Notification.php`**.
      Méthode : `public function createNoteAdded(int $userId, string $evalLibelle, ?float $note, float $bareme): int`
      Corps : appeler `$this->create(['user_id' => $userId, 'type' => 'alerte_note', 'titre' => "Note enregistrée : {$evalLibelle}", 'message' => "Votre note pour \"{$evalLibelle}\" a été enregistrée : {$note}/{$bareme}."]);`
      Retourner l'ID inséré.
      Note importante : le `$fillable` de `Notification` contient `['user_id', 'type', 'titre', 'message', 'lu']` — tous les champs utilisés sont déjà déclarés, aucune modification du tableau `$fillable` n'est nécessaire.
      Files: `c:\xampp\htdocs\Backend_AGES\Model\Notification.php`
      Verify: `php vendor/bin/phpunit --testsuite Unitaires` — pas de régression.

- [ ] 5. **Ajouter `Notification::createBulletinDisponible()` dans `Model/Notification.php`**.
      Méthode : `public function createBulletinDisponible(int $userId, string $sessionLibelle): int`
      Corps : appeler `$this->create(['user_id' => $userId, 'type' => 'bulletin', 'titre' => "Bulletin disponible : {$sessionLibelle}", 'message' => "Votre bulletin de notes pour la session \"{$sessionLibelle}\" est disponible. Connectez-vous à votre espace pour le consulter."]);`
      Retourner l'ID inséré.
      Files: `c:\xampp\htdocs\Backend_AGES\Model\Notification.php`
      Verify: `php vendor/bin/phpunit --testsuite Unitaires` — pas de régression.

- [ ] 6. **Ajouter le hook notification dans `NoteController::saveOrUpdate()`** dans `Controller/NoteController.php`.
      Après le bloc `if ($success)` qui retourne la réponse 200, ajouter (avant le `sendResponse`) un bloc `try/catch` silencieux qui :
      1. Vérifie que `$note !== null` (ne pas notifier si suppression de note).
      2. Récupère le `user_id` de l'auditeur via la jointure `inscriptions → auditeurs` sur `id_inscription` = `$data['id_inscription']`.
      3. Récupère le libellé de l'évaluation et le barème du paramètre via jointures `evaluations`, `parametres` sur `id_evaluation` / `id_parametre`.
      4. Instancie `$notifModel = new Notification()` (ajouter `require_once __DIR__ . '/../Model/Notification.php';` en début de fichier, juste après `require_once __DIR__ . '/../Model/Note.php'`).
      5. Appelle `$notifModel->createNoteAdded($userId, $evalLibelle, $note, $bareme)`.
      Toute exception dans ce bloc est capturée et ignorée (log avec `error_log()` optionnel).
      Le bloc notification vient APRÈS `$this->sendResponse(200, [...])` mais AVANT la fin du `if ($success)` — ou mieux : exécuté uniquement si `$success === true`, et la réponse est envoyée qu'il y ait ou non une erreur dans la notification.
      Architecture : insérer le code de notification AVANT l'appel `$this->sendResponse()` dans le `if ($success)`, pour que le `try/catch` silencieux entoure uniquement la partie notification, pas la réponse.
      Files: `c:\xampp\htdocs\Backend_AGES\Controller\NoteController.php`
      Verify: `php vendor/bin/phpunit --testsuite Unitaires` — pas de régression. Tester manuellement `POST /notes/save-or-update` avec une note valide.

- [ ] 7. **Ajouter le hook notification bulletin dans `ResultatController::valider()`** dans `Controller/ResultatController.php`.
      Après `$result = $this->model->deciderPassages(...)`, et AVANT la construction de `$label`, ajouter un `try/catch` silencieux qui :
      1. Ajoute `require_once __DIR__ . '/../Model/Notification.php';` en tête de fichier (après les `require_once` existants).
      2. Instantie `$notifModel = new Notification()`.
      3. Récupère le libellé de session via `SELECT libelle FROM sessions WHERE id = :id` (utilise `Database::getInstance()`).
      4. Pour chaque `$inscriptionId` dans `$data['inscriptions']` (tableau d'IDs transmis dans le body), récupère le `user_id` de l'auditeur via `SELECT a.user_id FROM inscriptions i JOIN auditeurs a ON a.id = i.id_auditeur WHERE i.id = :id LIMIT 1`.
      5. Si `$userId` n'est pas null, appelle `$notifModel->createBulletinDisponible((int)$userId, $sessionLibelle)`.
      Toute exception est silencieusement capturée.
      Files: `c:\xampp\htdocs\Backend_AGES\Controller\ResultatController.php`
      Verify: `php vendor/bin/phpunit --testsuite Unitaires` — pas de régression.

- [ ] 8. **Ajouter `AuditeurEspaceController::getAlertes()` dans `Controller/AuditeurEspaceController.php`**.
      Méthode publique `getAlertes(): void` qui :
      1. Appelle `$this->resolveAuditeur()` ; retourne si null.
      2. Récupère les **évaluations à venir dans 7 jours** :
         ```sql
         SELECT e.id, e.libelle, e.date_evaluation,
                s.libelle AS session_libelle
         FROM evaluations e
         JOIN sessions s ON s.id = e.id_session
         JOIN inscriptions i ON i.id_session = s.id AND i.id_auditeur = :id_auditeur AND i.statut = 'active'
         WHERE e.date_evaluation BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
         ORDER BY e.date_evaluation
         ```
         Pour chaque ligne, construire : `['type' => 'evaluation', 'niveau' => 'info', 'titre' => "Évaluation dans {$jours} jour(s) : {$eval['libelle']}", 'message' => "L'évaluation \"{$eval['libelle']}\" de la session \"{$eval['session_libelle']}\" a lieu le {$eval['date_evaluation']}.", 'date_evaluation' => $eval['date_evaluation']]` — calculer `$jours` avec `(new DateTime($eval['date_evaluation']))->diff(new DateTime('today'))->days`.
      3. Récupère le **taux d'absence** :
         ```sql
         SELECT COUNT(*) AS total,
                SUM(CASE WHEN pr.statut = 'absent' THEN 1 ELSE 0 END) AS absents
         FROM presences pr
         JOIN inscriptions i ON i.id = pr.id_inscription
         WHERE i.id_auditeur = :id_auditeur
         ```
         Calculer `$tauxAbsence = $total > 0 ? ($absents / $total * 100) : 0`.
         Si `$tauxAbsence > 30` → ajouter une alerte `['type' => 'presence', 'niveau' => 'danger', 'titre' => 'Taux d\'absence élevé', 'message' => sprintf("Votre taux d'absence est de %.1f %% (seuil critique : 30 %%).", $tauxAbsence)]`.
         Sinon si `$tauxAbsence > 20` → alerte avec `'niveau' => 'warning'` et message similaire avec seuil 20%.
         Sinon → ne rien ajouter (taux ≤ 20% : aucune alerte présence).
      4. Retourner `$this->sendResponse(200, ['success' => true, 'data' => ['alertes' => $alertes]])`.
      Files: `c:\xampp\htdocs\Backend_AGES\Controller\AuditeurEspaceController.php`
      Verify: `php vendor/bin/phpunit --testsuite Unitaires` — pas de régression.

- [ ] 9. **Ajouter la route `GET /auditeur/alertes`** dans `Route/routes/auditeur_espace.php`.
      Dans le bloc `if ($method === 'GET')`, ajouter juste avant le `else { throw new Exception(...)  }` final :
      ```php
      } elseif ($param1 === 'alertes') {
          $controller->getAlertes();
      ```
      Files: `c:\xampp\htdocs\Backend_AGES\Route\routes\auditeur_espace.php`
      Verify: `php vendor/bin/phpunit --testsuite Unitaires` — pas de régression. Tester manuellement `GET /auditeur/alertes` avec un token auditeur valide.

- [ ] 10. **Ajouter `FicheNotesController::printFicheNotes(int $evaluationId): void`** dans `Controller/FicheNotesController.php`.
       La méthode réutilise exactement la même logique de récupération des données que `getFicheNotes()` (mêmes 3 requêtes SQL : évaluation, paramètres, auditeurs groupés).
       Si l'évaluation est introuvable : envoyer `header('Content-Type: application/json; charset=utf-8')`, `http_response_code(404)`, puis `echo json_encode(['success' => false, 'error' => 'Évaluation introuvable.'], JSON_UNESCAPED_UNICODE)` et retourner.
       Sinon : envoyer `header('Content-Type: text/html; charset=utf-8')` et `echo` le HTML complet (pas de `json_encode`) :

       **Structure HTML :**
       ```html
       <!DOCTYPE html>
       <html lang="fr">
       <head>
         <meta charset="utf-8">
         <title>Fiche de notes — {libelle}</title>
         <style>
           @page { size: A4 landscape; margin: 1cm; }
           @media print { body { margin: 0; } .no-print { display: none; } }
           body { font-family: Arial, sans-serif; font-size: 11px; color: #000; }
           h1 { font-size: 14px; text-align: center; margin: 0 0 2px 0; }
           h2 { font-size: 12px; text-align: center; margin: 0 0 8px 0; font-weight: normal; }
           .meta { text-align: center; margin-bottom: 8px; font-size: 10px; }
           table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
           th, td { border: 1px solid #000; padding: 3px 5px; text-align: left; }
           th { background: #f0f0f0; text-align: center; font-size: 10px; }
           .sub { font-size: 9px; font-weight: normal; color: #444; }
           .footer { margin-top: 16px; font-size: 10px; }
           .print-btn { margin: 8px; padding: 6px 14px; cursor: pointer; }
         </style>
       </head>
       <body>
         <button class="no-print print-btn" onclick="window.print()">Imprimer</button>
         <h1>AGES — Académie de Langues</h1>
         <h2>Fiche de notes — {evaluation.libelle}</h2>
         <div class="meta">
           Session : {session_libelle} | Formation : {type_formation} | Niveau : {niveau_libelle} |
           Année : {annee_libelle} | Date : {date_evaluation} | Effectif total : {effectif_total}
         </div>
         <!-- Pour chaque groupe : -->
         <h3>Groupe : {groupe.libelle} ({groupe.code})</h3>
         <table>
           <thead>
             <tr>
               <th>Matricule</th>
               <th>Nom</th>
               <th>Prénom</th>
               <!-- Pour chaque paramètre : -->
               <th>{param.libelle}<br><span class="sub">/{param.bareme}</span></th>
               <th>Total</th>
               <th>Signature</th>
             </tr>
           </thead>
           <tbody>
             <!-- Pour chaque auditeur du groupe (trié par nom) : -->
             <tr>
               <td>{matricule}</td><td>{nom}</td><td>{prenom}</td>
               <!-- N cellules vides pour les notes (à remplir manuellement) -->
               <td></td>...<td></td>
               <td></td> <!-- Total -->
               <td></td> <!-- Signature -->
             </tr>
           </tbody>
         </table>
         <div class="footer">
           Signature du formateur : _______________________________ &nbsp;&nbsp; Date : _______________
         </div>
       </body>
       </html>
       ```
       Toutes les valeurs dynamiques sont échappées avec `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
       Files: `c:\xampp\htdocs\Backend_AGES\Controller\FicheNotesController.php`
       Verify: `php vendor/bin/phpunit --testsuite Unitaires` — pas de régression. Tester `GET /evaluations/1/fiche-notes/imprimer` dans un navigateur : réponse HTML A4 paysage avec tableau des auditeurs.

- [ ] 11. **Ajouter la route `GET /evaluations/{id}/fiche-notes/imprimer`** dans `Route/routes/evaluations.php`.
       Dans le bloc `if ($resource === 'evaluations') { if ($method === 'GET')`, la condition actuelle pour `fiche-notes` est :
       ```php
       } elseif (is_numeric($param1) && $param2 === 'fiche-notes') {
           (new FicheNotesController())->getFicheNotes((int)$param1);
       ```
       Remplacer ce bloc par deux conditions ordonnées (la plus spécifique en premier) :
       ```php
       } elseif (is_numeric($param1) && $param2 === 'fiche-notes' && $param3 === 'imprimer') {
           (new FicheNotesController())->printFicheNotes((int)$param1);
       } elseif (is_numeric($param1) && $param2 === 'fiche-notes') {
           (new FicheNotesController())->getFicheNotes((int)$param1);
       ```
       `$param3` est déjà défini dans `Route/routes/bootstrap.php` (`$param3 = $segments[3] ?? null`).
       Files: `c:\xampp\htdocs\Backend_AGES\Route\routes\evaluations.php`
       Verify: `php vendor/bin/phpunit --testsuite Unitaires` — pas de régression. Tester que `GET /evaluations/1/fiche-notes` retourne toujours du JSON et que `GET /evaluations/1/fiche-notes/imprimer` retourne du HTML.

- [ ] 12. **Ajouter `InscriptionController::searchAuditeur(): void`** dans `Controller/InscriptionController.php`.
       La méthode :
       1. Lit `$q = trim($_GET['q'] ?? '')`.
       2. Si `mb_strlen($q) < 2` : retourne `$this->sendError(400, "Le terme de recherche doit contenir au moins 2 caractères.")`.
       3. Exécute via `Database::getInstance()` :
          ```sql
          SELECT a.id, a.matricule, a.nom, a.prenom, a.telephone, a.email,
                 a.user_id
          FROM auditeurs a
          WHERE LOWER(a.nom) LIKE LOWER(:q)
             OR LOWER(a.prenom) LIKE LOWER(:q)
             OR LOWER(a.matricule) LIKE LOWER(:q)
          ORDER BY a.nom, a.prenom
          LIMIT 20
          ```
          Avec le paramètre `':q' => '%' . $q . '%'` (préparé, pas interpolé).
       4. Retourne `$this->sendResponse(200, ['success' => true, 'data' => $rows, 'count' => count($rows)])`.
       Note : `Database::getInstance()` est accessible depuis le contrôleur via `require_once __DIR__ . '/../config/Database.php'` — vérifier qu'il est déjà présent dans le fichier (il l'est via le `require_once` de `Model/Inscription.php` → `Model/Model.php` → `config/Database.php`). Utiliser `$db = Database::getInstance(); $stmt = $db->prepare(...); $stmt->execute([...]); $rows = $stmt->fetchAll();`.
       Files: `c:\xampp\htdocs\Backend_AGES\Controller\InscriptionController.php`
       Verify: `php vendor/bin/phpunit --testsuite Unitaires` — pas de régression. Tester `GET /inscriptions/auditeur-search?q=du` : retourne un tableau JSON avec `success: true` et les auditeurs correspondants.

- [ ] 13. **Ajouter la route `GET /inscriptions/auditeur-search`** dans `Route/routes/auditeurs.php`.
       Dans le bloc `if ($resource === 'inscriptions') { if ($method === 'GET')`, ajouter juste avant le `else { throw new Exception("Route non trouvée.", 404); }` :
       ```php
       } elseif ($param1 === 'auditeur-search') {
           $controller->searchAuditeur();
       ```
       Files: `c:\xampp\htdocs\Backend_AGES\Route\routes\auditeurs.php`
       Verify: `php vendor/bin/phpunit --testsuite Unitaires` — pas de régression. Tester `GET /inscriptions/auditeur-search?q=test` : retourne JSON `{ success: true, data: [...], count: N }`.

---

## Ordre de dépendance

| Item | Dépend de |
|------|-----------|
| 1–3 (SQL) | indépendants |
| 4–5 (Notification méthodes) | indépendants l'un de l'autre, ne dépendent pas des SQL |
| 6 (hook NoteController) | dépend de 4 |
| 7 (hook ResultatController) | dépend de 5 |
| 8 (getAlertes) | indépendant |
| 9 (route alertes) | dépend de 8 |
| 10 (printFicheNotes) | indépendant |
| 11 (route imprimer) | dépend de 10 |
| 12 (searchAuditeur) | indépendant |
| 13 (route auditeur-search) | dépend de 12 |

## Points d'attention pour l'implémenteur

1. **ENUM `type` sur `notifications`** : la table existante définit `type` comme `ENUM('info','alerte_note','alerte_presence','alerte_evaluation','bulletin')`. Les méthodes `createNoteAdded` et `createBulletinDisponible` utilisent respectivement `'alerte_note'` et `'bulletin'` — deux valeurs présentes dans l'ENUM. Ne pas tenter de passer `'note'` ou autre valeur hors ENUM.

2. **Ne pas modifier `api.php`** : `auditeur_espace.php` y est déjà enregistré.

3. **Ne pas modifier `Authorization.php`, `User::findByPhone()`, `User::revokeSessions()`**.

4. **`$param3` dans bootstrap** : `bootstrap.php` déclare déjà `$param3 = $segments[3] ?? null` — la route `fiche-notes/imprimer` (item 11) peut l'utiliser directement.

5. **Content-Type fiche HTML** : `printFicheNotes` doit envoyer `header('Content-Type: text/html; charset=utf-8')` avant tout `echo`. Le bootstrap envoie `Content-Type: application/json` par défaut — `printFicheNotes` doit surcharger avec `header('Content-Type: text/html; charset=utf-8', true)`.

6. **Pas de `ob_start()` dans `printFicheNotes`** : le bootstrap gère la compression gzip et les output buffers pour le journal. La fiche HTML sort en écriture (méthode GET), donc les buffers liés au journal ne s'activent pas (`$journalWrite = $method !== 'GET'`).
