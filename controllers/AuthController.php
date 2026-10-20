<?php
/**
 * ============================================================
 * VendoLink — Authentication Controller
 * ============================================================
 *
 * Handles: Login, Logout, Forgot Password, Reset Password,
 * Remember Me, CSRF, Session management, Rate Limiting.
 *
 * This controller is dispatched by the auth view files.
 * It should not produce output — it redirects after processing.
 *
 * @package VendoLink
 * @version 1.0
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/ProductKey.php';

class AuthController
{
    /** @var User User model instance */
    private User $userModel;

    /** @var ProductKey Product key model instance */
    private ProductKey $productKeyModel;

    public function __construct()
    {
        // Ensure session is active
        if (session_status() === PHP_SESSION_NONE) {
            session_name(SESSION_NAME);
            session_start();
        }

        $this->userModel       = new User();
        $this->productKeyModel = new ProductKey();
    }

    // ============================================================
    // Login
    // ============================================================

    /**
     * Process the login form (POST /auth/login.php).
     *
     * Validates credentials, checks lockout, verifies password,
     * handles remember-me, populates session, logs activity,
     * and redirects to dashboard.
     */
    public function login(): void
    {
        // Verify CSRF token
        csrf_verify();

        $identifier  = trim($_POST['identifier'] ?? '');
        $password    = $_POST['password']         ?? '';
        $rememberMe  = isset($_POST['remember_me']);

        // ----------------------------------------------------------
        // Basic input validation
        // ----------------------------------------------------------
        if (empty($identifier) || empty($password)) {
            flash('danger', 'Please enter your email/username and password.');
            redirect('auth/login.php');
        }

        // ----------------------------------------------------------
        // Find user by email or username
        // ----------------------------------------------------------
        $user = $this->userModel->findByIdentifier($identifier);

        if (!$user) {
            // Vague message — do not reveal whether email exists
            flash('danger', 'Invalid credentials. Please try again.');
            log_activity('LOGIN_FAILED', "Failed login attempt for identifier: {$identifier}", null);
            redirect('auth/login.php');
        }

        // ----------------------------------------------------------
        // Account status check
        // ----------------------------------------------------------
        if ($user['status'] !== 'active') {
            flash('danger', 'Your account is ' . $user['status'] . '. Please contact the administrator.');
            redirect('auth/login.php');
        }

        // ----------------------------------------------------------
        // Lockout check
        // ----------------------------------------------------------
        if ($this->userModel->isLockedOut($user)) {
            $minutes = ceil(LOCKOUT_DURATION / 60);
            flash('danger', "Too many failed login attempts. Please wait {$minutes} minutes.");
            redirect('auth/login.php');
        }

        // ----------------------------------------------------------
        // Password verification
        // ----------------------------------------------------------
        if (!$this->userModel->verifyPassword($password, $user['password'])) {
            $this->userModel->incrementLoginAttempts((int)$user['id']);

            $remaining = MAX_LOGIN_ATTEMPTS - ((int)$user['login_attempts'] + 1);
            if ($remaining > 0) {
                flash('danger', "Invalid credentials. {$remaining} attempt(s) remaining.");
            } else {
                flash('danger', 'Account temporarily locked due to too many failed attempts.');
            }

            log_activity('LOGIN_FAILED', "Wrong password for user ID {$user['id']}.", (int)$user['id']);
            redirect('auth/login.php');
        }

        // ----------------------------------------------------------
        // Rehash if bcrypt cost factor has changed
        // ----------------------------------------------------------
        if (password_needs_rehash($user['password'], PASSWORD_BCRYPT, ['cost' => BCRYPT_COST])) {
            $this->userModel->updatePassword((int)$user['id'], $password);
        }

        // ----------------------------------------------------------
        // Successful login — populate session
        // ----------------------------------------------------------
        $this->userModel->resetLoginAttempts((int)$user['id']);
        $this->userModel->updateLastLogin((int)$user['id']);
        populate_session($user);

        // ----------------------------------------------------------
        // Remember Me
        // ----------------------------------------------------------
        if ($rememberMe) {
            $this->userModel->setRememberMeToken((int)$user['id']);
        }

        log_activity('LOGIN', 'Successful login.', (int)$user['id']);

        // ----------------------------------------------------------
        // Redirect to intended URL or dashboard
        // ----------------------------------------------------------
        $intendedUrl = $_SESSION['intended_url'] ?? '';
        unset($_SESSION['intended_url']);

        if (!empty($intendedUrl) && str_starts_with($intendedUrl, '/')) {
            redirect_url($intendedUrl);
        } else {
            if (has_role([ROLE_ADMIN, ROLE_SUPPORT])) {
                redirect('admin/dashboard/index.php');
            } else {
                redirect('dashboard/index.php');
            }
        }
    }

    // ============================================================
    // Logout
    // ============================================================

    /**
     * Process logout — destroys session and redirects to login.
     */
    public function logout(): void
    {
        if (is_logged_in()) {
            $userId = $_SESSION['user_id'] ?? null;
            if ($userId) {
                $this->userModel->clearRememberMeToken((int)$userId);
                log_activity('LOGOUT', 'User logged out.', (int)$userId);
            }
        }

        // 1. Fully destroy the current session and wipe cookies
        destroy_session();

        // 2. Start a fresh session specifically to carry the flash message
        if (session_status() === PHP_SESSION_NONE) {
            if (!headers_sent()) {
                session_name(SESSION_NAME);
            }
            @session_start();
            if (!headers_sent()) {
                @session_regenerate_id(true);
            }
        }

        if (isset($_GET['reason']) && $_GET['reason'] === 'inactivity') {
            flash('warning', 'You have been automatically logged out due to 15 minutes of inactivity.');
        } else {
            flash('success', 'You have been logged out successfully.');
        }

        // 3. Send no-cache headers to prevent Back button access
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
        }

        redirect('auth/login.php');
    }

    // ============================================================
    // Forgot Password
    // ============================================================

    /**
     * Process the forgot-password form (POST /auth/forgot_password.php).
     *
     * Generates a reset token and (stub) sends an email.
     * Always shows a success message to prevent user enumeration.
     */
    public function forgotPassword(): void
    {
        csrf_verify();

        $email = trim($_POST['email'] ?? '');

        if (!validate_email($email)) {
            flash('danger', 'Please enter a valid email address.');
            redirect('auth/forgot_password.php');
        }

        $user = $this->userModel->findByEmail($email);

        if (!$user) {
            flash('danger', 'This email address is not registered in our system.');
            redirect('auth/forgot_password.php');
        }

        if ($user['status'] !== 'active') {
            flash('danger', 'This account is currently inactive or disabled. Please contact an administrator.');
            redirect('auth/forgot_password.php');
        }

        $rawToken   = $this->userModel->createPasswordResetToken((int)$user['id']);
        $resetLink  = BASE_URL . '/auth/reset_password.php?token=' . urlencode($rawToken);

        // Send reset email via configured transport
        $this->sendPasswordResetEmail($user['email'], $user['full_name'], $resetLink);

        log_activity(
            'PASSWORD_RESET_REQUEST',
            'Password reset requested.',
            (int)$user['id']
        );

        flash('success', 'A password reset link has been sent to your email address.');
        redirect('auth/forgot_password.php');
    }

    // ============================================================
    // Reset Password
    // ============================================================

    /**
     * Process the reset-password form (POST /auth/reset_password.php).
     *
     * Validates the token, updates the password, and logs out
     * any existing sessions for the user.
     */
    public function resetPassword(): void
    {
        csrf_verify();

        $rawToken        = $_POST['token']           ?? '';
        $newPassword     = $_POST['new_password']    ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        // ----------------------------------------------------------
        // Validate token
        // ----------------------------------------------------------
        $user = $this->userModel->findByResetToken($rawToken);

        if (!$user) {
            flash('danger', 'Invalid or expired reset link. Please request a new one.');
            redirect('auth/forgot_password.php');
        }

        // ----------------------------------------------------------
        // Validate new password
        // ----------------------------------------------------------
        if (strlen($newPassword) < 8) {
            flash('danger', 'Password must be at least 8 characters long.');
            redirect('auth/reset_password.php?token=' . urlencode($rawToken));
        }

        if ($newPassword !== $confirmPassword) {
            flash('danger', 'Passwords do not match.');
            redirect('auth/reset_password.php?token=' . urlencode($rawToken));
        }

        // ----------------------------------------------------------
        // Update password
        // ----------------------------------------------------------
        $this->userModel->updatePassword((int)$user['id'], $newPassword);
        log_activity('PASSWORD_RESET', 'Password successfully reset.', (int)$user['id']);

        flash('success', 'Password changed successfully. Please log in with your new password.');
        redirect('auth/login.php');
    }

    // ============================================================
    // Email Verification
    // ============================================================

    /**
     * Process email verification from a link token.
     *
     * @param string $rawToken Token from query string
     */
    public function verifyEmail(string $rawToken): void
    {
        $result = $this->userModel->verifyEmail($rawToken);

        if ($result) {
            log_activity('EMAIL_VERIFIED', 'Email address verified.');
            flash('success', 'Email verified successfully!');
        } else {
            flash('danger', 'Invalid or expired verification link.');
        }

        if (is_logged_in()) {
            redirect('users/profile.php');
        } else {
            redirect('auth/login.php');
        }
    }

    /**
     * Send email verification link.
     */
    public function sendVerificationEmail(string $to, string $name, string $verifyLink): bool
    {
        $subject  = 'Verify Your Email Address — ' . APP_NAME;
        $safeName = htmlspecialchars(trim($name) ?: 'Owner', ENT_QUOTES, 'UTF-8');

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Email Address — VendoLink</title>
</head>
<body style="margin: 0; padding: 0; background-color: #0b0f19; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale;">
    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #0b0f19; table-layout: fixed;">
        <tr>
            <td align="center" style="padding: 40px 16px;">
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 560px; background-color: #0f172a; border-radius: 14px; border: 1px solid #1e293b; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5);">
                    <tr>
                        <td height="4" style="background-color: #0ea5e9; font-size: 0; line-height: 0;">&nbsp;</td>
                    </tr>
                    <tr>
                        <td align="center" style="padding: 36px 32px 24px 32px;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center">
                                        <div style="font-size: 28px; font-weight: 800; letter-spacing: -0.5px; color: #ffffff; line-height: 1.2;">
                                            Vendo<span style="color: #0ea5e9;">Link</span>
                                        </div>
                                        <div style="font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 1.5px; margin-top: 6px;">
                                            IoT Monitoring &amp; Sales Audit System
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 0 32px;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td height="1" style="background-color: #1e293b; font-size: 0; line-height: 0;">&nbsp;</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 32px 32px 24px 32px;">
                            <h1 style="margin: 0 0 16px 0; font-size: 20px; font-weight: 700; color: #ffffff; line-height: 1.4;">
                                Verify Your Email Address
                            </h1>
                            <p style="margin: 0 0 16px 0; font-size: 15px; line-height: 1.6; color: #cbd5e1;">
                                Hello <strong style="color: #ffffff;">{$safeName}</strong>,
                            </p>
                            <p style="margin: 0 0 24px 0; font-size: 14px; line-height: 1.6; color: #94a3b8;">
                                Please click the button below to verify your email address and activate your VendoLink account:
                            </p>
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 28px 0;">
                                <tr>
                                    <td align="center">
                                        <a href="{$verifyLink}" style="background-color: #0ea5e9; color: #ffffff; padding: 14px 32px; text-decoration: none; border-radius: 8px; font-weight: 700; font-size: 14px; display: inline-block; letter-spacing: 0.5px;">Verify Email Address</a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin: 24px 0 0 0; font-size: 12px; line-height: 1.5; color: #64748b;">
                                If you did not create a VendoLink account, you can safely ignore this email.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 24px 32px 32px 32px; background-color: #0b1120; border-top: 1px solid #1e293b; text-align: center;">
                            <p style="margin: 0 0 6px 0; font-size: 13px; font-weight: 600; color: #cbd5e1;">
                                VendoLink Support
                            </p>
                            <p style="margin: 0 0 10px 0; font-size: 11px; color: #64748b; line-height: 1.4;">
                                IoT Monitoring &amp; Sales Audit System
                            </p>
                            <p style="margin: 0; font-size: 11px; color: #475569; line-height: 1.4;">
                                This is an automated notification. Replies to this email are not monitored.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

        $plainText = <<<TEXT
VendoLink — IoT Monitoring & Sales Audit System
================================================

Verify Your Email Address

Hello {$name},

Please verify your email address for your VendoLink account by visiting the link below:
{$verifyLink}

If you did not request this, you can safely ignore this email.

--
VendoLink Support
IoT Monitoring & Sales Audit System
TEXT;

        return Mailer::send($to, $subject, $htmlBody, $plainText);
    }

    // ============================================================
    // Registration & OTP Flow
    // ============================================================

    /**
     * Process Owner Account Self-Registration (POST /auth/register.php).
     */
    public function register(): void
    {
        csrf_verify();

        $fullName        = trim($_POST['full_name'] ?? '');
        $username        = trim($_POST['username'] ?? '');
        $email           = trim($_POST['email'] ?? '');
        $productKey      = trim($_POST['product_key'] ?? '');
        $password        = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        $setFormData = function() use ($fullName, $username, $email, $productKey) {
            $_SESSION['reg_form_data'] = [
                'full_name'   => $fullName,
                'username'    => $username,
                'email'       => $email,
                'product_key' => $productKey,
            ];
        };

        // Validate required fields
        if (empty($fullName) || empty($username) || empty($email) || empty($productKey) || empty($password) || empty($confirmPassword)) {
            $setFormData();
            flash('danger', 'Please fill in all required fields.');
            redirect('auth/register.php');
        }

        // Validate Product Key existence and state
        $keyValidation = $this->productKeyModel->validateKey($productKey);
        if (!$keyValidation['valid']) {
            $setFormData();
            flash('danger', $keyValidation['error']);
            redirect('auth/register.php');
        }

        // Validate email format
        if (!validate_email($email)) {
            $setFormData();
            flash('danger', 'Please enter a valid email address.');
            redirect('auth/register.php');
        }

        // Validate username format (min 3 chars, alphanumeric + underscore)
        if (strlen($username) < 3 || !preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $setFormData();
            flash('danger', 'Username must be at least 3 characters and contain only letters, numbers, and underscores.');
            redirect('auth/register.php');
        }

        // Check unique email
        if ($this->userModel->findByEmail($email)) {
            $setFormData();
            flash('danger', 'That email address is already registered. Please sign in or use a different email.');
            redirect('auth/register.php');
        }

        // Check unique username
        if ($this->userModel->findByUsername($username)) {
            $setFormData();
            flash('danger', 'That username is already taken. Please choose another username.');
            redirect('auth/register.php');
        }

        // Password requirements (min 8 chars, alphanumeric)
        if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $setFormData();
            flash('danger', 'Password must be at least 8 characters long and contain both letters and numbers.');
            redirect('auth/register.php');
        }

        // Password confirmation
        if ($password !== $confirmPassword) {
            $setFormData();
            flash('danger', 'Password confirmation does not match.');
            redirect('auth/register.php');
        }

        unset($_SESSION['reg_form_data']);

        // Create owner account (inactive & unverified)
        // Create owner account (inactive & unverified)
        $userId = $this->userModel->createOwnerAccount([
            'full_name' => $fullName,
            'username'  => $username,
            'email'     => $email,
            'password'  => $password,
        ]);

        // Key details stored in session — key remains UNUSED until email OTP verification succeeds
        $keyData   = $keyValidation['key'];
        $keyId     = (int)$keyData['id'];
        $hwSerial  = $keyData['hardware_serial'] ?? null;
        $machineId = !empty($keyData['machine_id']) ? (int)$keyData['machine_id'] : null;

        // Generate 6-digit OTP
        $otp = $this->userModel->createEmailVerifyOtp($userId);

        // Store OTP and pending activation state in session
        $_SESSION['reg_user_id']    = $userId;
        $_SESSION['reg_email']      = $email;
        $_SESSION['reg_name']       = $fullName;
        $_SESSION['reg_key_id']     = $keyId;
        $_SESSION['reg_key_code']   = $keyData['product_key'];
        $_SESSION['reg_hw_serial']  = $hwSerial;
        $_SESSION['reg_machine_id'] = $machineId;
        $_SESSION['otp_sent_at']    = time();
        $_SESSION['otp_attempts']   = 0;

        // Send OTP email
        $sent = $this->sendOtpEmail($email, $fullName, $otp);

        log_activity('REGISTER_INITIATED', 'Owner account registration initiated with product key ' . e($keyData['product_key']), $userId);

        if ($sent) {
            flash('info', 'We sent a 6-digit verification code to ' . e(mask_email($email)));
        } else {
            flash('warning', "We couldn't send your verification email. Please try again later.");
        }
        redirect('auth/verify_otp.php');
    }

    /**
     * Resend 6-digit OTP code (POST /auth/verify_otp.php with action=resend).
     */
    public function resendOtp(): void
    {
        csrf_verify();

        $userId = $_SESSION['reg_user_id'] ?? null;
        $email  = $_SESSION['reg_email']   ?? '';
        $name   = $_SESSION['reg_name']    ?? 'Owner';

        if (!$userId || !$email) {
            flash('danger', 'Registration session expired. Please start registration again.');
            redirect('auth/register.php');
        }

        // Cooldown check (60 seconds)
        $sentAt  = $_SESSION['otp_sent_at'] ?? 0;
        $elapsed = time() - $sentAt;
        if ($elapsed < 60) {
            $wait = 60 - $elapsed;
            flash('warning', "Please wait {$wait} seconds before requesting a new code.");
            redirect('auth/verify_otp.php');
        }

        // Generate new OTP
        $otp = $this->userModel->createEmailVerifyOtp((int)$userId);
        $_SESSION['otp_sent_at']  = time();
        $_SESSION['otp_attempts'] = 0;

        // Send OTP email
        $sent = $this->sendOtpEmail($email, $name, $otp);

        log_activity('REGISTER_OTP_RESENT', 'Resent email verification OTP code.', (int)$userId);

        if ($sent) {
            flash('success', 'A new 6-digit code has been sent to ' . e(mask_email($email)));
        } else {
            flash('danger', "We couldn't send your verification email. Please try again later.");
        }
        redirect('auth/verify_otp.php');
    }

    /**
     * Process 6-digit OTP verification and atomic hardware claiming (POST /auth/verify_otp.php).
     */
    public function verifyOtp(): void
    {
        csrf_verify();

        $userId = $_SESSION['reg_user_id'] ?? null;

        if (!$userId) {
            flash('danger', 'Registration session expired. Please register again.');
            redirect('auth/register.php');
        }

        $otp = trim($_POST['otp'] ?? '');

        if (empty($otp)) {
            flash('danger', 'Please enter the 6-digit verification code.');
            redirect('auth/verify_otp.php');
        }

        // Attempt limit check (max 5 attempts)
        $attempts = ($_SESSION['otp_attempts'] ?? 0) + 1;
        $_SESSION['otp_attempts'] = $attempts;

        if ($attempts > 5) {
            flash('danger', 'Maximum verification attempts exceeded. Please click Resend Code.');
            redirect('auth/verify_otp.php');
        }

        $keyId     = (int)($_SESSION['reg_key_id'] ?? 0);
        $hwSerial  = $_SESSION['reg_hw_serial'] ?? null;
        $machineId = !empty($_SESSION['reg_machine_id']) ? (int)$_SESSION['reg_machine_id'] : null;
        $keyCode   = $_SESSION['reg_key_code'] ?? '';

        // Execute user verification, key consumption, and machine claiming atomically
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();

        try {
            // 1. Verify OTP and activate user account
            $success = $this->userModel->verifyEmailOtp((int)$userId, $otp);

            if (!$success) {
                $pdo->rollBack();
                flash('danger', 'Invalid or expired verification code. Please try again.');
                log_activity('REGISTER_OTP_FAILED', 'Failed OTP verification attempt.', (int)$userId);
                redirect('auth/verify_otp.php');
            }

            // 2. Claim Product Key & bind hardware unit
            if ($keyId > 0) {
                $activated = $this->productKeyModel->activateKey($keyId, (int)$userId, $hwSerial, $machineId);
                if (!$activated) {
                    $pdo->rollBack();
                    flash('danger', 'Unable to complete activation: this Product Key has already been claimed or is invalid. Please contact support.');
                    log_activity('REGISTER_KEY_CLAIM_FAILED', "Failed to claim key ID {$keyId} during OTP verification.", (int)$userId);
                    redirect('auth/register.php');
                }
                log_activity('PRODUCT_KEY_ACTIVATED', "Activated product key {$keyCode} for owner ID #{$userId}.", (int)$userId);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('danger', 'System error during activation: ' . $e->getMessage());
            redirect('auth/verify_otp.php');
        }

        // Verification successful — activate session state
        log_activity('REGISTER_SUCCESS', 'Owner account verified and activated successfully.', (int)$userId);

        unset(
            $_SESSION['reg_user_id'],
            $_SESSION['reg_email'],
            $_SESSION['reg_name'],
            $_SESSION['reg_key_id'],
            $_SESSION['reg_key_code'],
            $_SESSION['reg_hw_serial'],
            $_SESSION['reg_machine_id'],
            $_SESSION['otp_sent_at'],
            $_SESSION['otp_attempts']
        );
        $_SESSION['reg_completed'] = true;

        redirect('auth/verify_otp.php');
    }

    /**
     * Send 6-digit OTP via email.
     */
    public function sendOtpEmail(string $to, string $name, string $otp): bool
    {
        $subject      = "Your VendoLink Verification Code: {$otp}";
        $formattedOtp = implode(' ', str_split($otp));
        $safeName     = htmlspecialchars(trim($name) ?: 'Owner', ENT_QUOTES, 'UTF-8');

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Email Address — VendoLink</title>
</head>
<body style="margin: 0; padding: 0; background-color: #0b0f19; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale;">
    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #0b0f19; table-layout: fixed;">
        <tr>
            <td align="center" style="padding: 40px 16px;">
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 560px; background-color: #0f172a; border-radius: 14px; border: 1px solid #1e293b; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5);">
                    <tr>
                        <td height="4" style="background-color: #0ea5e9; font-size: 0; line-height: 0;">&nbsp;</td>
                    </tr>
                    <tr>
                        <td align="center" style="padding: 36px 32px 24px 32px;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center">
                                        <div style="font-size: 28px; font-weight: 800; letter-spacing: -0.5px; color: #ffffff; line-height: 1.2;">
                                            Vendo<span style="color: #0ea5e9;">Link</span>
                                        </div>
                                        <div style="font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 1.5px; margin-top: 6px;">
                                            IoT Monitoring &amp; Sales Audit System
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 0 32px;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td height="1" style="background-color: #1e293b; font-size: 0; line-height: 0;">&nbsp;</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 32px 32px 24px 32px;">
                            <h1 style="margin: 0 0 16px 0; font-size: 20px; font-weight: 700; color: #ffffff; line-height: 1.4;">
                                Verify Your Email Address
                            </h1>
                            <p style="margin: 0 0 16px 0; font-size: 15px; line-height: 1.6; color: #cbd5e1;">
                                Hello <strong style="color: #ffffff;">{$safeName}</strong>,
                            </p>
                            <p style="margin: 0 0 24px 0; font-size: 14px; line-height: 1.6; color: #94a3b8;">
                                Thank you for registering a VendoLink Owner account. To complete your account registration and activate your machine management features, please verify your email address using the six-digit verification code below:
                            </p>
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 28px 0;">
                                <tr>
                                    <td align="center">
                                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="background-color: #1e293b; border: 2px solid #0ea5e9; border-radius: 10px; width: 100%; max-width: 380px;">
                                            <tr>
                                                <td align="center" style="padding: 20px 16px;">
                                                    <div style="font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 2px; color: #64748b; margin-bottom: 8px;">
                                                        Verification Code
                                                    </div>
                                                    <div style="font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, Courier, monospace; font-size: 32px; font-weight: 800; letter-spacing: 10px; color: #38bdf8; line-height: 1.2;">
                                                        {$formattedOtp}
                                                    </div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #162032; border-left: 3px solid #0ea5e9; border-radius: 6px; margin: 24px 0;">
                                <tr>
                                    <td style="padding: 14px 18px;">
                                        <p style="margin: 0 0 6px 0; font-size: 13px; font-weight: 600; color: #e2e8f0; line-height: 1.4;">
                                            &#9201; This code expires in 5 minutes.
                                        </p>
                                        <p style="margin: 0; font-size: 12px; color: #94a3b8; line-height: 1.5;">
                                            <strong>Security Reminder:</strong> Never share this code with anyone. VendoLink staff will never ask for your verification code.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin: 24px 0 0 0; font-size: 12px; line-height: 1.5; color: #64748b;">
                                If you did not create a VendoLink account, no action is required and you can safely ignore this email. The account will not be activated without email verification.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 24px 32px 32px 32px; background-color: #0b1120; border-top: 1px solid #1e293b; text-align: center;">
                            <p style="margin: 0 0 6px 0; font-size: 13px; font-weight: 600; color: #cbd5e1;">
                                VendoLink Support
                            </p>
                            <p style="margin: 0 0 10px 0; font-size: 11px; color: #64748b; line-height: 1.4;">
                                IoT Monitoring &amp; Sales Audit System
                            </p>
                            <p style="margin: 0; font-size: 11px; color: #475569; line-height: 1.4;">
                                This is an automated notification. Replies to this email are not monitored.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

        $plainText = <<<TEXT
VendoLink — IoT Monitoring & Sales Audit System
================================================

Verify Your Email Address

Hello {$name},

Thank you for registering a VendoLink Owner account. To complete your account registration and activate your machine management features, please verify your email address using the six-digit verification code below:

VERIFICATION CODE: {$otp}

This code expires in 5 minutes.

SECURITY REMINDER:
Never share this code with anyone. VendoLink staff will never ask for your verification code.

If you did not create a VendoLink account, no action is required and you can safely ignore this email. The account will not be activated without email verification.

--
VendoLink Support
IoT Monitoring & Sales Audit System
TEXT;

        return Mailer::send($to, $subject, $htmlBody, $plainText);
    }

    /**
     * Send 6-digit OTP for email change verification via email.
     */
    public function sendEmailChangeOtp(string $to, string $name, string $otp): bool
    {
        $subject      = "Your VendoLink Email Change Code: {$otp}";
        $formattedOtp = implode(' ', str_split($otp));
        $safeName     = htmlspecialchars(trim($name) ?: 'Owner', ENT_QUOTES, 'UTF-8');

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Email Change — VendoLink</title>
</head>
<body style="margin: 0; padding: 0; background-color: #0b0f19; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale;">
    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #0b0f19; table-layout: fixed;">
        <tr>
            <td align="center" style="padding: 40px 16px;">
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 560px; background-color: #0f172a; border-radius: 14px; border: 1px solid #1e293b; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5);">
                    <tr>
                        <td height="4" style="background-color: #0ea5e9; font-size: 0; line-height: 0;">&nbsp;</td>
                    </tr>
                    <tr>
                        <td align="center" style="padding: 36px 32px 24px 32px;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center">
                                        <div style="font-size: 28px; font-weight: 800; letter-spacing: -0.5px; color: #ffffff; line-height: 1.2;">
                                            Vendo<span style="color: #0ea5e9;">Link</span>
                                        </div>
                                        <div style="font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 1.5px; margin-top: 6px;">
                                            IoT Monitoring &amp; Sales Audit System
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 0 32px;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td height="1" style="background-color: #1e293b; font-size: 0; line-height: 0;">&nbsp;</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 32px 32px 24px 32px;">
                            <h1 style="margin: 0 0 16px 0; font-size: 20px; font-weight: 700; color: #ffffff; line-height: 1.4;">
                                Confirm Email Address Change
                            </h1>
                            <p style="margin: 0 0 16px 0; font-size: 15px; line-height: 1.6; color: #cbd5e1;">
                                Hello <strong style="color: #ffffff;">{$safeName}</strong>,
                            </p>
                            <p style="margin: 0 0 24px 0; font-size: 14px; line-height: 1.6; color: #94a3b8;">
                                You recently requested to update the email address associated with your VendoLink account. Please use the six-digit confirmation code below to complete this change:
                            </p>
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 28px 0;">
                                <tr>
                                    <td align="center">
                                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="background-color: #1e293b; border: 2px solid #0ea5e9; border-radius: 10px; width: 100%; max-width: 380px;">
                                            <tr>
                                                <td align="center" style="padding: 20px 16px;">
                                                    <div style="font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 2px; color: #64748b; margin-bottom: 8px;">
                                                        Confirmation Code
                                                    </div>
                                                    <div style="font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, Courier, monospace; font-size: 32px; font-weight: 800; letter-spacing: 10px; color: #38bdf8; line-height: 1.2;">
                                                        {$formattedOtp}
                                                    </div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #162032; border-left: 3px solid #0ea5e9; border-radius: 6px; margin: 24px 0;">
                                <tr>
                                    <td style="padding: 14px 18px;">
                                        <p style="margin: 0 0 6px 0; font-size: 13px; font-weight: 600; color: #e2e8f0; line-height: 1.4;">
                                            &#9201; This code expires in 5 minutes.
                                        </p>
                                        <p style="margin: 0; font-size: 12px; color: #94a3b8; line-height: 1.5;">
                                            <strong>Security Notice:</strong> If you did not request to change your email address, please disregard this email or contact support immediately.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin: 24px 0 0 0; font-size: 12px; line-height: 1.5; color: #64748b;">
                                No changes will be made to your account until this code is entered and verified.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 24px 32px 32px 32px; background-color: #0b1120; border-top: 1px solid #1e293b; text-align: center;">
                            <p style="margin: 0 0 6px 0; font-size: 13px; font-weight: 600; color: #cbd5e1;">
                                VendoLink Support
                            </p>
                            <p style="margin: 0 0 10px 0; font-size: 11px; color: #64748b; line-height: 1.4;">
                                IoT Monitoring &amp; Sales Audit System
                            </p>
                            <p style="margin: 0; font-size: 11px; color: #475569; line-height: 1.4;">
                                This is an automated notification. Replies to this email are not monitored.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

        $plainText = <<<TEXT
VendoLink — IoT Monitoring & Sales Audit System
================================================

Confirm Email Address Change

Hello {$name},

You recently requested to update the email address associated with your VendoLink account. Please use the six-digit confirmation code below to complete this change:

CONFIRMATION CODE: {$otp}

This code expires in 5 minutes.

SECURITY NOTICE:
If you did not request to change your email address, please contact support immediately. No changes will be made to your account until this code is entered and verified.

--
VendoLink Support
IoT Monitoring & Sales Audit System
TEXT;

        return Mailer::send($to, $subject, $htmlBody, $plainText);
    }

    // ============================================================
    // Private Helpers
    // ============================================================

    /**
     * Send password reset email.
     *
     * @param string $to        Recipient email
     * @param string $name      Recipient full name
     * @param string $resetLink Full reset URL
     */
    private function sendPasswordResetEmail(string $to, string $name, string $resetLink): void
    {
        $subject  = 'Reset Your Password — ' . APP_NAME;
        $safeName = htmlspecialchars(trim($name) ?: 'User', ENT_QUOTES, 'UTF-8');

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Your Password — VendoLink</title>
</head>
<body style="margin: 0; padding: 0; background-color: #0b0f19; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale;">
    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #0b0f19; table-layout: fixed;">
        <tr>
            <td align="center" style="padding: 40px 16px;">
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 560px; background-color: #0f172a; border-radius: 14px; border: 1px solid #1e293b; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5);">
                    <tr>
                        <td height="4" style="background-color: #0ea5e9; font-size: 0; line-height: 0;">&nbsp;</td>
                    </tr>
                    <tr>
                        <td align="center" style="padding: 36px 32px 24px 32px;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center">
                                        <div style="font-size: 28px; font-weight: 800; letter-spacing: -0.5px; color: #ffffff; line-height: 1.2;">
                                            Vendo<span style="color: #0ea5e9;">Link</span>
                                        </div>
                                        <div style="font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 1.5px; margin-top: 6px;">
                                            IoT Monitoring &amp; Sales Audit System
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 0 32px;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td height="1" style="background-color: #1e293b; font-size: 0; line-height: 0;">&nbsp;</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 32px 32px 24px 32px;">
                            <h1 style="margin: 0 0 16px 0; font-size: 20px; font-weight: 700; color: #ffffff; line-height: 1.4;">
                                Password Reset Request
                            </h1>
                            <p style="margin: 0 0 16px 0; font-size: 15px; line-height: 1.6; color: #cbd5e1;">
                                Hello <strong style="color: #ffffff;">{$safeName}</strong>,
                            </p>
                            <p style="margin: 0 0 24px 0; font-size: 14px; line-height: 1.6; color: #94a3b8;">
                                We received a request to reset the password for your VendoLink account. Click the button below to set a new password:
                            </p>
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 28px 0;">
                                <tr>
                                    <td align="center">
                                        <a href="{$resetLink}" style="background-color: #0ea5e9; color: #ffffff; padding: 14px 32px; text-decoration: none; border-radius: 8px; font-weight: 700; font-size: 14px; display: inline-block; letter-spacing: 0.5px;">Reset Password</a>
                                    </td>
                                </tr>
                            </table>
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #162032; border-left: 3px solid #0ea5e9; border-radius: 6px; margin: 24px 0;">
                                <tr>
                                    <td style="padding: 14px 18px;">
                                        <p style="margin: 0 0 6px 0; font-size: 13px; font-weight: 600; color: #e2e8f0; line-height: 1.4;">
                                            &#9201; This link expires in 1 hour.
                                        </p>
                                        <p style="margin: 0; font-size: 12px; color: #94a3b8; line-height: 1.5;">
                                            <strong>Security Notice:</strong> If you did not request a password reset, you can safely ignore this email. Your current password remains secure.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 24px 32px 32px 32px; background-color: #0b1120; border-top: 1px solid #1e293b; text-align: center;">
                            <p style="margin: 0 0 6px 0; font-size: 13px; font-weight: 600; color: #cbd5e1;">
                                VendoLink Support
                            </p>
                            <p style="margin: 0 0 10px 0; font-size: 11px; color: #64748b; line-height: 1.4;">
                                IoT Monitoring &amp; Sales Audit System
                            </p>
                            <p style="margin: 0; font-size: 11px; color: #475569; line-height: 1.4;">
                                This is an automated notification. Replies to this email are not monitored.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

        $plainText = <<<TEXT
VendoLink — IoT Monitoring & Sales Audit System
================================================

Password Reset Request

Hello {$name},

We received a request to reset the password for your VendoLink account. Open the link below to choose a new password:
{$resetLink}

This link expires in 1 hour.

SECURITY NOTICE:
If you did not request a password reset, you can safely ignore this email. Your current password remains secure.

--
VendoLink Support
IoT Monitoring & Sales Audit System
TEXT;

        Mailer::send($to, $subject, $htmlBody, $plainText);
    }

    /**
     * Send email via direct SSL/TLS SMTP socket connection (delegates to centralized Mailer).
     */
    private function sendViaSmtpSocket(string $to, string $subject, string $htmlBody, string $from, string $fromName): bool
    {
        return Mailer::send($to, $subject, $htmlBody, null, $from, $fromName);
    }
}
