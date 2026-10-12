<?php
/**
 * VendoLink — Emergency Password Reset Tool
 * Run this via browser: http://localhost/vendolink_claude_v1/reset_admin.php
 * DELETE THIS FILE immediately after use!
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/middleware/auth.php';

// Security Guard: Only allow CLI execution or authenticated Administrator session
if (php_sapi_name() !== 'cli') {
    require_admin();
}

$db  = Database::getInstance();
$msg = '';

// Handle POST — set new password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['new_password'])) {
    if (php_sapi_name() !== 'cli') {
        csrf_verify();
    }
    $userId   = (int)($_POST['user_id'] ?? 0);
    $newPass  = $_POST['new_password'];

    if (strlen($newPass) < 8) {
        $msg = '<div style="color:#ef4444">Password must be at least 8 characters.</div>';
    } elseif ($userId < 1) {
        $msg = '<div style="color:#ef4444">No user selected.</div>';
    } else {
        $hashed = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
        $db->query(
            'UPDATE users SET password = ?, login_attempts = 0, locked_until = NULL WHERE id = ?',
            [$hashed, $userId]
        );
        $msg = '<div style="color:#22c55e;font-size:1.1rem;font-weight:600">
            ✅ Password updated successfully!<br>
            <small style="font-weight:400">You can now <a href="' . BASE_URL . '/auth/login.php" style="color:#38bdf8">log in here</a>.</small><br><br>
            <strong style="color:#f59e0b">⚠ DELETE this file immediately: reset_admin.php</strong>
        </div>';
    }
}

// Fetch all users
$users = $db->query('SELECT id, username, email, full_name, role, status, login_attempts, locked_until, last_login FROM users ORDER BY role, id')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>VendoLink — Emergency Password Reset</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: system-ui, sans-serif; background: #0f172a; color: #f1f5f9;
         min-height: 100vh; padding: 2rem; }
  .card { background: #1e293b; border-radius: 12px; padding: 2rem;
          max-width: 720px; margin: 0 auto; box-shadow: 0 20px 60px rgba(0,0,0,.5); }
  h1 { font-size: 1.4rem; color: #f1f5f9; margin-bottom: .25rem; }
  .warn { background: rgba(239,68,68,.15); border: 1px solid rgba(239,68,68,.4);
          border-radius: 8px; padding: .75rem 1rem; margin-bottom: 1.5rem;
          color: #fca5a5; font-size: .88rem; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 1.5rem; font-size: .85rem; }
  th { text-align: left; padding: .5rem .75rem; color: #94a3b8; border-bottom: 1px solid #334155;
       font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; }
  td { padding: .6rem .75rem; border-bottom: 1px solid rgba(255,255,255,.05); }
  tr:hover td { background: rgba(255,255,255,.03); }
  .badge { font-size: .7rem; padding: .2rem .5rem; border-radius: 20px; font-weight: 600; }
  .badge-admin { background: rgba(239,68,68,.2); color: #f87171; }
  .badge-owner { background: rgba(245,158,11,.2); color: #fbbf24; }
  .badge-tech  { background: rgba(56,189,248,.2); color: #38bdf8; }
  .badge-active   { background: rgba(34,197,94,.15); color: #4ade80; }
  .badge-inactive { background: rgba(100,116,139,.2); color: #94a3b8; }
  .badge-locked   { background: rgba(239,68,68,.15); color: #f87171; }
  h2 { font-size: 1rem; color: #94a3b8; margin-bottom: 1rem; margin-top: 1.5rem;
       padding-top: 1.5rem; border-top: 1px solid #334155; }
  select, input[type=password] {
    background: #0f172a; border: 1px solid #334155; color: #f1f5f9;
    border-radius: 8px; padding: .55rem .85rem; width: 100%; font-size: .9rem; margin-bottom: .75rem;
  }
  select:focus, input[type=password]:focus { outline: none; border-color: #38bdf8; }
  button { background: linear-gradient(135deg, #0ea5e9, #6366f1); border: none;
           color: white; padding: .65rem 1.5rem; border-radius: 8px;
           font-weight: 600; cursor: pointer; font-size: .9rem; }
  button:hover { opacity: .9; }
  .msg { margin-top: 1rem; padding: .75rem; background: rgba(0,0,0,.3); border-radius: 8px; }
  label { display: block; font-size: .78rem; color: #94a3b8; margin-bottom: .3rem; text-transform: uppercase; letter-spacing: .04em; }
</style>
</head>
<body>
<div class="card">
  <h1>🔐 VendoLink — Emergency Password Reset</h1>
  <p style="color:#64748b;font-size:.83rem;margin:.35rem 0 1rem">For development use only. Delete this file when done.</p>

  <div class="warn">
    ⚠ <strong>Security Warning:</strong> This tool resets passwords without authentication.
    Delete <code>reset_admin.php</code> from your server immediately after use.
  </div>

  <h2 style="border:none;margin-top:0;padding-top:0;color:#f1f5f9">All Users in Database</h2>
  <?php if (empty($users)): ?>
    <p style="color:#ef4444">No users found. The database may be empty — run setup.php first.</p>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th>ID</th>
        <th>Username</th>
        <th>Email</th>
        <th>Full Name</th>
        <th>Role</th>
        <th>Status</th>
        <th>Failed Logins</th>
        <th>Last Login</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): 
        $locked = $u['login_attempts'] >= 5 && $u['locked_until'] && strtotime($u['locked_until']) > time();
      ?>
      <tr>
        <td style="color:#64748b"><?= $u['id'] ?></td>
        <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
        <td style="color:#94a3b8"><?= htmlspecialchars($u['email']) ?></td>
        <td><?= htmlspecialchars($u['full_name']) ?></td>
        <td>
          <span class="badge badge-<?= $u['role'] === 'administrator' ? 'admin' : ($u['role'] === 'owner' ? 'owner' : 'tech') ?>">
            <?= ucfirst($u['role']) ?>
          </span>
        </td>
        <td>
          <?php if ($locked): ?>
            <span class="badge badge-locked">Locked</span>
          <?php else: ?>
            <span class="badge badge-<?= $u['status'] ?>"><?= ucfirst($u['status']) ?></span>
          <?php endif; ?>
        </td>
        <td style="color:<?= $u['login_attempts'] > 0 ? '#f87171' : '#64748b' ?>">
          <?= $u['login_attempts'] ?> attempt(s)
          <?php if ($locked): ?>
            <br><small style="color:#f59e0b">Locked until: <?= $u['locked_until'] ?></small>
          <?php endif; ?>
        </td>
        <td style="color:#64748b;font-size:.78rem">
          <?= $u['last_login'] ? $u['last_login'] : 'Never' ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <?php if (!empty($users)): ?>
  <h2>Reset Password</h2>
  <form method="POST" action="">
    <?= csrf_field() ?>
    <label>Select User</label>
    <select name="user_id">
      <?php foreach ($users as $u): ?>
      <option value="<?= $u['id'] ?>">
        [ID <?= $u['id'] ?>] <?= htmlspecialchars($u['username']) ?> — <?= htmlspecialchars($u['email']) ?> (<?= $u['role'] ?>)
      </option>
      <?php endforeach; ?>
    </select>

    <label>New Password (min. 8 characters)</label>
    <input type="password" name="new_password" placeholder="Enter new password" required>

    <button type="submit">Set New Password</button>
  </form>

  <?php if ($msg): ?>
  <div class="msg"><?= $msg ?></div>
  <?php endif; ?>
  <?php endif; ?>

</div>
</body>
</html>
