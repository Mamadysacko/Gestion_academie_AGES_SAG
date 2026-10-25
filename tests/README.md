# Tests du backend (PHPUnit 11)

## Lancer

Depuis `C:\xampp\htdocs\Backend_AGES` (`php` n'est pas dans le PATH : utilisez les raccourcis `.bat`, avec `.\` devant) :

```
.\phpunit.bat                              # tout (≈ 1 s)
.\phpunit.bat --testsuite Unitaires        # sans base de données
.\phpunit.bat --testsuite Integration      # contrôleurs + base de test
.\phpunit.bat --filter testCreation        # un test précis (par nom)
.\phpunit.bat --testdox                    # liste lisible des comportements vérifiés
.\composer.bat test                        # équivalent via Composer
```

Installation sur un autre poste : `.\composer.bat install` (composer.phar : https://getcomposer.org/download/).
`vendor/` et `composer.phar` ne sont pas versionnés ; `composer.json` et `composer.lock` le sont.

## Organisation

| Dossier / fichier | Rôle |
|---|---|
| `phpunit.xml` | Configuration : les 2 suites, le bootstrap, les règles strictes (avertissement ou test « risqué » = échec). |
| `bootstrap.php` | Vise **toujours** `academie_langues3_test` (jamais la vraie base) et charge les classes. |
| `Unit/` | Tests **unitaires** : une règle précise, sans MySQL ni contrôleur (calculs, validations, droits). |
| `Integration/` | Tests **d'intégration** : un contrôleur, ses modèles et la base de test. |
| `Support/ControllerTestCase.php` | Socle d'intégration : base reconstruite une fois, **une transaction annulée par test**, méthode `request()` pour appeler un contrôleur. |
| `Support/PhpInput.php` | Fournit le corps JSON aux contrôleurs (ils lisent `php://input`). |
| `database/fixtures.sql` | Les données de test (identifiants fixes). |
| `database/setup_test_db.php` | Recrée `academie_langues3_test` : structure copiée de la vraie base (lecture seule) + fixtures. |
| `run_controller_tests.php` | Version « démonstration » : affiche chaque requête et sa réponse. À garder pour **voir** ce qui se passe. |

## Procédure pour écrire un test (dans cet ordre)

1. **Choisir le bon type.**
   - La règle tient dans une fonction sans base de données (calcul, mise en forme, droit) → `tests/Unit/`.
   - Le comportement traverse contrôleur + SQL (code HTTP, données écrites) → `tests/Integration/`.
2. **Nommer le fichier et la classe** `QuelqueChoseTest` (suffixe `Test`), `final class … extends TestCase`
   (ou `ControllerTestCase` en intégration).
3. **Un test = un comportement**, nommé comme une phrase : `testUnAuditeurInscritNePeutPasEtreSupprime`.
4. **Écrire en trois temps** : *préparer* (les données viennent de `fixtures.sql`), *agir* (appeler), *vérifier*
   (`assertSame` pour le code HTTP, puis **une requête SQL de contrôle** : la réponse JSON ne prouve pas que la base a changé).
5. **Tester aussi les refus** (400, 401, 403, 404, 409), pas seulement le cas qui marche. Plusieurs cas
   proches → un `#[DataProvider]` (voir `AuditeurControllerTest::creationsRefusees`).
6. **Ne jamais dépendre d'un autre test.** En intégration chaque test est annulé automatiquement ; si une
   donnée manque, ajoutez-la dans `fixtures.sql` plutôt que de l'insérer dans le test.
7. **Vérifier que le test sait échouer** : cassez volontairement le code (ou inversez l'attendu), constatez
   l'échec, puis rétablissez. Un test qui ne peut jamais échouer ne protège de rien.
8. **Lancer toute la suite** avant de valider un changement.

## Exemple minimal (intégration)

```php
final class SalleControllerTest extends ControllerTestCase
{
    public function testSalleInconnueRenvoie404(): void
    {
        [$code, $json] = $this->request(fn() => (new SalleController())->getById(999));

        self::assertSame(404, $code);
        self::assertFalse($json['success']);
    }
}
```

## Règles à retenir

- La vraie base `academie_langues3` n'est jamais écrite par les tests (sa structure est seulement lue).
- `fixtures.sql` décrit : 3 niveaux, 1 session avec 3 évaluations, 4 auditeurs (3 inscrits), des notes,
  3 rôles (admin, secrétaire sans droit de passage, approbateur avec). Les tests s'appuient sur ces ids.
- Après un changement du **schéma** de la vraie base (migration), rien à faire : la base de test est
  reconstruite à chaque lancement à partir de la structure actuelle.
