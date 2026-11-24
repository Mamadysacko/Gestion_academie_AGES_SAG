# Espace auditeur, notifications, fiche de notes imprimable et recherche d'auditeur

Cette livraison implémente l'espace personnel de l'auditeur (notes, présences, bulletin, emploi du temps, alertes), un système de notifications in-app déclenché par la saisie de notes et la validation de bulletins, une fiche de notes HTML imprimable à destination du formateur, et un endpoint de recherche d'auditeurs pour faciliter les inscriptions. Trois migrations SQL créent la table `notifications`, insèrent le rôle `auditeur` avec ses permissions minimales, et ajoutent la colonne `first_login` sur `users`. Le tout s'intègre dans l'architecture route-fichier + autoloader existante, sans modifier le fichier `api.php` principal.

**Watch for :**
- `searchAuditeur()` appelle `Database::getInstance()` directement sans aucun `require_once` dans son fichier — fonctionne uniquement parce que `Inscription.php` est chargé avant, ce qui est une dépendance implicite fragile. *(confirmed)*
- `getAlertes()` construit les dates d'évaluation avec `new DateTime($ev['date_evaluation'])` sur une valeur brute de base de données, sans try/catch localisé ; un format de date malformé lève une exception non attrapée qui sort du `catch (Exception $e)` global mais sans message métier utile. *(likely)*
- La fiche HTML est générée avec une concaténation `echo` imbriquée dans un seul `try/catch` dont le `catch` ne peut plus envoyer les en-têtes si la sortie a déjà commencé — ce cas est géré par `headers_sent()`, mais le HTML partiel reste dans le buffer du client. *(confirmed)*
- La migration `migration_first_login.sql` utilise `PREPARE/EXECUTE` sur une variable de session, qui peut être NULL dans certains contextes MySQL strict — la migration peut silencieusement ne pas s'exécuter. *(likely)*

**Verdict** : NEEDS_CHANGES

---

## High-level view

Les trois migrations sont idempotentes dans leur intention, mais `migration_first_login.sql` s'appuie sur une variable de session MySQL (`@col_exists`) qui peut valoir NULL si le runner exécute chaque instruction dans une connexion séparée, ce qui annule silencieusement l'`ALTER TABLE`. `migration_role_auditeur.sql` et `migration_notifications.sql` sont solides (`INSERT IGNORE`, `CREATE TABLE IF NOT EXISTS`).

Les deux nouveaux hooks de notification — dans `NoteController::saveOrUpdate()` et `ResultatController::valider()` — sont enveloppés dans `try/catch (Throwable)` avec `error_log`, donc une défaillance de notification ne peut pas faire échouer l'opération principale.

`AuditeurEspaceController::getAlertes()` délègue à `resolveAuditeur()` pour l'authentification et la vérification du rôle. Le calcul des seuils d'absence (20 % / 30 %) est correct. La requête sur les évaluations à venir utilise une jointure via `emplois_du_temps` qui peut retourner des doublons si un auditeur est inscrit dans plusieurs créneaux d'une même session.

Le routing de `fiche-notes/imprimer` dans `evaluations.php` teste `$param3 === 'imprimer'` avant `$param2 === 'fiche-notes'` seul, donc l'ordre est correct. La fiche HTML passe toutes les valeurs de base de données par `htmlspecialchars` via le helper `$e()`, émet `Content-Type: text/html; charset=utf-8`, et inclut `@page { size: A4 landscape; }`. Le buffer de sortie n'est pas ouvert localement, ce qui empêche d'envoyer un JSON d'erreur propre si une exception survient après le premier `echo`.

`searchAuditeur()` valide la longueur minimale du terme (2 caractères), utilise trois paramètres de bind séparés, et retourne au plus 20 résultats. La dépendance à `Database::getInstance()` sans `require_once` local est une fragilité de couplage implicite détaillée dans les issues.

---

<details>
<summary>Issues (4)</summary>

1. **Migration first_login silencieuse** — `@col_exists` peut être NULL si le runner MySQL exécute les instructions dans des connexions séparées, ce qui fait que `IF(@col_exists = 0, …)` évalue à NULL et l'`ALTER TABLE` ne s'exécute pas. Remplacer par `ALTER TABLE … ADD COLUMN IF NOT EXISTS` (MySQL 8+) ou par un bloc procédural.

2. **Dépendance implicite sur Database dans searchAuditeur** — `InscriptionController::searchAuditeur()` appelle `Database::getInstance()` directement sans `require_once`. Ça fonctionne aujourd'hui parce que `Inscription.php` est toujours chargé avant, mais c'est une dépendance cachée. Ajouter `require_once __DIR__ . '/../config/Database.php';` en tête de `InscriptionController.php`.

3. **Doublon d'auditeurs dans les alertes évaluation** — La requête de `getAlertes()` joint `evaluations → sessions → emplois_du_temps → inscriptions` ; un auditeur inscrit dans plusieurs créneaux de la même session génère plusieurs alertes pour la même évaluation. Ajouter `GROUP BY e.id` ou `DISTINCT e.id` à la requête.

4. **HTML partiel en cas d'exception tardive dans printFicheNotes** — Si une exception survient après le premier `echo`, les en-têtes ne peuvent plus être modifiés et le client reçoit un document HTML coupé. Capturer les données en mémoire avant tout `echo` (output buffering local) pour pouvoir envoyer un JSON d'erreur propre si la construction échoue.

</details>

---

<details>
<summary>Détails</summary>

### Migration first_login : variable de session fragile

`migration_first_login.sql` stocke dans `@col_exists` le résultat d'une sous-requête `information_schema`, puis passe cette variable à `IF()` pour décider d'exécuter ou non l'`ALTER TABLE`. MySQL évalue `IF(NULL = 0, …)` comme `IF(FALSE, …)`, donc si la variable n'est jamais initialisée (exécution dans une connexion fraîche par certains clients MySQL, PHPMyAdmin multi-requête, ou script CI), l'`ALTER TABLE` est silencieusement ignoré et `first_login` n'existe pas — ce qui fait ensuite planter `Authorization::currentUser()` dont la requête SELECT inclut `u.first_login`.

La correction la plus portable sur MySQL 8 :
```sql
ALTER TABLE users ADD COLUMN IF NOT EXISTS first_login TINYINT(1) NOT NULL DEFAULT 0;
```
Sur MySQL 5.7 : encapsuler dans une procédure stockée avec `IF NOT EXISTS` ou accepter que la migration soit rejouée (l'`ALTER TABLE` retournera une erreur si la colonne existe déjà, que l'appelant devra ignorer).

### Doublons dans les alertes évaluation de getAlertes()

La jointure `evaluations → sessions → emplois_du_temps → inscriptions(id_auditeur)` ne déduplique pas les évaluations. Si un auditeur est inscrit dans deux créneaux (`emplois_du_temps`) de la même session (groupe A le matin, groupe B le soir), la même évaluation apparaît deux fois dans le tableau `$alertes`. Ajouter `GROUP BY e.id, e.libelle, e.date_evaluation, s.libelle` ou sélectionner avec `SELECT DISTINCT e.id, e.libelle, …` règle le problème.

### HTML partiel sur exception tardive dans printFicheNotes

`printFicheNotes()` commence à envoyer des `echo` dès que l'évaluation est chargée. Si une exception survient au milieu de la génération des tableaux (par exemple un `fetchAll` qui échoue sur la liste des auditeurs), le `catch` vérifie `headers_sent()` correctement, mais le client a déjà reçu les premiers octets d'un document HTML invalide. La solution standard : ouvrir un buffer local (`ob_start()`) en début de méthode, construire tout le HTML dans des variables ou dans ce buffer, puis `ob_get_clean()` et envoyer en une seule fois — ou laisser le buffer absorber la sortie partielle et l'effacer (`ob_end_clean()`) avant d'envoyer le JSON d'erreur.

</details>

---

<details>
<summary>Carte des fichiers</summary>

| Fichier | Modification |
|---|---|
| `database/migration_first_login.sql` | Ajout colonne `first_login` sur `users` (idempotent conditionnel via variable de session) |
| `database/migration_notifications.sql` | Création table `notifications` (`CREATE TABLE IF NOT EXISTS`) |
| `database/migration_role_auditeur.sql` | Insertion rôle `auditeur` et permissions minimales (`INSERT IGNORE`) |
| `Model/Notification.php` | Ajout méthodes `createNoteAdded` et `createBulletinDisponible` |
| `Controller/NoteController.php` | Hook notification silencieux dans `saveOrUpdate` |
| `Controller/ResultatController.php` | Hook notification bulletin silencieux dans `valider` |
| `Controller/AuditeurEspaceController.php` | Nouvelle méthode `getAlertes` |
| `Route/routes/auditeur_espace.php` | Route `GET /auditeur/alertes` |
| `Controller/FicheNotesController.php` | Nouvelle méthode `printFicheNotes` |
| `Route/routes/evaluations.php` | Route `GET /evaluations/{id}/fiche-notes/imprimer` |
| `Controller/InscriptionController.php` | Nouvelle méthode `searchAuditeur` |
| `Route/routes/auditeurs.php` | Route `GET /inscriptions/auditeur-search` |

</details>
