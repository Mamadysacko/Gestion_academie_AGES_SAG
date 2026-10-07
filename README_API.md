# API Backend AGES - Documentation

## Configuration

### Prérequis
- PHP 8.0 ou supérieur
- MySQL 8.0 ou supérieur
- XAMPP ou serveur web compatible

### Installation
1. Importer la base de données : `database/DB Gestion Academie.sql`
2. Configurer les paramètres de connexion dans `config/config.php`
3. S'assurer que le serveur Apache et MySQL sont démarrés

### URL de base
```
http://localhost/Backend_AGES/Route/api.php/
```

## Endpoints disponibles

### 1. Années Scolaires (`/annees-scolaires`)

#### Récupérer toutes les années scolaires
```http
GET /annees-scolaires
```

#### Récupérer l'année scolaire active
```http
GET /annees-scolaires/active
```

#### Récupérer une année scolaire par ID
```http
GET /annees-scolaires/{id}
```

#### Créer une année scolaire
```http
POST /annees-scolaires
Content-Type: application/json

{
    "libelle": "2024-2025",
    "date_debut": "2024-09-01",
    "date_fin": "2025-06-30",
    "statut": 1
}
```

#### Mettre à jour une année scolaire
```http
PUT /annees-scolaires/{id}
Content-Type: application/json

{
    "libelle": "2024-2025",
    "statut": 1
}
```

#### Activer une année scolaire
```http
POST /annees-scolaires/{id}/activate
```

#### Supprimer une année scolaire
```http
DELETE /annees-scolaires/{id}
```

---

### 2. Rôles (`/roles`)

#### Récupérer tous les rôles
```http
GET /roles
```

#### Récupérer un rôle par ID
```http
GET /roles/{id}
```

#### Récupérer un rôle par code
```http
GET /roles/code/{code}
```

#### Créer un rôle
```http
POST /roles
Content-Type: application/json

{
    "code": "admin",
    "nom": "Administrateur"
}
```

#### Mettre à jour un rôle
```http
PUT /roles/{id}
Content-Type: application/json

{
    "nom": "Administrateur Système"
}
```

#### Supprimer un rôle
```http
DELETE /roles/{id}
```

---

### 3. Utilisateurs (`/users`)

#### Récupérer tous les utilisateurs
```http
GET /users
```

#### Récupérer un utilisateur par ID
```http
GET /users/{id}
```

#### Récupérer un utilisateur par email
```http
GET /users/email/{email}
```

#### Récupérer les utilisateurs d'un rôle
```http
GET /users/role/{role_id}
```

#### Créer un utilisateur
```http
POST /users
Content-Type: application/json

{
    "nom": "Diop",
    "prenom": "Amadou",
    "email": "amadou.diop@example.com",
    "telephone": "771234567",
    "password": "motdepasse123",
    "role_id": 1,
    "actif": 1
}
```

#### Mettre à jour un utilisateur
```http
PUT /users/{id}
Content-Type: application/json

{
    "telephone": "779876543",
    "actif": 1
}
```

#### Authentifier un utilisateur
```http
POST /users/authenticate
Content-Type: application/json

{
    "email": "amadou.diop@example.com",
    "password": "motdepasse123"
}
```

#### Activer/Désactiver un utilisateur
```http
POST /users/{id}/toggle-actif
```

#### Supprimer un utilisateur
```http
DELETE /users/{id}
```

---

### 4. Départements (`/departements`)

#### Récupérer tous les départements
```http
GET /departements
```

#### Récupérer un département par ID
```http
GET /departements/{id}
```

#### Récupérer un département par code
```http
GET /departements/code/{code}
```

#### Créer un département
```http
POST /departements
Content-Type: application/json

{
    "code": "ANGLAIS",
    "libelle": "Département d'Anglais"
}
```

#### Mettre à jour un département
```http
PUT /departements/{id}
Content-Type: application/json

{
    "libelle": "Département de Langue Anglaise"
}
```

#### Supprimer un département
```http
DELETE /departements/{id}
```

---

### 5. Niveaux (`/niveaux`)

#### Récupérer tous les niveaux
```http
GET /niveaux
```

#### Récupérer un niveau par ID
```http
GET /niveaux/{id}
```

#### Récupérer un niveau par libellé
```http
GET /niveaux/libelle/{libelle}
```

#### Créer un niveau
```http
POST /niveaux
Content-Type: application/json

{
    "libelle": "Débutant"
}
```

#### Mettre à jour un niveau
```http
PUT /niveaux/{id}
Content-Type: application/json

{
    "libelle": "Niveau Débutant A1"
}
```

#### Supprimer un niveau
```http
DELETE /niveaux/{id}
```

---

## Codes de réponse HTTP

| Code | Signification |
|------|---------------|
| 200  | Succès |
| 201  | Créé avec succès |
| 400  | Requête invalide |
| 401  | Non authentifié |
| 404  | Ressource non trouvée |
| 405  | Méthode non autorisée |
| 409  | Conflit (doublon) |
| 500  | Erreur serveur |

## Format des réponses

### Réponse de succès
```json
{
    "success": true,
    "data": { ... },
    "message": "Opération réussie"
}
```

### Réponse d'erreur
```json
{
    "success": false,
    "error": "Message d'erreur détaillé"
}
```

## Exemples d'utilisation avec cURL

### Créer un rôle
```bash
curl -X POST http://localhost/Backend_AGES/Route/api.php/roles \
  -H "Content-Type: application/json" \
  -d '{"code":"scolarite","nom":"Service Scolarité"}'
```

### Authentifier un utilisateur
```bash
curl -X POST http://localhost/Backend_AGES/Route/api.php/users/authenticate \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password123"}'
```
Le champ `email` accepte également le numéro de téléphone enregistré. Les clients existants peuvent utiliser `usernameOrEmail` à la place de `email`.

### Récupérer l'année scolaire active
```bash
curl -X GET http://localhost/Backend_AGES/Route/api.php/annees-scolaires/active
```

## Notes importantes

1. **Mots de passe** : Les mots de passe fournis lors de la création ou de la mise à jour sont enregistrés en clair. C'est dangereux pour la sécurité ; les anciens mots de passe déjà hashés restent acceptés à la connexion pour préserver les comptes existants, mais ne peuvent pas être récupérés automatiquement.

2. **Contraintes d'unicité** : 
   - Années scolaires : libellé unique
   - Rôles : code et nom uniques
   - Users : email unique
   - Départements : code unique
   - Niveaux : libellé unique

3. **Validation des dates** : Pour les années scolaires, la date de fin doit être postérieure à la date de début.

4. **Suppression en cascade** : Certaines suppressions peuvent échouer si des enregistrements liés existent (contraintes de clés étrangères).

5. **CORS** : Les headers CORS sont configurés pour accepter toutes les origines (`*`). À ajuster en production.


---

### 6. Types de Formation (`/types-formations`)

#### Récupérer tous les types de formation
```http
GET /types-formations
```

#### Récupérer un type de formation par ID
```http
GET /types-formations/{id}
```

#### Récupérer un type de formation par libellé
```http
GET /types-formations/libelle/{libelle}
```

#### Créer un type de formation
```http
POST /types-formations
Content-Type: application/json

{
    "libelle": "Formation Intensive"
}
```

#### Mettre à jour un type de formation
```http
PUT /types-formations/{id}
Content-Type: application/json

{
    "libelle": "Formation Intensive Accélérée"
}
```

#### Supprimer un type de formation
```http
DELETE /types-formations/{id}
```

---

### 7. Formateurs (`/formateurs`)

#### Récupérer tous les formateurs
```http
GET /formateurs
```

#### Récupérer un formateur par ID
```http
GET /formateurs/{id}
```

#### Récupérer un formateur par matricule
```http
GET /formateurs/matricule/{matricule}
```

#### Récupérer un formateur par email
```http
GET /formateurs/email/{email}
```

#### Récupérer les formateurs actifs
```http
GET /formateurs/actifs
```

#### Récupérer le formateur lié à un utilisateur
```http
GET /formateurs/user/{user_id}
```

#### Créer un formateur
```http
POST /formateurs
Content-Type: application/json

{
    "matricule": "FOR-001",
    "nom": "Sall",
    "prenom": "Ibrahim",
    "password": "password123",
    "telephone": "778765432",
    "email": "ibrahim.sall@ages.sn",
    "actif": 1,
    "user_id": null
}
```

#### Mettre à jour un formateur
```http
PUT /formateurs/{id}
Content-Type: application/json

{
    "telephone": "779876543",
    "actif": 1
}
```

#### Activer/Désactiver un formateur
```http
POST /formateurs/{id}/toggle-actif
```

#### Supprimer un formateur
```http
DELETE /formateurs/{id}
```

---

### 8. Salles (`/salles`)

#### Récupérer toutes les salles
```http
GET /salles
```

#### Récupérer une salle par ID
```http
GET /salles/{id}
```

#### Récupérer une salle par code
```http
GET /salles/code/{code}
```

#### Récupérer les salles disponibles
```http
GET /salles/disponibles
```

#### Créer une salle
```http
POST /salles
Content-Type: application/json

{
    "code": "S01",
    "libelle": "Salle 01",
    "capacite": 20,
    "statut": "disponible"
}
```

#### Mettre à jour une salle
```http
PUT /salles/{id}
Content-Type: application/json

{
    "capacite": 25,
    "statut": "disponible"
}
```

#### Changer le statut d'une salle
```http
POST /salles/{id}/statut
Content-Type: application/json

{
    "statut": "indisponible"
}
```

#### Supprimer une salle
```http
DELETE /salles/{id}
```

---

### 9. Sessions (`/sessions`)

#### Récupérer toutes les sessions
```http
GET /sessions
```

#### Récupérer une session par ID
```http
GET /sessions/{id}
```

#### Récupérer une session avec ses relations
```http
GET /sessions/{id}/relations
```

#### Récupérer les sessions d'une année scolaire
```http
GET /sessions/annee/{id_annee}
```

#### Récupérer les sessions d'un type de formation
```http
GET /sessions/type-formation/{id_type}
```

#### Récupérer les sessions d'un niveau
```http
GET /sessions/niveau/{id_niveau}
```

#### Créer une session
```http
POST /sessions
Content-Type: application/json

{
    "id_annee": 1,
    "id_type_formation": 1,
    "id_niveau": 1,
    "code": "ANG-A1-2024",
    "libelle": "Anglais Débutant Session 1",
    "date_debut": "2024-09-01",
    "date_fin": "2024-12-15",
    "statut": "planifiee"
}
```

#### Mettre à jour une session
```http
PUT /sessions/{id}
Content-Type: application/json

{
    "statut": "en_cours",
    "date_fin": "2024-12-20"
}
```

#### Changer le statut d'une session
```http
POST /sessions/{id}/statut
Content-Type: application/json

{
    "statut": "ouverte"
}
```

**Statuts possibles** : `planifiee`, `ouverte`, `en_cours`, `terminee`, `annulee`

#### Supprimer une session
```http
DELETE /sessions/{id}
```

---

### 10. Groupes (`/groupes`)

#### Récupérer tous les groupes
```http
GET /groupes
```

#### Récupérer un groupe par ID
```http
GET /groupes/{id}
```

#### Récupérer un groupe avec sa session
```http
GET /groupes/{id}/session
```

#### Récupérer les groupes d'une session
```http
GET /groupes/session/{id_session}
```

#### Récupérer les groupes d'un jour de la semaine
```http
GET /groupes/jour/{jour_semaine}
```
*Jours : 1 = lundi, 2 = mardi, 3 = mercredi, 4 = jeudi, 5 = vendredi*

#### Récupérer les places restantes dans un groupe
```http
GET /groupes/{id}/places-restantes
```

#### Créer un groupe
```http
POST /groupes
Content-Type: application/json

{
    "id_session": 1,
    "code": "G1",
    "libelle": "Groupe 1",
    "jour_semaine": 1,
    "capacite": 20,
    "statut": 1
}
```

#### Mettre à jour un groupe
```http
PUT /groupes/{id}
Content-Type: application/json

{
    "capacite": 25,
    "statut": 1
}
```

#### Supprimer un groupe
```http
DELETE /groupes/{id}
```

---

## Validations spécifiques

### Types de Formation
- Libellé unique

### Formateurs
- Matricule unique
- Email unique et valide (si fourni)
- Mot de passe enregistré tel que fourni (stockage en clair)

### Salles
- Code unique
- Capacité > 0 (si fournie)
- Statut : `disponible` ou `indisponible`

### Sessions
- Code unique
- Combinaison (année, type_formation, libellé) unique
- Date fin >= date début
- Statut : `planifiee`, `ouverte`, `en_cours`, `terminee`, `annulee`

### Groupes
- Combinaison (session, code) unique
- Jour de semaine : 1 à 5 (lundi à vendredi)
- Capacité > 0 (si fournie)

## Exemples avancés

### Créer une session complète avec toutes les relations
```bash
curl -X POST http://localhost/Backend_AGES/Route/api.php/sessions \
  -H "Content-Type: application/json" \
  -d '{
    "id_annee": 1,
    "id_type_formation": 1,
    "id_niveau": 1,
    "code": "ANG-A1-2024-S1",
    "libelle": "Anglais Débutant A1",
    "date_debut": "2024-09-01",
    "date_fin": "2024-12-15",
    "statut": "planifiee"
  }'
```

### Récupérer tous les formateurs actifs
```bash
curl -X GET http://localhost/Backend_AGES/Route/api.php/formateurs/actifs
```

### Vérifier les places disponibles dans un groupe
```bash
curl -X GET http://localhost/Backend_AGES/Route/api.php/groupes/1/places-restantes
```
