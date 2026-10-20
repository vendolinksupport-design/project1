<?php
/**
 * ============================================================
 * VendoLink — User Controller
 * ============================================================
 *
 * Handles User Management actions: Add User, Edit User,
 * Toggle Status (Enable/Disable), Change User Password.
 *
 * Dispatched by POST actions in users/index.php.
 *
 * @package VendoLink
 * @version 1.0
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/User.php';

class UserController
{
    private User $userModel;

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(SESSION_NAME);
            session_start();
        }

        require_role([ROLE_ADMIN, ROLE_OWNER]);
        $this->userModel = new User();
    }

    /**
     * Dispatch POST action.
     */
    public function handle(): void
    {
        csrf_verify();

        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'create':
                $this->create();
                break;
            case 'edit':
                $this->edit();
                break;
            case 'toggle_status':
                $this->toggleStatus();
                break;
            case 'change_password':
                $this->changeUserPassword();
                break;
            default:
                flash('danger', 'Invalid action.');
                redirect('users/index.php');
        }
    }

    /**
     * Create a new user account.
     */
    private function create(): void
    {
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $role     = trim($_POST['role'] ?? ROLE_STAFF);
        $password = $_POST['password'] ?? '';
        $status   = trim($_POST['status'] ?? 'active');

        if (empty($fullName) || empty($email)) {
            flash('danger', 'Full Name and Email are required.');
            redirect('users/index.php');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('danger', 'Please enter a valid email address.');
            redirect('users/index.php');
        }

        if ($this->userModel->findByEmail($email)) {
            flash('danger', 'An account with that email already exists.');
            redirect('users/index.php');
        }

        if (!in_array($role, ALL_ROLES, true)) {
            flash('danger', 'Invalid user role selected.');
            redirect('users/index.php');
        }

        if (in_array($role, [ROLE_ADMIN, ROLE_SUPPORT], true)) {
            flash('danger', 'You cannot create internal accounts from this panel.');
            redirect('users/index.php');
        }

        // If no password provided, generate a random temporary password
        if (empty($password)) {
            $password = generate_token(12);
        } elseif (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            flash('danger', 'Password must be at least 8 characters long and contain both letters and numbers.');
            redirect('users/index.php');
        }

        try {
            $newId = $this->userModel->create([
                'full_name' => $fullName,
                'email'     => $email,
                'role'      => $role,
                'password'  => $password,
                'status'    => $status,
            ]);

            log_activity('USER_CREATED', "Created new {$role} account: {$email} (ID: {$newId})", (int)$newId);
            flash('success', "User account for '{$fullName}' created successfully.");
        } catch (Exception $e) {
            flash('danger', 'Failed to create user: ' . $e->getMessage());
        }

        redirect('users/index.php');
    }

    /**
     * Edit existing user account details.
     */
    private function edit(): void
    {
        $id       = (int)($_POST['id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $role     = trim($_POST['role'] ?? ROLE_STAFF);
        $status   = trim($_POST['status'] ?? 'active');

        $user = $this->userModel->findById($id);
        if (!$user) {
            flash('danger', 'User record not found.');
            redirect('users/index.php');
        }

        if (in_array($user['role'], [ROLE_ADMIN, ROLE_SUPPORT], true) || in_array($role, [ROLE_ADMIN, ROLE_SUPPORT], true)) {
            flash('danger', 'You cannot modify or assign internal accounts.');
            redirect('users/index.php');
        }

        if (empty($fullName) || empty($email)) {
            flash('danger', 'Full Name and Email are required.');
            redirect('users/index.php');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('danger', 'Please enter a valid email address.');
            redirect('users/index.php');
        }

        $existing = $this->userModel->findByEmail($email);
        if ($existing && (int)$existing['id'] !== $id) {
            flash('danger', 'That email address is already in use by another account.');
            redirect('users/index.php');
        }

        // Last Owner guard
        if ($status === 'inactive' && in_array($user['role'], [ROLE_OWNER, ROLE_ADMIN], true)) {
            if ($this->userModel->countActiveOwners() <= 1 && $user['status'] === 'active') {
                flash('danger', 'Cannot disable the last active Owner account.');
                redirect('users/index.php');
            }
        }

        try {
            $this->userModel->update($id, [
                'full_name' => $fullName,
                'email'     => $email,
                'username'  => $user['username'],
                'role'      => $role,
                'status'    => $status,
            ]);

            log_activity('USER_EDITED', "Updated user account ID {$id} ({$email})", $id);
            flash('success', "Account details for '{$fullName}' updated successfully.");
        } catch (Exception $e) {
            flash('danger', 'Failed to update user: ' . $e->getMessage());
        }

        redirect('users/index.php');
    }

    /**
     * Enable or Disable user account (soft status toggle).
     */
    private function toggleStatus(): void
    {
        $id        = (int)($_POST['id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? '');

        if (!in_array($newStatus, ['active', 'inactive'], true)) {
            flash('danger', 'Invalid status requested.');
            redirect('users/index.php');
        }

        $user = $this->userModel->findById($id);
        if ($user && in_array($user['role'], [ROLE_ADMIN, ROLE_SUPPORT], true)) {
            flash('danger', 'You cannot modify internal accounts.');
            redirect('users/index.php');
        }

        try {
            $this->userModel->setStatus($id, $newStatus);
            $actionWord = ($newStatus === 'active') ? 'enabled' : 'disabled';

            log_activity(
                ($newStatus === 'active') ? 'USER_ENABLED' : 'USER_DISABLED',
                "User account ID {$id} {$actionWord}.",
                $id
            );

            flash('success', "User account status changed to " . strtoupper($newStatus) . ".");
        } catch (DomainException $e) {
            flash('danger', $e->getMessage());
        } catch (Exception $e) {
            flash('danger', 'Failed to update user status: ' . $e->getMessage());
        }

        redirect('users/index.php');
    }

    /**
     * Update password for a user from admin/owner panel.
     */
    private function changeUserPassword(): void
    {
        $id              = (int)($_POST['id'] ?? 0);
        $newPassword     = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        $user = $this->userModel->findById($id);
        if (!$user) {
            flash('danger', 'User record not found.');
            redirect('users/index.php');
        }

        if (in_array($user['role'], [ROLE_ADMIN, ROLE_SUPPORT], true)) {
            flash('danger', 'You cannot modify internal accounts.');
            redirect('users/index.php');
        }

        if (strlen($newPassword) < 8 || !preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
            flash('danger', 'Password must be at least 8 characters long and contain both letters and numbers.');
            redirect('users/index.php');
        }

        if ($newPassword !== $confirmPassword) {
            flash('danger', 'New password and confirm password do not match.');
            redirect('users/index.php');
        }

        try {
            $this->userModel->updatePassword($id, $newPassword);
            log_activity('PASSWORD_CHANGED', "Admin/Owner updated password for user ID {$id}.", $id);
            flash('success', "Password for user '{$user['full_name']}' updated successfully.");
        } catch (Exception $e) {
            flash('danger', 'Failed to update password: ' . $e->getMessage());
        }

        redirect('users/index.php');
    }
}
