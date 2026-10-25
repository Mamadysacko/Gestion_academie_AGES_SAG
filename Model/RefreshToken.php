<?php
/**
 * Modèle RefreshToken (table : refresh_tokens)
 *
 * Jetons de renouvellement opaques, stockés hachés (SHA-256), à usage unique :
 * chaque renouvellement révoque le jeton présenté et en émet un nouveau dans la même « famille ».
 * Présenter un jeton déjà révoqué trahit un vol : toute la famille est alors révoquée.
 */

require_once __DIR__ . '/Model.php';

class RefreshToken extends Model
{
    protected string $table = 'refresh_tokens';

    /** Crée un jeton et retourne sa valeur brute (seule occasion de la connaître). */
    public function issue(int $userId, ?string $family = null): string
    {
        $raw = bin2hex(random_bytes(32));
        $this->db->prepare(
            "INSERT INTO {$this->table} (user_id, famille, token_hash, expires_at, adresse_ip, user_agent)
             VALUES (:user_id, :famille, :hash, :expires, :ip, :ua)"
        )->execute([
            'user_id' => $userId,
            'famille' => $family ?? bin2hex(random_bytes(16)),
            'hash' => hash('sha256', $raw),
            'expires' => date('Y-m-d H:i:s', time() + JWT_REFRESH_TTL),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'ua' => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
        ]);
        return $raw;
    }

    /**
     * Échange un jeton valide contre un nouveau (rotation), dans une transaction.
     * @return array{user_id:int, refresh_token:string}
     * @throws DomainException (401) si le jeton est inconnu, expiré, révoqué ou réutilisé.
     */
    public function rotate(string $raw): array
    {
        $outcome = Database::transaction(function () use ($raw): array {
            $row = $this->fetchOne(
                "SELECT * FROM {$this->table} WHERE token_hash = :hash FOR UPDATE",
                ['hash' => hash('sha256', $raw)]
            );
            if ($row === null) {
                return ['erreur' => 'Jeton de renouvellement inconnu.'];
            }
            if ($row['revoked_at'] !== null) {
                // Réutilisation d'un jeton déjà échangé : on coupe toute la chaîne de ce navigateur
                // (la révocation est validée avec la transaction, l'erreur est levée ensuite).
                $this->revokeFamily($row['famille'], 'reutilisation');
                return ['erreur' => 'Jeton de renouvellement déjà utilisé : par sécurité, reconnectez-vous.'];
            }
            if (strtotime($row['expires_at']) < time()) {
                return ['erreur' => 'Session expirée : reconnectez-vous.'];
            }

            $newRaw = $this->issue((int) $row['user_id'], $row['famille']);
            $this->db->prepare(
                "UPDATE {$this->table}
                 SET revoked_at = NOW(), motif_revocation = 'rotation', last_used_at = NOW(), remplace_par = :next
                 WHERE id = :id"
            )->execute(['next' => (int) $this->db->lastInsertId(), 'id' => (int) $row['id']]);
            return ['user_id' => (int) $row['user_id'], 'refresh_token' => $newRaw];
        });

        if (isset($outcome['erreur'])) {
            throw new DomainException($outcome['erreur'], 401);
        }
        return $outcome;
    }

    /** Révoque un jeton précis (déconnexion de ce navigateur) et toute sa famille. */
    public function revoke(string $raw, string $reason = 'deconnexion'): bool
    {
        $family = $this->fetchColumn(
            "SELECT famille FROM {$this->table} WHERE token_hash = :hash",
            ['hash' => hash('sha256', $raw)]
        );
        if ($family === false) {
            return false;
        }
        $this->revokeFamily((string) $family, $reason);
        return true;
    }

    /** Révoque tous les jetons actifs d'un utilisateur (déconnexion de tous les appareils). */
    public function revokeAllForUser(int $userId, string $reason = 'deconnexion_globale'): int
    {
        $stmt = $this->db->prepare(
            "UPDATE {$this->table} SET revoked_at = NOW(), motif_revocation = :reason
             WHERE user_id = :user_id AND revoked_at IS NULL"
        );
        $stmt->execute(['reason' => $reason, 'user_id' => $userId]);
        return $stmt->rowCount();
    }

    /** Supprime les jetons expirés depuis plus de 30 jours (appelé de temps en temps à la connexion). */
    public function purgeExpired(): int
    {
        return (int) $this->db->exec(
            "DELETE FROM {$this->table} WHERE expires_at < (NOW() - INTERVAL 30 DAY)"
        );
    }

    private function revokeFamily(string $family, string $reason): void
    {
        $this->db->prepare(
            "UPDATE {$this->table} SET revoked_at = NOW(), motif_revocation = :reason
             WHERE famille = :famille AND revoked_at IS NULL"
        )->execute(['reason' => $reason, 'famille' => $family]);
    }
}
