<?php
/**
 * Moteur de recherche des affectations hebdomadaires (sans base de données).
 *
 * Trois paramètres distincts, à ne pas confondre :
 *   - passages    : nombre de jours de venue de CHAQUE groupe dans la semaine ;
 *   - groupes     : nombre de groupes de la session (fournis dans `groupes`) ;
 *   - salles_par_groupe : nombre de salles occupées simultanément par un groupe.
 *
 * Règles appliquées :
 *   - un groupe garde les MÊMES salles à chacun de ses passages (ses auditeurs restent dans leur
 *     classe, avec le formateur titulaire de la salle) ;
 *   - deux passages d'un même groupe tombent des jours différents ;
 *   - une salle (et son formateur) ne reçoit qu'un groupe à la fois : aucun chevauchement d'horaires
 *     avec un autre groupe de la session ni avec les réservations externes (`occupations`) ;
 *   - les groupes « fixes » (créneaux déjà utilisés par des inscriptions) ne bougent pas ; un groupe
 *     fixe incomplet reçoit seulement les passages qui lui manquent, dans ses salles.
 *
 * La recherche explore toutes les combinaisons (retour arrière, groupes les plus contraints d'abord),
 * jusqu'à `max_solutions` solutions distinctes ou `max_noeuds` étapes, puis classe les solutions :
 * charge équilibrée entre les jours, passages espacés, préférences reprises de la session précédente.
 */

final class PlanningSolver
{
    private array $days;
    private array $slots;
    private int $passages;
    private int $roomsPerGroup;
    private bool $spread;
    /** @var array<int,array> salles utilisables indexées par id */
    private array $rooms;
    private array $groups;
    private int $maxSolutions;
    private int $maxNodes;

    /** Occupation courante : "salle|jour" => [[debut, fin, libellé], …] */
    private array $roomBusy = [];
    /** Occupation courante : "formateur|jour" => [[debut, fin, libellé], …] */
    private array $trainerBusy = [];
    /** Nombre d'occupations salle par jour (tenu à jour à chaque réservation). */
    private array $loadByDay = [];

    private int $nodes = 0;
    private array $solutions = [];
    private array $signatures = [];
    private array $current = [];
    private array $bestPartial = [];
    private array $failures = [];

    public function __construct(array $input)
    {
        $this->days = array_values(array_unique(array_map('intval', $input['jours'] ?? [1, 2, 3, 4, 5])));
        sort($this->days);
        $this->slots = array_values($input['creneaux'] ?? [['debut' => '08:00', 'fin' => '12:00']]);
        foreach ($this->slots as &$slot) {
            $slot = ['debut' => self::time($slot['debut']), 'fin' => self::time($slot['fin'])];
        }
        unset($slot);
        $this->passages = max(1, (int) ($input['passages'] ?? 1));
        $this->roomsPerGroup = max(1, (int) ($input['salles_par_groupe'] ?? 1));
        $this->spread = (bool) ($input['espacer'] ?? true);
        $this->maxSolutions = max(1, (int) ($input['max_solutions'] ?? 25));
        $this->maxNodes = max(100, (int) ($input['max_noeuds'] ?? 40000));

        $this->rooms = [];
        foreach ($input['salles'] ?? [] as $room) {
            $this->rooms[(int) $room['id']] = [
                'id' => (int) $room['id'],
                'code' => (string) ($room['code'] ?? $room['id']),
                'priorite' => (int) ($room['priorite'] ?? 0),
                'formateur' => isset($room['formateur']) ? (int) $room['formateur'] : null,
            ];
        }
        uasort($this->rooms, static fn($a, $b) => $a['priorite'] <=> $b['priorite'] ?: strnatcasecmp($a['code'], $b['code']));

        foreach ($input['occupations'] ?? [] as $busy) {
            $this->reserve((int) $busy['id_salle'], (int) $busy['jour'], self::time($busy['debut']), self::time($busy['fin']), (string) ($busy['libelle'] ?? 'réservé'), isset($busy['id_formateur']) ? (int) $busy['id_formateur'] : null);
        }

        $this->groups = [];
        foreach ($input['groupes'] ?? [] as $group) {
            $presetDays = array_values(array_unique(array_map('intval', $group['jours'] ?? [])));
            sort($presetDays);
            $this->groups[] = [
                'cle' => (string) ($group['cle'] ?? $group['id'] ?? $group['code']),
                'code' => (string) ($group['code'] ?? ''),
                'fixe' => (bool) ($group['fixe'] ?? false),
                'jours' => $presetDays,
                'creneau' => isset($group['creneau']) ? ['debut' => self::time($group['creneau']['debut']), 'fin' => self::time($group['creneau']['fin'])] : null,
                'salles' => array_values(array_map('intval', $group['salles'] ?? [])),
                'pref_jours' => array_map('intval', $group['preferences']['jours'] ?? []),
                'pref_salles' => array_map('intval', $group['preferences']['salles'] ?? []),
            ];
        }
    }

    /** Lance la recherche et retourne solutions classées + diagnostic. */
    public function solve(): array
    {
        $todo = [];
        foreach ($this->groups as $group) {
            if ($group['fixe']) {
                // Créneaux figés : ils réservent leurs salles avant toute recherche.
                $slot = $group['creneau'] ?? $this->slots[0];
                foreach ($group['jours'] as $day) {
                    foreach ($group['salles'] as $roomId) {
                        $this->reserve($roomId, $day, $slot['debut'], $slot['fin'], $group['code'], $this->rooms[$roomId]['formateur'] ?? null);
                    }
                }
                if (count($group['jours']) >= $this->passages) {
                    $this->current[$group['cle']] = $this->assignment($group, $group['jours'], $slot, $group['salles'], true);
                    continue;
                }
            }
            $todo[] = $group;
        }
        $diagnostic = $this->preflight($todo);

        // Paramètres incompatibles : inutile de chercher. Capacité insuffisante : on cherche quand même
        // la meilleure solution partielle, utile pour décider quoi ajouter.
        $impossible = $this->passages > count($this->days) || $this->rooms === [] || $this->roomsPerGroup > count($this->rooms);
        if (!$impossible) {
            // Les groupes les plus contraints (fixes incomplets, préférences fortes) d'abord.
            usort($todo, static fn($a, $b) => [(int) !$a['fixe'], -count($a['salles'])] <=> [(int) !$b['fixe'], -count($b['salles'])]);
            $this->search($todo, 0, 0.0);
        }

        usort($this->solutions, static fn($a, $b) => $a['score'] <=> $b['score']);
        $complete = $this->solutions !== [];

        if (!$complete && !$impossible && $todo !== []) {
            $this->greedyPartial($todo);
            $diagnostic['bloquant'][] = $this->explainFailure($todo);
        }

        return [
            'complete' => $complete,
            'nb_solutions' => count($this->solutions),
            'exploration_complete' => $this->nodes < $this->maxNodes && count($this->solutions) < $this->maxSolutions,
            'noeuds' => $this->nodes,
            'solutions' => array_map(fn($s) => [
                'score' => round($s['score'], 2),
                'affectations' => array_values($s['affectations']),
                'charge_par_jour' => $s['charge'],
            ], array_slice($this->solutions, 0, 5)),
            'partielle' => $complete ? null : array_values($this->bestPartial),
            'non_places' => $complete ? [] : $this->unplaced($todo),
            'diagnostic' => $diagnostic,
            'capacite' => $diagnostic['capacite'],
        ];
    }

    /* ------------------------------------------------------------------
     | Recherche
     |------------------------------------------------------------------ */

    private function search(array $todo, int $index, float $score): void
    {
        if (count($this->solutions) >= $this->maxSolutions || $this->nodes >= $this->maxNodes) {
            return;
        }
        $this->nodes++;

        if ($index === count($todo)) {
            $this->record($score);
            return;
        }
        if ($index >= count($this->bestPartial) - $this->countFixed()) {
            $this->bestPartial = $this->current;
        }

        $group = $todo[$index];
        $candidates = $this->candidates($group);
        if ($candidates === []) {
            $this->failures[$group['cle']] = ($this->failures[$group['cle']] ?? 0) + 1;
            return;
        }

        foreach ($candidates as $candidate) {
            $this->apply($group, $candidate);
            $this->search($todo, $index + 1, $score + $candidate['score']);
            $this->undo($group, $candidate);
            if (count($this->solutions) >= $this->maxSolutions || $this->nodes >= $this->maxNodes) {
                return;
            }
        }
    }

    /** Combinaisons (jours × créneau × salles) possibles pour un groupe dans l'état courant. */
    private function candidates(array $group): array
    {
        $missing = $this->passages - count($group['jours']);
        $freeDays = array_values(array_diff($this->days, $group['jours']));
        $slots = $group['creneau'] !== null ? [$group['creneau']] : $this->slots;
        $result = [];

        foreach (self::combinations($freeDays, max(0, $missing)) as $combo) {
            $days = array_merge($group['jours'], $combo);
            sort($days);
            foreach ($slots as $slot) {
                foreach ($this->roomSetsFor($group, $days, $slot) as $rooms) {
                    $result[] = [
                        'jours' => $days,
                        'nouveaux_jours' => $combo,
                        'creneau' => $slot,
                        'salles' => $rooms,
                        'score' => $this->candidateScore($group, $days, $rooms),
                    ];
                }
            }
        }
        usort($result, static fn($a, $b) => $a['score'] <=> $b['score']);
        return $result;
    }

    /**
     * Ensembles de salles possibles, libres à tous les jours demandés (celles du groupe si elles sont
     * imposées). Deux variantes : ordre de priorité, et « remplissage » (salles déjà chargées les autres
     * jours d'abord) qui évite de morceler les salles libres pour les groupes suivants.
     */
    private function roomSetsFor(array $group, array $days, array $slot): array
    {
        $isFree = function (int $roomId) use ($days, $slot, $group): bool {
            if (!isset($this->rooms[$roomId])) {
                return false;
            }
            foreach ($days as $day) {
                if (in_array($day, $group['jours'], true) && $group['salles'] !== []) {
                    continue; // passage déjà en place pour ce groupe
                }
                if (!$this->roomFree($roomId, $day, $slot['debut'], $slot['fin'])) {
                    return false;
                }
            }
            return true;
        };

        if ($group['salles'] !== []) {
            foreach ($group['salles'] as $roomId) {
                if (!$isFree($roomId)) {
                    return [];
                }
            }
            return [$group['salles']];
        }

        $free = array_values(array_filter(array_keys($this->rooms), $isFree));
        if (count($free) < $this->roomsPerGroup) {
            return [];
        }
        // Salles déjà connues du groupe (session précédente) d'abord, puis priorité et code.
        $preferred = $group['pref_salles'];
        $byPriority = $free;
        usort($byPriority, function (int $a, int $b) use ($preferred): int {
            $pa = in_array($a, $preferred, true) ? 0 : 1;
            $pb = in_array($b, $preferred, true) ? 0 : 1;
            return $pa <=> $pb ?: $this->rooms[$a]['priorite'] <=> $this->rooms[$b]['priorite'] ?: strnatcasecmp($this->rooms[$a]['code'], $this->rooms[$b]['code']);
        });
        $sets = [array_slice($byPriority, 0, $this->roomsPerGroup)];

        if (count($free) > $this->roomsPerGroup) {
            $load = [];
            foreach ($free as $roomId) {
                $load[$roomId] = 0;
                foreach ($this->days as $day) {
                    $load[$roomId] += count($this->roomBusy["{$roomId}|{$day}"] ?? []);
                }
            }
            $packed = $free;
            usort($packed, fn(int $a, int $b): int => $load[$b] <=> $load[$a] ?: strnatcasecmp($this->rooms[$a]['code'], $this->rooms[$b]['code']));
            $packed = array_slice($packed, 0, $this->roomsPerGroup);
            $sortedA = $sets[0];
            $sortedB = $packed;
            sort($sortedA);
            sort($sortedB);
            if ($sortedA !== $sortedB) {
                usort($packed, fn(int $a, int $b): int => strnatcasecmp($this->rooms[$a]['code'], $this->rooms[$b]['code']));
                $sets[] = $packed;
            }
        }
        return $sets;
    }

    /** Solution partielle gloutonne : place chaque groupe qui peut l'être, saute les autres. */
    private function greedyPartial(array $todo): void
    {
        $saved = [$this->roomBusy, $this->trainerBusy, $this->current, $this->loadByDay];
        foreach ($todo as $group) {
            $candidates = $this->candidates($group);
            if ($candidates !== []) {
                $this->apply($group, $candidates[0]);
            }
        }
        if (count($this->current) > count($this->bestPartial)) {
            $this->bestPartial = $this->current;
        }
        [$this->roomBusy, $this->trainerBusy, $this->current, $this->loadByDay] = $saved;
    }

    private function roomFree(int $roomId, int $day, string $start, string $end): bool
    {
        foreach ($this->roomBusy["{$roomId}|{$day}"] ?? [] as [$s, $e]) {
            if ($start < $e && $s < $end) {
                return false;
            }
        }
        $trainer = $this->rooms[$roomId]['formateur'] ?? null;
        if ($trainer !== null) {
            foreach ($this->trainerBusy["{$trainer}|{$day}"] ?? [] as [$s, $e]) {
                if ($start < $e && $s < $end) {
                    return false;
                }
            }
        }
        return true;
    }

    /** Plus le score est bas, meilleure est la proposition. */
    private function candidateScore(array $group, array $days, array $rooms): float
    {
        $score = 0.0;
        foreach ($days as $day) {
            $score += $this->dayLoad($day) * 0.5; // équilibre : éviter d'entasser les groupes un même jour
            if ($group['pref_jours'] !== [] && !in_array($day, $group['pref_jours'], true)) {
                $score += 3;
            }
        }
        if ($this->spread) {
            for ($i = 1; $i < count($days); $i++) {
                if ($days[$i] - $days[$i - 1] === 1) {
                    $score += 2; // passages consécutifs moins favorables à l'apprentissage
                }
            }
        }
        if ($group['pref_salles'] !== []) {
            $score += count(array_diff($rooms, $group['pref_salles'])) * 0.5;
        }
        return $score;
    }

    private function dayLoad(int $day): int
    {
        return $this->loadByDay[$day] ?? 0;
    }

    private function apply(array $group, array $candidate): void
    {
        foreach ($candidate['jours'] as $day) {
            if (in_array($day, $group['jours'], true) && $group['salles'] !== []) {
                continue;
            }
            foreach ($candidate['salles'] as $roomId) {
                $this->reserve($roomId, $day, $candidate['creneau']['debut'], $candidate['creneau']['fin'], $group['code'], $this->rooms[$roomId]['formateur'] ?? null);
            }
        }
        $this->current[$group['cle']] = $this->assignment($group, $candidate['jours'], $candidate['creneau'], $candidate['salles'], $group['fixe'], $candidate['nouveaux_jours']);
    }

    private function undo(array $group, array $candidate): void
    {
        foreach ($candidate['jours'] as $day) {
            if (in_array($day, $group['jours'], true) && $group['salles'] !== []) {
                continue;
            }
            foreach ($candidate['salles'] as $roomId) {
                array_pop($this->roomBusy["{$roomId}|{$day}"]);
                $this->loadByDay[$day]--;
                $trainer = $this->rooms[$roomId]['formateur'] ?? null;
                if ($trainer !== null) {
                    array_pop($this->trainerBusy["{$trainer}|{$day}"]);
                }
            }
        }
        unset($this->current[$group['cle']]);
    }

    private function reserve(int $roomId, int $day, string $start, string $end, string $label, ?int $trainerId): void
    {
        $this->roomBusy["{$roomId}|{$day}"][] = [$start, $end, $label];
        $this->loadByDay[$day] = ($this->loadByDay[$day] ?? 0) + 1;
        if ($trainerId !== null) {
            $this->trainerBusy["{$trainerId}|{$day}"][] = [$start, $end, $label];
        }
    }

    private function record(float $score): void
    {
        // Deux solutions qui ne diffèrent que par l'ordre de groupes interchangeables sont identiques.
        $shapes = array_map(static fn($a) => ($a['fixe'] ? $a['cle'] . ':' : '') . implode(',', $a['jours']) . '@' . $a['creneau']['debut'] . '#' . implode(',', $a['salles']), $this->current);
        sort($shapes);
        $signature = implode(';', $shapes);
        if (isset($this->signatures[$signature])) {
            return;
        }
        $this->signatures[$signature] = true;

        $charge = [];
        foreach ($this->days as $day) {
            $charge[$day] = $this->dayLoad($day);
        }
        $mean = array_sum($charge) / max(1, count($charge));
        $variance = 0.0;
        foreach ($charge as $value) {
            $variance += ($value - $mean) ** 2;
        }
        $this->solutions[] = [
            'score' => $score + sqrt($variance / max(1, count($charge))),
            'affectations' => $this->current,
            'charge' => $charge,
        ];
    }

    private function assignment(array $group, array $days, array $slot, array $rooms, bool $fixed, array $newDays = []): array
    {
        return [
            'cle' => $group['cle'],
            'code' => $group['code'],
            'fixe' => $fixed,
            'jours' => array_values($days),
            'nouveaux_jours' => array_values($newDays),
            'creneau' => ['debut' => substr($slot['debut'], 0, 5), 'fin' => substr($slot['fin'], 0, 5)],
            'salles' => array_values($rooms),
            'salles_codes' => array_map(fn($id) => $this->rooms[$id]['code'] ?? (string) $id, $rooms),
        ];
    }

    /* ------------------------------------------------------------------
     | Diagnostic
     |------------------------------------------------------------------ */

    /** Contrôles immédiats : paramètres incompatibles et capacité insuffisante. */
    private function preflight(array $todo): array
    {
        $blocking = [];
        $warnings = [];
        $roomCount = count($this->rooms);

        if ($this->passages > count($this->days)) {
            $blocking[] = sprintf('La formation prévoit %d passage(s) par semaine, mais seuls %d jour(s) sont autorisés.', $this->passages, count($this->days));
        }
        if ($roomCount === 0) {
            $blocking[] = 'Aucune salle utilisable : rattachez des salles disponibles à la formation et donnez-leur un formateur titulaire actif.';
        } elseif ($this->roomsPerGroup > $roomCount) {
            $blocking[] = sprintf('Chaque groupe doit occuper %d salle(s) simultanément, mais la formation ne dispose que de %d salle(s) utilisable(s).', $this->roomsPerGroup, $roomCount);
        }

        $capacity = $this->capacity($todo);
        if ($roomCount > 0 && $capacity['requis'] > $capacity['disponible']) {
            $blocking[] = sprintf(
                'Capacité insuffisante : %d groupe(s) à placer × %d passage(s) × %d salle(s) = %d occupations salle-créneau nécessaires, %d disponibles (%d salle(s) × %d jour(s) × %d créneau(x), moins %d déjà réservée(s)).',
                $capacity['groupes'], $this->passages, $this->roomsPerGroup, $capacity['requis'], $capacity['disponible'],
                $roomCount, count($this->days), count($this->slots), $capacity['reservees']
            );
        }
        foreach ($this->groups as $group) {
            if ($group['salles'] !== [] && count($group['salles']) !== $this->roomsPerGroup && $group['fixe']) {
                $warnings[] = sprintf('Le groupe %s occupe %d salle(s) (configuration : %d) ; ses créneaux actuels sont conservés (mode « Compléter » ou historique d’inscriptions). Utilisez « Regénérer » pour recalculer les groupes sans historique.', $group['code'], count($group['salles']), $this->roomsPerGroup);
            }
            if (count($group['jours']) > $this->passages) {
                $warnings[] = sprintf('Le groupe %s a %d passages enregistrés pour %d prévu(s) par la configuration.', $group['code'], count($group['jours']), $this->passages);
            }
        }
        return ['bloquant' => $blocking, 'avertissements' => $warnings, 'capacite' => $capacity];
    }

    /** Occupations salle-créneau nécessaires aux groupes restant à placer, et occupations encore libres. */
    private function capacity(array $todo): array
    {
        $required = 0;
        foreach ($todo as $group) {
            $rooms = $group['salles'] !== [] ? count($group['salles']) : $this->roomsPerGroup;
            $required += max(0, $this->passages - count($group['jours'])) * $rooms;
        }
        $roomCount = count($this->rooms);
        $reserved = 0;
        foreach ($this->rooms as $roomId => $_) {
            foreach ($this->days as $day) {
                foreach ($this->slots as $slot) {
                    if (!$this->roomFree($roomId, $day, $slot['debut'], $slot['fin'])) {
                        $reserved++;
                    }
                }
            }
        }
        $total = $roomCount * count($this->days) * count($this->slots);
        return [
            'groupes' => count($todo),
            'requis' => $required,
            'disponible' => max(0, $total - $reserved),
            'total' => $total,
            'reservees' => $reserved,
        ];
    }

    private function explainFailure(array $todo): string
    {
        $placed = count($this->bestPartial) - $this->countFixed();
        $blocked = $this->unplaced($todo);
        $names = implode(', ', array_map(static fn($g) => $g['code'], $blocked));
        return sprintf(
            'Aucune répartition complète sans conflit : au mieux %d groupe(s) sur %d peuvent être placés. Groupe(s) sans créneau possible : %s. '
            . 'Chaque groupe doit trouver %d jour(s) où les mêmes %d salle(s) sont libres. Ajoutez des salles à la formation, réduisez le nombre de salles par groupe ou de passages, ou autorisez d\'autres jours/créneaux.',
            max(0, $placed), count($todo), $names !== '' ? $names : '—', $this->passages, $this->roomsPerGroup
        );
    }

    private function unplaced(array $todo): array
    {
        $result = [];
        foreach ($todo as $group) {
            if (!isset($this->bestPartial[$group['cle']])) {
                $result[] = ['cle' => $group['cle'], 'code' => $group['code']];
            }
        }
        return $result;
    }

    private function countFixed(): int
    {
        $count = 0;
        foreach ($this->groups as $group) {
            if ($group['fixe'] && count($group['jours']) >= $this->passages) {
                $count++;
            }
        }
        return $count;
    }

    /* ------------------------------------------------------------------
     | Outils
     |------------------------------------------------------------------ */

    /** Combinaisons de $k éléments parmi $items (ordre conservé). */
    public static function combinations(array $items, int $k): array
    {
        if ($k === 0) {
            return [[]];
        }
        if (count($items) < $k) {
            return [];
        }
        $result = [];
        $first = array_shift($items);
        foreach (self::combinations($items, $k - 1) as $rest) {
            $result[] = array_merge([$first], $rest);
        }
        foreach (self::combinations($items, $k) as $rest) {
            $result[] = $rest;
        }
        return $result;
    }

    /** Normalise une heure en HH:MM:SS (comparaisons de chaînes fiables). */
    public static function time(string $value): string
    {
        $value = trim($value);
        return strlen($value) === 5 ? $value . ':00' : substr($value, 0, 8);
    }
}
