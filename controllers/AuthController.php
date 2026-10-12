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
        $logMessage = sprintf(
            "[%s] EMAIL VERIFICATION LINK for %s <%s>:\n%s\n",
            date('Y-m-d H:i:s'),
            $name,
            $to,
            $verifyLink
        );

        $logFile = LOGS_PATH . '/mail.log';
        if (!is_dir(LOGS_PATH)) {
            mkdir(LOGS_PATH, 0755, true);
        }
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);

        if (defined('SMTP_ENABLED') && SMTP_ENABLED) {
            $subject  = 'Verify Your Email Address — ' . APP_NAME;
            $from     = SMTP_FROM_EMAIL;
            $fromName = SMTP_FROM_NAME;

            $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Email Verification</title>
</head>
<body style="font-family: 'Segoe UI', Arial, sans-serif; background-color: #0a0f1e; color: #ffffff; padding: 20px;">
    <div style="max-width: 550px; margin: 0 auto; background-color: #0f172a; padding: 30px; border-radius: 12px; border: 1px solid #1e293b;">
        <h2 style="color: #0ea5e9; margin-top: 0; font-size: 22px;">Verify Your Email Address</h2>
        <p style="color: #cbd5e1; font-size: 15px;">Hello <strong>{$name}</strong>,</p>
        <p style="color: #cbd5e1; font-size: 14px; line-height: 1.5;">Please click the button below to verify your email address for your VendoLink account:</p>
        <div style="margin: 30px 0; text-align: center;">
            <a href="{$verifyLink}" style="background-color: #0ea5e9; color: #ffffff; padding: 12px 28px; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 14px; display: inline-block;">Verify Email Address</a>
        </div>
        <p style="font-size: 12px; color: #94a3b8; line-height: 1.4;">If you did not request this, you can safely ignore this email.</p>
    </div>
</body>
</html>
HTML;
            return $this->sendViaSmtpSocket($to, $subject, $htmlBody, $from, $fromName);
        }
        return true;
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

        // Password requirements (min 8 chars)
        if (strlen($password) < 8) {
            $setFormData();
            flash('danger', 'Password must be at least 8 characters long.');
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
        $userId = $this->userModel->createOwnerAccount([
            'full_name' => $fullName,
            'username'  => $username,
            'email'     => $email,
            'password'  => $password,
        ]);

        // Activate Product Key & link owner to physical hardware unit
        $keyData  = $keyValidation['key'];
        $keyId    = (int)$keyData['id'];
        $hwSerial = $keyData['hardware_serial'];
        $this->productKeyModel->activateKey($keyId, $userId, $hwSerial);

        // Generate 6-digit OTP
        $otp = $this->userModel->createEmailVerifyOtp($userId);

        // Store OTP state in session
        $_SESSION['reg_user_id']   = $userId;
        $_SESSION['reg_email']     = $email;
        $_SESSION['reg_name']      = $fullName;
        $_SESSION['otp_sent_at']   = time();
        $_SESSION['otp_attempts']  = 0;

        // Send OTP email
        $this->sendOtpEmail($email, $fullName, $otp);

        log_activity('REGISTER_INITIATED', 'Owner account registration initiated with product key ' . e($keyData['product_key']), $userId);
        log_activity('PRODUCT_KEY_ACTIVATED', "Activated product key {$keyData['product_key']} for hardware {$hwSerial}.", $userId);

        flash('info', 'We sent a 6-digit verification code to ' . e(mask_email($email)));
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
        $this->sendOtpEmail($email, $name, $otp);

        log_activity('REGISTER_OTP_RESENT', 'Resent email verification OTP code.', (int)$userId);

        flash('success', 'A new 6-digit code has been sent to ' . e(mask_email($email)));
        redirect('auth/verify_otp.php');
    }

    /**
     * Process 6-digit OTP verification (POST /auth/verify_otp.php).
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

        // Verify OTP in DB
        $success = $this->userModel->verifyEmailOtp((int)$userId, $otp);

        if (!$success) {
            flash('danger', 'Invalid or expired verification code. Please try again.');
            log_activity('REGISTER_OTP_FAILED', 'Failed OTP verification attempt.', (int)$userId);
            redirect('auth/verify_otp.php');
        }

        // Verification successful — activate account!
        log_activity('REGISTER_SUCCESS', 'Owner account verified and activated successfully.', (int)$userId);

        unset($_SESSION['reg_user_id'], $_SESSION['reg_email'], $_SESSION['reg_name'], $_SESSION['otp_sent_at'], $_SESSION['otp_attempts']);
        $_SESSION['reg_completed'] = true;

        redirect('auth/verify_otp.php');
    }

    /**
     * Send 6-digit OTP via email.
     */
    public function sendOtpEmail(string $to, string $name, string $otp): bool
    {
        $logMessage = sprintf(
            "[%s] REGISTRATION OTP CODE for %s <%s>: %s\n",
            date('Y-m-d H:i:s'),
            $name,
            $to,
            $otp
        );

        $logFile = LOGS_PATH . '/mail.log';
        if (!is_dir(LOGS_PATH)) {
            mkdir(LOGS_PATH, 0755, true);
        }
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);

        if (defined('SMTP_ENABLED') && SMTP_ENABLED) {
            $subject  = "Your VendoLink Verification Code: {$otp}";
            $from     = SMTP_FROM_EMAIL;
            $fromName = SMTP_FROM_NAME;

            $formattedOtp = implode(' ', str_split($otp));

            $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Verification Code</title>
</head>
<body style="font-family: 'Segoe UI', Arial, sans-serif; background-color: #0a0f1e; color: #ffffff; padding: 20px;">
    <div style="max-width: 550px; margin: 0 auto; background-color: #0f172a; padding: 30px; border-radius: 12px; border: 1px solid #1e293b; text-align: center;">
        <h2 style="color: #0ea5e9; margin-top: 0; font-size: 22px;">Verify your email</h2>
        <p style="color: #cbd5e1; font-size: 15px; text-align: left;">Hello <strong>{$name}</strong>,</p>
        <p style="color: #cbd5e1; font-size: 14px; line-height: 1.5; text-align: left;">Use the 6-digit verification code below to complete your VendoLink owner account registration:</p>
        
        <div style="margin: 28px 0; background-color: #1e293b; border: 1px solid #0ea5e9; border-radius: 10px; padding: 18px; letter-spacing: 12px; font-size: 32px; font-weight: 800; color: #38bdf8; font-family: monospace;">
            {$formattedOtp}
        </div>
        
        <p style="font-size: 13px; color: #94a3b8; line-height: 1.4;">This code expires in <strong>5 minutes</strong>. Do not share this code with anyone.</p>
    </div>
</body>
</html>
HTML;
            return $this->sendViaSmtpSocket($to, $subject, $htmlBody, $from, $fromName);
        }
        return true;
    }

    /**
     * Send 6-digit OTP for email change verification via email.
     */
    public function sendEmailChangeOtp(string $to, string $name, string $otp): bool
    {
        $logMessage = sprintf(
            "[%s] EMAIL CHANGE OTP CODE for %s <%s>: %s\n",
            date('Y-m-d H:i:s'),
            $name,
            $to,
            $otp
        );

        $logFile = LOGS_PATH . '/mail.log';
        if (!is_dir(LOGS_PATH)) {
            mkdir(LOGS_PATH, 0755, true);
        }
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);

        if (defined('SMTP_ENABLED') && SMTP_ENABLED) {
            $subject  = "Your VendoLink Email Change Code: {$otp}";
            $from     = SMTP_FROM_EMAIL;
            $fromName = SMTP_FROM_NAME;

            $formattedOtp = implode(' ', str_split($otp));

            $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Email Change Verification Code</title>
</head>
<body style="font-family: 'Segoe UI', Arial, sans-serif; background-color: #0a0f1e; color: #ffffff; padding: 20px;">
    <div style="max-width: 550px; margin: 0 auto; background-color: #0f172a; padding: 30px; border-radius: 12px; border: 1px solid #1e293b; text-align: center;">
        <h2 style="color: #0ea5e9; margin-top: 0; font-size: 22px;">Confirm Email Change</h2>
        <p style="color: #cbd5e1; font-size: 15px; text-align: left;">Hello <strong>{$name}</strong>,</p>
        <p style="color: #cbd5e1; font-size: 14px; line-height: 1.5; text-align: left;">You requested to change your VendoLink account email address to this address. Use the 6-digit verification code below to confirm this change:</p>
        
        <div style="margin: 28px 0; background-color: #1e293b; border: 1px solid #0ea5e9; border-radius: 10px; padding: 18px; letter-spacing: 12px; font-size: 32px; font-weight: 800; color: #38bdf8; font-family: monospace;">
            {$formattedOtp}
        </div>
        
        <p style="font-size: 13px; color: #94a3b8; line-height: 1.4;">This code expires in <strong>5 minutes</strong>. If you did not request this change, please ignore this email or contact support.</p>
    </div>
</body>
</html>
HTML;
            return $this->sendViaSmtpSocket($to, $subject, $htmlBody, $from, $fromName);
        }
        return true;
    }

    // ============================================================
    // Private Helpers
    // ============================================================

    /**
     * Send password reset email.
     *
     * STUB: Replace this with PHPMailer / SMTP in production.
     * For development, the reset link is written to the log file.
     *
     * @param string $to        Recipient email
     * @param string $name      Recipient full name
     * @param string $resetLink Full reset URL
     */
    private function sendPasswordResetEmail(string $to, string $name, string $resetLink): void
    {
        // 1. Always log locally for audit/debugging
        $logMessage = sprintf(
            "[%s] PASSWORD RESET LINK for %s <%s>:\n%s\n",
            date('Y-m-d H:i:s'),
            $name,
            $to,
            $resetLink
        );

        $logFile = LOGS_PATH . '/mail.log';
        if (!is_dir(LOGS_PATH)) {
            mkdir(LOGS_PATH, 0755, true);
        }
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);

        // 2. Real online email sending if SMTP is enabled
        if (defined('SMTP_ENABLED') && SMTP_ENABLED) {
            $subject  = 'Reset Your Password — ' . APP_NAME;
            $from     = SMTP_FROM_EMAIL;
            $fromName = SMTP_FROM_NAME;

            $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Password Reset</title>
</head>
<body style="font-family: 'Segoe UI', Arial, sans-serif; background-color: #0a0f1e; color: #ffffff; padding: 20px;">
    <div style="max-width: 550px; margin: 0 auto; background-color: #0f172a; padding: 30px; border-radius: 12px; border: 1px solid #1e293b;">
        <h2 style="color: #0ea5e9; margin-top: 0; font-size: 22px;">VendoLink Password Reset</h2>
        <p style="color: #cbd5e1; font-size: 15px;">Hello <strong>{$name}</strong>,</p>
        <p style="color: #cbd5e1; font-size: 14px; line-height: 1.5;">You requested a password reset for your VendoLink account. Click the button below to set a new password:</p>
        <div style="margin: 30px 0; text-align: center;">
            <a href="{$resetLink}" style="background-color: #0ea5e9; color: #ffffff; padding: 12px 28px; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 14px; display: inline-block;">Reset Password</a>
        </div>
        <p style="font-size: 12px; color: #94a3b8; line-height: 1.4;">If you did not request a password reset, you can safely ignore this message. This link expires in 1 hour for security.</p>
    </div>
</body>
</html>
HTML;

            // Attempt SMTP socket delivery
            $sent = $this->sendViaSmtpSocket($to, $subject, $htmlBody, $from, $fromName);

            // Fallback to PHP mail() if socket send fails
            if (!$sent) {
                $headers  = "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                $headers .= "From: {$fromName} <{$from}>\r\n";
                $headers .= "Reply-To: {$from}\r\n";
                $headers .= "X-Mailer: PHP/" . phpversion();
                @mail($to, $subject, $htmlBody, $headers);
            }
        }
    }

    /**
     * Send email via direct SSL/TLS SMTP socket connection.
     */
    private function sendViaSmtpSocket(string $to, string $subject, string $htmlBody, string $from, string $fromName): bool
    {
        $host     = SMTP_HOST;
        $port     = SMTP_PORT;
        $username = SMTP_USER;
        $password = SMTP_PASS;

        $socket = @fsockopen($host, $port, $errno, $errstr, 15);
        if (!$socket) {
            error_log("[VendoLink SMTP Error] Connection failed: {$errstr} ({$errno})");
            return false;
        }

        fgets($socket, 512);

        $cmds = [
            "EHLO localhost\r\n" => 250,
            "AUTH LOGIN\r\n" => 334,
            base64_encode($username) . "\r\n" => 334,
            base64_encode($password) . "\r\n" => 235,
            "MAIL FROM: <{$from}>\r\n" => 250,
            "RCPT TO: <{$to}>\r\n" => 250,
            "DATA\r\n" => 354,
        ];

        foreach ($cmds as $cmd => $expectedCode) {
            fputs($socket, $cmd);
            $res = '';
            while ($str = fgets($socket, 512)) {
                $res .= $str;
                if (substr($str, 3, 1) === ' ') break;
            }
            $code = (int)substr($res, 0, 3);
            if ($code !== $expectedCode) {
                error_log("[VendoLink SMTP Error] Failed on step '{$cmd}': expected {$expectedCode}, got {$code}");
                fclose($socket);
                return false;
            }
        }

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$fromName} <{$from}>\r\n";
        $headers .= "To: {$to}\r\n";
        $headers .= "Subject: {$subject}\r\n";
        $headers .= "Date: " . date('r') . "\r\n";

        fputs($socket, $headers . "\r\n" . $htmlBody . "\r\n.\r\n");
        $res = fgets($socket, 512);
        $code = (int)substr($res, 0, 3);

        fputs($socket, "QUIT\r\n");
        fclose($socket);

        return $code === 250;
    }
}
