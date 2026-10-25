<?php
/**
 * Modèle TypeFormation (table : types_formations)
 */

require_once __DIR__ . '/Model.php';

class TypeFormation extends Model
{
    protected string $table = 'types_formations';

    protected array $fillable = [
        'libelle',
    ];

    /** Crée la formation et la version 1 de sa configuration de référence (valeurs par défaut). */
    public function create(array $data): int
    {
        require_once __DIR__ . '/FormationConfig.php';
        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        try {
            $id = parent::create($data);
            $config = new FormationConfig();
            $this->db->prepare(
                "INSERT INTO formation_config_historique (id_type_formation, version, configuration, motif)
                 VALUES (:id, 1, :config, 'Configuration initiale')"
            )->execute(['id' => $id, 'config' => json_encode($config->snapshot($config->find($id)), JSON_UNESCAPED_UNICODE)]);
            if ($ownTx) {
                $this->db->commit();
            }
            return $id;
        } catch (Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** Trouve un type de formation par son libellé unique. */
    public function findByLibelle(string $libelle): ?array
    {
        return $this->fetchOne(
            "SELECT * FROM {$this->table} WHERE libelle = :libelle LIMIT 1",
            ['libelle' => $libelle]
        );
    }
}
