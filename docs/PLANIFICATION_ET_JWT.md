# Planification automatique, calendrier et authentification JWT

Ce document décrit les évolutions d'octobre 2026 : authentification JWT, configuration de
référence des formations, planification automatique des sessions et calendrier.

## 1. Mise en place

1. Sauvegarder la base (`mysqldump … academie_langues3 > sauvegarde.sql`).
2. Appliquer la migration (une seule fois, tracée dans `schema_migrations`) :

   ```
   C:\xampp\php\php.exe database\apply_migration.php migration_2026_10_planification_jwt.sql
   ```

3. Optionnel : définir les variables d'environnement d'Apache

   | Variable | Défaut | Rôle |
   |---|---|---|
   | `AGES_JWT_SECRET` | généré dans `config/jwt_secret.key` | secret de signature (≥ 32 caractères) |
   | `AGES_JWT_ACCESS_TTL` | `900` (15 min) | durée du jeton d'accès |
   | `AGES_JWT_REFRESH_TTL` | `1209600` (14 j) | durée du jeton de renouvellement |
   | `AGES_AUTH_SESSION_FALLBACK` | `true` | accepte encore le cookie de session PHP des anciens clients |

4. Après toute évolution de structure : `php database/export_schema.php` régénère
   `database/DB Gestion Academie.sql` (lu par le centre d'aide du frontend).

La migration ne supprime aucune donnée : les créneaux existants reçoivent leur session et leurs
horaires, chaque groupe reçoit son jour actuel comme premier passage, la configuration de chaque
formation est déduite de l'existant et les salles utilisées par une seule formation lui deviennent dédiées.

## 2. Authentification JWT

| Route | Rôle |
|---|---|
| `POST /auth/login` | renvoie `data` (utilisateur) + `access_token`, `token_type`, `expires_in`, `refresh_token`, `refresh_expires_in` ; pose le cookie HttpOnly `ages_refresh` |
| `POST /auth/refresh` | échange le jeton de renouvellement (corps `refresh_token` ou cookie) contre un nouveau couple de jetons |
| `POST /auth/logout` | révoque le jeton de renouvellement de ce navigateur et ferme la session |
| `POST /auth/logout-all` | révoque tous les jetons du compte et invalide immédiatement les jetons d'accès émis |

- Jeton d'accès : JWT HS256 (`sub`, `role`, `ver`, `exp`), envoyé en `Authorization: Bearer …`.
- Jeton de renouvellement : opaque, stocké haché (SHA-256), à usage unique (rotation). Réutiliser un
  jeton déjà échangé révoque toute sa famille (détection de vol).
- `users.token_version` est incrémenté par « déconnexion globale » et par changement de mot de passe :
  les jetons d'accès antérieurs sont refusés.
- Les droits ne sont pas lus dans le jeton : rôle et permissions (`role_permissions`) sont relus en base
  à chaque requête ; un compte désactivé perd l'accès immédiatement.
- Les mots de passe encore stockés en clair sont hachés à la première connexion réussie.
- Frontend : le jeton d'accès reste en mémoire ; il est renouvelé une minute avant expiration, et
  automatiquement après une réponse 401. Au chargement, la session est restaurée par le cookie.

## 3. Configuration de référence des formations

Trois paramètres **distincts** :

| Paramètre | Sens |
|---|---|
| `passages_semaine` (1–5) | nombre de jours de venue de **chaque groupe** par semaine |
| `nb_groupes` | nombre de groupes ouverts dans une session |
| `salles_par_groupe` | nombre de salles occupées **simultanément** par un groupe |

Besoin hebdomadaire = groupes × passages × salles (occupations salle-créneau).
S'y ajoutent `jours_autorises`, `heure_debut`/`heure_fin` ou plusieurs `creneaux`, `espacer_passages`, `couleur`.

- Chaque modification crée une version (`formation_config_historique` : version, instantané JSON, motif, auteur).
- Une session fige la version utilisée à sa création (`sessions.config_formation`, `config_version`) :
  modifier la formation ne change jamais les sessions existantes. Une session planifiée ou ouverte peut
  adopter explicitement la nouvelle version.
- Salles dédiées (`formation_salles`) : une salle a au plus une formation propriétaire (contrainte unique) ;
  une autre formation ne peut l'utiliser qu'avec un **partage** motivé, accordé par un utilisateur ayant
  le droit « Valider » du module Salles. Les créneaux manuels respectent la même règle.

| Route | Rôle |
|---|---|
| `GET /formations`, `GET /formations/{id}` | configuration, salles rattachées |
| `PUT /formations/{id}/configuration` | nouvelle version (`motif` conseillé) |
| `GET /formations/{id}/historique` | versions successives |
| `GET/PUT /formations/{id}/salles` | salles dédiées / partagées |

## 4. Planification

Règles appliquées par le moteur (`Model/PlanningSolver.php`, sans base de données) :

- un groupe garde les **mêmes salles** à chacun de ses passages (les auditeurs restent dans leur classe,
  avec le formateur titulaire de la salle) ; deux passages d'un groupe tombent des jours différents ;
- une salle et son formateur n'accueillent qu'un groupe à la fois, y compris vis-à-vis des autres
  sessions **dont les dates se recoupent** (deux sessions successives peuvent réutiliser les mêmes salles) ;
- seules les salles dédiées/partagées disponibles, avec un formateur titulaire actif, sont utilisées ;
- les créneaux ayant un historique (inscriptions, présences) ne sont jamais déplacés.

La recherche explore les combinaisons par retour arrière (groupes les plus contraints d'abord), classe
les solutions (équilibre des jours, passages espacés, préférences de la session précédente) et, en cas
d'impossibilité, explique pourquoi (paramètres incompatibles, capacité insuffisante, meilleure solution
partielle et groupes non placés).

| Route | Droit | Rôle |
|---|---|---|
| `POST /planification/simuler/{session}` | lecture | propositions, sans écriture (`mode` : `completer` \| `regenerer`) |
| `POST /planification/appliquer/{session}` | création | écrit la solution (`solution`, `accepter_partiel`) en transaction verrouillée, journalisée dans `planification_generations` |
| `POST /planification/reprendre/{session}` | création | reprend groupes, horaires, jours et salles d'une session précédente, puis adapte le planning |
| `POST /planification/adopter-configuration/{session}` | création | applique à la session la version actuelle de la formation |
| `POST /planification/verifier` | lecture | conflits d'un créneau envisagé + alternatives |
| `POST /planification/alternatives` | lecture | autres salles, autres jours, autres horaires libres |
| `PUT /planification/passages/{groupe}` | modification | jours de passage d'un groupe |
| `GET /planification/synthese` | lecture | séances programmées / réalisées / restantes / manquantes, passages, conflits, occupation |
| `GET /planification/conflits`, `/occupation`, `/generations/{session}` | lecture | détail |

À la création d'une session (`POST /sessions`), la configuration est figée puis, par défaut
(`reprendre_configuration`, `generer_planning`), reprise de la dernière session de la formation.

Créneaux manuels (`/emplois-du-temps`) : l'horaire suit le passage du groupe ; un nouveau jour devient
un passage si la configuration le permet ; salle, formateur et groupe sont contrôlés sur les horaires et
les périodes qui se chevauchent ; un refus (409) renvoie les conflits et des alternatives.

Présences : un auditeur est attendu à chaque jour de passage de son groupe, dans sa salle ; l'inscription
reste rattachée au créneau du premier passage.

## 5. Tests

```
phpunit.bat                                   # 127 tests (unitaires + intégration sur base jetable)
npm run test:aide                             # cohérence du centre d'aide
node tests/e2e-planification.mjs              # navigateur réel (simulation seulement)
```
