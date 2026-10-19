<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$token = (string)($_GET['token'] ?? '');
$ok = false;

if ($token !== '') {
    try {
        $pdo = get_pdo();
        $hash = hash('sha256', $token);
        $stmt = $pdo->prepare('SELECT id FROM users WHERE verification_token_hash = ? AND verification_token_expires_at > NOW() AND email_verified_at IS NULL');
        $stmt->execute([$hash]);
        $user = $stmt->fetch();

        if ($user) {
            $update = $pdo->prepare('UPDATE users SET email_verified_at = NOW(), verification_token_hash = NULL, verification_token_expires_at = NULL WHERE id = ?');
            $update->execute([$user['id']]);
            $ok = true;
        }
    } catch (Throwable $e) {
        error_log('[NKT-1 accounts] ' . $e->getMessage());
        $ok = false;
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>NKT-1</title>
<style>
  body { font-family: Georgia, serif; max-width: 28rem; margin: 4rem auto; text-align: center; color: #14161c; padding: 0 24px; }
  a { color: #2f5d50; }
</style>
</head>
<body>
<?php if ($ok): ?>
  <h1>Email verified</h1>
  <p>Your account is ready. You can close this tab and log in.</p>
<?php else: ?>
  <h1>Link invalid or expired</h1>
  <p>Verification links expire after 24 hours. Try registering again to get a new one.</p>
<?php endif; ?>
  <p><a href="../index.html">Back to NKT-1</a></p>
</body>
</html>
