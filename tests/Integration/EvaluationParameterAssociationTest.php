<?php

final class EvaluationParameterAssociationTest extends ControllerTestCase
{
    public function testUneNouvelleEvaluationRecupereAutomatiquementTousLesParametres(): void
    {
        $id = (new Evaluation())->create([
            'id_session' => 1,
            'numero' => 4,
            'libelle' => 'Évaluation supplémentaire',
            'date_evaluation' => '2026-12-11',
        ]);

        self::assertSame(
            2,
            (int) $this->scalar('SELECT COUNT(*) FROM evaluation_parametres WHERE id_evaluation = ?', [$id])
        );
    }

    public function testUnNouveauParametreEstAssociePartoutEtPeutEtreDetache(): void
    {
        $id = (new ParametreEvaluation())->create([
            'libelle' => 'Participation',
            'bareme' => 5,
        ]);

        self::assertSame(
            3,
            (int) $this->scalar('SELECT COUNT(*) FROM evaluation_parametres WHERE id_parametre = ?', [$id])
        );

        [$detachCode] = $this->request(
            fn() => (new ParametreEvaluationController())->detachFromEvaluation(1, $id)
        );

        self::assertSame(200, $detachCode);
        self::assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM evaluation_parametres WHERE id_evaluation = 1 AND id_parametre = ?',
            [$id]
        ));
        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM evaluation_parametres WHERE id_evaluation = 2 AND id_parametre = ?',
            [$id]
        ));
    }
}
