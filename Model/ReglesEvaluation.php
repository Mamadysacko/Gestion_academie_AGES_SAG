<?php
/**
 * Règles d'évaluation et de passage (tables : regles_evaluation, baremes_appreciation)
 *
 * - regles_evaluation : la ligne id = 1 est la règle générale (id_niveau NULL, non supprimable) ;
 *   une règle propre à un niveau la remplace pour les sessions de ce niveau.
 *   Champs : nombre d'évaluations par session, moyenne d'admission (/4),
 *   taux de présence minimal (%), passage automatique à la clôture.
 * - baremes_appreciation : échelle 1 à 4, chaque note ayant un seuil minimal de moyenne (/4).
 */

require_once __DIR__ . '/Model.php';

class ReglesEvaluation extends Model
{
    protected string $table = 'regles_evaluation';

    public const GENERAL_ID = 1;

    protected array $fillable = [
        'libelle', 'id_niveau', 'nb_evaluations', 'moyenne_admission', 'taux_presence_min', 'passage_automatique',
    ];

    /** Règle applicable : celle du niveau si elle existe, sinon la règle générale. */
    public function getRegles(?int $niveauId = null): array
    {
        $row = $niveauId !== null
            ? $this->fetchOne("SELECT * FROM {$this->table} WHERE id_niveau = :niveau LIMIT 1", ['niveau' => $niveauId])
            : null;
        $row = $row ?? $this->fetchOne("SELECT * FROM {$this->table} WHERE id = :id", ['id' => self::GENERAL_ID]);
        if ($row === null) {
            $this->db->exec("INSERT INTO {$this->table} (id, libelle) VALUES (1, 'Règle générale')");
            $row = $this->fetchOne("SELECT * FROM {$this->table} WHERE id = 1");
        }
        return $this->format($row);
    }

    /** Toutes les règles (générale d'abord) avec le libellé du niveau. */
    public function listRules(): array
    {
        return array_map(fn(array $row): array => $this->format($row) + ['niveau_libelle' => $row['niveau_libelle']], $this->fetchAll(
            "SELECT r.*, n.libelle AS niveau_libelle
             FROM {$this->table} r LEFT JOIN niveaux n ON n.id = r.id_niveau
             ORDER BY r.id_niveau IS NOT NULL, n.ordre, r.id"
        ));
    }

    /** Modifie la règle générale (compatibilité avec le panneau du module Évaluations). */
    public function updateRegles(array $data): array
    {
        $this->updateRule(self::GENERAL_ID, $data);
        return $this->getRegles();
    }

    /** @throws InvalidArgumentException */
    public function createRule(array $data): int
    {
        $values = $this->validateRule($data, null);
        if ($values['id_niveau'] === null) {
            throw new InvalidArgumentException('La règle générale existe déjà : choisissez le niveau concerné par cette règle.');
        }
        return $this->create($values);
    }

    /** @throws InvalidArgumentException */
    public function updateRule(int $id, array $data): bool
    {
        $current = $this->find($id);
        if ($current === null) {
            throw new InvalidArgumentException('Règle introuvable.');
        }
        $values = $this->validateRule($data + $current, $id);
        if ($id === self::GENERAL_ID) {
            $values['id_niveau'] = null;
        } elseif ($values['id_niveau'] === null) {
            throw new InvalidArgumentException('Une règle spécifique doit être rattachée à un niveau.');
        }
        return $this->update($id, $values);
    }

    /** @throws InvalidArgumentException */
    public function deleteRule(int $id): bool
    {
        if ($id === self::GENERAL_ID) {
            throw new InvalidArgumentException('La règle générale ne peut pas être supprimée : elle s’applique à tous les niveaux sans règle propre.');
        }
        return $this->delete($id);
    }

    /* ------------------------------------------------------------------
     | Barème d'appréciation
     |------------------------------------------------------------------ */

    /** Barème d'appréciation trié par note croissante. */
    public function getBareme(): array
    {
        return array_map(static fn(array $row): array => [
            'id' => (int) $row['note'],
            'note' => (int) $row['note'],
            'libelle' => $row['libelle'],
            'seuil_min' => (float) $row['seuil_min'],
        ], $this->fetchAll('SELECT * FROM baremes_appreciation ORDER BY note'));
    }

    /** Remplace tout le barème (4 lignes). @throws InvalidArgumentException */
    public function updateBareme(array $rows): array
    {
        $this->inScaleTransaction(function () use ($rows): void {
            $this->db->exec('DELETE FROM baremes_appreciation');
            foreach ($rows as $row) {
                $this->insertScaleRow($row);
            }
        });
        return $this->getBareme();
    }

    /** Ajoute une appréciation. @throws InvalidArgumentException */
    public function createScaleRow(array $row): void
    {
        $this->inScaleTransaction(function () use ($row): void {
            if ($this->fetchOne('SELECT 1 AS ok FROM baremes_appreciation WHERE note = :note', ['note' => (int) ($row['note'] ?? 0)])) {
                throw new InvalidArgumentException('Cette note existe déjà dans le barème.');
            }
            $this->insertScaleRow($row);
        });
    }

    /** Modifie une appréciation (libellé, seuil). @throws InvalidArgumentException */
    public function updateScaleRow(int $note, array $row): void
    {
        $this->inScaleTransaction(function () use ($note, $row): void {
            $current = $this->fetchOne('SELECT * FROM baremes_appreciation WHERE note = :note', ['note' => $note]);
            if ($current === null) {
                throw new InvalidArgumentException('Appréciation introuvable.');
            }
            $this->db->prepare('DELETE FROM baremes_appreciation WHERE note = :note')->execute(['note' => $note]);
            $this->insertScaleRow(['note' => $row['note'] ?? $note] + $row + $current);
        });
    }

    /** Supprime une appréciation. @throws InvalidArgumentException */
    public function deleteScaleRow(int $note): void
    {
        $this->inScaleTransaction(function () use ($note): void {
            $this->db->prepare('DELETE FROM baremes_appreciation WHERE note = :note')->execute(['note' => $note]);
        });
    }

    /** Appréciation (note 1 à 4 + libellé) correspondant à une moyenne sur 4. */
    public static function appreciationFor(?float $moyenne, array $bareme): ?array
    {
        if ($moyenne === null) {
            return null;
        }
        $result = null;
        foreach ($bareme as $row) {
            if ($moyenne + 1e-9 >= $row['seuil_min']) {
                $result = ['note' => $row['note'], 'libelle' => $row['libelle']];
            }
        }
        return $result;
    }

    /* ------------------------------------------------------------------
     | Interne
     |------------------------------------------------------------------ */

    private function format(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'libelle' => $row['libelle'],
            'id_niveau' => $row['id_niveau'] === null ? null : (int) $row['id_niveau'],
            'nb_evaluations' => (int) $row['nb_evaluations'],
            'moyenne_admission' => (float) $row['moyenne_admission'],
            'taux_presence_min' => (float) $row['taux_presence_min'],
            'passage_automatique' => (bool) $row['passage_automatique'],
        ];
    }

    private function validateRule(array $data, ?int $excludeId): array
    {
        $libelle = trim((string) ($data['libelle'] ?? ''));
        if ($libelle === '' || mb_strlen($libelle) > 100) {
            throw new InvalidArgumentException('Le libellé de la règle est obligatoire (100 caractères max.).');
        }
        $niveau = ($data['id_niveau'] ?? '') === '' || $data['id_niveau'] === null ? null : filter_var($data['id_niveau'], FILTER_VALIDATE_INT);
        if ($niveau === false || ($niveau !== null && !$this->fetchOne('SELECT 1 AS ok FROM niveaux WHERE id = :id', ['id' => $niveau]))) {
            throw new InvalidArgumentException('Le niveau sélectionné est introuvable.');
        }
        if ($niveau !== null && $this->fetchOne(
            "SELECT 1 AS ok FROM {$this->table} WHERE id_niveau = :niveau AND id <> :id",
            ['niveau' => $niveau, 'id' => (int) $excludeId]
        )) {
            throw new InvalidArgumentException('Une règle existe déjà pour ce niveau.');
        }
        $nb = filter_var($data['nb_evaluations'] ?? 3, FILTER_VALIDATE_INT);
        if ($nb === false || $nb < 1 || $nb > 9) {
            throw new InvalidArgumentException('Le nombre d’évaluations par session doit être compris entre 1 et 9.');
        }
        $moyenne = $data['moyenne_admission'] ?? 2;
        if (!is_numeric($moyenne) || $moyenne < 0 || $moyenne > 4) {
            throw new InvalidArgumentException('La moyenne d’admission doit être comprise entre 0 et 4.');
        }
        $presence = $data['taux_presence_min'] ?? 0;
        if (!is_numeric($presence) || $presence < 0 || $presence > 100) {
            throw new InvalidArgumentException('Le taux de présence minimal doit être compris entre 0 et 100 %.');
        }
        return [
            'libelle' => $libelle,
            'id_niveau' => $niveau,
            'nb_evaluations' => $nb,
            'moyenne_admission' => round((float) $moyenne, 2),
            'taux_presence_min' => round((float) $presence, 2),
            'passage_automatique' => in_array($data['passage_automatique'] ?? 1, [true, 1, '1', 'true', 'on'], true) ? 1 : 0,
        ];
    }

    private function insertScaleRow(array $row): void
    {
        $note = filter_var($row['note'] ?? null, FILTER_VALIDATE_INT);
        if ($note === false || $note < 1 || $note > 4) {
            throw new InvalidArgumentException('La note d’appréciation doit être comprise entre 1 et 4.');
        }
        $libelle = trim((string) ($row['libelle'] ?? ''));
        if ($libelle === '' || mb_strlen($libelle) > 50) {
            throw new InvalidArgumentException("Le libellé de l’appréciation {$note} est obligatoire (50 caractères max.).");
        }
        if (!is_numeric($row['seuil_min'] ?? null) || $row['seuil_min'] < 0 || $row['seuil_min'] > 4) {
            throw new InvalidArgumentException("Le seuil de l’appréciation {$note} doit être compris entre 0 et 4.");
        }
        $this->db->prepare('INSERT INTO baremes_appreciation (note, libelle, seuil_min) VALUES (:note, :libelle, :seuil)')
            ->execute(['note' => $note, 'libelle' => $libelle, 'seuil' => round((float) $row['seuil_min'], 2)]);
    }

    /**
     * Applique une modification du barème puis vérifie sa cohérence :
     * la plus petite note commence à 0 et les seuils croissent strictement avec la note.
     */
    private function inScaleTransaction(callable $change): void
    {
        $this->db->beginTransaction();
        try {
            $change();
            $rows = $this->getBareme();
            if ($rows === []) {
                throw new InvalidArgumentException('Le barème doit contenir au moins une appréciation.');
            }
            if ($rows[0]['seuil_min'] != 0.0) {
                throw new InvalidArgumentException('La plus petite appréciation doit commencer à 0 pour couvrir toutes les moyennes.');
            }
            for ($i = 1, $n = count($rows); $i < $n; $i++) {
                if ($rows[$i]['seuil_min'] <= $rows[$i - 1]['seuil_min']) {
                    throw new InvalidArgumentException('Les seuils doivent croître strictement avec la note (1 → 4).');
                }
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
