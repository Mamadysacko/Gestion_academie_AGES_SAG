<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Model/Model.php';
require_once __DIR__ . '/../../Model/Auditeur.php';

final class AuditeurTest extends TestCase
{
    public function testSexesAutorisesSontMEtF(): void
    {
        self::assertSame(['M', 'F'], Auditeur::SEXES);
    }

    public function testTableAuditeursEstCorrecte(): void
    {
        $reflection = new ReflectionClass(Auditeur::class);
        $auditeur = $reflection->newInstanceWithoutConstructor();

        $table = $reflection->getProperty('table');
        $table->setAccessible(true);

        self::assertSame('auditeurs', $table->getValue($auditeur));
    }

    public function testChampsAutorisésSontDefinis(): void
    {
        $reflection = new ReflectionClass(Auditeur::class);
        $auditeur = $reflection->newInstanceWithoutConstructor();

        $fillable = $reflection->getProperty('fillable');
        $fillable->setAccessible(true);

        $champs = $fillable->getValue($auditeur);

        self::assertContains('matricule', $champs);
        self::assertContains('nom', $champs);
        self::assertContains('prenom', $champs);
        self::assertContains('sexe', $champs);
        self::assertContains('email', $champs);
        self::assertNotContains('id', $champs);
    }

    public function testFiltrageDesChampsNonAutorises(): void
    {
        $reflection = new ReflectionClass(Auditeur::class);
        $auditeur = $reflection->newInstanceWithoutConstructor();

        $methode = $reflection->getMethod('filterData');
        $methode->setAccessible(true);

        $resultat = $methode->invoke($auditeur, [
            'matricule' => 'TEST-001',
            'nom' => 'Diallo',
            'prenom' => 'Mamadou',
            'id' => 999,
            'champ_inconnu' => 'interdit'
        ]);

        self::assertSame([
            'matricule' => 'TEST-001',
            'nom' => 'Diallo',
            'prenom' => 'Mamadou'
        ], $resultat);
    }
}
