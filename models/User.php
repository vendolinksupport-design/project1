<?php
/**
 * ============================================================
 * VendoLink — User Model
 * ============================================================
 *
 * Handles all database interactions for the `users` table
 * using PDO prepared statements.
 *
 * @package VendoLink
 * @version 1.0
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

class User
{
    /** @var Database Database singleton */
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ============================================================
    // Lookup Methods
    // ============================================================

    /**
     * Find a user by their primary key.
     *
     * @param int $id
     * @return array|null User row or null
     */
    public function findById(int $id): ?array
    {
        $row = $this->db->query(
            'SELECT * FROM users WHERE id = ? LIMIT 1',
            [$id]
        )->fetch();

        return $row ?: null;
    }

    /**
     * Find a user by their email address.
     *
     * @param string $email
     * @return array|null User row or null
     */
    public function findByEmail(string $email): ?array
    {
        $row = $this->db->query(
            'SELECT * FROM users WHERE email = ? LIMIT 1',
            [$email]
        )->fetch();

        return $row ?: null;
    }

    /**
     * Find a user by their username.
     *
     * @param string $username
     * @return array|null User row or null
     */
    public function findByUsername(string $username): ?array
    {
        $row = $this->db->query(
            'SELECT * FROM users WHERE username = ? LIMIT 1',
            [$username]
        )->fetch();

        return $row ?: null;
    }

    /**
     * Find a user by email or username (for login flexibility).
     *
     * @param string $identifier Email or username
     * @return array|null
     */
    public function findByIdentifier(string $identifier): ?array
    {
        $row = $this->db->query(
            'SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1',
            [$identifier, $identifier]
        )->fetch();

        return $row ?: null;
    }

    /**
     * Retrieve all users, optionally filtered by role.
     *
     * @param string|null $role Filter by role
     * @return array
     */
    public function all(?string $role = null): array
    {
        if ($role) {
            return $this->db->query(
                'SELECT * FROM users WHERE role = ? ORDER BY created_at DESC',
                [$role]
            )->fetchAll();
        }

        return $this->db->query(
            'SELECT * FROM users ORDER BY created_at DESC'
        )->fetchAll();
    }

    /**
     * Retrieve all internal users (Admin and Support).
     *
     * @return array
     */
    public function getInternalUsers(): array
    {
        return $this->db->query(
            "SELECT * FROM users WHERE role IN (?, ?) ORDER BY created_at DESC",
            [ROLE_ADMIN, ROLE_SUPPORT]
        )->fetchAll();
    }

    // ============================================================
    // Authentication Methods
    // ============================================================

    /**
     * Verify a plain-text password against the stored bcrypt hash.
     *
     * @param string $password Plain-text password
     * @param string $hash     Stored bcrypt hash
     * @return bool
     */
    public function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Check if an account is locked out due to failed login attempts.
     *
     * @param array $user User row from DB
     * @return bool True if locked out
     */
    public function isLockedOut(array $user): bool
    {
        if ($user['login_attempts'] >= MAX_LOGIN_ATTEMPTS) {
            if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                return true;
            }
            // Lock expired — reset attempts
            $this->resetLoginAttempts((int)$user['id']);
        }
        return false;
    }

    /**
     * Increment failed login attempts and lock if threshold exceeded.
     *
     * @param int $userId
     */
    public function incrementLoginAttempts(int $userId): void
    {
        $this->db->query(
            'UPDATE users
             SET login_attempts = login_attempts + 1,
                 locked_until  = IF(login_attempts + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NULL)
             WHERE id = ?',
            [MAX_LOGIN_ATTEMPTS, LOCKOUT_DURATION, $userId]
        );
    }

    /**
     * Reset failed login attempts counter.
     *
     * @param int $userId
     */
    public function resetLoginAttempts(int $userId): void
    {
        $this->db->query(
            'UPDATE users SET login_attempts = 0, locked_until = NULL WHERE id = ?',
            [$userId]
        );
    }

    /**
     * Update the last_login timestamp.
     *
     * @param int $userId
     */
    public function updateLastLogin(int $userId): void
    {
        $this->db->query(
            'UPDATE users SET last_login = NOW() WHERE id = ?',
            [$userId]
        );
    }

    // ============================================================
    // Remember-Me
    // ============================================================

    /**
     * Store a hashed remember-me token for the user.
     * Sets a 30-day browser cookie and stores a SHA-256 hash in DB.
     *
     * @param int $userId
     */
    public function setRememberMeToken(int $userId): void
    {
        $rawToken    = generate_token(32);
        $hashedToken = hash('sha256', $rawToken);

        $this->db->query(
            'UPDATE users SET remember_token = ? WHERE id = ?',
            [$hashedToken, $userId]
        );

        setcookie(
            'remember_token',
            $rawToken,
            time() + (REMEMBER_ME_DAYS * 86400),
            '/',
            '',
            isset($_SERVER['HTTPS']),
            true  // httponly
        );
    }

    /**
     * Clear the remember-me token from the database.
     *
     * @param int $userId
     */
    public function clearRememberMeToken(int $userId): void
    {
        $this->db->query(
            'UPDATE users SET remember_token = NULL WHERE id = ?',
            [$userId]
        );
    }

    // ============================================================
    // Password Reset
    // ============================================================

    /**
     * Generate a password reset token and store a hashed version in DB.
     * Token expires in 1 hour.
     *
     * @param int $userId
     * @return string Raw token to be sent via email
     */
    public function createPasswordResetToken(int $userId): string
    {
        $rawToken    = generate_token(32);
        $hashedToken = hash('sha256', $rawToken);
        $expires     = date('Y-m-d H:i:s', time() + 3600);

        $this->db->query(
            'UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?',
            [$hashedToken, $expires, $userId]
        );

        return $rawToken;
    }

    /**
     * Find a user by a valid (non-expired) reset token.
     *
     * @param string $rawToken Raw token from email link
     * @return array|null User row or null if invalid/expired
     */
    public function findByResetToken(string $rawToken): ?array
    {
        $hashedToken = hash('sha256', $rawToken);

        $row = $this->db->query(
            'SELECT * FROM users
             WHERE reset_token = ?
               AND reset_token_expires > NOW()
             LIMIT 1',
            [$hashedToken]
        )->fetch();

        return $row ?: null;
    }

    /**
     * Update the user's password and clear the reset token.
     *
     * @param int    $userId
     * @param string $newPassword Plain-text password (will be hashed here)
     */
    public function updatePassword(int $userId, string $newPassword): void
    {
        $hashed = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);

        $this->db->query(
            'UPDATE users
             SET password            = ?,
                 reset_token         = NULL,
                 reset_token_expires = NULL,
                 remember_token      = NULL,
                 updated_at          = NOW()
             WHERE id = ?',
            [$hashed, $userId]
        );
    }

    // ============================================================
    // Email Verification
    // ============================================================

    /**
     * Generate and store an email verification token.
     *
     * @param int $userId
     * @return string Raw token
     */
    public function createEmailVerifyToken(int $userId): string
    {
        $rawToken = generate_token(32);

        $this->db->query(
            'UPDATE users SET email_verify_token = ? WHERE id = ?',
            [hash('sha256', $rawToken), $userId]
        );

        return $rawToken;
    }

    /**
     * Verify an email verification token and mark the user as verified.
     *
     * @param string $rawToken
     * @return bool True if successfully verified
     */
    public function verifyEmail(string $rawToken): bool
    {
        $hashedToken = hash('sha256', $rawToken);

        $user = $this->db->query(
            'SELECT id FROM users WHERE email_verify_token = ? AND email_verified = 0 LIMIT 1',
            [$hashedToken]
        )->fetch();

        if (!$user) {
            return false;
        }

        $this->db->query(
            'UPDATE users SET email_verified = 1, email_verify_token = NULL WHERE id = ?',
            [$user['id']]
        );

        return true;
    }

    /**
     * Update user email address and set email_verified status.
     *
     * @param int    $userId
     * @param string $newEmail
     * @param bool   $verified
     */
    public function updateEmail(int $userId, string $newEmail, bool $verified = true): void
    {
        $this->db->query(
            'UPDATE users
             SET email               = :email,
                 email_verified      = :verified,
                 email_verify_token  = NULL,
                 reset_token_expires = NULL,
                 updated_at          = NOW()
             WHERE id = :id',
            [
                ':email'    => strtolower(trim($newEmail)),
                ':verified' => $verified ? 1 : 0,
                ':id'       => $userId,
            ]
        );
    }


    /**
     * Generate a 6-digit OTP for email verification (5 min expiration).
     *
     * @param int $userId
     * @return string Raw 6-digit OTP code
     */
    public function createEmailVerifyOtp(int $userId): string
    {
        $otp       = sprintf('%06d', random_int(0, 999999));
        $hashedOtp = hash('sha256', $otp);
        $expires   = date('Y-m-d H:i:s', time() + 300); // 5 minutes

        $this->db->query(
            'UPDATE users SET email_verify_token = ?, reset_token_expires = ? WHERE id = ?',
            [$hashedOtp, $expires, $userId]
        );

        return $otp;
    }

    /**
     * Verify a 6-digit OTP for email verification.
     *
     * @param int    $userId
     * @param string $rawOtp 6-digit OTP code
     * @return bool True if successfully verified
     */
    public function verifyEmailOtp(int $userId, string $rawOtp): bool
    {
        $hashedOtp = hash('sha256', trim($rawOtp));

        $row = $this->db->query(
            'SELECT id FROM users
             WHERE id = ?
               AND email_verify_token = ?
               AND reset_token_expires > NOW()
             LIMIT 1',
            [$userId, $hashedOtp]
        )->fetch();

        if (!$row) {
            return false;
        }

        $this->db->query(
            'UPDATE users
             SET email_verified = 1,
                 status = "active",
                 email_verify_token = NULL,
                 reset_token_expires = NULL,
                 updated_at = NOW()
             WHERE id = ?',
            [$userId]
        );

        return true;
    }

    /**
     * Create a new Owner account (unverified, inactive until OTP verified).
     *
     * @param array $data [full_name, username, email, password]
     * @return int New user ID
     */
    public function createOwnerAccount(array $data): int
    {
        $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);

        $this->db->query(
            'INSERT INTO users (full_name, username, email, password, role, status, email_verified, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, "inactive", 0, NOW(), NOW())',
            [
                trim($data['full_name']),
                trim($data['username']),
                trim($data['email']),
                $hashedPassword,
                ROLE_OWNER, // Strictly Owner role
            ]
        );

        return (int)$this->db->lastInsertId();
    }

    /**
     * Create a new Support account.
     *
     * @param array $data [full_name, username, email, password]
     * @return int New user ID
     */
    public function createSupportAccount(array $data): int
    {
        $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);

        $this->db->query(
            'INSERT INTO users (full_name, username, email, password, role, status, email_verified, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, "active", 1, NOW(), NOW())',
            [
                trim($data['full_name']),
                trim($data['username']),
                trim($data['email']),
                $hashedPassword,
                ROLE_SUPPORT,
            ]
        );

        return (int)$this->db->lastInsertId();
    }

    // ============================================================
    // CRUD
    // ============================================================

    /**
     * Get summary metrics for User Management dashboard.
     *
     * @param bool $customerOnly Exclude internal roles from metrics
     * @return array [total_users, active_users, inactive_users, owner_count, staff_count]
     */
    public function getUserMetrics(bool $customerOnly = false): array
    {
        $whereClause = $customerOnly ? "WHERE role NOT IN ('" . ROLE_ADMIN . "', '" . ROLE_SUPPORT . "')" : "";
        
        $row = $this->db->query(
            "SELECT
                COUNT(*) AS total_users,
                COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) AS active_users,
                COALESCE(SUM(CASE WHEN status != 'active' THEN 1 ELSE 0 END), 0) AS inactive_users,
                COALESCE(SUM(CASE WHEN role = '" . ROLE_OWNER . "' THEN 1 ELSE 0 END), 0) AS owner_count,
                COALESCE(SUM(CASE WHEN role IN ('" . ROLE_STAFF . "', '" . ROLE_TECHNICIAN . "') THEN 1 ELSE 0 END), 0) AS staff_count
             FROM users {$whereClause}"
        )->fetch();

        return [
            'total_users'    => (int) ($row['total_users'] ?? 0),
            'active_users'   => (int) ($row['active_users'] ?? 0),
            'inactive_users' => (int) ($row['inactive_users'] ?? 0),
            'owner_count'    => (int) ($row['owner_count'] ?? 0),
            'staff_count'    => (int) ($row['staff_count'] ?? 0),
        ];
    }

    /**
     * Count active Owner / Administrator accounts in the system.
     * Used as a safety guard to prevent locking out the system.
     *
     * @return int
     */
    public function countActiveOwners(): int
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS total FROM users WHERE role IN ('owner', 'administrator') AND status = 'active'"
        )->fetch();

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Retrieve paginated users matching active filters.
     *
     * @param array $filters [search, role, status]
     * @param int   $page
     * @param int   $perPage
     * @return array [items, total_items, total_pages, page]
     */
    public function getPaginated(array $filters = [], int $page = 1, int $perPage = 10): array
    {
        $where = ['1=1'];
        $params = [];
        
        if (!empty($filters['customerOnly'])) {
            $where[] = "role NOT IN ('" . ROLE_ADMIN . "', '" . ROLE_SUPPORT . "')";
        }

        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $where[] = '(full_name LIKE :search_1 OR email LIKE :search_2 OR username LIKE :search_3)';
            $params[':search_1'] = '%' . $search . '%';
            $params[':search_2'] = '%' . $search . '%';
            $params[':search_3'] = '%' . $search . '%';
        }

        if (!empty($filters['role']) && $filters['role'] !== 'all') {
            $where[] = 'role = :role';
            $params[':role'] = $filters['role'];
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $where[] = 'status = :status';
            $params[':status'] = $filters['status'];
        }

        $whereClause = implode(' AND ', $where);

        // Count total
        $countRow = $this->db->query(
            "SELECT COUNT(*) AS total FROM users WHERE {$whereClause}",
            $params
        )->fetch();
        $totalItems = (int) ($countRow['total'] ?? 0);

        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page       = max(1, min($page, $totalPages));
        $offset     = ($page - 1) * $perPage;

        $items = $this->db->query(
            "SELECT id, username, email, full_name, role, status, email_verified, last_login, created_at, updated_at
             FROM users
             WHERE {$whereClause}
             ORDER BY created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return [
            'items'       => $items,
            'total_items' => $totalItems,
            'total_pages' => $totalPages,
            'page'        => $page,
        ];
    }

    // ============================================================
    // CRUD
    // ============================================================

    /**
     * Create a new user record.
     *
     * @param array $data Associative array of user fields
     * @return int New user ID
     */
    public function create(array $data): int
    {
        $username = !empty($data['username']) ? trim($data['username']) : explode('@', $data['email'])[0];
        
        // Ensure username uniqueness
        $baseUsername = $username;
        $counter = 1;
        while ($this->findByUsername($username)) {
            $username = $baseUsername . $counter;
            $counter++;
        }

        $data['password'] = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);

        $this->db->query(
            'INSERT INTO users
                (username, email, password, full_name, role, status, phone)
             VALUES
                (:username, :email, :password, :full_name, :role, :status, :phone)',
            [
                ':username'  => $username,
                ':email'     => strtolower(trim($data['email'])),
                ':password'  => $data['password'],
                ':full_name' => trim($data['full_name']),
                ':role'      => $data['role']      ?? ROLE_STAFF,
                ':status'    => $data['status']    ?? 'active',
                ':phone'     => $data['phone']     ?? null,
            ]
        );

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update a user record.
     *
     * @param int   $id   User ID
     * @param array $data Fields to update (do NOT include password here)
     */
    public function update(int $id, array $data): void
    {
        $this->db->query(
            'UPDATE users
             SET full_name  = :full_name,
                 email      = :email,
                 username   = :username,
                 role       = :role,
                 status     = :status,
                 phone      = :phone,
                 updated_at = NOW()
             WHERE id = :id',
            [
                ':full_name' => trim($data['full_name']),
                ':email'     => strtolower(trim($data['email'])),
                ':username'  => trim($data['username']),
                ':role'      => $data['role'],
                ':status'    => $data['status'],
                ':phone'     => $data['phone'] ?? null,
                ':id'        => $id,
            ]
        );
    }

    /**
     * Toggle or set user active/inactive status safely.
     * Ensures the last active Owner/Administrator cannot be disabled.
     *
     * @param int    $id
     * @param string $newStatus 'active' or 'inactive'
     * @return bool True if successful, throws exception if forbidden
     */
    public function setStatus(int $id, string $newStatus): bool
    {
        $user = $this->findById($id);
        if (!$user) {
            throw new InvalidArgumentException('User not found.');
        }

        if ($newStatus === 'inactive' && in_array($user['role'], [ROLE_OWNER, ROLE_ADMIN], true)) {
            if ($this->countActiveOwners() <= 1 && $user['status'] === 'active') {
                throw new DomainException('Cannot disable the last active Owner account.');
            }
        }

        $this->db->query(
            'UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?',
            [$newStatus, $id]
        );

        return true;
    }

    /**
     * Soft-delete by setting status to 'inactive'.
     *
     * @param int $id
     */
    public function deactivate(int $id): void
    {
        $this->setStatus($id, 'inactive');
    }

    /**
     * Permanently delete a user (Legacy - soft disable is preferred).
     *
     * @param int $id
     */
    public function delete(int $id): void
    {
        $this->db->query('DELETE FROM users WHERE id = ?', [$id]);
    }
}
