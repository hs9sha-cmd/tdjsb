<?php
/**
 * TDJSB - Database Initialization Script
 * Runs schema.sql and ensures the default admin account is configured.
 */

require_once dirname(__DIR__) . '/config/database.php';

$schemaFile = __DIR__ . '/schema.sql';
if (!file_exists($schemaFile)) {
    die("❌ Schema file not found at {$schemaFile}\n");
}

$sql = file_get_contents($schemaFile);

try {
    // Enable multiple statements if supported
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, 1);
    $pdo->exec($sql);
    echo "✅ Database schema and default seeds initialized successfully.\n";

    // Ensure default admin user with password '12345' exists
    $password_hash = password_hash('12345', PASSWORD_DEFAULT);
    
    $checkAdmin = $pdo->prepare("SELECT id FROM users WHERE username = 'admin'");
    $checkAdmin->execute();
    $admin = $checkAdmin->fetch();

    if (!$admin) {
        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, force_password_change) VALUES ('admin', ?, 'System Administrator', 0)");
        $stmt->execute([$password_hash]);
        $admin_id = $pdo->lastInsertId();

        $stmtRole = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, 1)");
        $stmtRole->execute([$admin_id]);
        
        echo "✅ Created default admin user (Username: admin, Password: 12345)\n";
    } else {
        // Update password to 12345 to ensure consistency
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, force_password_change = 0 WHERE id = ?");
        $stmt->execute([$password_hash, $admin['id']]);

        // Ensure admin has role_id 1
        $checkRole = $pdo->prepare("SELECT * FROM user_roles WHERE user_id = ? AND role_id = 1");
        $checkRole->execute([$admin['id']]);
        if (!$checkRole->fetch()) {
            $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, 1)")->execute([$admin['id']]);
        }
        echo "ℹ️ Admin user verified and password set to '12345'.\n";
    }

    echo "\n🎉 Database setup completed! You can now log in with:\n";
    echo "   Username: admin\n";
    echo "   Password: 12345\n";

} catch (\PDOException $e) {
    die("❌ Error initializing schema: " . $e->getMessage() . "\n");
}
