<?php
/**
 * Modèle Notification (table : notifications)
 */

require_once __DIR__ . '/Model.php';

class Notification extends Model
{
    protected string $table = 'notifications';

    protected array $fillable = [
        'user_id', 'type', 'titre', 'message', 'lu',
    ];

    /** Notifications d'un utilisateur (les plus récentes en premier). */
    public function getForUser(int $userId, bool $unreadOnly = false): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE user_id = :user_id";
        if ($unreadOnly) {
            $sql .= " AND lu = 0";
        }
        $sql .= " ORDER BY created_at DESC LIMIT 50";
        return $this->fetchAll($sql, ['user_id' => $userId]);
    }

    /** Marque une notification comme lue (seulement si elle appartient à l'utilisateur). */
    public function markRead(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE {$this->table} SET lu = 1 WHERE id = :id AND user_id = :user_id"
        );
        return $stmt->execute(['id' => $id, 'user_id' => $userId]);
    }

    /** Marque toutes les notifications d'un utilisateur comme lues. */
    public function markAllRead(int $userId): int
    {
        $stmt = $this->db->prepare(
            "UPDATE {$this->table} SET lu = 1 WHERE user_id = :user_id AND lu = 0"
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->rowCount();
    }

    /** Nombre de notifications non lues. */
    public function countUnread(int $userId): int
    {
        return (int) $this->fetchColumn(
            "SELECT COUNT(*) FROM {$this->table} WHERE user_id = :uid AND lu = 0",
            ['uid' => $userId]
        );
    }

    /** Crée une notification de bienvenue pour un nouvel auditeur. */
    public function createWelcome(int $userId, string $login, string $password): int
    {
        return $this->create([
            'user_id' => $userId,
            'type'    => 'info',
            'titre'   => 'Bienvenue ! Voici vos identifiants de connexion',
            'message' => "Votre compte a été créé.\n\nIdentifiant : {$login}\nMot de passe : {$password}\n\n⚠️ Notez bien ces informations et modifiez votre mot de passe depuis votre profil.",
        ]);
    }

    /** Crée une notification lors de l'enregistrement d'une note. */
    public function createNoteAdded(int $userId, string $evalLibelle, ?float $note, float $bareme): int
    {
        $noteStr = $note !== null
            ? number_format($note, 2, ',', '') . '/' . number_format($bareme, 2, ',', '')
            : 'saisie';
        return $this->create([
            'user_id' => $userId,
            'type'    => 'alerte_note',
            'titre'   => "Note enregistrée : {$evalLibelle}",
            'message' => "Votre note pour l'évaluation « {$evalLibelle} » a été enregistrée : {$noteStr}.",
        ]);
    }

    /** Crée une notification indiquant qu'un bulletin est disponible. */
    public function createBulletinDisponible(int $userId, string $sessionLibelle): int
    {
        return $this->create([
            'user_id' => $userId,
            'type'    => 'bulletin',
            'titre'   => "Bulletin disponible : {$sessionLibelle}",
            'message' => "Votre bulletin de notes pour la session « {$sessionLibelle} » est disponible. Connectez-vous à votre espace pour le consulter.",
        ]);
    }
}
