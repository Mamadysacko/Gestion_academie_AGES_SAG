<?php
/**
 * Moteur de rapports : catalogue de rapports (7 catégories) calculés à partir des données réelles.
 *
 * Chaque rapport renvoie :
 *   { code, categorie, titre, periode, filtres[], resume[{label,value}],
 *     sections[{ titre, colonnes[{key,label,type}], lignes[] }], genere_le }
 * Types de colonnes : text | int | dec | pct | date.
 * Les calculs de moyennes, appréciations et décisions viennent de `Resultat` (source unique).
 */

require_once __DIR__ . '/Model.php';
require_once __DIR__ . '/Resultat.php';
require_once __DIR__ . '/Presence.php';

class Rapport extends Model
{
    protected string $table = 'journal_operations';

    /** code => [categorie, titre, méthode, argument, période par défaut] */
    private const CATALOGUE = [
        // 1. Auditeurs
        'aud-effectif-general' => ['auditeurs', 'Effectif général des auditeurs', 'effectifGeneral', null, null],
        'aud-par-groupe' => ['auditeurs', 'Liste des auditeurs par groupe', 'listeAuditeurs', 'groupe', null],
        'aud-par-salle' => ['auditeurs', 'Liste des auditeurs par salle', 'listeAuditeurs', 'salle', null],
        'aud-par-session' => ['auditeurs', 'Liste des auditeurs par session', 'listeAuditeurs', 'session', null],
        'aud-par-niveau' => ['auditeurs', 'Liste des auditeurs par niveau', 'listeAuditeurs', 'niveau', null],
        'aud-par-departement' => ['auditeurs', 'Répartition des auditeurs par département', 'repartition', 'departement', null],
        'aud-par-type' => ['auditeurs', 'Répartition des auditeurs par type d’employeur', 'repartition', 'type', null],
        'aud-inscriptions-statuts' => ['auditeurs', 'Inscriptions actives, terminées et abandonnées', 'inscriptionsStatuts', null, null],
        // 2. Présences
        'pre-journalier' => ['presences', 'Rapport journalier des présences et absences', 'presenceJournalier', null, 'journalier'],
        'pre-hebdomadaire' => ['presences', 'Rapport hebdomadaire des présences', 'presencePeriodique', 'date', 'hebdomadaire'],
        'pre-mensuel' => ['presences', 'Rapport mensuel des présences', 'presencePeriodique', 'date', 'mensuel'],
        'pre-annuel' => ['presences', 'Rapport annuel des présences', 'presencePeriodique', 'mois', 'annuel'],
        'pre-par-auditeur' => ['presences', 'Présences par auditeur', 'presenceDimension', 'auditeur', 'annuel'],
        'pre-par-groupe' => ['presences', 'Présences par groupe', 'presenceDimension', 'groupe', 'annuel'],
        'pre-par-salle' => ['presences', 'Présences par salle', 'presenceDimension', 'salle', 'annuel'],
        'pre-par-formateur' => ['presences', 'Présences par formateur', 'presenceDimension', 'formateur', 'annuel'],
        'pre-taux-session' => ['presences', 'Taux de présence et d’absence par session', 'presenceDimension', 'session', 'annuel'],
        'pre-absences-justifiees' => ['presences', 'Absences justifiées et non justifiées', 'absencesJustifiees', null, 'annuel'],
        // 3. Notes et évaluations
        'not-par-evaluation' => ['notes', 'Notes par évaluation', 'notesParEvaluation', null, null],
        'not-resultats-auditeur' => ['notes', 'Résultats par auditeur', 'resultatsAuditeur', null, null],
        'not-resultats-groupe' => ['notes', 'Résultats par groupe', 'resultatsAgreges', 'groupe', null],
        'not-resultats-session' => ['notes', 'Résultats par session', 'resultatsAgreges', 'session', null],
        'not-moyennes-auditeur' => ['notes', 'Moyennes générales par auditeur', 'moyennesAuditeur', null, null],
        'not-moyennes-groupe' => ['notes', 'Moyennes par groupe', 'moyennesGroupe', null, null],
        'not-appreciations-evaluation' => ['notes', 'Appréciations par évaluation', 'appreciations', 'evaluation', null],
        'not-appreciations-session' => ['notes', 'Appréciations générales par session', 'appreciations', 'session', null],
        'not-manquantes' => ['notes', 'Notes manquantes et évaluations non terminées', 'notesManquantes', null, null],
        'not-classement' => ['notes', 'Classement des auditeurs par moyenne', 'classement', null, null],
        // 4. Passage de niveau
        'pas-admis' => ['passages', 'Auditeurs admis au niveau supérieur', 'passageListe', 'admis', null],
        'pas-non-admis' => ['passages', 'Auditeurs non admis', 'passageListe', 'non_admis', null],
        'pas-redoublement' => ['passages', 'Auditeurs autorisés à redoubler', 'passageListe', 'redouble', null],
        'pas-termine' => ['passages', 'Auditeurs ayant terminé la formation', 'passageListe', 'termine', null],
        'pas-decisions-session' => ['passages', 'Décisions de passage par session', 'passageDecisions', 'session', null],
        'pas-decisions-annee' => ['passages', 'Passages de niveau par année scolaire', 'passageDecisions', 'annee', null],
        'pas-progression' => ['passages', 'Progression des auditeurs entre les niveaux', 'passageProgression', null, null],
        'pas-taux-reussite' => ['passages', 'Moyennes et taux de réussite par session', 'tauxReussite', null, null],
        // 5. Formateurs
        'for-affectations' => ['formateurs', 'Formateurs et leurs affectations', 'formateursAffectations', null, null],
        'for-seances' => ['formateurs', 'Séances assurées par formateur', 'formateursSeances', null, 'mensuel'],
        'for-groupes' => ['formateurs', 'Groupes pris en charge par formateur', 'formateursResume', 'groupes', null],
        'for-salles' => ['formateurs', 'Salles utilisées par formateur', 'formateursResume', 'salles', null],
        'for-presences-saisies' => ['formateurs', 'Présences saisies par formateur', 'formateursPresences', null, 'annuel'],
        'for-notes-saisies' => ['formateurs', 'Évaluations et notes saisies par formateur', 'formateursNotes', null, null],
        // 6. Salles et emplois du temps
        'sal-occupation' => ['salles', 'Occupation des salles', 'sallesOccupation', null, null],
        'sal-disponibilite' => ['salles', 'Disponibilité des salles', 'sallesDisponibilite', null, null],
        'sal-seances' => ['salles', 'Séances par jour et par semaine', 'seancesJourSemaine', null, 'mensuel'],
        'sal-edt-groupe' => ['salles', 'Emplois du temps par groupe', 'edtListe', 'groupe', null],
        'sal-edt-formateur' => ['salles', 'Emplois du temps par formateur', 'edtListe', 'formateur', null],
        'sal-conflits' => ['salles', 'Conflits de salles et d’horaires', 'sallesConflits', null, null],
        'sal-effectifs' => ['salles', 'Effectifs par salle et par groupe', 'effectifsSalleGroupe', null, null],
        // 7. Administratifs et statistiques
        'adm-tableau-general' => ['admin', 'Tableau de bord général de la formation', 'tableauGeneral', null, 'annuel'],
        'adm-stat-annee' => ['admin', 'Statistiques par année scolaire', 'statistiques', 'annee', 'annuel'],
        'adm-stat-session' => ['admin', 'Statistiques par session', 'statistiques', 'session', 'annuel'],
        'adm-stat-departement' => ['admin', 'Statistiques par département', 'statistiques', 'departement', 'annuel'],
        'adm-stat-type' => ['admin', 'Statistiques par type d’employeur', 'statistiques', 'type', 'annuel'],
        'adm-comparatif-sessions' => ['admin', 'Comparatif des résultats entre sessions', 'comparatifSessions', null, null],
        'adm-global' => ['admin', 'Effectifs, présences et résultats (global)', 'rapportGlobal', null, 'annuel'],
        'adm-synthese-annuelle' => ['admin', 'Synthèse annuelle de la formation', 'syntheseAnnuelle', null, 'annuel'],
    ];

    private const CATEGORIES = [
        'auditeurs' => 'Gestion des auditeurs',
        'presences' => 'Présences',
        'notes' => 'Notes et évaluations',
        'passages' => 'Passage de niveau',
        'formateurs' => 'Formateurs',
        'salles' => 'Salles et emplois du temps',
        'admin' => 'Administratifs et statistiques',
    ];

    /** Filtres proposés dans l'interface pour chaque catégorie. */
    private const CATEGORY_FILTERS = [
        'auditeurs' => ['id_annee', 'id_session', 'id_niveau', 'id_groupe', 'id_salle', 'id_departement', 'id_type_employe', 'statut'],
        'presences' => ['periode', 'id_annee', 'id_session', 'id_niveau', 'id_groupe', 'id_salle', 'id_formateur', 'id_departement', 'id_type_employe'],
        'notes' => ['id_annee', 'id_session', 'id_niveau', 'id_groupe', 'id_salle', 'id_formateur', 'id_departement', 'id_type_employe', 'numero'],
        'passages' => ['id_annee', 'id_session', 'id_niveau', 'id_groupe', 'id_salle', 'id_departement', 'id_type_employe'],
        'formateurs' => ['periode', 'id_annee', 'id_session', 'id_groupe', 'id_salle', 'id_formateur', 'jour_semaine'],
        'salles' => ['periode', 'id_annee', 'id_session', 'id_groupe', 'id_salle', 'id_formateur', 'jour_semaine'],
        'admin' => ['periode', 'id_annee', 'id_session', 'id_niveau', 'id_departement', 'id_type_employe'],
    ];

    private array $resultsCache = [];

    /* ------------------------------------------------------------------
     | Catalogue et génération
     |------------------------------------------------------------------ */

    public function catalogue(): array
    {
        $list = [];
        foreach (self::CATALOGUE as $code => [$category, $title, , , $period]) {
            $list[] = [
                'code' => $code, 'categorie' => $category, 'titre' => $title,
                'periode_par_defaut' => $period, 'filtres' => self::CATEGORY_FILTERS[$category],
            ];
        }
        return ['categories' => self::CATEGORIES, 'rapports' => $list];
    }

    /** @throws InvalidArgumentException */
    public function generate(string $code, array $filters): array
    {
        if (!isset(self::CATALOGUE[$code])) {
            throw new InvalidArgumentException('Rapport inconnu.');
        }
        [$category, $title, $method, $arg, $defaultPeriod] = self::CATALOGUE[$code];
        $f = $this->normalize($filters);
        $period = $defaultPeriod !== null || !empty($f['periode']) ? $this->resolvePeriod($f, $defaultPeriod) : null;
        $out = $this->{$method}($f, $period, $arg);

        return [
            'code' => $code,
            'categorie' => $category,
            'categorie_libelle' => self::CATEGORIES[$category],
            'titre' => $title,
            'periode' => $period,
            'filtres' => $this->filterLabels($f),
            'resume' => $out['resume'] ?? [],
            'sections' => $out['sections'],
            'genere_le' => date('Y-m-d H:i'),
        ];
    }

    /* ------------------------------------------------------------------
     | Filtres, période, SQL commun
     |------------------------------------------------------------------ */

    private function normalize(array $in): array
    {
        $f = [];
        foreach (['id_annee', 'id_session', 'id_niveau', 'id_groupe', 'id_salle', 'id_formateur', 'id_departement', 'id_type_employe', 'jour_semaine', 'numero'] as $key) {
            $value = filter_var($in[$key] ?? null, FILTER_VALIDATE_INT);
            if ($value) {
                $f[$key] = $value;
            }
        }
        if (!empty($in['statut']) && in_array($in['statut'], ['active', 'terminee', 'abandonnee', 'annulee'], true)) {
            $f['statut'] = $in['statut'];
        }
        foreach (['periode', 'date', 'mois', 'date_debut', 'date_fin'] as $key) {
            if (!empty($in[$key])) {
                $f[$key] = (string) $in[$key];
            }
        }
        return $f;
    }

    private function assertDate(string $value): void
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$d || $d->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Date invalide (format AAAA-MM-JJ).');
        }
    }

    /** @return array{type:string,debut:string,fin:string,libelle:string} */
    private function resolvePeriod(array $f, ?string $default): array
    {
        $type = $f['periode'] ?? $default ?? 'annuel';
        $fmt = static fn(string $iso): string => (new DateTimeImmutable($iso))->format('d/m/Y');
        $date = $f['date'] ?? date('Y-m-d');
        $this->assertDate($date);
        $day = new DateTimeImmutable($date);

        switch ($type) {
            case 'journalier':
                return ['type' => $type, 'debut' => $date, 'fin' => $date, 'libelle' => $fmt($date)];
            case 'hebdomadaire':
                $start = $day->modify('monday this week');
                $end = $start->modify('+6 days');
                return ['type' => $type, 'debut' => $start->format('Y-m-d'), 'fin' => $end->format('Y-m-d'),
                    'libelle' => 'Semaine du ' . $start->format('d/m/Y') . ' au ' . $end->format('d/m/Y')];
            case 'mensuel':
                $month = $f['mois'] ?? $day->format('Y-m');
                if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
                    throw new InvalidArgumentException('Mois invalide (AAAA-MM).');
                }
                $first = new DateTimeImmutable($month . '-01');
                $names = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
                return ['type' => $type, 'debut' => $first->format('Y-m-d'), 'fin' => $first->modify('last day of this month')->format('Y-m-d'),
                    'libelle' => ucfirst($names[(int) $first->format('n') - 1]) . ' ' . $first->format('Y')];
            case 'libre':
                if (empty($f['date_debut']) || empty($f['date_fin'])) {
                    throw new InvalidArgumentException('Indiquez la date de début et la date de fin.');
                }
                $this->assertDate($f['date_debut']);
                $this->assertDate($f['date_fin']);
                if ($f['date_fin'] < $f['date_debut']) {
                    throw new InvalidArgumentException('La date de fin doit suivre la date de début.');
                }
                return ['type' => $type, 'debut' => $f['date_debut'], 'fin' => $f['date_fin'],
                    'libelle' => 'Du ' . $fmt($f['date_debut']) . ' au ' . $fmt($f['date_fin'])];
            default: // annuel : année choisie, sinon année active, sinon année civile
                $year = isset($f['id_annee'])
                    ? $this->fetchOne('SELECT libelle, date_debut, date_fin FROM annees_scolaires WHERE id = :id', ['id' => $f['id_annee']])
                    : $this->fetchOne('SELECT libelle, date_debut, date_fin FROM annees_scolaires WHERE statut = 1 ORDER BY date_debut DESC LIMIT 1');
                if ($year !== null) {
                    return ['type' => 'annuel', 'debut' => $year['date_debut'], 'fin' => $year['date_fin'], 'libelle' => 'Année scolaire ' . $year['libelle']];
                }
                $y = $day->format('Y');
                return ['type' => 'annuel', 'debut' => "$y-01-01", 'fin' => "$y-12-31", 'libelle' => "Année $y"];
        }
    }

    private function filterLabels(array $f): array
    {
        $labels = [];
        $one = function (string $sql, string $key, string $label) use ($f, &$labels): void {
            if (isset($f[$key])) {
                $value = $this->fetchColumn($sql, ['id' => $f[$key]]);
                if ($value !== false && $value !== null) {
                    $labels[] = ['label' => $label, 'value' => (string) $value];
                }
            }
        };
        $one('SELECT libelle FROM annees_scolaires WHERE id = :id', 'id_annee', 'Année scolaire');
        $one("SELECT CONCAT(code, ' · ', libelle) FROM sessions WHERE id = :id", 'id_session', 'Session');
        $one('SELECT libelle FROM niveaux WHERE id = :id', 'id_niveau', 'Niveau');
        $one("SELECT CONCAT(code, ' · ', libelle) FROM groupes WHERE id = :id", 'id_groupe', 'Groupe');
        $one("SELECT CONCAT(code, ' · ', libelle) FROM salles WHERE id = :id", 'id_salle', 'Salle');
        $one("SELECT CONCAT(prenom, ' ', nom) FROM v_formateurs WHERE id = :id", 'id_formateur', 'Formateur');
        $one("SELECT CONCAT(code, ' · ', libelle) FROM departements WHERE id = :id", 'id_departement', 'Département');
        $one('SELECT libelle FROM types_employes WHERE id = :id', 'id_type_employe', 'Type d’employeur');
        if (isset($f['jour_semaine'])) {
            $labels[] = ['label' => 'Jour', 'value' => self::DAYS[$f['jour_semaine']] ?? (string) $f['jour_semaine']];
        }
        if (isset($f['numero'])) {
            $labels[] = ['label' => 'Évaluation', 'value' => 'n° ' . $f['numero']];
        }
        if (isset($f['statut'])) {
            $labels[] = ['label' => 'Statut d’inscription', 'value' => $f['statut']];
        }
        return $labels;
    }

    private const DAYS = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi'];

    private const JOINS = "JOIN auditeurs a ON a.id = i.id_auditeur
             JOIN departements d ON d.id = i.id_departement
             JOIN groupes g ON g.id = e.id_groupe
             JOIN sessions s ON s.id = i.id_session
             JOIN niveaux n ON n.id = s.id_niveau
             JOIN annees_scolaires an ON an.id = i.id_annee
             JOIN salles sa ON sa.id = e.id_salle
             JOIN v_formateurs f ON f.id = e.id_formateur
             LEFT JOIN types_employes te ON te.id = a.id_type_employe LEFT JOIN positions po ON po.id = a.id_position";

    private function insFrom(): string
    {
        return 'FROM inscriptions i JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps ' . self::JOINS;
    }

    private function presFrom(): string
    {
        return 'FROM presences p JOIN inscriptions i ON i.id = p.id_inscription
             JOIN emplois_du_temps e ON e.id = COALESCE(p.id_emploi_du_temps, i.id_emploi_du_temps) ' . self::JOINS;
    }

    /** Clause WHERE (inscriptions) : ' AND ...' et paramètres. */
    private function insWhere(array $f, array &$params, array $only = []): string
    {
        $map = ['id_annee' => 'i.id_annee', 'id_session' => 'i.id_session', 'id_niveau' => 's.id_niveau', 'id_groupe' => 'e.id_groupe',
            'id_salle' => 'e.id_salle', 'id_formateur' => 'e.id_formateur', 'id_departement' => 'i.id_departement',
            'id_type_employe' => 'a.id_type_employe', 'jour_semaine' => 'e.jour_semaine', 'statut' => 'i.statut'];
        $sql = '';
        foreach ($map as $key => $column) {
            if (isset($f[$key]) && ($only === [] || in_array($key, $only, true))) {
                $sql .= " AND {$column} = :w_{$key}";
                $params["w_{$key}"] = $f[$key];
            }
        }
        return $sql;
    }

    private function presWhere(array $f, array $period, array &$params): string
    {
        $params['p_start'] = $period['debut'];
        $params['p_end'] = $period['fin'];
        return ' AND p.date_presence BETWEEN :p_start AND :p_end' . $this->insWhere($f, $params);
    }

    /** Clause WHERE sur les créneaux (emplois_du_temps e + groupes g + sessions s + salles + formateurs). */
    private function edtWhere(array $f, array &$params): string
    {
        $map = ['id_annee' => 'e.id_annee', 'id_session' => 's.id', 'id_niveau' => 's.id_niveau', 'id_groupe' => 'e.id_groupe',
            'id_salle' => 'e.id_salle', 'id_formateur' => 'e.id_formateur', 'jour_semaine' => 'e.jour_semaine'];
        $sql = '';
        foreach ($map as $key => $column) {
            if (isset($f[$key])) {
                $sql .= " AND {$column} = :w_{$key}";
                $params["w_{$key}"] = $f[$key];
            }
        }
        return $sql;
    }

    private const EDT_FROM = 'FROM emplois_du_temps e
             JOIN groupes g ON g.id = e.id_groupe
             JOIN sessions s ON s.id = g.id_session
             JOIN niveaux n ON n.id = s.id_niveau
             JOIN annees_scolaires an ON an.id = e.id_annee
             JOIN salles sa ON sa.id = e.id_salle
             JOIN v_formateurs f ON f.id = e.id_formateur';

    /* ------------------------------------------------------------------
     | Fabrication des colonnes / sections
     |------------------------------------------------------------------ */

    private function c(string $key, string $label, string $type = 'text'): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type];
    }

    private function sec(string $title, array $cols, array $rows): array
    {
        return ['titre' => $title, 'colonnes' => $cols, 'lignes' => array_values($rows)];
    }

    private function numbered(array $rows): array
    {
        $n = 0;
        return array_map(static function (array $row) use (&$n): array {
            return ['no' => ++$n] + $row;
        }, $rows);
    }

    private function pct(float|int|null $part, float|int|null $total): ?float
    {
        return $total ? round($part / $total * 100, 1) : null;
    }

    private function num(mixed $value): int
    {
        return (int) $value;
    }

    /* ------------------------------------------------------------------
     | 1. Auditeurs
     |------------------------------------------------------------------ */

    private const DIMENSIONS = [
        'niveau' => ['n.libelle AS libelle, n.ordre AS ord', 'n.id, n.libelle, n.ordre', 'ord', 'Niveau'],
        'session' => ['s.code AS code, s.libelle AS libelle, s.date_debut AS ord', 's.id, s.code, s.libelle, s.date_debut', 'ord DESC', 'Session'],
        'groupe' => ['g.code AS code, g.libelle AS libelle', 'g.id, g.code, g.libelle', 'code', 'Groupe'],
        'salle' => ['sa.code AS code, sa.libelle AS libelle', 'sa.id, sa.code, sa.libelle', 'code', 'Salle'],
        'departement' => ['d.code AS code, d.libelle AS libelle', 'd.id, d.code, d.libelle', 'libelle', 'Département'],
        'type' => ["COALESCE(te.libelle, 'Non renseigné') AS libelle", "COALESCE(te.id, 0), COALESCE(te.libelle, 'Non renseigné')", 'libelle', 'Type d’employeur'],
        'annee' => ['an.libelle AS libelle, an.date_debut AS ord', 'an.id, an.libelle, an.date_debut', 'ord DESC', 'Année scolaire'],
        'formateur' => ["f.matricule AS code, CONCAT(f.prenom, ' ', f.nom) AS libelle", 'f.id, f.matricule, f.prenom, f.nom', 'libelle', 'Formateur'],
    ];

    /** Effectifs par dimension (auditeurs distincts, sexe, statuts d'inscription). */
    private function countBy(string $dimension, array $f, string $title = null, bool $excludeCancelled = false): array
    {
        [$select, $group, $order, $label] = self::DIMENSIONS[$dimension];
        $params = [];
        $where = $this->insWhere($f, $params) . ($excludeCancelled && !isset($f['statut']) ? " AND i.statut <> 'annulee'" : '');
        $rows = $this->fetchAll(
            "SELECT {$select},
                    COUNT(DISTINCT i.id_auditeur) AS auditeurs,
                    COUNT(DISTINCT CASE WHEN a.sexe = 'M' THEN i.id_auditeur END) AS hommes,
                    COUNT(DISTINCT CASE WHEN a.sexe = 'F' THEN i.id_auditeur END) AS femmes,
                    SUM(i.statut = 'active') AS actives, SUM(i.statut = 'terminee') AS terminees,
                    SUM(i.statut = 'abandonnee') AS abandonnees, SUM(i.statut = 'annulee') AS annulees,
                    COUNT(i.id) AS total
             {$this->insFrom()} WHERE 1 = 1 {$where}
             GROUP BY {$group} ORDER BY {$order}",
            $params
        );
        $totalAuditeurs = array_sum(array_column($rows, 'auditeurs'));
        $hasCode = isset($rows[0]['code']) || str_contains($select, 'AS code');
        $cols = array_merge(
            $hasCode ? [$this->c('code', 'Code')] : [],
            [$this->c('libelle', $label),
             $this->c('auditeurs', 'Auditeurs', 'int'), $this->c('pct', '% de l’effectif', 'pct'),
             $this->c('hommes', 'Hommes', 'int'), $this->c('femmes', 'Femmes', 'int'),
             $this->c('actives', 'Actives', 'int'), $this->c('terminees', 'Terminées', 'int'),
             $this->c('abandonnees', 'Abandonnées', 'int'), $this->c('annulees', 'Annulées', 'int'), $this->c('total', 'Inscriptions', 'int')]
        );
        $lines = array_map(function (array $r) use ($totalAuditeurs, $hasCode): array {
            $line = $hasCode ? ['code' => $r['code']] : [];
            return $line + ['libelle' => $r['libelle'], 'auditeurs' => $this->num($r['auditeurs']), 'pct' => $this->pct($r['auditeurs'], $totalAuditeurs)]
                + array_map([$this, 'num'], array_intersect_key($r, array_flip(['hommes', 'femmes', 'actives', 'terminees', 'abandonnees', 'annulees', 'total'])));
        }, $rows);
        return $this->sec($title ?? "Effectifs par " . mb_strtolower($label), $cols, $lines);
    }

    private function effectifGeneral(array $f): array
    {
        $params = [];
        $where = $this->insWhere($f, $params);
        $k = $this->fetchOne(
            "SELECT COUNT(DISTINCT i.id_auditeur) AS auditeurs,
                    COUNT(DISTINCT CASE WHEN i.statut = 'active' THEN i.id_auditeur END) AS actifs,
                    COUNT(DISTINCT CASE WHEN a.sexe = 'M' THEN i.id_auditeur END) AS hommes,
                    COUNT(DISTINCT CASE WHEN a.sexe = 'F' THEN i.id_auditeur END) AS femmes
             {$this->insFrom()} WHERE 1 = 1 {$where}",
            $params
        );
        $registered = (int) $this->fetchColumn('SELECT COUNT(*) FROM auditeurs');
        return [
            'resume' => [
                ['label' => 'Auditeurs enregistrés', 'value' => $registered],
                ['label' => 'Auditeurs inscrits (périmètre)', 'value' => $this->num($k['auditeurs'])],
                ['label' => 'Inscrits actifs', 'value' => $this->num($k['actifs'])],
                ['label' => 'Hommes / Femmes', 'value' => $this->num($k['hommes']) . ' / ' . $this->num($k['femmes'])],
            ],
            'sections' => [
                $this->countBy('niveau', $f, 'Effectif par niveau'),
                $this->countBy('session', $f, 'Effectif par session'),
                $this->countBy('departement', $f, 'Effectif par département'),
                $this->countBy('type', $f, 'Effectif par type d’employeur'),
            ],
        ];
    }

    private function listeAuditeurs(array $f, ?array $period, string $dimension): array
    {
        $order = ['groupe' => 'g.code, sa.code', 'salle' => 'sa.code, g.code', 'session' => 's.date_debut DESC, g.code', 'niveau' => 'n.ordre, s.code'][$dimension];
        $params = [];
        $where = $this->insWhere($f, $params);
        $rows = $this->fetchAll(
            "SELECT n.libelle AS niveau, s.code AS session, g.code AS groupe, sa.code AS salle,
                    a.matricule, CONCAT(a.nom, ' ', a.prenom) AS nom, a.sexe, a.telephone, po.libelle AS position,
                    d.libelle AS departement, COALESCE(te.libelle, 'Non renseigné') AS type_employeur, i.statut
             {$this->insFrom()} WHERE 1 = 1 {$where} ORDER BY {$order}, a.nom, a.prenom",
            $params
        );
        $first = ['groupe' => 'groupe', 'salle' => 'salle', 'session' => 'session', 'niveau' => 'niveau'][$dimension];
        $cols = [$this->c('no', 'N°', 'int')];
        foreach (['groupe' => 'Groupe', 'salle' => 'Salle', 'session' => 'Session', 'niveau' => 'Niveau'] as $key => $label) {
            if ($key === $first) {
                $cols[] = $this->c($key, $label);
            }
        }
        foreach (['groupe' => 'Groupe', 'salle' => 'Salle', 'session' => 'Session', 'niveau' => 'Niveau'] as $key => $label) {
            if ($key !== $first) {
                $cols[] = $this->c($key, $label);
            }
        }
        $cols = array_merge($cols, [$this->c('matricule', 'Matricule'), $this->c('nom', 'Nom et prénom'), $this->c('sexe', 'Sexe'),
            $this->c('position', 'Poste'), $this->c('departement', 'Département'), $this->c('type_employeur', 'Type d’employeur'),
            $this->c('telephone', 'Téléphone'), $this->c('statut', 'Statut')]);
        return [
            'resume' => [['label' => 'Auditeurs listés', 'value' => count($rows)]],
            'sections' => [
                $this->sec('Liste des auditeurs', $cols, $this->numbered($rows)),
                $this->countBy($dimension, $f, 'Effectifs par ' . mb_strtolower(self::DIMENSIONS[$dimension][3])),
            ],
        ];
    }

    private function repartition(array $f, ?array $period, string $dimension): array
    {
        return ['sections' => [$this->countBy($dimension, $f, 'Répartition par ' . mb_strtolower(self::DIMENSIONS[$dimension][3]), true)]];
    }

    private function inscriptionsStatuts(array $f): array
    {
        $params = [];
        $where = $this->insWhere($f, $params);
        $k = $this->fetchOne("SELECT SUM(i.statut = 'active') a, SUM(i.statut = 'terminee') t, SUM(i.statut = 'abandonnee') b, SUM(i.statut = 'annulee') c, COUNT(*) n {$this->insFrom()} WHERE 1 = 1 {$where}", $params);
        return [
            'resume' => [
                ['label' => 'Actives', 'value' => $this->num($k['a'])], ['label' => 'Terminées', 'value' => $this->num($k['t'])],
                ['label' => 'Abandonnées', 'value' => $this->num($k['b'])], ['label' => 'Annulées', 'value' => $this->num($k['c'])],
            ],
            'sections' => [$this->countBy('session', $f, 'Inscriptions par session'), $this->countBy('annee', $f, 'Inscriptions par année scolaire'), $this->countBy('groupe', $f, 'Inscriptions par groupe')],
        ];
    }

    /* ------------------------------------------------------------------
     | 2. Présences
     |------------------------------------------------------------------ */

    private const PRESENCE_DIMENSIONS = [
        'date' => ['p.date_presence AS libelle', 'p.date_presence', 'libelle', 'Date', 'date'],
        'mois' => ["DATE_FORMAT(p.date_presence, '%Y-%m') AS libelle", "DATE_FORMAT(p.date_presence, '%Y-%m')", 'libelle', 'Mois', 'text'],
        'auditeur' => ["a.matricule AS code, CONCAT(a.nom, ' ', a.prenom) AS libelle, CONCAT(g.code, ' · ', sa.code) AS classe", 'a.id, a.matricule, a.nom, a.prenom, g.code, sa.code', 'libelle', 'Auditeur', 'text'],
        'groupe' => ['g.code AS code, g.libelle AS libelle', 'g.id, g.code, g.libelle', 'code', 'Groupe', 'text'],
        'salle' => ['sa.code AS code, sa.libelle AS libelle', 'sa.id, sa.code, sa.libelle', 'code', 'Salle', 'text'],
        'formateur' => ["f.matricule AS code, CONCAT(f.prenom, ' ', f.nom) AS libelle", 'f.id, f.matricule, f.prenom, f.nom', 'libelle', 'Formateur', 'text'],
        'session' => ['s.code AS code, s.libelle AS libelle', 's.id, s.code, s.libelle', 'code', 'Session', 'text'],
        'departement' => ['d.code AS code, d.libelle AS libelle', 'd.id, d.code, d.libelle', 'libelle', 'Département', 'text'],
        'type' => ["COALESCE(te.libelle, 'Non renseigné') AS libelle", "COALESCE(te.id, 0), COALESCE(te.libelle, 'Non renseigné')", 'libelle', 'Type d’employeur', 'text'],
    ];

    private function presenceRows(string $dimension, array $f, array $period): array
    {
        [$select, $group, $order] = self::PRESENCE_DIMENSIONS[$dimension];
        $params = [];
        $where = $this->presWhere($f, $period, $params);
        $rows = $this->fetchAll(
            "SELECT {$select}, COUNT(*) AS total, SUM(p.statut = 'present') AS present, SUM(p.statut = 'absent') AS absent,
                    SUM(p.statut = 'permissionnaire') AS permissionnaire, SUM(p.statut = 'malade') AS malade, SUM(p.statut = 'conge') AS conge
             {$this->presFrom()} WHERE 1 = 1 {$where} GROUP BY {$group} ORDER BY {$order}",
            $params
        );
        return array_map(function (array $r): array {
            foreach (['total', 'present', 'absent', 'permissionnaire', 'malade', 'conge'] as $key) {
                $r[$key] = $this->num($r[$key]);
            }
            $r['taux_presence'] = $this->pct($r['present'], $r['total']);
            $r['taux_absence'] = $this->pct($r['absent'], $r['total']);
            return $r;
        }, $rows);
    }

    private function presenceCols(string $dimension): array
    {
        [, , , $label, $type] = self::PRESENCE_DIMENSIONS[$dimension];
        $has = in_array($dimension, ['auditeur', 'groupe', 'salle', 'formateur', 'session', 'departement'], true);
        return array_merge(
            $has ? [$this->c('code', $dimension === 'auditeur' ? 'Matricule' : 'Code')] : [],
            [$this->c('libelle', $dimension === 'auditeur' ? 'Auditeur' : $label, $type)],
            $dimension === 'auditeur' ? [$this->c('classe', 'Groupe · salle')] : [],
            [$this->c('total', 'Pointages', 'int'), $this->c('present', 'Présents', 'int'), $this->c('absent', 'Absents', 'int'),
             $this->c('permissionnaire', 'Permissionnaires', 'int'), $this->c('malade', 'Malades', 'int'), $this->c('conge', 'Congés', 'int'),
             $this->c('taux_presence', 'Taux de présence', 'pct'), $this->c('taux_absence', 'Taux d’absence', 'pct')]
        );
    }

    private function presenceResume(array $rows): array
    {
        $total = array_sum(array_column($rows, 'total'));
        $present = array_sum(array_column($rows, 'present'));
        $absent = array_sum(array_column($rows, 'absent'));
        return [
            ['label' => 'Pointages', 'value' => $total], ['label' => 'Présents', 'value' => $present], ['label' => 'Absents', 'value' => $absent],
            ['label' => 'Taux de présence', 'value' => $this->pct($present, $total) === null ? '—' : $this->pct($present, $total) . ' %'],
            ['label' => 'Taux d’absence', 'value' => $this->pct($absent, $total) === null ? '—' : $this->pct($absent, $total) . ' %'],
        ];
    }

    private function presenceJournalier(array $f, array $period): array
    {
        $date = $period['debut'];
        $sheet = (new Presence())->getFeuille($date, array_intersect_key($f, array_flip(['id_session', 'id_groupe', 'id_salle', 'id_formateur'])));
        $labels = ['present' => 'Présent', 'absent' => 'Absent', 'permissionnaire' => 'Permissionnaire', 'malade' => 'Malade', 'conge' => 'Congé'];
        $lines = [];
        $counts = ['present' => 0, 'absent' => 0, 'autres' => 0, 'non' => 0];
        foreach ($sheet['auditeurs'] as $r) {
            if (isset($f['id_niveau']) || isset($f['id_departement']) || isset($f['id_type_employe'])) {
                continue;
            }
            $s = $r['statut'];
            $counts[$s === null ? 'non' : ($s === 'present' ? 'present' : ($s === 'absent' ? 'absent' : 'autres'))]++;
            $lines[] = ['salle' => $r['salle_code'], 'groupe' => $r['groupe_code'], 'session' => $r['session_code'],
                'formateur' => $r['formateur_prenom'] . ' ' . $r['formateur_nom'], 'matricule' => $r['auditeur_matricule'],
                'nom' => $r['auditeur_nom'] . ' ' . $r['auditeur_prenom'], 'statut' => $s === null ? 'Non pointé' : $labels[$s],
                'observation' => Presence::observationLabel($s, $r['conge_date_debut'] ?? null, $r['conge_date_fin'] ?? null, $r['observation'])];
        }
        $attendus = count($lines);
        return [
            'resume' => [
                ['label' => 'Auditeurs attendus', 'value' => $attendus], ['label' => 'Présents', 'value' => $counts['present']], ['label' => 'Absents', 'value' => $counts['absent']],
                ['label' => 'Permission / maladie / congé', 'value' => $counts['autres']], ['label' => 'Non pointés', 'value' => $counts['non']],
                ['label' => 'Taux de présence', 'value' => $attendus ? round($counts['present'] / $attendus * 100, 1) . ' %' : '—'],
            ],
            'sections' => [
                $this->sec('Présences et absences du ' . (new DateTimeImmutable($date))->format('d/m/Y'),
                    [$this->c('salle', 'Salle'), $this->c('groupe', 'Groupe'), $this->c('session', 'Session'), $this->c('formateur', 'Formateur'),
                     $this->c('matricule', 'Matricule'), $this->c('nom', 'Nom et prénom'), $this->c('statut', 'Statut'), $this->c('observation', 'Observation')], $lines),
                $this->sec('Synthèse par groupe', $this->presenceCols('groupe'), $this->presenceRows('groupe', $f, $period)),
            ],
        ];
    }

    private function presencePeriodique(array $f, array $period, string $granularity): array
    {
        $rows = $this->presenceRows($granularity === 'mois' ? 'mois' : 'date', $f, $period);
        $dim = $granularity === 'mois' ? 'mois' : 'date';
        return [
            'resume' => $this->presenceResume($rows),
            'sections' => [
                $this->sec($granularity === 'mois' ? 'Évolution mensuelle' : 'Évolution journalière', $this->presenceCols($dim), $rows),
                $this->sec('Par groupe', $this->presenceCols('groupe'), $this->presenceRows('groupe', $f, $period)),
                $this->sec('Par salle', $this->presenceCols('salle'), $this->presenceRows('salle', $f, $period)),
            ],
        ];
    }

    private function presenceDimension(array $f, array $period, string $dimension): array
    {
        $rows = $this->presenceRows($dimension, $f, $period);
        $sections = [$this->sec('Présences par ' . mb_strtolower(self::PRESENCE_DIMENSIONS[$dimension][3]), $this->presenceCols($dimension), $rows)];
        if ($dimension === 'session') {
            $sections[] = $this->sec('Évolution mensuelle', $this->presenceCols('mois'), $this->presenceRows('mois', $f, $period));
        }
        return ['resume' => $this->presenceResume($rows), 'sections' => $sections];
    }

    private function absencesJustifiees(array $f, array $period): array
    {
        $rows = array_filter($this->presenceRows('auditeur', $f, $period), static fn($r) => $r['absent'] + $r['permissionnaire'] + $r['malade'] + $r['conge'] > 0);
        $lines = array_map(static fn($r) => [
            'code' => $r['code'], 'libelle' => $r['libelle'], 'classe' => $r['classe'], 'non_justifiees' => $r['absent'],
            'permissions' => $r['permissionnaire'], 'maladies' => $r['malade'], 'conges' => $r['conge'],
            'justifiees' => $r['permissionnaire'] + $r['malade'] + $r['conge'], 'total' => $r['total'],
        ], $rows);
        usort($lines, static fn($a, $b) => $b['non_justifiees'] <=> $a['non_justifiees']);
        $nj = array_sum(array_column($lines, 'non_justifiees'));
        $j = array_sum(array_column($lines, 'justifiees'));
        return [
            'resume' => [['label' => 'Absences non justifiées', 'value' => $nj], ['label' => 'Absences justifiées', 'value' => $j], ['label' => 'Auditeurs concernés', 'value' => count($lines)]],
            'sections' => [$this->sec('Absences par auditeur', [
                $this->c('code', 'Matricule'), $this->c('libelle', 'Auditeur'), $this->c('classe', 'Groupe · salle'),
                $this->c('non_justifiees', 'Non justifiées (absent)', 'int'), $this->c('permissions', 'Permissions', 'int'), $this->c('maladies', 'Maladies', 'int'),
                $this->c('conges', 'Congés', 'int'), $this->c('justifiees', 'Total justifiées', 'int'), $this->c('total', 'Pointages', 'int'),
            ], $lines)],
        ];
    }

    /* ------------------------------------------------------------------
     | 3. Notes et évaluations (via Resultat)
     |------------------------------------------------------------------ */

    /** @return array<int,array> sessions avec leurs résultats filtrés */
    private function collect(array $f): array
    {
        $key = json_encode($f);
        if (isset($this->resultsCache[$key])) {
            return $this->resultsCache[$key];
        }
        $where = ' WHERE EXISTS (SELECT 1 FROM inscriptions i WHERE i.id_session = s.id)';
        $params = [];
        foreach (['id_annee' => 's.id_annee', 'id_session' => 's.id', 'id_niveau' => 's.id_niveau'] as $k => $col) {
            if (isset($f[$k])) {
                $where .= " AND {$col} = :c_{$k}";
                $params["c_{$k}"] = $f[$k];
            }
        }
        $dept = isset($f['id_departement']) ? $this->fetchColumn('SELECT libelle FROM departements WHERE id = :id', ['id' => $f['id_departement']]) : null;
        $type = isset($f['id_type_employe']) ? $this->fetchColumn('SELECT libelle FROM types_employes WHERE id = :id', ['id' => $f['id_type_employe']]) : null;
        $model = new Resultat();
        $out = [];
        foreach ($this->fetchAll("SELECT s.id FROM sessions s {$where} ORDER BY s.date_debut DESC", $params) as $row) {
            $res = $model->getSessionResults((int) $row['id'], array_intersect_key($f, array_flip(['id_groupe', 'id_salle', 'id_formateur'])));
            $res['auditeurs'] = array_values(array_filter($res['auditeurs'], static fn($r) => ($dept === null || $r['departement_libelle'] === $dept)
                && ($type === null || ($r['type_employe_libelle'] ?? null) === $type)));
            if ($res['auditeurs'] !== []) {
                $out[] = $res;
            }
        }
        return $this->resultsCache[$key] = $out;
    }

    private function agg(array $rows): array
    {
        $avgs = array_values(array_filter(array_column($rows, 'moyenne_generale'), static fn($v) => $v !== null));
        $count = static fn(string $s) => count(array_filter($rows, static fn($r) => $r['resultat'] === $s));
        $admis = $count('admis');
        $non = $count('non_admis');
        return [
            'effectif' => count($rows), 'moyenne' => $avgs ? round(array_sum($avgs) / count($avgs), 2) : null,
            'min' => $avgs ? min($avgs) : null, 'max' => $avgs ? max($avgs) : null,
            'admis' => $admis, 'non_admis' => $non, 'en_attente' => $count('en_attente'), 'abandon' => $count('abandonne'),
            'taux_reussite' => ($admis + $non) ? round($admis / ($admis + $non) * 100, 1) : null,
        ];
    }

    private function appr(?array $a): string
    {
        return $a ? $a['note'] . '/4 · ' . $a['libelle'] : '—';
    }

    private const RES = ['admis' => 'Admis', 'non_admis' => 'Non admis', 'en_attente' => 'En attente', 'abandonne' => 'Abandon'];

    private function maxEvaluations(array $data): int
    {
        return max(array_merge([1], array_map(static fn($s) => count($s['evaluations']), $data)));
    }

    private function notesParEvaluation(array $f): array
    {
        $data = $this->collect($f);
        $lines = [];
        foreach ($data as $s) {
            foreach ($s['evaluations'] as $i => $ev) {
                if (isset($f['numero']) && (int) $ev['numero'] !== $f['numero']) {
                    continue;
                }
                foreach ($s['auditeurs'] as $r) {
                    $e = $r['evaluations'][$i];
                    $lines[] = ['session' => $s['session']['code'], 'evaluation' => 'Évaluation ' . $ev['numero'] . ' · ' . $ev['libelle'], 'date' => $ev['date_evaluation'],
                        'groupe' => $r['groupe_code'], 'salle' => $r['salle_code'], 'matricule' => $r['auditeur_matricule'], 'nom' => $r['auditeur_nom'] . ' ' . $r['auditeur_prenom'],
                        'saisie' => $e['notes_saisies'] . '/' . $e['notes_attendues'], 'moyenne' => $e['moyenne'], 'appreciation' => $this->appr($e['appreciation'])];
                }
            }
        }
        $done = count(array_filter($lines, static fn($l) => $l['moyenne'] !== null));
        return [
            'resume' => [['label' => 'Notes de moyenne calculées', 'value' => $done], ['label' => 'Évaluations incomplètes', 'value' => count($lines) - $done]],
            'sections' => [$this->sec('Notes par évaluation', [
                $this->c('session', 'Session'), $this->c('evaluation', 'Évaluation'), $this->c('date', 'Date', 'date'), $this->c('groupe', 'Groupe'), $this->c('salle', 'Salle'),
                $this->c('matricule', 'Matricule'), $this->c('nom', 'Auditeur'), $this->c('saisie', 'Notes saisies'), $this->c('moyenne', 'Moyenne /4', 'dec'), $this->c('appreciation', 'Appréciation'),
            ], $lines)],
        ];
    }

    private function resultatsAuditeur(array $f): array
    {
        $data = $this->collect($f);
        $max = $this->maxEvaluations($data);
        $cols = [$this->c('session', 'Session'), $this->c('groupe', 'Groupe'), $this->c('salle', 'Salle'), $this->c('matricule', 'Matricule'), $this->c('nom', 'Auditeur')];
        for ($i = 1; $i <= $max; $i++) {
            $cols[] = $this->c("e$i", "Éval. $i", 'dec');
        }
        array_push($cols, $this->c('moyenne', 'Moyenne générale', 'dec'), $this->c('appreciation', 'Appréciation'), $this->c('presence', 'Présence', 'pct'), $this->c('resultat', 'Résultat'));
        $lines = [];
        foreach ($data as $s) {
            foreach ($s['auditeurs'] as $r) {
                $line = ['session' => $s['session']['code'], 'groupe' => $r['groupe_code'], 'salle' => $r['salle_code'], 'matricule' => $r['auditeur_matricule'], 'nom' => $r['auditeur_nom'] . ' ' . $r['auditeur_prenom']];
                for ($i = 0; $i < $max; $i++) {
                    $line['e' . ($i + 1)] = $r['evaluations'][$i]['moyenne'] ?? null;
                }
                $lines[] = $line + ['moyenne' => $r['moyenne_generale'], 'appreciation' => $this->appr($r['appreciation_generale']), 'presence' => $r['presences']['taux'], 'resultat' => self::RES[$r['resultat']]];
            }
        }
        return ['resume' => $this->resumeResultats($data), 'sections' => [$this->sec('Résultats par auditeur', $cols, $lines)]];
    }

    /** Toutes les lignes d'auditeurs de plusieurs sessions. */
    private function allRows(array $data): array
    {
        $all = [];
        foreach ($data as $s) {
            foreach ($s['auditeurs'] as $r) {
                $all[] = $r;
            }
        }
        return $all;
    }

    private function resumeResultats(array $data): array
    {
        $all = $this->allRows($data);
        $a = $this->agg($all ?: []);
        return [['label' => 'Auditeurs', 'value' => $a['effectif']], ['label' => 'Moyenne générale', 'value' => $a['moyenne'] === null ? '—' : $a['moyenne'] . ' /4'],
            ['label' => 'Admis', 'value' => $a['admis']], ['label' => 'Non admis', 'value' => $a['non_admis']], ['label' => 'En attente', 'value' => $a['en_attente']]];
    }

    private function resultsAggCols(string $label): array
    {
        return [$this->c('libelle', $label), $this->c('session', 'Session'), $this->c('effectif', 'Effectif', 'int'), $this->c('moyenne', 'Moyenne /4', 'dec'),
            $this->c('min', 'Min', 'dec'), $this->c('max', 'Max', 'dec'), $this->c('admis', 'Admis', 'int'), $this->c('non_admis', 'Non admis', 'int'),
            $this->c('en_attente', 'En attente', 'int'), $this->c('taux_reussite', 'Taux de réussite', 'pct')];
    }

    private function resultatsAgreges(array $f, ?array $p, string $dimension): array
    {
        $data = $this->collect($f);
        $lines = [];
        foreach ($data as $s) {
            if ($dimension === 'session') {
                $lines[] = ['libelle' => $s['session']['code'] . ' · ' . $s['session']['libelle'], 'session' => $s['session']['annee_libelle']] + $this->agg($s['auditeurs']);
                continue;
            }
            $groups = [];
            foreach ($s['auditeurs'] as $r) {
                $groups[$r['groupe_code'] . ' · ' . $r['groupe_libelle']][] = $r;
            }
            ksort($groups);
            foreach ($groups as $label => $rows) {
                $lines[] = ['libelle' => $label, 'session' => $s['session']['code']] + $this->agg($rows);
            }
        }
        return ['resume' => $this->resumeResultats($data), 'sections' => [$this->sec('Résultats par ' . ($dimension === 'session' ? 'session' : 'groupe'), $this->resultsAggCols($dimension === 'session' ? 'Session' : 'Groupe'), $lines)]];
    }

    private function moyennesAuditeur(array $f): array
    {
        $data = $this->collect($f);
        $lines = [];
        foreach ($data as $s) {
            foreach ($s['auditeurs'] as $r) {
                if ($r['moyenne_generale'] !== null) {
                    $lines[] = ['session' => $s['session']['code'], 'matricule' => $r['auditeur_matricule'], 'nom' => $r['auditeur_nom'] . ' ' . $r['auditeur_prenom'],
                        'groupe' => $r['groupe_code'], 'moyenne' => $r['moyenne_generale'], 'appreciation' => $this->appr($r['appreciation_generale'])];
                }
            }
        }
        usort($lines, static fn($a, $b) => strcmp($a['nom'], $b['nom']));
        return ['resume' => $this->resumeResultats($data), 'sections' => [$this->sec('Moyennes générales par auditeur', [
            $this->c('session', 'Session'), $this->c('matricule', 'Matricule'), $this->c('nom', 'Auditeur'), $this->c('groupe', 'Groupe'),
            $this->c('moyenne', 'Moyenne générale /4', 'dec'), $this->c('appreciation', 'Appréciation générale'),
        ], $lines)]];
    }

    private function moyennesGroupe(array $f): array
    {
        $data = $this->collect($f);
        $max = $this->maxEvaluations($data);
        $cols = [$this->c('session', 'Session'), $this->c('groupe', 'Groupe'), $this->c('effectif', 'Effectif', 'int')];
        for ($i = 1; $i <= $max; $i++) {
            $cols[] = $this->c("e$i", "Moy. éval. $i", 'dec');
        }
        array_push($cols, $this->c('moyenne', 'Moyenne générale', 'dec'), $this->c('min', 'Min', 'dec'), $this->c('max', 'Max', 'dec'));
        $lines = [];
        foreach ($data as $s) {
            $groups = [];
            foreach ($s['auditeurs'] as $r) {
                $groups[$r['groupe_code'] . ' · ' . $r['groupe_libelle']][] = $r;
            }
            ksort($groups);
            foreach ($groups as $label => $rows) {
                $a = $this->agg($rows);
                $line = ['session' => $s['session']['code'], 'groupe' => $label, 'effectif' => $a['effectif']];
                for ($i = 0; $i < $max; $i++) {
                    $vals = array_values(array_filter(array_map(static fn($r) => $r['evaluations'][$i]['moyenne'] ?? null, $rows), static fn($v) => $v !== null));
                    $line['e' . ($i + 1)] = $vals ? round(array_sum($vals) / count($vals), 2) : null;
                }
                $lines[] = $line + ['moyenne' => $a['moyenne'], 'min' => $a['min'], 'max' => $a['max']];
            }
        }
        return ['resume' => $this->resumeResultats($data), 'sections' => [$this->sec('Moyennes par groupe', $cols, $lines)]];
    }

    private function appreciations(array $f, ?array $p, string $mode): array
    {
        $data = $this->collect($f);
        $bareme = $data[0]['bareme'] ?? (new ReglesEvaluation())->getBareme();
        $cols = [$this->c('session', 'Session')];
        if ($mode === 'evaluation') {
            $cols[] = $this->c('evaluation', 'Évaluation');
        }
        foreach ($bareme as $b) {
            $cols[] = $this->c('n' . $b['note'], $b['note'] . '/4 · ' . $b['libelle'], 'int');
        }
        array_push($cols, $this->c('sans', 'Non évalués', 'int'), $this->c('total', 'Effectif', 'int'));
        $lines = [];
        $make = function (string $session, ?string $evaluation, array $apps) use ($bareme, $mode): array {
            $line = ['session' => $session] + ($mode === 'evaluation' ? ['evaluation' => $evaluation] : []);
            foreach ($bareme as $b) {
                $line['n' . $b['note']] = count(array_filter($apps, static fn($a) => $a && $a['note'] === $b['note']));
            }
            return $line + ['sans' => count(array_filter($apps, static fn($a) => !$a)), 'total' => count($apps)];
        };
        foreach ($data as $s) {
            if ($mode === 'session') {
                $lines[] = $make($s['session']['code'] . ' · ' . $s['session']['libelle'], null, array_column($s['auditeurs'], 'appreciation_generale'));
                continue;
            }
            foreach ($s['evaluations'] as $i => $ev) {
                if (isset($f['numero']) && (int) $ev['numero'] !== $f['numero']) {
                    continue;
                }
                $lines[] = $make($s['session']['code'], 'Évaluation ' . $ev['numero'] . ' · ' . $ev['libelle'], array_map(static fn($r) => $r['evaluations'][$i]['appreciation'] ?? null, $s['auditeurs']));
            }
        }
        return ['resume' => $this->resumeResultats($data), 'sections' => [$this->sec($mode === 'session' ? 'Appréciations générales par session' : 'Appréciations par évaluation', $cols, $lines)]];
    }

    private function notesManquantes(array $f): array
    {
        $data = $this->collect($f);
        $lines = [];
        $planning = [];
        foreach ($data as $s) {
            $planning[] = ['session' => $s['session']['code'] . ' · ' . $s['session']['libelle'], 'prevues' => $s['regles']['nb_evaluations'], 'planifiees' => count($s['evaluations']),
                'manquantes' => max(0, $s['regles']['nb_evaluations'] - count($s['evaluations']))];
            foreach ($s['evaluations'] as $i => $ev) {
                foreach ($s['auditeurs'] as $r) {
                    $e = $r['evaluations'][$i];
                    if (!$e['complete']) {
                        $lines[] = ['session' => $s['session']['code'], 'evaluation' => 'Évaluation ' . $ev['numero'], 'date' => $ev['date_evaluation'], 'groupe' => $r['groupe_code'], 'salle' => $r['salle_code'],
                            'formateur' => $r['formateur_prenom'] . ' ' . $r['formateur_nom'], 'matricule' => $r['auditeur_matricule'], 'nom' => $r['auditeur_nom'] . ' ' . $r['auditeur_prenom'],
                            'saisies' => $e['notes_saisies'], 'attendues' => $e['notes_attendues'], 'manquantes' => $e['notes_attendues'] - $e['notes_saisies']];
                    }
                }
            }
        }
        return [
            'resume' => [['label' => 'Évaluations incomplètes', 'value' => count($lines)], ['label' => 'Notes manquantes', 'value' => array_sum(array_column($lines, 'manquantes'))],
                ['label' => 'Évaluations à planifier', 'value' => array_sum(array_column($planning, 'manquantes'))]],
            'sections' => [
                $this->sec('Notes manquantes par auditeur', [$this->c('session', 'Session'), $this->c('evaluation', 'Évaluation'), $this->c('date', 'Date', 'date'), $this->c('groupe', 'Groupe'), $this->c('salle', 'Salle'),
                    $this->c('formateur', 'Formateur'), $this->c('matricule', 'Matricule'), $this->c('nom', 'Auditeur'), $this->c('saisies', 'Saisies', 'int'), $this->c('attendues', 'Attendues', 'int'), $this->c('manquantes', 'Manquantes', 'int')], $lines),
                $this->sec('Évaluations non planifiées', [$this->c('session', 'Session'), $this->c('prevues', 'Prévues', 'int'), $this->c('planifiees', 'Planifiées', 'int'), $this->c('manquantes', 'À planifier', 'int')], $planning),
            ],
        ];
    }

    private function classement(array $f): array
    {
        $data = $this->collect($f);
        $lines = [];
        foreach ($data as $s) {
            $rows = array_values(array_filter($s['auditeurs'], static fn($r) => $r['moyenne_generale'] !== null));
            usort($rows, static fn($a, $b) => $b['moyenne_generale'] <=> $a['moyenne_generale'] ?: strcmp($a['auditeur_nom'], $b['auditeur_nom']));
            $rank = 0;
            $previous = null;
            foreach ($rows as $i => $r) {
                if ($previous === null || $r['moyenne_generale'] != $previous) {
                    $rank = $i + 1;
                    $previous = $r['moyenne_generale'];
                }
                $lines[] = ['session' => $s['session']['code'], 'rang' => $rank, 'matricule' => $r['auditeur_matricule'], 'nom' => $r['auditeur_nom'] . ' ' . $r['auditeur_prenom'],
                    'groupe' => $r['groupe_code'], 'salle' => $r['salle_code'], 'moyenne' => $r['moyenne_generale'], 'appreciation' => $this->appr($r['appreciation_generale']), 'resultat' => self::RES[$r['resultat']]];
            }
        }
        return ['resume' => $this->resumeResultats($data), 'sections' => [$this->sec('Classement par moyenne générale (par session)', [
            $this->c('session', 'Session'), $this->c('rang', 'Rang', 'int'), $this->c('matricule', 'Matricule'), $this->c('nom', 'Auditeur'), $this->c('groupe', 'Groupe'), $this->c('salle', 'Salle'),
            $this->c('moyenne', 'Moyenne /4', 'dec'), $this->c('appreciation', 'Appréciation'), $this->c('resultat', 'Résultat'),
        ], $lines)]];
    }

    /* ------------------------------------------------------------------
     | 4. Passage de niveau
     |------------------------------------------------------------------ */

    /** Décision retenue : celle approuvée par un utilisateur habilité ; aucune décision n'est déduite des moyennes. */
    private function decisionOf(array $r): array
    {
        if ($r['passage'] !== null) {
            return [$r['passage']['decision'], true];
        }
        return ['en_attente', false];
    }

    private function passageListe(array $f, ?array $p, string $decision): array
    {
        $data = $this->collect($f);
        $lines = [];
        foreach ($data as $s) {
            foreach ($s['auditeurs'] as $r) {
                [$d, $validated] = $this->decisionOf($r);
                if ($d !== $decision) {
                    continue;
                }
                $arrival = $r['passage']['niveau_arrivee_libelle'] ?? ($decision === 'admis' ? ($s['session']['niveau_suivant']['libelle'] ?? 'Fin de cursus') : null);
                $lines[] = ['session' => $s['session']['code'], 'niveau' => $s['session']['niveau_libelle'], 'arrivee' => $decision === 'admis' ? $arrival : ($decision === 'termine' ? 'Formation terminée' : 'Reste en ' . $s['session']['niveau_libelle']),
                    'groupe' => $r['groupe_code'], 'matricule' => $r['auditeur_matricule'], 'nom' => $r['auditeur_nom'] . ' ' . $r['auditeur_prenom'],
                    'moyenne' => $r['moyenne_generale'], 'appreciation' => $this->appr($r['appreciation_generale']), 'presence' => $r['presences']['taux'],
                    'statut' => $validated ? 'Approuvée' : 'En attente', 'date' => $r['passage']['date_decision'] ?? null, 'motif' => $r['passage']['observation'] ?? null];
            }
        }
        $titles = ['admis' => 'Auditeurs passés en classe supérieure', 'non_admis' => 'Auditeurs non admis', 'redouble' => 'Auditeurs qui redoublent', 'termine' => 'Auditeurs ayant terminé la formation'];
        return [
            'resume' => [['label' => $titles[$decision], 'value' => count($lines)], ['label' => 'Décisions validées', 'value' => count(array_filter($lines, static fn($l) => $l['statut'] === 'Approuvée'))]],
            'sections' => [$this->sec($titles[$decision], [$this->c('session', 'Session'), $this->c('niveau', 'Niveau de départ'), $this->c('arrivee', 'Niveau d’arrivée'), $this->c('groupe', 'Groupe'),
                $this->c('matricule', 'Matricule'), $this->c('nom', 'Auditeur'), $this->c('moyenne', 'Moyenne /4', 'dec'), $this->c('appreciation', 'Appréciation'), $this->c('presence', 'Présence', 'pct'),
                $this->c('statut', 'Décision'), $this->c('date', 'Date', 'date'), $this->c('motif', 'Motif')], $lines)],
        ];
    }

    private function decisionCounts(array $rows): array
    {
        $c = ['admis' => 0, 'non_admis' => 0, 'redouble' => 0, 'abandonne' => 0, 'termine' => 0, 'en_attente' => 0, 'validees' => 0];
        foreach ($rows as $r) {
            [$d, $v] = $this->decisionOf($r);
            $c[$d] = ($c[$d] ?? 0) + 1;
            $c['validees'] += $v ? 1 : 0;
        }
        return $c;
    }

    private function passageDecisions(array $f, ?array $p, string $dimension): array
    {
        $data = $this->collect($f);
        $groups = [];
        foreach ($data as $s) {
            $key = $dimension === 'session' ? $s['session']['code'] . ' · ' . $s['session']['libelle'] : $s['session']['annee_libelle'];
            foreach ($s['auditeurs'] as $r) {
                $groups[$key][] = $r;
            }
        }
        $lines = [];
        foreach ($groups as $label => $rows) {
            $c = $this->decisionCounts($rows);
            $lines[] = ['libelle' => $label, 'effectif' => count($rows), 'admis' => $c['admis'], 'non_admis' => $c['non_admis'], 'redouble' => $c['redouble'], 'termine' => $c['termine'], 'abandonne' => $c['abandonne'],
                'en_attente' => $c['en_attente'], 'validees' => $c['validees'], 'taux' => $this->pct($c['admis'] + $c['termine'], $c['admis'] + $c['termine'] + $c['non_admis'] + $c['redouble'])];
        }
        return ['resume' => $this->resumeResultats($data), 'sections' => [$this->sec('Décisions par ' . ($dimension === 'session' ? 'session' : 'année scolaire'), [
            $this->c('libelle', $dimension === 'session' ? 'Session' : 'Année scolaire'), $this->c('effectif', 'Effectif', 'int'), $this->c('admis', 'Admis', 'int'), $this->c('non_admis', 'Non admis', 'int'),
            $this->c('redouble', 'Redoublements', 'int'), $this->c('termine', 'Formations terminées', 'int'), $this->c('abandonne', 'Abandons', 'int'), $this->c('en_attente', 'En attente', 'int'), $this->c('validees', 'Décisions validées', 'int'), $this->c('taux', 'Taux d’admission', 'pct'),
        ], $lines)]];
    }

    private function passageProgression(array $f): array
    {
        $params = [];
        $where = $this->insWhere($f, $params);
        $rows = $this->fetchAll(
            "SELECT a.matricule, CONCAT(a.nom, ' ', a.prenom) AS nom, nc.libelle AS niveau_actuel, s.code AS session, an.libelle AS annee,
                    nd.libelle AS depart, COALESCE(na.libelle, '') AS arrivee, pn.decision, pn.moyenne, pn.appreciation, pn.date_decision AS date, pn.mode_validation AS mode
             FROM passages_niveaux pn
             JOIN inscriptions i ON i.id = pn.id_inscription
             JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
             " . self::JOINS . "
             JOIN niveaux nd ON nd.id = pn.niveau_depart
             LEFT JOIN niveaux na ON na.id = pn.niveau_arrivee
             LEFT JOIN niveaux nc ON nc.id = a.id_niveau
             WHERE 1 = 1 {$where}
             ORDER BY a.nom, a.prenom, pn.date_decision",
            $params
        );
        $labels = ['admis' => 'Admis', 'non_admis' => 'Non admis', 'redouble' => 'Redoublement', 'abandonne' => 'Abandon', 'termine' => 'Formation terminée'];
        $lines = array_map(static fn($r) => ['matricule' => $r['matricule'], 'nom' => $r['nom'], 'niveau_actuel' => $r['niveau_actuel'] ?? '—', 'annee' => $r['annee'], 'session' => $r['session'],
            'depart' => $r['depart'], 'arrivee' => $r['arrivee'] ?: '—', 'decision' => $labels[$r['decision']], 'moyenne' => $r['moyenne'] === null ? null : (float) $r['moyenne'],
            'appreciation' => $r['appreciation'] === null ? '—' : $r['appreciation'] . '/4', 'date' => $r['date'], 'mode' => $r['mode']], $rows);
        return [
            'resume' => [['label' => 'Décisions enregistrées', 'value' => count($lines)], ['label' => 'Auditeurs concernés', 'value' => count(array_unique(array_column($lines, 'matricule')))]],
            'sections' => [$this->sec('Historique de progression', [$this->c('matricule', 'Matricule'), $this->c('nom', 'Auditeur'), $this->c('niveau_actuel', 'Niveau actuel'), $this->c('annee', 'Année'), $this->c('session', 'Session'),
                $this->c('depart', 'Niveau de départ'), $this->c('arrivee', 'Niveau d’arrivée'), $this->c('decision', 'Décision'), $this->c('moyenne', 'Moyenne /4', 'dec'), $this->c('appreciation', 'Appréciation'),
                $this->c('date', 'Date', 'date'), $this->c('mode', 'Validation')], $lines)],
        ];
    }

    private function tauxReussite(array $f): array
    {
        $data = $this->collect($f);
        $lines = [];
        foreach ($data as $s) {
            $a = $this->agg($s['auditeurs']);
            $lines[] = ['session' => $s['session']['code'] . ' · ' . $s['session']['libelle'], 'niveau' => $s['session']['niveau_libelle'], 'annee' => $s['session']['annee_libelle'],
                'effectif' => $a['effectif'], 'moyenne' => $a['moyenne'], 'admis' => $a['admis'], 'non_admis' => $a['non_admis'], 'en_attente' => $a['en_attente'], 'taux' => $a['taux_reussite']];
        }
        return ['resume' => $this->resumeResultats($data), 'sections' => [$this->sec('Moyennes et taux de réussite par session', [
            $this->c('session', 'Session'), $this->c('niveau', 'Niveau'), $this->c('annee', 'Année'), $this->c('effectif', 'Effectif', 'int'), $this->c('moyenne', 'Moyenne /4', 'dec'),
            $this->c('admis', 'Admis', 'int'), $this->c('non_admis', 'Non admis', 'int'), $this->c('en_attente', 'En attente', 'int'), $this->c('taux', 'Taux de réussite', 'pct'),
        ], $lines)]];
    }

    /* ------------------------------------------------------------------
     | 5. Formateurs
     |------------------------------------------------------------------ */

    private function edtRows(array $f, string $order = 'f.nom, f.prenom, e.jour_semaine'): array
    {
        $params = [];
        $where = $this->edtWhere($f, $params);
        return $this->fetchAll(
            "SELECT e.id, e.jour_semaine, e.statut, e.id_annee, e.id_formateur, e.id_salle, e.id_groupe,
                    f.matricule AS f_matricule, CONCAT(f.prenom, ' ', f.nom) AS formateur, f.actif AS f_actif,
                    sa.code AS salle, sa.libelle AS salle_libelle, sa.capacite, sa.statut AS salle_statut,
                    g.code AS groupe, g.libelle AS groupe_libelle, g.heure_debut, g.heure_fin, g.jour_semaine AS g_jour,
                    s.code AS session, s.libelle AS session_libelle, s.id AS id_session, s.date_debut AS s_debut, s.date_fin AS s_fin,
                    n.libelle AS niveau, an.libelle AS annee,
                    (SELECT COUNT(*) FROM inscriptions i WHERE i.id_emploi_du_temps = e.id AND i.statut = 'active') AS effectif
             " . self::EDT_FROM . " WHERE e.statut = 1 {$where} ORDER BY {$order}",
            $params
        );
    }

    private function formateursAffectations(array $f): array
    {
        $rows = $this->edtRows($f);
        $lines = array_map(fn($r) => ['formateur' => $r['formateur'], 'matricule' => $r['f_matricule'], 'salle' => $r['salle'] . ' · ' . $r['salle_libelle'], 'groupe' => $r['groupe'] . ' · ' . $r['groupe_libelle'],
            'session' => $r['session'], 'annee' => $r['annee'], 'jour' => self::DAYS[$r['jour_semaine']] ?? '', 'horaires' => substr($r['heure_debut'], 0, 5) . ' – ' . substr($r['heure_fin'], 0, 5), 'effectif' => $this->num($r['effectif'])], $rows);
        return [
            'resume' => [['label' => 'Affectations actives', 'value' => count($lines)], ['label' => 'Formateurs affectés', 'value' => count(array_unique(array_column($rows, 'id_formateur')))]],
            'sections' => [$this->sec('Formateurs et affectations', [$this->c('formateur', 'Formateur'), $this->c('matricule', 'Matricule'), $this->c('salle', 'Salle'), $this->c('groupe', 'Groupe'), $this->c('session', 'Session'),
                $this->c('annee', 'Année'), $this->c('jour', 'Jour'), $this->c('horaires', 'Horaires'), $this->c('effectif', 'Auditeurs', 'int')], $lines)],
        ];
    }

    private function formateursSeances(array $f, array $period): array
    {
        $params = [];
        $where = $this->presWhere($f, $period, $params);
        $rows = $this->fetchAll(
            "SELECT p.date_presence AS date, CONCAT(f.prenom, ' ', f.nom) AS formateur, g.code AS groupe, sa.code AS salle, s.code AS session,
                    COUNT(*) AS pointes, SUM(p.statut = 'present') AS presents, SUM(p.statut = 'absent') AS absents
             {$this->presFrom()} WHERE 1 = 1 {$where}
             GROUP BY p.date_presence, f.id, f.prenom, f.nom, g.id, g.code, sa.id, sa.code, s.id, s.code
             ORDER BY p.date_presence DESC, f.nom",
            $params
        );
        $lines = array_map(fn($r) => ['date' => $r['date'], 'formateur' => $r['formateur'], 'groupe' => $r['groupe'], 'salle' => $r['salle'], 'session' => $r['session'],
            'pointes' => $this->num($r['pointes']), 'presents' => $this->num($r['presents']), 'absents' => $this->num($r['absents']), 'taux' => $this->pct($r['presents'], $r['pointes'])], $rows);
        $byTrainer = [];
        foreach ($lines as $l) {
            $byTrainer[$l['formateur']] = ($byTrainer[$l['formateur']] ?? 0) + 1;
        }
        arsort($byTrainer);
        return [
            'resume' => [['label' => 'Séances assurées', 'value' => count($lines)], ['label' => 'Formateurs actifs', 'value' => count($byTrainer)]],
            'sections' => [
                $this->sec('Séances par formateur', [$this->c('formateur', 'Formateur'), $this->c('seances', 'Séances assurées', 'int')], array_map(static fn($k, $v) => ['formateur' => $k, 'seances' => $v], array_keys($byTrainer), $byTrainer)),
                $this->sec('Détail des séances', [$this->c('date', 'Date', 'date'), $this->c('formateur', 'Formateur'), $this->c('groupe', 'Groupe'), $this->c('salle', 'Salle'), $this->c('session', 'Session'),
                    $this->c('pointes', 'Pointés', 'int'), $this->c('presents', 'Présents', 'int'), $this->c('absents', 'Absents', 'int'), $this->c('taux', 'Taux de présence', 'pct')], $lines),
            ],
        ];
    }

    private function formateursResume(array $f, ?array $p, string $mode): array
    {
        $rows = $this->edtRows($f);
        $by = [];
        foreach ($rows as $r) {
            $by[$r['formateur']]['matricule'] = $r['f_matricule'];
            $by[$r['formateur']]['items'][$mode === 'groupes' ? $r['groupe'] : $r['salle']] = true;
            $by[$r['formateur']]['creneaux'] = ($by[$r['formateur']]['creneaux'] ?? 0) + 1;
            $by[$r['formateur']]['effectif'] = ($by[$r['formateur']]['effectif'] ?? 0) + $this->num($r['effectif']);
        }
        ksort($by);
        $lines = [];
        foreach ($by as $name => $d) {
            $lines[] = ['formateur' => $name, 'matricule' => $d['matricule'], 'nombre' => count($d['items']), 'liste' => implode(', ', array_keys($d['items'])), 'creneaux' => $d['creneaux'], 'effectif' => $d['effectif']];
        }
        $label = $mode === 'groupes' ? 'Groupes' : 'Salles';
        return [
            'resume' => [['label' => 'Formateurs', 'value' => count($lines)]],
            'sections' => [$this->sec("$label pris en charge par formateur", [$this->c('formateur', 'Formateur'), $this->c('matricule', 'Matricule'), $this->c('nombre', "Nombre de " . mb_strtolower($label), 'int'),
                $this->c('liste', $label), $this->c('creneaux', 'Créneaux hebdomadaires', 'int'), $this->c('effectif', 'Auditeurs', 'int')], $lines)],
        ];
    }

    private function formateursPresences(array $f, array $period): array
    {
        $params = [];
        $where = $this->presWhere($f, $period, $params);
        $rows = $this->fetchAll(
            "SELECT CONCAT(fp.prenom, ' ', fp.nom) AS libelle, fp.matricule AS code, COUNT(*) AS total, SUM(p.statut = 'present') AS present, SUM(p.statut = 'absent') AS absent,
                    SUM(p.statut = 'permissionnaire') AS permissionnaire, SUM(p.statut = 'malade') AS malade, SUM(p.statut = 'conge') AS conge,
                    MIN(p.date_presence) AS premiere, MAX(p.date_presence) AS derniere, COUNT(DISTINCT p.date_presence) AS jours
             {$this->presFrom()} JOIN v_formateurs fp ON fp.id = p.id_formateur
             WHERE 1 = 1 {$where} GROUP BY fp.id, fp.prenom, fp.nom, fp.matricule ORDER BY total DESC",
            $params
        );
        $lines = array_map(fn($r) => ['code' => $r['code'], 'libelle' => $r['libelle'], 'jours' => $this->num($r['jours']), 'total' => $this->num($r['total']), 'present' => $this->num($r['present']), 'absent' => $this->num($r['absent']),
            'autres' => $this->num($r['permissionnaire']) + $this->num($r['malade']) + $this->num($r['conge']), 'premiere' => $r['premiere'], 'derniere' => $r['derniere']], $rows);
        return [
            'resume' => [['label' => 'Pointages saisis', 'value' => array_sum(array_column($lines, 'total'))], ['label' => 'Formateurs', 'value' => count($lines)]],
            'sections' => [$this->sec('Présences saisies par formateur', [$this->c('code', 'Matricule'), $this->c('libelle', 'Formateur'), $this->c('jours', 'Jours pointés', 'int'), $this->c('total', 'Pointages saisis', 'int'),
                $this->c('present', 'Présents', 'int'), $this->c('absent', 'Absents', 'int'), $this->c('autres', 'Autres statuts', 'int'), $this->c('premiere', 'Premier pointage', 'date'), $this->c('derniere', 'Dernier pointage', 'date')], $lines)],
        ];
    }

    private function formateursNotes(array $f): array
    {
        $params = [];
        $extra = '';
        foreach (['id_annee' => 'i.id_annee', 'id_session' => 'ev.id_session', 'id_groupe' => 'e.id_groupe', 'id_salle' => 'e.id_salle', 'id_formateur' => 'e.id_formateur'] as $key => $col) {
            if (isset($f[$key])) {
                $extra .= " AND {$col} = :w_{$key}";
                $params["w_{$key}"] = $f[$key];
            }
        }
        $rows = $this->fetchAll(
            "SELECT CONCAT(fo.prenom, ' ', fo.nom) AS formateur, s.code AS session, ev.numero, ev.libelle AS evaluation,
                    COUNT(DISTINCT i.id) AS auditeurs,
                    COUNT(DISTINCT i.id) * (SELECT COUNT(*) FROM evaluation_parametres ep WHERE ep.id_evaluation = ev.id) AS attendues,
                    SUM((SELECT COUNT(*) FROM notes n WHERE n.id_evaluation = ev.id AND n.id_inscription = i.id AND n.note IS NOT NULL)) AS saisies
             FROM evaluations ev
             JOIN sessions s ON s.id = ev.id_session
             JOIN inscriptions i ON i.id_session = s.id AND i.statut IN ('active','terminee')
             JOIN emplois_du_temps e ON e.id = i.id_emploi_du_temps
             JOIN v_formateurs fo ON fo.id = e.id_formateur
             WHERE 1 = 1 {$extra}
             GROUP BY fo.id, fo.prenom, fo.nom, s.id, s.code, ev.id, ev.numero, ev.libelle
             ORDER BY fo.nom, s.code, ev.numero",
            $params
        );
        $lines = array_map(fn($r) => ['formateur' => $r['formateur'], 'session' => $r['session'], 'evaluation' => 'Évaluation ' . $r['numero'] . ' · ' . $r['evaluation'], 'auditeurs' => $this->num($r['auditeurs']),
            'attendues' => $this->num($r['attendues']), 'saisies' => $this->num($r['saisies']), 'taux' => $this->pct($r['saisies'], $r['attendues'])], $rows);
        return [
            'resume' => [['label' => 'Notes saisies', 'value' => array_sum(array_column($lines, 'saisies'))], ['label' => 'Notes attendues', 'value' => array_sum(array_column($lines, 'attendues'))]],
            'sections' => [$this->sec('Notes saisies par formateur (sur ses classes)', [$this->c('formateur', 'Formateur'), $this->c('session', 'Session'), $this->c('evaluation', 'Évaluation'), $this->c('auditeurs', 'Auditeurs', 'int'),
                $this->c('attendues', 'Notes attendues', 'int'), $this->c('saisies', 'Notes saisies', 'int'), $this->c('taux', 'Avancement', 'pct')], $lines)],
        ];
    }

    /* ------------------------------------------------------------------
     | 6. Salles et emplois du temps
     |------------------------------------------------------------------ */

    private function sallesOccupation(array $f): array
    {
        $rows = $this->edtRows($f, 'sa.code, e.jour_semaine');
        $by = [];
        foreach ($rows as $r) {
            $by[$r['id_salle']]['d'] = $r;
            $by[$r['id_salle']]['jours'][$r['jour_semaine']] = true;
            $by[$r['id_salle']]['groupes'][$r['groupe']] = true;
            $by[$r['id_salle']]['effectif'] = ($by[$r['id_salle']]['effectif'] ?? 0) + $this->num($r['effectif']);
            $by[$r['id_salle']]['n'] = ($by[$r['id_salle']]['n'] ?? 0) + 1;
            $by[$r['id_salle']]['max'] = max($by[$r['id_salle']]['max'] ?? 0, $this->num($r['effectif']));
        }
        $params = [];
        $salles = $this->fetchAll('SELECT id, code, libelle, capacite, statut FROM salles' . (isset($f['id_salle']) ? ' WHERE id = :id' : '') . ' ORDER BY code', isset($f['id_salle']) ? ['id' => $f['id_salle']] : $params);
        $lines = [];
        foreach ($salles as $s) {
            $d = $by[$s['id']] ?? null;
            $days = $d ? count($d['jours']) : 0;
            $lines[] = ['code' => $s['code'], 'libelle' => $s['libelle'], 'statut' => $s['statut'] === 'disponible' ? 'Disponible' : 'Indisponible', 'capacite' => $s['capacite'] === null ? null : (int) $s['capacite'],
                'creneaux' => $d['n'] ?? 0, 'jours' => $days, 'occupation' => round($days / 5 * 100, 1), 'groupes' => $d ? implode(', ', array_keys($d['groupes'])) : '—',
                'effectif' => $d['max'] ?? 0, 'remplissage' => $s['capacite'] ? $this->pct($d['max'] ?? 0, $s['capacite']) : null];
        }
        return [
            'resume' => [['label' => 'Salles', 'value' => count($lines)], ['label' => 'Taux d’occupation moyen', 'value' => $lines ? round(array_sum(array_column($lines, 'occupation')) / count($lines), 1) . ' %' : '—']],
            'sections' => [$this->sec('Occupation des salles', [$this->c('code', 'Salle'), $this->c('libelle', 'Libellé'), $this->c('statut', 'Statut'), $this->c('capacite', 'Capacité', 'int'), $this->c('creneaux', 'Créneaux', 'int'), $this->c('jours', 'Jours occupés / 5', 'int'),
                $this->c('occupation', 'Taux d’occupation', 'pct'), $this->c('groupes', 'Groupes'), $this->c('effectif', 'Effectif maximal', 'int'), $this->c('remplissage', 'Taux de remplissage', 'pct')], $lines)],
        ];
    }

    private function sallesDisponibilite(array $f): array
    {
        $rows = $this->edtRows($f);
        $busy = [];
        foreach ($rows as $r) {
            $busy[$r['id_salle']][$r['jour_semaine']][] = $r['groupe'];
        }
        $salles = $this->fetchAll('SELECT id, code, libelle, capacite, statut FROM salles' . (isset($f['id_salle']) ? ' WHERE id = :id' : '') . ' ORDER BY code', isset($f['id_salle']) ? ['id' => $f['id_salle']] : []);
        $lines = [];
        foreach ($salles as $s) {
            $line = ['code' => $s['code'], 'libelle' => $s['libelle'], 'statut' => $s['statut'] === 'disponible' ? 'Disponible' : 'Indisponible'];
            $free = 0;
            foreach (self::DAYS as $d => $name) {
                $taken = $busy[$s['id']][$d] ?? null;
                $line["j$d"] = $taken ? implode(', ', $taken) : ($s['statut'] === 'disponible' ? 'Libre' : '—');
                $free += (!$taken && $s['statut'] === 'disponible') ? 1 : 0;
            }
            $lines[] = $line + ['libres' => $free];
        }
        $cols = [$this->c('code', 'Salle'), $this->c('libelle', 'Libellé'), $this->c('statut', 'Statut')];
        foreach (self::DAYS as $d => $name) {
            $cols[] = $this->c("j$d", $name);
        }
        $cols[] = $this->c('libres', 'Jours libres', 'int');
        return ['resume' => [['label' => 'Jours-salles libres', 'value' => array_sum(array_column($lines, 'libres'))]], 'sections' => [$this->sec('Disponibilité hebdomadaire des salles', $cols, $lines)]];
    }

    private function seancesJourSemaine(array $f, array $period): array
    {
        $rows = $this->edtRows($f);
        $days = [];
        foreach (self::DAYS as $d => $name) {
            $slice = array_filter($rows, static fn($r) => (int) $r['jour_semaine'] === $d);
            $days[] = ['jour' => $name, 'creneaux' => count($slice), 'groupes' => count(array_unique(array_column($slice, 'id_groupe'))), 'salles' => count(array_unique(array_column($slice, 'id_salle'))),
                'formateurs' => count(array_unique(array_column($slice, 'id_formateur'))), 'auditeurs' => array_sum(array_map(fn($r) => $this->num($r['effectif']), $slice))];
        }
        $weeks = [];
        for ($c = new DateTimeImmutable($period['debut']); $c <= new DateTimeImmutable($period['fin']); $c = $c->modify('+1 day')) {
            $wd = (int) $c->format('N');
            if ($wd > 5) {
                continue;
            }
            $iso = $c->format('Y-m-d');
            $week = $c->modify('monday this week')->format('Y-m-d');
            $weeks[$week] ??= ['semaine' => 'Semaine du ' . (new DateTimeImmutable($week))->format('d/m/Y'), 'seances' => 0, 'jours' => 0, 'auditeurs' => 0];
            $todays = array_filter($rows, static fn($r) => (int) $r['jour_semaine'] === $wd && $iso >= $r['s_debut'] && $iso <= $r['s_fin']);
            $weeks[$week]['seances'] += count($todays);
            $weeks[$week]['jours'] += $todays ? 1 : 0;
            $weeks[$week]['auditeurs'] += array_sum(array_map(fn($r) => $this->num($r['effectif']), $todays));
        }
        return [
            'resume' => [['label' => 'Créneaux actifs', 'value' => count($rows)], ['label' => 'Séances sur la période', 'value' => array_sum(array_column($weeks, 'seances'))]],
            'sections' => [
                $this->sec('Séances par jour de la semaine', [$this->c('jour', 'Jour'), $this->c('creneaux', 'Créneaux', 'int'), $this->c('groupes', 'Groupes', 'int'), $this->c('salles', 'Salles', 'int'), $this->c('formateurs', 'Formateurs', 'int'), $this->c('auditeurs', 'Auditeurs', 'int')], $days),
                $this->sec('Séances par semaine — ' . $period['libelle'], [$this->c('semaine', 'Semaine'), $this->c('seances', 'Séances', 'int'), $this->c('jours', 'Jours de cours', 'int'), $this->c('auditeurs', 'Présences attendues', 'int')], array_values($weeks)),
            ],
        ];
    }

    private function edtListe(array $f, ?array $p, string $dimension): array
    {
        $rows = $this->edtRows($f, $dimension === 'groupe' ? 'g.code, e.jour_semaine, sa.code' : 'f.nom, f.prenom, e.jour_semaine');
        $lines = array_map(fn($r) => ['groupe' => $r['groupe'] . ' · ' . $r['groupe_libelle'], 'formateur' => $r['formateur'], 'jour' => self::DAYS[$r['jour_semaine']] ?? '', 'horaires' => substr($r['heure_debut'], 0, 5) . ' – ' . substr($r['heure_fin'], 0, 5),
            'salle' => $r['salle'] . ' · ' . $r['salle_libelle'], 'session' => $r['session'], 'niveau' => $r['niveau'], 'annee' => $r['annee'], 'effectif' => $this->num($r['effectif'])], $rows);
        $first = $dimension === 'groupe' ? [$this->c('groupe', 'Groupe'), $this->c('formateur', 'Formateur')] : [$this->c('formateur', 'Formateur'), $this->c('groupe', 'Groupe')];
        return [
            'resume' => [['label' => 'Créneaux', 'value' => count($lines)]],
            'sections' => [$this->sec('Emplois du temps par ' . $dimension, array_merge($first, [$this->c('jour', 'Jour'), $this->c('horaires', 'Horaires'), $this->c('salle', 'Salle'), $this->c('session', 'Session'),
                $this->c('niveau', 'Niveau'), $this->c('annee', 'Année'), $this->c('effectif', 'Auditeurs', 'int')]), $lines)],
        ];
    }

    private function sallesConflits(array $f): array
    {
        $rows = $this->edtRows($f);
        $titulaires = [];
        foreach ($this->fetchAll('SELECT id, id_salle, CONCAT(prenom, \' \', nom) AS nom FROM v_formateurs WHERE id_salle IS NOT NULL') as $t) {
            $titulaires[(int) $t['id_salle']] = $t;
        }
        $lines = [];
        $add = function (string $type, string $detail, array $r) use (&$lines): void {
            $lines[] = ['type' => $type, 'jour' => self::DAYS[$r['jour_semaine']] ?? '', 'groupe' => $r['groupe'], 'salle' => $r['salle'], 'formateur' => $r['formateur'], 'detail' => $detail];
        };
        $n = count($rows);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $rows[$i];
                $b = $rows[$j];
                if ($a['id_annee'] !== $b['id_annee'] || $a['jour_semaine'] !== $b['jour_semaine'] || !($a['heure_debut'] < $b['heure_fin'] && $b['heure_debut'] < $a['heure_fin'])) {
                    continue;
                }
                if ($a['id_salle'] === $b['id_salle']) {
                    $add('Conflit de salle', "{$a['salle']} réservée par {$a['groupe']} et {$b['groupe']} sur des horaires qui se chevauchent.", $a);
                }
                if ($a['id_formateur'] === $b['id_formateur']) {
                    $add('Conflit de formateur', "{$a['formateur']} affecté à {$a['groupe']} ({$a['salle']}) et {$b['groupe']} ({$b['salle']}) simultanément.", $a);
                }
            }
        }
        foreach ($rows as $r) {
            $t = $titulaires[(int) $r['id_salle']] ?? null;
            if ($t && (int) $t['id'] !== (int) $r['id_formateur']) {
                $add('Affectation', "Le formateur n’est pas le titulaire de la salle ({$t['nom']}).", $r);
            }
            if ($r['capacite'] !== null && $this->num($r['effectif']) > (int) $r['capacite']) {
                $add('Capacité', "{$r['effectif']} auditeurs pour {$r['capacite']} places.", $r);
            }
            if ((int) $r['g_jour'] !== (int) $r['jour_semaine']) {
                $add('Jour', 'Le groupe a cours le ' . mb_strtolower(self::DAYS[$r['g_jour']] ?? '?') . '.', $r);
            }
            if ($r['salle_statut'] !== 'disponible') {
                $add('Salle indisponible', 'La salle est marquée indisponible.', $r);
            }
            if (!(int) $r['f_actif']) {
                $add('Formateur inactif', 'Le formateur est inactif.', $r);
            }
        }
        return [
            'resume' => [['label' => 'Conflits et incohérences', 'value' => count($lines)], ['label' => 'Créneaux contrôlés', 'value' => $n]],
            'sections' => [$this->sec('Conflits de salles, d’horaires et d’affectation', [$this->c('type', 'Type'), $this->c('jour', 'Jour'), $this->c('groupe', 'Groupe'), $this->c('salle', 'Salle'), $this->c('formateur', 'Formateur'), $this->c('detail', 'Détail')], $lines)],
        ];
    }

    private function effectifsSalleGroupe(array $f): array
    {
        $rows = $this->edtRows($f, 'sa.code, g.code');
        $lines = array_map(fn($r) => ['salle' => $r['salle'] . ' · ' . $r['salle_libelle'], 'groupe' => $r['groupe'] . ' · ' . $r['groupe_libelle'], 'session' => $r['session'], 'jour' => self::DAYS[$r['jour_semaine']] ?? '',
            'formateur' => $r['formateur'], 'effectif' => $this->num($r['effectif']), 'capacite' => $r['capacite'] === null ? null : (int) $r['capacite'], 'taux' => $r['capacite'] ? $this->pct($r['effectif'], $r['capacite']) : null], $rows);
        $salles = [];
        $groupes = [];
        foreach ($lines as $l) {
            $salles[$l['salle']] = ($salles[$l['salle']] ?? 0) + $l['effectif'];
            $groupes[$l['groupe']] = ($groupes[$l['groupe']] ?? 0) + $l['effectif'];
        }
        return [
            'resume' => [['label' => 'Auditeurs affectés', 'value' => array_sum($salles)]],
            'sections' => [
                $this->sec('Effectifs par salle et par groupe', [$this->c('salle', 'Salle'), $this->c('groupe', 'Groupe'), $this->c('session', 'Session'), $this->c('jour', 'Jour'), $this->c('formateur', 'Formateur'),
                    $this->c('effectif', 'Effectif', 'int'), $this->c('capacite', 'Capacité', 'int'), $this->c('taux', 'Remplissage', 'pct')], $lines),
                $this->sec('Total par salle', [$this->c('libelle', 'Salle'), $this->c('effectif', 'Auditeurs', 'int')], array_map(static fn($k, $v) => ['libelle' => $k, 'effectif' => $v], array_keys($salles), $salles)),
                $this->sec('Total par groupe', [$this->c('libelle', 'Groupe'), $this->c('effectif', 'Auditeurs', 'int')], array_map(static fn($k, $v) => ['libelle' => $k, 'effectif' => $v], array_keys($groupes), $groupes)),
            ],
        ];
    }

    /* ------------------------------------------------------------------
     | 7. Administratifs et statistiques
     |------------------------------------------------------------------ */

    private function tableauGeneral(array $f, array $period): array
    {
        $params = [];
        $where = $this->insWhere($f, $params);
        $k = $this->fetchOne("SELECT COUNT(DISTINCT i.id_auditeur) AS auditeurs, SUM(i.statut = 'active') AS actives {$this->insFrom()} WHERE 1 = 1 {$where}", $params);
        $pr = $this->presenceRows('session', $f, $period);
        $pt = array_sum(array_column($pr, 'total'));
        $pp = array_sum(array_column($pr, 'present'));
        $data = $this->collect($f);
        $a = $this->agg($this->allRows($data));
        $today = date('Y-m-d');
        $rows = [
            ['Auditeurs enregistrés', (int) $this->fetchColumn('SELECT COUNT(*) FROM auditeurs')], ['Auditeurs inscrits (périmètre)', $this->num($k['auditeurs'])], ['Inscriptions actives', $this->num($k['actives'])],
            ['Formateurs actifs', (int) $this->fetchColumn('SELECT COUNT(*) FROM v_formateurs WHERE actif = 1')], ['Salles disponibles / total', $this->fetchColumn("SELECT CONCAT(SUM(statut = 'disponible'), ' / ', COUNT(*)) FROM salles")],
            ['Groupes actifs', (int) $this->fetchColumn('SELECT COUNT(*) FROM groupes WHERE statut = 1')], ['Sessions en cours', (int) $this->fetchColumn("SELECT COUNT(*) FROM sessions WHERE statut IN ('ouverte','en_cours') AND :d BETWEEN date_debut AND date_fin", ['d' => $today])],
            ['Évaluations réalisées', (int) $this->fetchColumn('SELECT COUNT(*) FROM evaluations WHERE date_evaluation <= :d', ['d' => $today])], ['Pointages sur la période', $pt],
            ['Taux de présence', $this->pct($pp, $pt) === null ? '—' : $this->pct($pp, $pt) . ' %'], ['Moyenne générale', $a['moyenne'] === null ? '—' : $a['moyenne'] . ' /4'],
            ['Admis / Non admis / En attente', "{$a['admis']} / {$a['non_admis']} / {$a['en_attente']}"],
        ];
        return [
            'resume' => [['label' => 'Période', 'value' => $period['libelle']]],
            'sections' => [
                $this->sec('Indicateurs généraux', [$this->c('indicateur', 'Indicateur'), $this->c('valeur', 'Valeur')], array_map(static fn($r) => ['indicateur' => $r[0], 'valeur' => $r[1]], $rows)),
                $this->countBy('session', $f, 'Effectifs par session'),
                $this->sec('Présences par session', $this->presenceCols('session'), $pr),
            ],
        ];
    }

    /** Statistiques croisées effectif / présence / résultats par dimension. */
    private function statistiques(array $f, array $period, string $dimension): array
    {
        $count = $this->countBy($dimension, $f, null, true);
        $presDim = ['annee' => 'session', 'session' => 'session', 'departement' => 'departement', 'type' => 'type'][$dimension];
        $pres = $this->presenceRows($presDim, $f, $period);
        $data = $this->collect($f);
        $resByKey = [];
        foreach ($data as $s) {
            foreach ($s['auditeurs'] as $r) {
                $key = match ($dimension) {
                    'annee' => $s['session']['annee_libelle'],
                    'session' => $s['session']['libelle'],
                    'departement' => $r['departement_libelle'],
                    'type' => $r['type_employe_libelle'] ?? 'Non renseigné',
                };
                $resByKey[$key][] = $r;
            }
        }
        $presByKey = [];
        foreach ($pres as $row) {
            if ($dimension === 'annee') {
                continue;
            }
            $presByKey[$dimension === 'session' ? $row['libelle'] : $row['libelle']] = $row;
        }
        if ($dimension === 'annee') {
            $total = array_sum(array_column($pres, 'total'));
            $present = array_sum(array_column($pres, 'present'));
            $presByKey = array_fill_keys(array_keys($resByKey) ?: [], ['total' => $total, 'present' => $present, 'taux_presence' => $this->pct($present, $total)]);
        }
        $lines = [];
        foreach ($count['lignes'] as $row) {
            $key = $row['libelle'];
            $agg = $this->agg($resByKey[$key] ?? []);
            $lines[] = ['libelle' => $key, 'auditeurs' => $row['auditeurs'], 'actives' => $row['actives'], 'hommes' => $row['hommes'], 'femmes' => $row['femmes'],
                'pointages' => $presByKey[$key]['total'] ?? 0, 'taux_presence' => $presByKey[$key]['taux_presence'] ?? null, 'moyenne' => $agg['moyenne'], 'admis' => $agg['admis'],
                'non_admis' => $agg['non_admis'], 'en_attente' => $agg['en_attente'], 'taux_reussite' => $agg['taux_reussite']];
        }
        $label = self::DIMENSIONS[$dimension][3];
        return [
            'resume' => [['label' => 'Période (présences)', 'value' => $period['libelle']], ['label' => $label . 's analysé(e)s', 'value' => count($lines)]],
            'sections' => [$this->sec('Statistiques par ' . mb_strtolower($label), [$this->c('libelle', $label), $this->c('auditeurs', 'Auditeurs', 'int'), $this->c('actives', 'Inscriptions actives', 'int'), $this->c('hommes', 'Hommes', 'int'), $this->c('femmes', 'Femmes', 'int'),
                $this->c('pointages', 'Pointages', 'int'), $this->c('taux_presence', 'Taux de présence', 'pct'), $this->c('moyenne', 'Moyenne /4', 'dec'), $this->c('admis', 'Admis', 'int'), $this->c('non_admis', 'Non admis', 'int'),
                $this->c('en_attente', 'En attente', 'int'), $this->c('taux_reussite', 'Taux de réussite', 'pct')], $lines)],
        ];
    }

    private function comparatifSessions(array $f): array
    {
        $data = $this->collect($f);
        $max = $this->maxEvaluations($data);
        $cols = [$this->c('session', 'Session'), $this->c('niveau', 'Niveau'), $this->c('annee', 'Année'), $this->c('effectif', 'Effectif', 'int')];
        for ($i = 1; $i <= $max; $i++) {
            $cols[] = $this->c("e$i", "Moy. éval. $i", 'dec');
        }
        array_push($cols, $this->c('moyenne', 'Moyenne générale', 'dec'), $this->c('presence', 'Présence moyenne', 'pct'), $this->c('admis', 'Admis', 'int'), $this->c('non_admis', 'Non admis', 'int'), $this->c('taux', 'Taux de réussite', 'pct'));
        $lines = [];
        foreach ($data as $s) {
            $a = $this->agg($s['auditeurs']);
            $line = ['session' => $s['session']['code'] . ' · ' . $s['session']['libelle'], 'niveau' => $s['session']['niveau_libelle'], 'annee' => $s['session']['annee_libelle'], 'effectif' => $a['effectif']];
            for ($i = 0; $i < $max; $i++) {
                $vals = array_values(array_filter(array_map(static fn($r) => $r['evaluations'][$i]['moyenne'] ?? null, $s['auditeurs']), static fn($v) => $v !== null));
                $line['e' . ($i + 1)] = $vals ? round(array_sum($vals) / count($vals), 2) : null;
            }
            $rates = array_values(array_filter(array_map(static fn($r) => $r['presences']['taux'], $s['auditeurs']), static fn($v) => $v !== null));
            $lines[] = $line + ['moyenne' => $a['moyenne'], 'presence' => $rates ? round(array_sum($rates) / count($rates), 1) : null, 'admis' => $a['admis'], 'non_admis' => $a['non_admis'], 'taux' => $a['taux_reussite']];
        }
        return ['resume' => $this->resumeResultats($data), 'sections' => [$this->sec('Comparatif des résultats entre sessions', $cols, $lines)]];
    }

    private function rapportGlobal(array $f, array $period): array
    {
        $pres = $this->presenceRows('session', $f, $period);
        $res = $this->resultatsAgreges($f, null, 'session');
        return [
            'resume' => array_merge([['label' => 'Période', 'value' => $period['libelle']]], $this->presenceResume($pres)),
            'sections' => [$this->countBy('session', $f, 'Effectifs par session', true), $this->sec('Présences par session', $this->presenceCols('session'), $pres), $res['sections'][0]],
        ];
    }

    private function syntheseAnnuelle(array $f, array $period): array
    {
        $pres = $this->presenceRows('mois', $f, $period);
        $general = $this->tableauGeneral($f, $period);
        return [
            'resume' => array_merge([['label' => 'Année', 'value' => $period['libelle']]], $this->presenceResume($pres)),
            'sections' => [
                $general['sections'][0],
                $this->countBy('niveau', $f, 'Effectifs par niveau', true),
                $this->sec('Présences par mois', $this->presenceCols('mois'), $pres),
                $this->resultatsAgreges($f, null, 'session')['sections'][0],
                $this->passageDecisions($f, null, 'session')['sections'][0],
            ],
        ];
    }
}
