<?php
/**
 * Journal des opérations (table : journal_operations)
 * Trace chaque écriture envoyée à l'API : qui, quoi, sur quelle ressource, avec quel résultat.
 */

require_once __DIR__ . '/Model.php';

class Journal extends Model
{
    protected string $table = 'journal_operations';

    private const SENSITIVE_KEYS = ['password', 'mot_de_passe', 'new_password', 'current_password', 'confirm_password', 'token'];

    /** Libellé lisible de l'action à partir de la méthode et de la route. */
    public static function actionFor(string $method, string $resource, ?string $param1, ?string $param2): string
    {
        if ($resource === 'auth') {
            return $param1 === 'login' ? 'connexion' : ($param1 === 'logout' ? 'deconnexion' : 'profil');
        }
        if ($method === 'DELETE') {
            return 'suppression';
        }
        if (in_array($param1, ['valider', 'decide', 'validate'], true) || $param2 === 'statut') {
            return 'validation';
        }
        return $method === 'POST' && !in_array($param1, ['save-or-update', 'mark', 'mark-bulk'], true) ? 'creation' : 'modification';
    }

    public function record(array $entry): void
    {
        $payload = json_decode((string) ($entry['body'] ?? ''), true);
        if (is_array($payload)) {
            array_walk_recursive($payload, static function (&$value, $key): void {
                if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                    $value = '***';
                }
            });
            $payload = mb_substr(json_encode($payload, JSON_UNESCAPED_UNICODE), 0, 2000);
        } else {
            $payload = null;
        }

        $this->db->prepare(
            "INSERT INTO {$this->table}
                (user_id, utilisateur, role_code, methode, action, ressource, ressource_id, chemin,
                 code_http, succes, message, donnees, adresse_ip)
             VALUES (:user_id, :utilisateur, :role_code, :methode, :action, :ressource, :ressource_id, :chemin,
                 :code_http, :succes, :message, :donnees, :ip)"
        )->execute([
            'user_id' => $entry['user']['id'] ?? null,
            'utilisateur' => isset($entry['user']) ? trim(($entry['user']['prenom'] ?? '') . ' ' . ($entry['user']['nom'] ?? '')) : ($entry['identifiant'] ?? null),
            'role_code' => $entry['user']['role_code'] ?? null,
            'methode' => $entry['method'],
            'action' => $entry['action'],
            'ressource' => mb_substr($entry['resource'], 0, 60),
            'ressource_id' => $entry['resource_id'] !== null ? mb_substr((string) $entry['resource_id'], 0, 60) : null,
            'chemin' => mb_substr($entry['path'], 0, 255),
            'code_http' => $entry['code'],
            'succes' => $entry['code'] < 400 ? 1 : 0,
            'message' => $entry['message'] !== null ? mb_substr($entry['message'], 0, 500) : null,
            'donnees' => $payload,
            'ip' => $entry['ip'],
        ]);
    }

    /** Recherche dans le journal (les plus récents d'abord). */
    public function search(array $filters, int $limit = 300): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['ressource'])) {
            $where[] = 'ressource = :ressource';
            $params['ressource'] = $filters['ressource'];
        }
        if (!empty($filters['action'])) {
            $where[] = 'action = :action';
            $params['action'] = $filters['action'];
        }
        if (isset($filters['succes']) && $filters['succes'] !== '') {
            $where[] = 'succes = :succes';
            $params['succes'] = (int) $filters['succes'];
        }
        if (!empty($filters['debut'])) {
            $where[] = 'created_at >= :debut';
            $params['debut'] = $filters['debut'] . ' 00:00:00';
        }
        if (!empty($filters['fin'])) {
            $where[] = 'created_at <= :fin';
            $params['fin'] = $filters['fin'] . ' 23:59:59';
        }
        if (!empty($filters['q'])) {
            $where[] = '(utilisateur LIKE :q1 OR chemin LIKE :q2 OR message LIKE :q3)';
            $params['q1'] = $params['q2'] = $params['q3'] = '%' . $filters['q'] . '%';
        }
        return $this->fetchAll(
            "SELECT * FROM {$this->table}"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY id DESC LIMIT ' . max(1, min($limit, 1000)),
            $params
        );
    }
}
