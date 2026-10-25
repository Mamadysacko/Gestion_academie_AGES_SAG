# Guide pratique : écrire et lancer vos propres tests

Tout se fait depuis `C:\xampp\htdocs\Backend_AGES` (invite de commandes ou terminal de VS Code).
Prérequis : XAMPP démarré (MySQL), et `vendor/` présent (sinon : `.\composer.bat install`).

> **Important — tapez `.\phpunit.bat`, pas `phpunit` ni `php vendor\bin\phpunit`.** Sur ce poste `php` n'est pas dans le PATH de Windows : les commandes « courtes » répondent `'php' n'est pas reconnu`. Les raccourcis `phpunit.bat` et `composer.bat` (à la racine de `Backend_AGES`) utilisent directement `C:\xampp\php\php.exe`. Il faut être dans le dossier `Backend_AGES` et mettre `.\` devant (PowerShell **et** cmd).

## A. Lancer les tests existants (2 minutes)

```
.\phpunit.bat
```
Résultat attendu : `OK (… tests, … assertions)` en vert. En rouge, PHPUnit affiche le nom du test, la valeur
attendue et la valeur reçue, avec le fichier et la ligne. Autres commandes utiles :

| Besoin | Commande |
|---|---|
| Seulement les tests sans base | `.\phpunit.bat --testsuite Unitaires` |
| Via Composer (même résultat) | `.\composer.bat test` |
| Seulement contrôleurs + base | `.\phpunit.bat --testsuite Integration` |
| Un fichier | `.\phpunit.bat tests\Integration\PositionControllerTest.php` |
| Un test précis | `.\phpunit.bat --filter testCreationValide` |
| Liste lisible de ce qui est vérifié | `.\phpunit.bat --testdox` |

## B. Écrire un test d'INTÉGRATION (contrôleur + base) — pas à pas

Exemple réel déjà écrit : `tests/Integration/TypeEmployeControllerTest.php`. Refaites-le pour un autre contrôleur
(ex. `SalleController`) en suivant ces 7 étapes.

1. **Créez le fichier** `tests/Integration/SalleControllerTest.php`. Le nom finit par `Test`, il est identique au nom de la classe.
2. **Écrivez le squelette** :
   ```php
   <?php
   final class SalleControllerTest extends ControllerTestCase
   {
   }
   ```
   (`ControllerTestCase` prépare la base de test, ouvre une transaction par test et vous connecte en administrateur.)
3. **Ajoutez un test = une méthode qui commence par `test`**, nommée comme une phrase :
   ```php
   public function testSalleInconnueRenvoie404(): void
   {
       [$code, $json] = $this->request(fn() => (new SalleController())->getById(999));

       self::assertSame(404, $code);
       self::assertFalse($json['success']);
   }
   ```
   `request()` appelle le contrôleur comme l'API et renvoie `[code HTTP, JSON]`.
4. **Pour envoyer des données (POST/PUT)**, passez le corps en second argument :
   ```php
   [$code, $json] = $this->request(fn() => (new SalleController())->create(), ['code' => 'S09', 'libelle' => 'Salle 9']);
   ```
   Pour un contrôleur qui lit `$_GET`, troisième argument : `['id_groupe' => 1]`.
5. **Vérifiez la base, pas seulement la réponse** :
   ```php
   self::assertSame('Salle 9', $this->scalar('SELECT libelle FROM salles WHERE code = ?', ['S09']));
   ```
6. **Ajoutez les cas de refus** : donnée manquante (400), doublon (409), inconnu (404). Plusieurs cas semblables → un `DataProvider` (voir `AuditeurControllerTest::creationsRefusees`).
7. **Lancez** `.\phpunit.bat --filter SalleControllerTest`, puis toute la suite.

**Besoin de données particulières ?** Ajoutez-les dans `tests/database/fixtures.sql` (ids fixes), jamais « à la main » dans un test.
Chaque test est annulé automatiquement : vous retrouvez toujours les mêmes données au départ.

**Tester un droit d'accès** (qui a le droit de faire quoi) :
```php
$this->loginAs(2); // 1 = admin, 2 = secrétaire (sans droit de passage), 3 = approbateur, null = non connecté
$code = null;
try { Authorization::authorizeRequest('resultats', 'POST', 'valider', '1'); } catch (RuntimeException $e) { $code = $e->getCode(); }
self::assertSame(403, $code);
```

## C. Écrire un test UNITAIRE (une règle, sans base) — pas à pas

Pour une fonction qui calcule ou met en forme sans lire la base (ex. `Presence::observationLabel`).

1. Fichier `tests/Unit/MaRegleTest.php`, `final class MaRegleTest extends PHPUnit\Framework\TestCase`.
2. Appelez la fonction avec des valeurs connues et comparez :
   ```php
   public function testPermissionSurUnJour(): void
   {
       self::assertSame('Permission le 02/10/2026', Presence::observationLabel('permissionnaire', '2026-10-02', '2026-10-02', null));
   }
   ```
3. Plusieurs valeurs à essayer → `#[DataProvider('cas')]` (voir `PresenceObservationTest`).
4. N'appelez **jamais** `new MonModele()` dans un test unitaire (il ouvre MySQL) : si vous en avez besoin, c'est un test d'intégration.

## D. Les vérifications les plus utiles (`self::…`)

| Je veux vérifier… | Instruction |
|---|---|
| Égalité stricte (code HTTP, texte, nombre) | `assertSame($attendu, $reçu)` |
| Booléen | `assertTrue(…)` / `assertFalse(…)` |
| Valeur vide | `assertNull(…)` |
| Nombre d'éléments d'une liste | `assertCount(3, $liste)` |
| Un texte contient un mot | `assertStringContainsString('mot', $texte)` |
| Une valeur appartient à une liste | `assertContains('x', $liste)` |

Écrivez toujours **d'abord l'attendu** : `assertSame(200, $code)`.

## E. Vérifier qu'un test est bon (à faire une fois par nouveau test)

1. Lancez-le : il doit passer.
2. Cassez volontairement le code testé (ex. changez `409` en `200` dans le contrôleur), relancez : **le test doit échouer**.
3. Rétablissez le code, relancez : tout est vert.
Un test qui ne peut jamais échouer ne protège de rien.

## F. Quand ça ne marche pas

| Message | Cause probable |
|---|---|
| `'php' n'est pas reconnu…` / `'phpunit' n'est pas reconnu…` | Vous n'êtes pas dans `Backend_AGES`, ou vous avez tapé `phpunit` / `php …` : utilisez `.\phpunit.bat` (avec `.\`). Option durable : ajouter `C:\xampp\php` au PATH Windows (Paramètres → Variables d'environnement), puis rouvrir le terminal. |
| `Erreur de connexion à la base de données` | MySQL n'est pas démarré dans XAMPP. |
| `Class "X" not found` | Faute de nom de classe, ou fichier du contrôleur/modèle absent de `Controller/` ou `Model/`. |
| `Failed asserting that 400 is identical to 201` | L'API a refusé : affichez `$json['error']` (ajoutez `fwrite(STDERR, json_encode($json));` temporairement). |
| Un test passe seul mais échoue dans la suite | Il dépend de données créées par un autre test : mettez-les dans `fixtures.sql`. |
| Le schéma de la vraie base a changé (migration) | Rien à faire : la base de test est reconstruite à chaque lancement. |
| `ARRÊT : les tests doivent viser academie_langues3_test` | Garde-fou : ne contournez jamais ce message, les tests ne doivent pas toucher la vraie base. |

## G. Voir ce qui se passe, sans PHPUnit

`C:\xampp\php\php.exe tests\run_controller_tests.php` (chemin complet : ce script n'a pas de raccourci) affiche chaque requête, sa réponse et ✔/✘. Ensuite, ouvrez
`academie_langues3_test` dans phpMyAdmin pour voir les données écrites.

## H. Routine conseillée

1. Avant de modifier un contrôleur : `.\phpunit.bat` (tout doit être vert).
2. Écrivez d'abord le test du comportement voulu (il échoue), puis modifiez le code jusqu'au vert.
3. Avant de valider (commit) : `.\phpunit.bat` une dernière fois.
