<?php
/**
 * Configuration de référence d'une formation (table types_formations + formation_salles
 * + formation_config_historique).
 *
 * Chaque modification crée une nouvelle version tracée ; les sessions gardent l'instantané de la
 * version utilisée à leur création, si bien que l'historique des sessions passées ne change jamais.
 */

require_once __DIR__ . '/Model.php';

class FormationConfig extends Model
{
    protected string $table = 'types_formations';

    /** Champs de configuration versionnés (en plus du libellé). */
    public const FIELDS = [
        'passages_semaine', 'nb_groupes', 'salles_par_groupe', 'jours_autorises',
        'heure_debut', 'heure_fin', 'creneaux', 'espacer_passages',
    ];

    public const MODES_SALLE = ['dediee', 'partagee'];

    /** Formations avec leur configuration, leurs salles et le nombre de sessions. */
    public function getAllWithConfig(): array
    {
        $rows = $this->fetchAll(
            "SELECT tf.*,
                    (SELECT COUNT(*) FROM sessions s WHERE s.id_type_formation = tf.id) AS nb_sessions,
                    (SELECT COUNT(*) FROM formation_salles fs WHERE fs.id_type_formation = tf.id AND fs.mode = 'dediee') AS nb_salles_dediees,
                    (SELECT COUNT(*) FROM formation_salles fs WHERE fs.id_type_formation = tf.id AND fs.mode = 'partagee') AS nb_salles_partagees
             FROM {$this->table} tf
             ORDER BY tf.libelle"
        );
        return array_map(fn($row) => $this->hydrate($row), $rows);
    }

    public function getWithConfig(int $id): ?array
    {
        $row = $this->find($id);
        if ($row === null) {
            return null;
        }
        $row = $this->hydrate($row);
        $row['salles'] = $this->getRooms($id);
        return $row;
    }

    /** Configuration seule (forme normalisée utilisée par le planificateur et les instantanés). */
    public function snapshot(array $row): array
    {
        $creneaux = $row['creneaux'];
        if (is_string($creneaux)) {
            $creneaux = json_decode($creneaux, true);
        }
        return [
            'passages_semaine' => (int) $row['passages_semaine'],
            'nb_groupes' => (int) $row['nb_groupes'],
            'salles_par_groupe' => (int) $row['salles_par_groupe'],
            'jours_autorises' => (string) $row['jours_autorises'],
            'heure_debut' => substr((string) $row['heure_debut'], 0, 5),
            'heure_fin' => substr((string) $row['heure_fin'], 0, 5),
            'creneaux' => is_array($creneaux) && $creneaux !== [] ? array_values($creneaux) : null,
            'espacer_passages' => (int) $row['espacer_passages'],
        ];
    }

    /** Créneaux horaires effectifs d'une configuration. */
    public static function slotsOf(array $config): array
    {
        if (!empty($config['creneaux'])) {
            return array_map(static fn($c) => ['debut' => substr($c['debut'], 0, 5), 'fin' => substr($c['fin'], 0, 5)], $config['creneaux']);
        }
        return [['debut' => substr($config['heure_debut'], 0, 5), 'fin' => substr($config['heure_fin'], 0, 5)]];
    }

    public static function daysOf(array $config): array
    {
        $days = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $config['jours_autorises'])), static fn($d) => $d >= 1 && $d <= 5)));
        sort($days);
        return $days;
    }

    /**
     * Valide et normalise une demande de modification (seuls les champs fournis sont contrôlés).
     * @throws InvalidArgumentException message explicite en cas d'incohérence.
     */
    public function validate(array $input, array $current): array
    {
        $clean = [];
        foreach (['passages_semaine' => [1, 5], 'nb_groupes' => [1, 50], 'salles_par_groupe' => [1, 50]] as $field => [$min, $max]) {
            if (array_key_exists($field, $input)) {
                $value = filter_var($input[$field], FILTER_VALIDATE_INT);
                if ($value === false || $value < $min || $value > $max) {
                    throw new InvalidArgumentException("Le champ « {$field} » doit être un entier entre {$min} et {$max}.");
                }
                $clean[$field] = $value;
            }
        }
        if (array_key_exists('jours_autorises', $input)) {
            $days = is_array($input['jours_autorises']) ? $input['jours_autorises'] : explode(',', (string) $input['jours_autorises']);
            $days = array_values(array_unique(array_map('intval', $days)));
            sort($days);
            if ($days === [] || array_filter($days, static fn($d) => $d < 1 || $d > 5)) {
                throw new InvalidArgumentException('Les jours autorisés doivent être compris entre 1 (lundi) et 5 (vendredi).');
            }
            $clean['jours_autorises'] = implode(',', $days);
        }
        foreach (['heure_debut', 'heure_fin'] as $field) {
            if (array_key_exists($field, $input)) {
                $clean[$field] = self::normalizeTime($input[$field], $field);
            }
        }
        if (array_key_exists('creneaux', $input)) {
            $clean['creneaux'] = $this->validateSlots($input['creneaux']);
        }
        if (array_key_exists('espacer_passages', $input)) {
            $clean['espacer_passages'] = filter_var($input['espacer_passages'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        if (array_key_exists('couleur', $input)) {
            $color = $input['couleur'];
            if ($color !== null && $color !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', (string) $color)) {
                throw new InvalidArgumentException('La couleur doit être au format #RRGGBB.');
            }
            $clean['couleur'] = $color === '' ? null : $color;
        }

        $merged = array_merge($this->snapshot($current), $clean);
        if ($merged['heure_fin'] <= $merged['heure_debut']) {
            throw new InvalidArgumentException('L’heure de fin doit être postérieure à l’heure de début.');
        }
        $dayCount = count(self::daysOf($merged));
        if ($merged['passages_semaine'] > $dayCount) {
            throw new InvalidArgumentException(sprintf(
                '%d passage(s) par semaine demandés, mais seulement %d jour(s) autorisé(s) : un groupe ne vient qu’une fois par jour.',
                $merged['passages_semaine'], $dayCount
            ));
        }
        return $clean;
    }

    /**
     * Enregistre une nouvelle version de la configuration, dans une transaction.
     * @return array configuration à jour (avec numéro de version)
     */
    public function updateConfig(int $id, array $changes, ?string $motif, ?int $userId): array
    {
        return Database::transaction(function () use ($id, $changes, $motif, $userId): array {
            $current = $this->fetchOne("SELECT * FROM {$this->table} WHERE id = :id FOR UPDATE", ['id' => $id]);
            if ($current === null) {
                throw new DomainException('Formation introuvable.', 404);
            }
            $clean = $this->validate($changes, $current);
            $before = $this->snapshot($current);
            $after = $this->snapshot(array_merge($current, $clean));
            $configChanged = $before !== $after;

            $set = $clean;
            if (array_key_exists('creneaux', $set)) {
                $set['creneaux'] = $set['creneaux'] === null ? null : json_encode($set['creneaux']);
            }
            if ($configChanged) {
                $set['config_version'] = (int) $current['config_version'] + 1;
                $set['config_updated_at'] = date('Y-m-d H:i:s');
            }
            if ($set !== []) {
                $assignments = implode(', ', array_map(static fn($c) => "{$c} = :{$c}", array_keys($set)));
                $this->db->prepare("UPDATE {$this->table} SET {$assignments} WHERE id = :__id")->execute($set + ['__id' => $id]);
            }
            if ($configChanged) {
                $this->db->prepare(
                    'INSERT INTO formation_config_historique (id_type_formation, version, configuration, motif, user_id)
                     VALUES (:id, :version, :config, :motif, :user)'
                )->execute([
                    'id' => $id,
                    'version' => $set['config_version'],
                    'config' => json_encode($after, JSON_UNESCAPED_UNICODE),
                    'motif' => $motif !== null ? mb_substr($motif, 0, 255) : null,
                    'user' => $userId,
                ]);
            }
            $result = $this->getWithConfig($id);
            $result['version_creee'] = $configChanged;
            return $result;
        });
    }

    public function getHistory(int $id): array
    {
        $rows = $this->fetchAll(
            "SELECT h.id, h.version, h.configuration, h.motif, h.created_at,
                    CONCAT(u.prenom, ' ', u.nom) AS auteur,
                    (SELECT COUNT(*) FROM sessions s WHERE s.id_type_formation = h.id_type_formation AND s.config_version = h.version) AS nb_sessions
             FROM formation_config_historique h
             LEFT JOIN users u ON u.id = h.user_id
             WHERE h.id_type_formation = :id
             ORDER BY h.version DESC",
            ['id' => $id]
        );
        foreach ($rows as &$row) {
            $row['configuration'] = json_decode((string) $row['configuration'], true);
        }
        unset($row);
        return $rows;
    }

    /* ------------------------------------------------------------------
     | Salles dédiées / partagées
     |------------------------------------------------------------------ */

    /** Salles rattachées à la formation, avec formateur titulaire et propriétaire éventuel. */
    public function getRooms(int $id): array
    {
        return $this->fetchAll(
            "SELECT fs.id_salle, fs.mode, fs.priorite, fs.motif, fs.created_at,
                    sa.code, sa.libelle, sa.capacite, sa.statut,
                    f.id AS id_formateur, CONCAT(f.prenom, ' ', f.nom) AS formateur, f.actif AS formateur_actif,
                    CONCAT(u.prenom, ' ', u.nom) AS autorise_par,
                    (SELECT tf2.libelle FROM formation_salles fs2 JOIN types_formations tf2 ON tf2.id = fs2.id_type_formation
                      WHERE fs2.id_salle = fs.id_salle AND fs2.mode = 'dediee' LIMIT 1) AS proprietaire
             FROM formation_salles fs
             JOIN salles sa ON sa.id = fs.id_salle
             LEFT JOIN formateurs f ON f.id_salle = sa.id
             LEFT JOIN users u ON u.id = fs.autorise_par
             WHERE fs.id_type_formation = :id
             ORDER BY fs.mode, fs.priorite, sa.code",
            ['id' => $id]
        );
    }

    /** Propriétaire (formation dédiée) de chaque salle : id_salle => [id, libelle]. */
    public function roomOwners(): array
    {
        $owners = [];
        foreach ($this->fetchAll(
            "SELECT fs.id_salle, tf.id, tf.libelle FROM formation_salles fs
             JOIN types_formations tf ON tf.id = fs.id_type_formation WHERE fs.mode = 'dediee'"
        ) as $row) {
            $owners[(int) $row['id_salle']] = ['id' => (int) $row['id'], 'libelle' => $row['libelle']];
        }
        return $owners;
    }

    /**
     * Une formation peut-elle utiliser cette salle ?
     * Oui si la salle lui est dédiée ou partagée avec elle, ou si elle n'est dédiée à aucune formation.
     * @return string|null message de refus
     */
    public function roomRefusal(int $formationId, int $roomId): ?string
    {
        $row = $this->fetchOne(
            "SELECT
                (SELECT mode FROM formation_salles WHERE id_type_formation = :f AND id_salle = :s1) AS lien,
                (SELECT tf.libelle FROM formation_salles fs JOIN types_formations tf ON tf.id = fs.id_type_formation
                  WHERE fs.id_salle = :s2 AND fs.mode = 'dediee' AND fs.id_type_formation <> :f2 LIMIT 1) AS autre_proprietaire",
            ['f' => $formationId, 's1' => $roomId, 's2' => $roomId, 'f2' => $formationId]
        );
        if ($row['lien'] !== null || $row['autre_proprietaire'] === null) {
            return null;
        }
        return sprintf(
            'Cette salle est dédiée à la formation « %s ». Une autorisation explicite de partage est nécessaire pour l’utiliser (Formations › Salles › Autoriser un partage).',
            $row['autre_proprietaire']
        );
    }

    /**
     * Remplace l'ensemble des salles rattachées à une formation (transaction).
     * Règles : une salle n'a qu'une formation propriétaire ; le partage d'une salle dédiée à une autre
     * formation exige l'autorisation explicite ($canShare) et un motif.
     * @param array $rooms [['id_salle'=>…, 'mode'=>'dediee'|'partagee', 'priorite'=>…, 'motif'=>…], …]
     */
    public function setRooms(int $formationId, array $rooms, bool $canShare, ?int $userId): array
    {
        try {
            return Database::transaction(fn() => $this->replaceRooms($formationId, $rooms, $canShare, $userId));
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000' && str_contains($e->getMessage(), 'uk_fs_proprietaire_unique')) {
                throw new DomainException('Une des salles est déjà dédiée à une autre formation.', 409);
            }
            throw $e;
        }
    }

    private function replaceRooms(int $formationId, array $rooms, bool $canShare, ?int $userId): array
    {
        if ($this->fetchOne("SELECT id FROM {$this->table} WHERE id = :id FOR UPDATE", ['id' => $formationId]) === null) {
            throw new DomainException('Formation introuvable.', 404);
        }
        $owners = $this->roomOwners();
        $existing = [];
        foreach ($this->fetchAll('SELECT * FROM formation_salles WHERE id_type_formation = :id', ['id' => $formationId]) as $row) {
            $existing[(int) $row['id_salle']] = $row;
        }

        $seen = [];
        $priority = 0;
        foreach ($rooms as $room) {
            $roomId = (int) ($room['id_salle'] ?? 0);
            $mode = (string) ($room['mode'] ?? 'dediee');
            if ($roomId <= 0 || isset($seen[$roomId])) {
                throw new InvalidArgumentException('Liste de salles invalide (identifiant manquant ou en double).');
            }
            if (!in_array($mode, self::MODES_SALLE, true)) {
                throw new InvalidArgumentException("Mode de salle invalide : « {$mode} » (dediee ou partagee).");
            }
            if (!$this->fetchColumn('SELECT 1 FROM salles WHERE id = :id', ['id' => $roomId])) {
                throw new InvalidArgumentException("La salle {$roomId} n’existe pas.");
            }
            $owner = $owners[$roomId] ?? null;
            if ($mode === 'dediee' && $owner !== null && $owner['id'] !== $formationId) {
                throw new DomainException(sprintf('La salle %s est déjà dédiée à la formation « %s ». Retirez-la d’abord de cette formation, ou demandez un partage.', $this->roomCode($roomId), $owner['libelle']), 409);
            }
            $isNewShare = $mode === 'partagee' && (($existing[$roomId]['mode'] ?? null) !== 'partagee');
            if ($isNewShare && $owner !== null && $owner['id'] !== $formationId) {
                if (!$canShare) {
                    throw new DomainException('Le partage d’une salle dédiée à une autre formation exige le droit « Valider » du module Salles.', 403);
                }
                if (trim((string) ($room['motif'] ?? '')) === '') {
                    throw new InvalidArgumentException(sprintf('Indiquez le motif du partage de la salle %s (dédiée à « %s »).', $this->roomCode($roomId), $owner['libelle']));
                }
            }
            $seen[$roomId] = [
                'mode' => $mode,
                'priorite' => isset($room['priorite']) ? max(0, (int) $room['priorite']) : $priority,
                'motif' => isset($room['motif']) ? mb_substr(trim((string) $room['motif']), 0, 255) : ($existing[$roomId]['motif'] ?? null),
                'autorise_par' => $isNewShare ? $userId : ($existing[$roomId]['autorise_par'] ?? null),
            ];
            $priority++;
        }

        // Une salle retirée redevient libre ; les créneaux déjà planifiés dans cette salle restent valables.
        $this->db->prepare('DELETE FROM formation_salles WHERE id_type_formation = :id')->execute(['id' => $formationId]);
        $insert = $this->db->prepare(
            'INSERT INTO formation_salles (id_type_formation, id_salle, mode, priorite, motif, autorise_par)
             VALUES (:f, :s, :mode, :priorite, :motif, :user)'
        );
        foreach ($seen as $roomId => $room) {
            $insert->execute([
                'f' => $formationId, 's' => $roomId, 'mode' => $room['mode'], 'priorite' => $room['priorite'],
                'motif' => $room['motif'], 'user' => $room['autorise_par'],
            ]);
        }
        return $this->getRooms($formationId);
    }

    /* ------------------------------------------------------------------
     | Interne
     |------------------------------------------------------------------ */

    private function hydrate(array $row): array
    {
        $row['creneaux'] = is_string($row['creneaux']) ? json_decode($row['creneaux'], true) : $row['creneaux'];
        $row['configuration'] = $this->snapshot($row);
        $row['jours'] = self::daysOf($row);
        return $row;
    }

    private function roomCode(int $roomId): string
    {
        return (string) ($this->fetchColumn('SELECT code FROM salles WHERE id = :id', ['id' => $roomId]) ?: $roomId);
    }

    private function validateSlots($slots): ?array
    {
        if ($slots === null || $slots === '' || $slots === []) {
            return null;
        }
        if (!is_array($slots) || count($slots) > 6) {
            throw new InvalidArgumentException('Les créneaux doivent être une liste (6 au maximum) de { debut, fin }.');
        }
        $clean = [];
        foreach ($slots as $slot) {
            $start = self::normalizeTime($slot['debut'] ?? null, 'debut');
            $end = self::normalizeTime($slot['fin'] ?? null, 'fin');
            if ($end <= $start) {
                throw new InvalidArgumentException("Créneau {$start}–{$end} : l’heure de fin doit suivre l’heure de début.");
            }
            $clean[] = ['debut' => substr($start, 0, 5), 'fin' => substr($end, 0, 5)];
        }
        usort($clean, static fn($a, $b) => strcmp($a['debut'], $b['debut']));
        for ($i = 1; $i < count($clean); $i++) {
            if ($clean[$i]['debut'] < $clean[$i - 1]['fin']) {
                throw new InvalidArgumentException("Les créneaux {$clean[$i - 1]['debut']}–{$clean[$i - 1]['fin']} et {$clean[$i]['debut']}–{$clean[$i]['fin']} se chevauchent.");
            }
        }
        return $clean;
    }

    private static function normalizeTime($value, string $field): string
    {
        if (!is_string($value) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value)) {
            throw new InvalidArgumentException("L’horaire « {$field} » doit respecter le format HH:MM.");
        }
        return substr($value, 0, 5) . ':00';
    }
}
