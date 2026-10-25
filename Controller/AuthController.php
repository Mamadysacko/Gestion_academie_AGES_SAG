<?php
/**
 * Jetons JWT : renouvellement, déconnexion et révocation.
 *
 * POST /auth/refresh     → nouveau jeton d'accès (+ rotation du jeton de renouvellement)
 * POST /auth/logout      → révoque le jeton de renouvellement de ce navigateur et ferme la session
 * POST /auth/logout-all  → révoque tous les jetons du compte (tous les appareils)
 *
 * Le jeton de renouvellement est lu dans le corps JSON (`refresh_token`) ou dans le cookie
 * HttpOnly `ages_refresh` posé à la connexion (inaccessible au JavaScript du navigateur).
 */

require_once __DIR__ . '/../Model/RefreshToken.php';
require_once __DIR__ . '/../Model/User.php';

class AuthController
{
    private RefreshToken $tokens;

    public function __construct()
    {
        $this->tokens = new RefreshToken();
    }

    /** Réponse de connexion : jetons + cookie de renouvellement. */
    public static function tokenPayload(array $user): array
    {
        $tokens = Authorization::issueTokens($user);
        self::setRefreshCookie($tokens['refresh_token'], JWT_REFRESH_TTL);
        if (random_int(1, 50) === 1) {
            (new RefreshToken())->purgeExpired();
        }
        return $tokens;
    }

    public function refresh(): void
    {
        try {
            $raw = $this->presentedToken();
            if ($raw === null) {
                $this->sendError(401, 'Jeton de renouvellement manquant : reconnectez-vous.');
                return;
            }
            $rotated = $this->tokens->rotate($raw);

            $user = (new User())->findWithRole($rotated['user_id']);
            if ($user === null || !(bool) $user['actif']) {
                $this->tokens->revokeAllForUser($rotated['user_id'], 'compte_inactif');
                self::setRefreshCookie('', -3600);
                $this->sendError(401, 'Compte inactif ou supprimé.');
                return;
            }

            $tokens = Authorization::issueTokens($user, $rotated['refresh_token']);
            self::setRefreshCookie($tokens['refresh_token'], JWT_REFRESH_TTL);
            unset($user['password']);
            $user['permissions'] = Authorization::permissionsForRole((int) $user['role_id']);
            unset($user['token_version']);

            $this->sendResponse(200, ['success' => true, 'data' => $user] + $tokens);
        } catch (DomainException $e) {
            self::setRefreshCookie('', -3600);
            $this->sendError($e->getCode() === 401 ? 401 : 400, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, 'Erreur lors du renouvellement du jeton : ' . $e->getMessage());
        }
    }

    public function logout(): void
    {
        try {
            $raw = $this->presentedToken();
            if ($raw !== null) {
                $this->tokens->revoke($raw);
            }
        } catch (Exception $e) {
            error_log('Révocation du jeton impossible : ' . $e->getMessage());
        }
        self::setRefreshCookie('', -3600);
        Authorization::logout();
        $this->sendResponse(200, ['success' => true, 'message' => 'Déconnexion réussie.']);
    }

    public function logoutAll(): void
    {
        try {
            $userId = Authorization::userId();
            if ($userId === null || Authorization::currentUser() === null) {
                $this->sendError(401, 'Authentification requise.');
                return;
            }
            $count = Database::transaction(function (PDO $db) use ($userId): int {
                $count = $this->tokens->revokeAllForUser($userId);
                // Les jetons d'accès déjà émis portent l'ancienne version : ils sont refusés immédiatement.
                $db->prepare('UPDATE users SET token_version = token_version + 1 WHERE id = :id')->execute(['id' => $userId]);
                return $count;
            });

            self::setRefreshCookie('', -3600);
            Authorization::logout();
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Toutes les sessions de ce compte ont été fermées.',
                'data' => ['jetons_revoques' => $count],
            ]);
        } catch (Exception $e) {
            $this->sendError(500, 'Erreur lors de la déconnexion globale : ' . $e->getMessage());
        }
    }

    private function presentedToken(): ?string
    {
        $data = json_decode((string) file_get_contents('php://input'), true);
        $raw = is_array($data) ? ($data['refresh_token'] ?? null) : null;
        if (!is_string($raw) || $raw === '') {
            $raw = $_COOKIE[JWT_REFRESH_COOKIE] ?? null;
        }
        return is_string($raw) && preg_match('/^[a-f0-9]{64}$/', $raw) ? $raw : null;
    }

    /** Cookie limité aux routes /auth de l'API. */
    private static function setRefreshCookie(string $value, int $ttl): void
    {
        if (headers_sent() || PHP_SAPI === 'cli') {
            return;
        }
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $base = preg_match('#^(.*?/Route)(/|$)#i', $path, $match) ? $match[1] : '';
        setcookie(JWT_REFRESH_COOKIE, $value, [
            'expires' => time() + $ttl,
            'path' => $base . '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
    }

    private function sendResponse(int $code, array $data): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private function sendError(int $code, string $message): void
    {
        $this->sendResponse($code, ['success' => false, 'error' => $message]);
    }
}
