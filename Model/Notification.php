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
    public function createWelcome(int $userId, string $login): int
    {
        return $this->create([
            'user_id' => $userId,
            'type'    => 'info',
            'titre'   => 'Bienvenue ! Voici vos identifiants de connexion',
            'message' => "Votre compte a été créé.\n\nIdentifiant : {$login}\n\nLe mot de passe temporaire a été affiché une seule fois à l'administrateur lors de la création. Si vous ne l'avez pas reçu, contactez l'administration pour le réinitialiser.",
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

    /** Notifie les auditeurs actifs et liés à un compte pour une session. */
    public function createForActiveAuditeursInSession(int $sessionId, string $titre, string $message, string $type = 'alerte_evaluation'): int
    {
        $users = $this->fetchAll(
            "SELECT DISTINCT a.user_id
             FROM inscriptions i
             JOIN auditeurs a ON a.id = i.id_auditeur
             WHERE i.id_session = :session_id
               AND i.statut = 'active'
               AND a.user_id IS NOT NULL",
            ['session_id' => $sessionId]
        );
        $created = 0;
        foreach ($users as $user) {
            $this->create([
                'user_id' => (int) $user['user_id'],
                'type' => $type,
                'titre' => $titre,
                'message' => $message,
            ]);
            $created++;
        }
        return $created;
    }

    /** Crée une alerte d'absence pour l'auditeur lié à une inscription. */
    public function createAbsenceAlertForInscription(int $inscriptionId, string $date): bool
    {
        $userId = $this->fetchColumn(
            "SELECT a.user_id
             FROM inscriptions i
             JOIN auditeurs a ON a.id = i.id_auditeur
             WHERE i.id = :id AND a.user_id IS NOT NULL
             LIMIT 1",
            ['id' => $inscriptionId]
        );
        if ($userId === false || $userId === null) {
            return false;
        }

        $formattedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $dateLabel = $formattedDate ? $formattedDate->format('d/m/Y') : $date;
        $this->create([
            'user_id' => (int) $userId,
            'type' => 'alerte_presence',
            'titre' => 'Absence enregistrée',
            'message' => "Une absence a été enregistrée pour votre séance du {$dateLabel}. Consultez votre espace ou contactez l'administration en cas d'erreur.",
        ]);
        return true;
    }
}
