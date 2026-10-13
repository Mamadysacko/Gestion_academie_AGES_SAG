# 📚 Documentation API Complète - Backend AGES

## 🎯 Vue d'ensemble

API REST complète pour la gestion d'une académie de langues avec **18 contrôleurs** et **120+ endpoints**.

### 📦 Contrôleurs disponibles

| # | Contrôleur | Resource | Endpoints |
|---|------------|----------|-----------|
| 1 | AnneeScolaire | `/annees-scolaires` | 7 |
| 2 | Role | `/roles` | 6 |
| 3 | User | `/users` | 9 |
| 4 | Departement | `/departements` | 6 |
| 5 | Niveau | `/niveaux` | 6 |
| 6 | TypeFormation | `/types-formations` | 6 |
| 7 | Formateur | `/formateurs` | 9 |
| 8 | Salle | `/salles` | 7 |
| 9 | Session | `/sessions` | 10 |
| 10 | Groupe | `/groupes` | 9 |
| 11 | **Auditeur** | `/auditeurs` | 7 |
| 12 | **Inscription** | `/inscriptions` | 9 |
| 13 | **EmploiDuTemps** | `/emplois-du-temps` | 10 |
| 14 | **Evaluation** | `/evaluations` | 6 |
| 15 | **ParametreEvaluation** | `/parametres-evaluations` | 6 |
| 16 | **Note** | `/notes` | 8 |
| 17 | **PassageNiveau** | `/passages-niveaux` | 7 |
| 18 | **Presence** | `/presences` | 10 |

**Total : 18 contrôleurs, 130+ endpoints**

## Authentification et permissions par rôle

Les routes métiers nécessitent une session ouverte avec `POST /auth/login`. Le cookie de session est HttpOnly; le client récupère le compte courant avec `GET /auth/me` et termine la session avec `POST /auth/logout`.

Les permissions sont persistées dans `role_permissions` sous forme de couples module/action (`read`, `create`, `update`, `delete`, `export`, `validate`). `GET /roles` retourne chaque rôle avec sa matrice. Les droits se mettent à jour avec `PUT /roles/{id}/permissions` et un corps `{ "permissions": { "evaluations": ["read", "update"] } }`. Les routes de l'API contrôlent également les droits, indépendamment du masquage des boutons dans le frontend. Le rôle `admin` est réservé et dispose de tous les droits.

La création d'un utilisateur auquel le rôle `formateur` est attribué exige un champ `matricule`. Le compte et son profil `formateurs` associé (`formateurs.user_id`) sont créés dans une même transaction; le nom, le prénom, l'email, le téléphone, le mot de passe et l'état actif sont synchronisés depuis le compte. Les mises à jour de ces champs et l'activation/désactivation sont également répercutées sur le profil lié.

Avant d'utiliser l'API après mise à jour, exécuter une fois `database/migration_role_permissions.sql` sur `academie_langues3`. Le script préserve les rôles et utilisateurs existants, puis initialise le rôle `admin`.

### Profil personnel et espace formateur

Les routes de profil sont liées à la session courante; aucun identifiant d'utilisateur ou de formateur n'est accepté par le client :

```http
GET /auth/profile
PUT /auth/profile
GET /auth/trainer-portal
POST /auth/trainer-portal/presences
POST /auth/trainer-portal/notes
```

`PUT /auth/profile` accepte uniquement `nom`, `prenom`, `email`, `telephone` et, facultativement, `current_password` avec `new_password`. Le rôle, l'état du compte et les permissions restent en lecture seule. Le changement de mot de passe exige le mot de passe actuel et un nouveau mot de passe d'au moins 8 caractères. Pour un formateur lié à un compte, les coordonnées et le mot de passe sont synchronisés avec son profil formateur.

`GET /auth/trainer-portal` est réservé à l'utilisateur authentifié dont le rôle est `formateur` et qui possède un profil lié. Le serveur déduit le formateur de la session et limite les classes, inscriptions, présences et notes à ses propres affectations. La réponse contient les listes `schedules`, `learners`, `attendance`, `grades`, `evaluations` et `criteria`.

Les données de cet espace sont toujours limitées au profil formateur lié. Les droits `presences.create` et `presences.update` sont contrôlés lors du pointage. Les historiques du portail et la saisie de notes utilisent les routes dédiées, qui n'exposent que les inscriptions des classes affectées au formateur.

`POST /auth/trainer-portal/presences` permet à un formateur lié de pointer ses propres auditeurs. Le serveur déduit son identifiant de la session, vérifie l'inscription et le créneau attribués, puis exige `presences.create` pour un nouveau pointage ou `presences.update` pour modifier un pointage existant. Les administrateurs utilisent `POST /presences/mark`.

`POST /auth/trainer-portal/notes` permet au formateur de saisir ou modifier une note pour une inscription active de ses classes. Le serveur vérifie l'affectation du formateur, la session de l'évaluation, le critère associé et le barème. Les routes génériques `/notes` sont interdites aux formateurs afin d'empêcher la consultation ou la modification de notes hors de leurs classes.

Les permissions sont relues depuis la base à chaque requête authentifiée. L'application actualise périodiquement le profil connecté et l'espace formateur afin que les changements enregistrés dans la matrice s'appliquent sans reconnexion. Pour initialiser les droits par défaut du rôle formateur si aucun droit n'est encore enregistré sur une base existante, réexécuter `database/migration_role_permissions.sql`; le script ne remplit pas les droits d'un rôle formateur déjà configuré.

Le planning actuel enregistre un jour de semaine récurrent, mais pas les heures ni les séances datées. L'historique de planning est donc celui des affectations par année scolaire; l'historique des présences et des notes conserve ses dates.

---

## 🆕 Nouveaux Endpoints (Partie 3)

### 11. Auditeurs (`/auditeurs`)

#### Récupérer tous les auditeurs
```http
GET /auditeurs
```

#### Récupérer un auditeur par ID
```http
GET /auditeurs/{id}
```

#### Récupérer un auditeur par matricule
```http
GET /auditeurs/matricule/{matricule}
```

#### Rechercher des auditeurs
```http
GET /auditeurs/search/{term}
```
*Recherche par nom, prénom ou matricule*

#### Créer un auditeur
```http
POST /auditeurs
Content-Type: application/json

{
    "matricule": "AUD-001",
    "nom": "Ndiaye",
    "prenom": "Awa",
    "sexe": "F",
    "telephone": "775551234",
    "email": "awa.ndiaye@example.com"
}
```

#### Mettre à jour un auditeur
```http
PUT /auditeurs/{id}
Content-Type: application/json

{
    "telephone": "779876543",
    "email": "awa.ndiaye@newmail.com"
}
```

#### Supprimer un auditeur
```http
DELETE /auditeurs/{id}
```

---

### 12. Inscriptions (`/inscriptions`)

#### Récupérer toutes les inscriptions
```http
GET /inscriptions
```

#### Récupérer une inscription par ID
```http
GET /inscriptions/{id}
```

#### Récupérer une inscription avec détails complets
```http
GET /inscriptions/{id}/details
```

#### Récupérer les inscriptions d'un auditeur
```http
GET /inscriptions/auditeur/{id_auditeur}
```

#### Récupérer les inscriptions d'un groupe
```http
GET /inscriptions/groupe/{id_groupe}
```

#### Récupérer les inscriptions d'un département
```http
GET /inscriptions/departement/{id_departement}
```

#### Créer une inscription
```http
POST /inscriptions
Content-Type: application/json

{
    "id_auditeur": 1,
    "id_emploi_du_temps": 1,
    "id_departement": 1,
    "date_inscription": "2024-09-01",
    "statut": "active"
}
```

#### Mettre à jour une inscription
```http
PUT /inscriptions/{id}
Content-Type: application/json

{
    "statut": "terminee"
}
```

#### Changer le statut d'une inscription
```http
POST /inscriptions/{id}/statut
Content-Type: application/json

{
    "statut": "abandonnee"
}
```

**Statuts possibles** : `active`, `terminee`, `abandonnee`, `annulee`

#### Supprimer une inscription
```http
DELETE /inscriptions/{id}
```

---

### 13. Emplois du Temps (`/emplois-du-temps`)

#### Récupérer tous les emplois du temps
```http
GET /emplois-du-temps
```

#### Récupérer un emploi du temps par ID
```http
GET /emplois-du-temps/{id}
```

#### Récupérer le planning complet
```http
GET /emplois-du-temps/planning-complet
```

#### Récupérer les emplois du temps d'un groupe
```http
GET /emplois-du-temps/groupe/{id_groupe}
```

#### Récupérer les emplois du temps d'une salle
```http
GET /emplois-du-temps/salle/{id_salle}
```

#### Récupérer les emplois du temps d'un formateur
```http
GET /emplois-du-temps/formateur/{id_formateur}
```

#### Récupérer les emplois du temps d'un jour
```http
GET /emplois-du-temps/jour/{jour_semaine}
```
*jour_semaine : 1=lundi, 2=mardi, 3=mercredi, 4=jeudi, 5=vendredi*

#### Récupérer les emplois du temps d'une année
```http
GET /emplois-du-temps/annee/{id_annee}
```

#### Créer un emploi du temps
```http
POST /emplois-du-temps
Content-Type: application/json

{
    "id_annee": 1,
    "id_groupe": 1,
    "id_salle": 1,
    "id_formateur": 1,
    "jour_semaine": 1,
    "statut": 1
}
```

#### Vérifier la disponibilité
```http
POST /emplois-du-temps/check-disponibilite
Content-Type: application/json

{
    "id_annee": 1,
    "id_salle": 1,
    "id_formateur": 1,
    "jour_semaine": 1
}
```

#### Mettre à jour un emploi du temps
```http
PUT /emplois-du-temps/{id}
```

#### Supprimer un emploi du temps
```http
DELETE /emplois-du-temps/{id}
```

---

### 14. Évaluations (`/evaluations`)

#### Récupérer toutes les évaluations
```http
GET /evaluations
```

#### Récupérer une évaluation par ID
```http
GET /evaluations/{id}
```

#### Récupérer les évaluations d'une session
```http
GET /evaluations/session/{id_session}
```

#### Créer une évaluation
```http
POST /evaluations
Content-Type: application/json

{
    "id_session": 1,
    "libelle": "Examen Final",
    "date_evaluation": "2024-12-15"
}
```

#### Mettre à jour une évaluation
```http
PUT /evaluations/{id}
```

#### Supprimer une évaluation
```http
DELETE /evaluations/{id}
```

---

### 15. Paramètres d'Évaluation (`/parametres-evaluations`)

#### Récupérer tous les paramètres
```http
GET /parametres-evaluations
```

#### Récupérer un paramètre par ID
```http
GET /parametres-evaluations/{id}
```

#### Récupérer les paramètres d'une évaluation
```http
GET /parametres-evaluations/evaluation/{id_evaluation}
```

#### Créer un paramètre
```http
POST /parametres-evaluations
Content-Type: application/json

{
    "id_evaluation": 1,
    "libelle": "Compréhension orale",
    "bareme": 4.00
}
```

#### Mettre à jour un paramètre
```http
PUT /parametres-evaluations/{id}
```

#### Supprimer un paramètre
```http
DELETE /parametres-evaluations/{id}
```

---

### 16. Notes (`/notes`)

#### Récupérer toutes les notes
```http
GET /notes
```

#### Récupérer une note par ID
```http
GET /notes/{id_note}
```

#### Récupérer les notes d'une inscription
```http
GET /notes/inscription/{id_inscription}
```

#### Récupérer les notes d'un paramètre
```http
GET /notes/parametre/{id_parametre}
```

#### Récupérer la moyenne d'une inscription
```http
GET /notes/moyenne/{id_inscription}
```

Les moyennes retournées sont pondérées selon les barèmes et normalisées sur 4 (`moyenne_sur_4`).

#### Créer une note
```http
POST /notes
Content-Type: application/json

{
    "id_parametre": 1,
    "id_inscription": 1,
    "note": 3.5,
    "observation": "Très bon travail"
}
```

#### Enregistrer ou mettre à jour une note
```http
POST /notes/save-or-update
Content-Type: application/json

{
    "id_parametre": 1,
    "id_inscription": 1,
    "note": 3.75,
    "observation": "Excellent progrès"
}
```

#### Mettre à jour une note
```http
PUT /notes/{id_note}
```

#### Supprimer une note
```http
DELETE /notes/{id_note}
```

---

### 17. Passages de Niveau (`/passages-niveaux`)

#### Récupérer tous les passages
```http
GET /passages-niveaux
```

#### Récupérer un passage par ID
```http
GET /passages-niveaux/{id}
```

#### Récupérer le passage d'une inscription
```http
GET /passages-niveaux/inscription/{id_inscription}
```

#### Créer un passage de niveau
```http
POST /passages-niveaux
Content-Type: application/json

{
    "id_inscription": 1,
    "niveau_depart": 1,
    "niveau_arrivee": 2,
    "decision": "admis",
    "moyenne": 3.1,
    "date_decision": "2024-12-20",
    "observation": "Admis avec mention"
}
```

#### Enregistrer une décision de passage
```http
POST /passages-niveaux/decide
Content-Type: application/json

{
    "id_inscription": 1,
    "decision": "admis",
    "moyenne": 3.1,
    "niveau_arrivee": 2,
    "observation": "Excellent travail"
}
```

**Décisions possibles** : `admis`, `non_admis`, `redouble`, `abandonne`

#### Mettre à jour un passage
```http
PUT /passages-niveaux/{id}
```

#### Supprimer un passage
```http
DELETE /passages-niveaux/{id}
```

---

### 18. Présences (`/presences`)

#### Récupérer toutes les présences
```http
GET /presences
```

#### Récupérer une présence par ID
```http
GET /presences/{id}
```

#### Récupérer les présences d'une inscription
```http
GET /presences/inscription/{id_inscription}
```

#### Récupérer les présences d'une date
```http
GET /presences/date/{date}
```
*Format date : YYYY-MM-DD*

#### Récupérer les présences d'un formateur
```http
GET /presences/formateur/{id_formateur}
```

#### Récupérer les présences d'un emploi du temps
```http
GET /presences/emploi-du-temps/{id_edt}
```

#### Récupérer les statistiques de présence
```http
GET /presences/statistiques/{id_inscription}
```

#### Créer une présence
```http
POST /presences
Content-Type: application/json

{
    "id_inscription": 1,
    "id_emploi_du_temps": 1,
    "id_formateur": 1,
    "date_presence": "2024-09-16",
    "statut": "present",
    "observation": null
}
```

#### Marquer une présence
```http
POST /presences/mark
Content-Type: application/json

{
    "id_inscription": 1,
    "date_presence": "2024-09-15",
    "statut": "present",
    "id_formateur": 1,
    "id_emploi_du_temps": 1,
    "observation": null
}
```

**Statuts possibles** : `present`, `absent`, `permissionnaire`, `malade`, `conge`

Le pointage est refusé si l'inscription ou l'affectation est inactive, si la date ne correspond pas au jour récurrent ou aux bornes de l'année scolaire, ou si la date est future.

#### Mettre à jour une présence
```http
PUT /presences/{id}
```

#### Supprimer une présence
```http
DELETE /presences/{id}
```

---

## 📊 Statistiques Globales

### Contrôleurs : 18
- Gestion administrative : 5 (Année, Role, User, Departement, Niveau)
- Gestion pédagogique : 4 (TypeFormation, Session, Groupe, Evaluation)
- Gestion des ressources : 2 (Formateur, Salle)
- Gestion des apprenants : 2 (Auditeur, Inscription)
- Gestion académique : 5 (EmploiDuTemps, Note, ParametreEvaluation, PassageNiveau, Presence)

### Endpoints : 130+
- GET : ~75 endpoints
- POST : ~30 endpoints
- PUT : ~18 endpoints
- DELETE : ~18 endpoints

### Fonctionnalités clés
✅ CRUD complet sur toutes les entités  
✅ Recherches et filtres avancés  
✅ Validations métier  
✅ Gestion des contraintes d'unicité  
✅ Calculs automatiques (moyennes, statistiques)  
✅ Vérifications de disponibilité  
✅ Relations complètes entre entités  

---

## 🔐 Sécurité

- Requêtes préparées (PDO)
- Validation des données
- Codes HTTP appropriés
- Gestion des erreurs
- Protection contre SQL injection
- Mots de passe enregistrés en clair (pratique déconseillée)

---

## 📝 Notes importantes

1. **Format des dates** : YYYY-MM-DD
2. **Jours de la semaine** : 1 = lundi, 5 = vendredi
3. **Clé primaire notes** : `id_note` (pas `id`)
4. **Contraintes uniques** respectées
5. **Cascade delete** géré par la BDD
6. **Relations** : jointures automatiques disponibles

---

**API complète et opérationnelle** ✨  
**Version** : 2.0.0  
**Date** : 2024
