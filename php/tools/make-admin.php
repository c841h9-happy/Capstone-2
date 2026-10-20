<?php

declare(strict_types=1);

/**
 * Make somebody an administrator, or take it away again.
 *
 *     cd C:\xampp\htdocs\Capstone
 *     php php/tools/make-admin.php you@example.com
 *     php php/tools/make-admin.php --demote you@example.com
 *
 * There is no page for this, and deliberately so: a form that grants
 * administrator access is a form somebody can be tricked into submitting, and the
 * first admin would have to be created before any such page could be protected.
 * A command needs a shell on the machine, which is a fair bar for the person who
 * owns the machine.
 *
 * Sign up through the site first and confirm the emailed code, then run this on
 * that address. Nothing here sets or reads a password.
 */

// A browser has no business running this. Without the guard, requesting the file
// over HTTP would promote whoever the query string named.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/auth.php';

$arguments = array_slice($argv, 1);
$demote = false;

// --demote may come before or after the address; both readings are natural and
// getting it wrong here would silently do the opposite of what was asked.
foreach ($arguments as $index => $argument) {
    if ($argument === '--demote') {
        $demote = true;
        unset($arguments[$index]);
    }
}

$email = strtolower(trim((string) (array_values($arguments)[0] ?? '')));
$targetRole = $demote ? ROLE_CUSTOMER : ROLE_ADMIN;

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php php/tools/make-admin.php [--demote] you@example.com\n");
    exit(1);
}

try {
    $connection = getDatabaseConnection();

    $statement = $connection->prepare(
        'SELECT id, name, email, role, email_verified_at FROM users WHERE email = :email LIMIT 1'
    );
    $statement->execute(['email' => $email]);
    $user = $statement->fetch();

    if ($user === false) {
        fwrite(STDERR, "No account has the address {$email}.\n\n");
        fwrite(STDERR, "Sign up on the site first -- this only changes an existing account's role.\n");
        exit(1);
    }

    // An unverified account cannot sign in at all, so promoting one would look
    // like it worked and then do nothing. Say so instead.
    if ($user['email_verified_at'] === null) {
        fwrite(STDERR, "That account has not confirmed its email address yet.\n\n");
        fwrite(STDERR, "Sign in once and type in the emailed code first -- until then the\n");
        fwrite(STDERR, "account cannot be used, so a role on it would have no effect.\n");
        exit(1);
    }

    if ($user['role'] === $targetRole) {
        echo "{$user['name']} <{$user['email']}> is already a {$targetRole}. Nothing to do.\n";
    } else {
        $connection->prepare('UPDATE users SET role = :role WHERE id = :id')
            ->execute(['role' => $targetRole, 'id' => $user['id']]);

        $verb = $demote ? 'Demoted' : 'Promoted';
        echo "{$verb} {$user['name']} <{$user['email']}> from {$user['role']} to {$targetRole}.\n";
        echo "It takes effect the next time they sign in -- a session already open keeps\n";
        echo "the role it was given.\n";
    }

    // Printed every time, because the useful question after changing one role is
    // who holds the others.
    $admins = $connection->query(
        'SELECT name, email FROM users WHERE role = ' . $connection->quote(ROLE_ADMIN) . ' ORDER BY name'
    )->fetchAll();

    echo "\nAdministrators (" . count($admins) . "):\n";

    if ($admins === []) {
        echo "  (none)\n";
    }

    foreach ($admins as $admin) {
        echo "  {$admin['name']} <{$admin['email']}>\n";
    }

    exit(0);
} catch (PDOException $exception) {
    // By far the likeliest failure: the migration has not been run, so there is
    // no column to set. Worth naming, because the driver's own message does not
    // mention the file that fixes it.
    if (str_contains($exception->getMessage(), 'role')) {
        fwrite(STDERR, "The users table has no `role` column yet.\n\n");
        fwrite(STDERR, "Run the migration first:\n");
        fwrite(STDERR, "  mysql -u root capstone < sql\\roles-upgrade.sql\n");
        exit(1);
    }

    fwrite(STDERR, "Database error: {$exception->getMessage()}\n\n");
    fwrite(STDERR, "Check that MySQL is running and that php/config/database.php matches it.\n");
    exit(1);
}
