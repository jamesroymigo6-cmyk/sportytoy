<?php
/**
 * SportSync database bootstrap.
 *
 * - Fresh installs import database/sports_events_full.sql (all tables used by the current build).
 * - Older v9/v10/v10.1 databases (33 tables) are migrated IN PLACE, preserving
 *   every record, the first time this file runs. Merged legacy tables are then
 *   dropped: sales, carts, cart_items, order_items, notifications,
 *   voice_messages, comments, likes, teams, matches, sms_logs,
 *   password_reset_tokens, participants, registrations, merchandise,
 *   inventory, equipment_assignments, weather_alerts, system_settings,
 *   venue_bookings.
 *
 * Every step is guarded and idempotent, so it is safe to run on every request.
 */

function sportsync_table_exists(PDO $pdo, string $table): bool {
    $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]);
    return (bool)$q->fetchColumn();
}

function sportsync_column_exists(PDO $pdo, string $table, string $column): bool {
    if (!sportsync_table_exists($pdo, $table)) return false;
    $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $q->execute([$table, $column]);
    return (bool)$q->fetchColumn();
}

function sportsync_column_nullable(PDO $pdo, string $table, string $column): ?bool {
    $q = $pdo->prepare('SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $q->execute([$table, $column]);
    $v = $q->fetchColumn();
    return $v === false ? null : (strtoupper((string)$v) === 'YES');
}

function sportsync_step(string $name, callable $fn): void {
    static $fkOff = false;
    try {
        $fn();
    } catch (Throwable $e) {
        sportsync_log('Schema step failed [' . $name . ']: ' . $e->getMessage());
    }
}

function sportsync_ensure_schema(PDO $pdo): void {
    try { sportsync_create_core_tables($pdo); } catch (Throwable $e) { sportsync_log($e); }
    try { sportsync_add_missing_columns($pdo); } catch (Throwable $e) { sportsync_log($e); }
    try { sportsync_migrate_legacy($pdo); } catch (Throwable $e) { sportsync_log($e); }
    try { sportsync_repair_user_credentials($pdo); } catch (Throwable $e) { sportsync_log($e); }
}

/* ------------------------------------------------------------------ */
/* Self-heal: unusable stored credentials and expired lockouts          */
/* ------------------------------------------------------------------ */
/**
 * Some earlier installs and hand-edited databases stored a user's password in
 * users.password_hash as PLAIN TEXT. password_verify() can never match such a
 * value, so the account is permanently unable to sign in no matter what is
 * typed. Detect those rows and re-hash the stored value (it is the password),
 * then release the lockout so the owner can sign in immediately.
 *
 * Runs on every request but costs one indexed scan of the small users table.
 */
function sportsync_repair_user_credentials(PDO $pdo): void {
    if (!sportsync_table_exists($pdo, 'users') || !sportsync_column_exists($pdo, 'users', 'password_hash')) return;

    $q = $pdo->query("SELECT id, email, password_hash FROM users
                      WHERE password_hash IS NOT NULL AND password_hash <> ''
                        AND password_hash NOT LIKE '\$2y\$%' AND password_hash NOT LIKE '\$2a\$%'
                        AND password_hash NOT LIKE '\$2b\$%' AND password_hash NOT LIKE '\$2x\$%'
                        AND password_hash NOT LIKE '\$argon2%'
                      LIMIT 100");
    $broken = $q ? $q->fetchAll() : [];

    foreach ($broken as $row) {
        // A genuine hash we simply do not recognise: leave it alone rather than
        // hashing a hash and destroying a recoverable credential.
        $info = password_get_info((string)$row['password_hash']);
        if (!empty($info['algo']) && $info['algo'] !== 0) continue;

        sportsync_step('rehash credential for user #' . $row['id'], function () use ($pdo, $row) {
            $pdo->prepare('UPDATE users SET password_hash=?, failed_login_attempts=0, locked_until=NULL WHERE id=?')
                ->execute([password_hash((string)$row['password_hash'], PASSWORD_DEFAULT), $row['id']]);
            sportsync_log('Re-hashed a plain-text password_hash for ' . $row['email'] . ' (user #' . $row['id'] . '). Sign in with the previous value of that column, then change the password.');
        });
    }

    // Drop lockouts whose window has already closed so a stale counter cannot
    // lock an account out again on the next mistyped password.
    if (sportsync_column_exists($pdo, 'users', 'locked_until')) {
        sportsync_step('clear expired lockouts', function () use ($pdo) {
            $pdo->exec('UPDATE users SET failed_login_attempts=0, locked_until=NULL WHERE locked_until IS NOT NULL AND locked_until <= NOW()');
        });
    }
}

/* ------------------------------------------------------------------ */
/* 1. Core consolidated tables (CREATE IF NOT EXISTS = self-repair)    */
/* ------------------------------------------------------------------ */
function sportsync_create_core_tables(PDO $pdo): void {
    $tables = [
        "roles(id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) UNIQUE NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "users(id INT AUTO_INCREMENT PRIMARY KEY, role_id INT NOT NULL, full_name VARCHAR(120) NOT NULL, email VARCHAR(150) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL, phone VARCHAR(40), address VARCHAR(255) NULL, status ENUM('active','inactive') DEFAULT 'active',
            failed_login_attempts INT NOT NULL DEFAULT 0, locked_until DATETIME NULL, email_verified_at DATETIME NULL,
            last_login_at DATETIME NULL, reset_token_hash CHAR(64) NULL, reset_expires_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_users_role(role_id), FOREIGN KEY(role_id) REFERENCES roles(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "venues(id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, address VARCHAR(255), latitude DECIMAL(10,7), longitude DECIMAL(10,7),
            capacity INT DEFAULT 0, facilities TEXT, layout_notes TEXT, image_path VARCHAR(255) NULL, status ENUM('available','maintenance','inactive') DEFAULT 'available',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "events(id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(160) NOT NULL, description TEXT, event_type VARCHAR(80),
            start_at DATETIME NOT NULL, end_at DATETIME NOT NULL, venue_id INT NULL, organizer_id INT NULL,
            status ENUM('draft','scheduled','ongoing','completed','cancelled') DEFAULT 'scheduled', is_outdoor TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_events_organizer_schedule(organizer_id,status,start_at), INDEX idx_events_venue_schedule(venue_id,status,start_at,end_at),
            FOREIGN KEY(venue_id) REFERENCES venues(id) ON DELETE SET NULL, FOREIGN KEY(organizer_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "event_registrations(id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, event_id INT NOT NULL, full_name VARCHAR(120) NOT NULL,
            email VARCHAR(150), phone VARCHAR(40), team VARCHAR(100), category VARCHAR(80), emergency_contact VARCHAR(160),
            status ENUM('pending','approved','rejected') DEFAULT 'pending', registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_registration(user_id,event_id), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "equipment(id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(140) NOT NULL, category VARCHAR(80), quantity INT DEFAULT 1,
            condition_status ENUM('excellent','good','fair','repair') DEFAULT 'good', current_location VARCHAR(120),
            status ENUM('available','checked_out','maintenance') DEFAULT 'available', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "event_equipment_reservations(id INT AUTO_INCREMENT PRIMARY KEY, event_id INT NOT NULL, equipment_id INT NOT NULL, user_id INT NOT NULL,
            quantity INT NOT NULL DEFAULT 1, start_at DATETIME NOT NULL, end_at DATETIME NOT NULL,
            status ENUM('reserved','released','cancelled') NOT NULL DEFAULT 'reserved', notes VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_eer_event(event_id),
            INDEX idx_eer_equipment_time(equipment_id,start_at,end_at,status),
            FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE, FOREIGN KEY(equipment_id) REFERENCES equipment(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "items(id INT AUTO_INCREMENT PRIMARY KEY, kind ENUM('supply','product') NOT NULL DEFAULT 'product', name VARCHAR(140) NOT NULL,
            category VARCHAR(80), description TEXT, price DECIMAL(12,2) NULL, quantity INT DEFAULT 0, min_stock INT DEFAULT 0,
            max_stock INT DEFAULT 9999, unit VARCHAR(30) DEFAULT 'pcs', location VARCHAR(120), image_path VARCHAR(255),
            status ENUM('active','inactive') DEFAULT 'active', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_items_kind(kind,status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "orders(id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, event_id INT NULL, items_json TEXT NOT NULL,
            total_amount DECIMAL(12,2) DEFAULT 0, payment_method VARCHAR(60) DEFAULT 'Cash',
            payment_status ENUM('pending','paid','failed','cancelled') DEFAULT 'pending', payment_reference VARCHAR(120) NULL,
            status ENUM('pending','confirmed','ready','completed','cancelled') DEFAULT 'pending', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_orders_user_created(user_id,created_at), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "announcements(id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(180) NOT NULL, body TEXT NOT NULL,
            type ENUM('general','urgent','emergency') DEFAULT 'general', audience VARCHAR(80) DEFAULT 'all', event_id INT NULL,
            created_by INT, expires_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE SET NULL, FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "messages(id INT AUTO_INCREMENT PRIMARY KEY, sender_id INT NULL, receiver_id INT NOT NULL,
            message_type ENUM('text','voice') NOT NULL DEFAULT 'text', subject VARCHAR(180), message TEXT NOT NULL,
            file_path VARCHAR(255) NULL, duration_seconds INT DEFAULT 0, is_read TINYINT(1) DEFAULT 0, read_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_message_receiver(receiver_id,is_read),
            FOREIGN KEY(sender_id) REFERENCES users(id) ON DELETE SET NULL, FOREIGN KEY(receiver_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "community_posts(id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
            post_type ENUM('community','event','tournament') NOT NULL DEFAULT 'community', event_id INT NULL, tournament_id INT NULL,
            content TEXT NOT NULL, image_path VARCHAR(255), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_community_created(created_at), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "post_interactions(id INT AUTO_INCREMENT PRIMARY KEY, post_id INT NOT NULL, user_id INT NOT NULL,
            type ENUM('comment','like') NOT NULL, content VARCHAR(500) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_interaction(post_id,user_id,type), FOREIGN KEY(post_id) REFERENCES community_posts(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "announcement_comments(id INT AUTO_INCREMENT PRIMARY KEY, announcement_id INT NOT NULL, user_id INT NOT NULL,
            content VARCHAR(500) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_anncomment(announcement_id,created_at),
            FOREIGN KEY(announcement_id) REFERENCES announcements(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "gcash_payouts(id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, checkout_session_id VARCHAR(80) NULL,
            reference_number VARCHAR(120) NULL, sender_mobile VARCHAR(32) NULL, amount DECIMAL(12,2) NOT NULL,
            status ENUM('verifying','paid','failed') NOT NULL DEFAULT 'verifying', note VARCHAR(255) NULL,
            verified_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, verified_at DATETIME NULL,
            INDEX idx_gcash_order(order_id),
            FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
            FOREIGN KEY(verified_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "tournaments(id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(160) NOT NULL, event_id INT NULL,
            format VARCHAR(60) DEFAULT 'Single Elimination', status ENUM('upcoming','ongoing','completed') DEFAULT 'upcoming',
            bracket_json TEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "activity_logs(id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, action VARCHAR(100) NOT NULL, details TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_activity_created(created_at),
            INDEX idx_activity_user(user_id,created_at), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($tables as $sql) {
        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . $sql);
    }
}

/* ------------------------------------------------------------------ */
/* 2. Column additions for databases created by older SportSync builds */
/* ------------------------------------------------------------------ */
function sportsync_add_missing_columns(PDO $pdo): void {
    $columns = [
        // Clerk identity link. Nullable: classic accounts without a Clerk sign-in stay NULL.
        ['users', 'clerk_id', "ALTER TABLE users ADD COLUMN clerk_id VARCHAR(64) NULL UNIQUE AFTER password_hash"],
        ['users', 'reset_token_hash', 'ALTER TABLE users ADD COLUMN reset_token_hash CHAR(64) NULL AFTER last_login_at'],
        ['users', 'address', "ALTER TABLE users ADD COLUMN address VARCHAR(255) NULL AFTER phone"],
        ['users', 'reset_expires_at', 'ALTER TABLE users ADD COLUMN reset_expires_at DATETIME NULL AFTER reset_token_hash'],
        ['orders', 'items_json', "ALTER TABLE orders ADD COLUMN items_json TEXT NULL AFTER event_id"],
        ['orders', 'payment_method', "ALTER TABLE orders ADD COLUMN payment_method VARCHAR(60) DEFAULT 'Cash' AFTER total_amount"],
        ['orders', 'payment_status', "ALTER TABLE orders ADD COLUMN payment_status ENUM('pending','paid','failed','cancelled') DEFAULT 'pending' AFTER payment_method"],
        ['orders', 'payment_reference', 'ALTER TABLE orders ADD COLUMN payment_reference VARCHAR(120) NULL AFTER payment_status'],
        ['orders', 'payment_verified_at', 'ALTER TABLE orders ADD COLUMN payment_verified_at DATETIME NULL AFTER payment_reference'],
        ['post_interactions', 'announcement_comment', "ALTER TABLE post_interactions MODIFY type ENUM('comment','like') NOT NULL"],
        ['gcash_payouts', 'order_id', "CREATE TABLE IF NOT EXISTS gcash_payouts(id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, checkout_session_id VARCHAR(80) NULL, reference_number VARCHAR(120) NULL, sender_mobile VARCHAR(32) NULL, amount DECIMAL(12,2) NOT NULL, status ENUM('verifying','paid','failed') NOT NULL DEFAULT 'verifying', note VARCHAR(255) NULL, verified_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, verified_at DATETIME NULL, INDEX idx_gcash_order(order_id), FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE, FOREIGN KEY(verified_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],
        ['tournaments', 'bracket_json', 'ALTER TABLE tournaments ADD COLUMN bracket_json TEXT NULL AFTER status'],
        ['messages', 'message_type', "ALTER TABLE messages ADD COLUMN message_type ENUM('text','voice') NOT NULL DEFAULT 'text' AFTER receiver_id"],
        ['messages', 'file_path', 'ALTER TABLE messages ADD COLUMN file_path VARCHAR(255) NULL AFTER message'],
        ['messages', 'duration_seconds', 'ALTER TABLE messages ADD COLUMN duration_seconds INT DEFAULT 0 AFTER file_path'],
        ['community_posts', 'post_type', "ALTER TABLE community_posts ADD COLUMN post_type ENUM('community','event','tournament') NOT NULL DEFAULT 'community' AFTER user_id"],
        ['community_posts', 'event_id', 'ALTER TABLE community_posts ADD COLUMN event_id INT NULL AFTER post_type'],
        ['community_posts', 'tournament_id', 'ALTER TABLE community_posts ADD COLUMN tournament_id INT NULL AFTER event_id'],
        ['announcements', 'expires_at', 'ALTER TABLE announcements ADD COLUMN expires_at DATETIME NULL AFTER created_by'],
        ['announcements', 'sms_total', 'ALTER TABLE announcements ADD COLUMN sms_total INT NOT NULL DEFAULT 0 AFTER expires_at'],
        ['announcements', 'sms_sent', 'ALTER TABLE announcements ADD COLUMN sms_sent INT NOT NULL DEFAULT 0 AFTER sms_total'],
        ['announcements', 'sms_failed', 'ALTER TABLE announcements ADD COLUMN sms_failed INT NOT NULL DEFAULT 0 AFTER sms_sent'],
        ['venues', 'image_path', 'ALTER TABLE venues ADD COLUMN image_path VARCHAR(255) NULL AFTER layout_notes'],
    ];
    foreach ($columns as [$table, $column, $sql]) {
        if (sportsync_table_exists($pdo, $table) && !sportsync_column_exists($pdo, $table, $column)) {
            sportsync_step('add ' . $table . '.' . $column, function () use ($pdo, $sql) { $pdo->exec($sql); });
        }
    }
    // System notifications are stored as messages with a NULL sender.
    if (sportsync_table_exists($pdo, 'messages') && sportsync_column_nullable($pdo, 'messages', 'sender_id') === false) {
        sportsync_step('messages.sender_id nullable', function () use ($pdo) {
            $pdo->exec('ALTER TABLE messages MODIFY sender_id INT NULL');
        });
    }
}

/* ------------------------------------------------------------------ */
/* 3. One-time migration from the legacy 33-table layout               */
/* ------------------------------------------------------------------ */
function sportsync_migrate_legacy(PDO $pdo): void {
    $has = fn(string $t): bool => sportsync_table_exists($pdo, $t);

    $drop = function (string $table) use ($pdo, $has): void {
        if ($has($table)) {
            sportsync_step('drop ' . $table, function () use ($pdo, $table) {
                $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', $table) . '`');
            });
        }
    };

    // -- Password reset tokens -> users.reset_* columns -------------------
    if ($has('password_reset_tokens') && $has('users') && sportsync_column_exists($pdo, 'users', 'reset_token_hash')) {
        sportsync_step('migrate password_reset_tokens', function () use ($pdo) {
            $pdo->exec("UPDATE users u JOIN password_reset_tokens p ON p.user_id=u.id
                        SET u.reset_token_hash=p.token_hash, u.reset_expires_at=p.expires_at
                        WHERE p.used_at IS NULL AND p.expires_at>NOW()");
        });
        $drop('password_reset_tokens');
    }

    // -- participants + registrations -> event_registrations --------------
    if ($has('participants') && $has('registrations')) {
        sportsync_step('migrate registrations', function () use ($pdo) {
            $pdo->exec("INSERT IGNORE INTO event_registrations(user_id,event_id,full_name,email,phone,team,category,emergency_contact,status,registered_at)
                        SELECT p.user_id, r.event_id, p.full_name, p.email, p.phone, p.team, p.category, p.emergency_contact, r.status, r.registered_at
                        FROM registrations r JOIN participants p ON p.id=r.participant_id");
        });
        $drop('registrations');
        $drop('participants');
    }

    // -- orders.items_json snapshot, then drop cart/order child tables ----
    if ($has('orders') && sportsync_column_exists($pdo, 'orders', 'items_json')) {
        if ($has('order_items')) {
            sportsync_step('migrate order_items to items_json', function () use ($pdo) {
                $rows = $pdo->query('SELECT oi.order_id, oi.quantity, oi.unit_price, COALESCE(m.name,"Item") name
                                     FROM order_items oi LEFT JOIN merchandise m ON m.id=oi.merchandise_id')->fetchAll();
                $byOrder = [];
                foreach ($rows as $r) {
                    $byOrder[(int)$r['order_id']][] = ['id' => null, 'name' => $r['name'], 'qty' => (int)$r['quantity'], 'price' => (float)$r['unit_price']];
                }
                $upd = $pdo->prepare('UPDATE orders SET items_json=? WHERE id=? AND (items_json IS NULL OR items_json="" OR items_json="[]")');
                foreach ($byOrder as $orderId => $items) {
                    $upd->execute([json_encode($items, JSON_UNESCAPED_UNICODE), $orderId]);
                }
                $pdo->exec('UPDATE orders SET items_json="[]" WHERE items_json IS NULL');
            });
        }
        $drop('cart_items');
        $drop('carts');
        $drop('order_items');
        $drop('sales'); // payment fields already live on orders
    }

    // -- merchandise + inventory -> items ----------------------------------
    // Guards keep the migration idempotent: run only when the target is empty.
    if ($has('merchandise')) {
        $products = (int)$pdo->query("SELECT COUNT(*) FROM items WHERE kind='product'")->fetchColumn();
        if ($products === 0) {
            sportsync_step('migrate merchandise', function () use ($pdo) {
                // Legacy merchandise has no category column; seed a sensible default.
                $pdo->exec("INSERT INTO items(kind,name,category,description,price,quantity,image_path,status,created_at)
                            SELECT 'product', name, 'Merchandise', description, price, stock, image_path, status, created_at FROM merchandise");
            });
        }
        $drop('merchandise');
    }
    if ($has('inventory')) {
        $supplies = (int)$pdo->query("SELECT COUNT(*) FROM items WHERE kind='supply'")->fetchColumn();
        if ($supplies === 0) {
            sportsync_step('migrate inventory', function () use ($pdo) {
                $pdo->exec("INSERT INTO items(kind,name,category,quantity,min_stock,max_stock,unit,location)
                            SELECT 'supply', item_name, category, quantity, min_stock, max_stock, unit, location FROM inventory");
            });
        }
        $drop('inventory_transactions'); // child table must go first (FK constraint)
        $drop('inventory');
    }

    // -- notifications + voice_messages -> messages ------------------------
    if ($has('notifications') && $has('messages') && sportsync_column_exists($pdo, 'messages', 'message_type')) {
        sportsync_step('migrate notifications', function () use ($pdo) {
            $pdo->exec("INSERT INTO messages(sender_id,receiver_id,message_type,subject,message,is_read,created_at)
                        SELECT NULL, user_id, 'text', title, message, is_read, created_at FROM notifications");
        });
        $drop('notifications');
    }
    if ($has('voice_messages') && $has('messages') && sportsync_column_exists($pdo, 'messages', 'message_type')) {
        sportsync_step('migrate voice_messages', function () use ($pdo) {
            $pdo->exec("INSERT INTO messages(sender_id,receiver_id,message_type,message,file_path,duration_seconds,is_read,created_at)
                        SELECT sender_id, receiver_id, 'voice', 'Voice message', file_path, duration_seconds, is_read, created_at FROM voice_messages");
        });
        $drop('voice_messages');
    }

    // -- comments + likes -> post_interactions ------------------------------
    if ($has('comments')) {
        sportsync_step('migrate comments', function () use ($pdo) {
            $pdo->exec("INSERT IGNORE INTO post_interactions(post_id,user_id,type,content,created_at)
                        SELECT post_id, user_id, 'comment', content, created_at FROM comments");
        });
        $drop('comments');
    }
    if ($has('likes')) {
        sportsync_step('migrate likes', function () use ($pdo) {
            $pdo->exec("INSERT IGNORE INTO post_interactions(post_id,user_id,type,created_at)
                        SELECT post_id, user_id, 'like', created_at FROM likes");
        });
        $drop('likes');
    }

    // -- teams + matches -> tournaments.bracket_json (PHP builds the JSON) --
    if ($has('teams') && $has('matches') && sportsync_column_exists($pdo, 'tournaments', 'bracket_json')) {
        sportsync_step('migrate bracket', function () use ($pdo) {
            $teams = [];
            foreach ($pdo->query('SELECT * FROM teams')->fetchAll() as $t) {
                $teams[(int)$t['tournament_id']][] = ['name' => $t['name'], 'seed' => $t['seed_no'] !== null ? (int)$t['seed_no'] : null];
            }
            $matches = [];
            foreach ($pdo->query('SELECT * FROM matches')->fetchAll() as $m) {
                $matches[(int)$m['tournament_id']][] = [
                    'round' => $m['round_name'],
                    'team1' => $m['team1_id'] ? ($pdo->query('SELECT name FROM teams WHERE id=' . (int)$m['team1_id'])->fetchColumn() ?: 'TBD') : 'TBD',
                    'team2' => $m['team2_id'] ? ($pdo->query('SELECT name FROM teams WHERE id=' . (int)$m['team2_id'])->fetchColumn() ?: 'TBD') : 'TBD',
                    'score1' => $m['score1'] !== null ? (int)$m['score1'] : null,
                    'score2' => $m['score2'] !== null ? (int)$m['score2'] : null,
                    'match_at' => $m['match_at'],
                    'status' => $m['status'],
                ];
            }
            $upd = $pdo->prepare('UPDATE tournaments SET bracket_json=? WHERE id=?');
            $all = $pdo->query('SELECT id FROM tournaments')->fetchAll();
            foreach ($all as $t) {
                $id = (int)$t['id'];
                $upd->execute([json_encode([
                    'teams' => $teams[$id] ?? [],
                    'matches' => $matches[$id] ?? [],
                ], JSON_UNESCAPED_UNICODE), $id]);
            }
        });
        $drop('matches');
        $drop('teams');
    }

    // -- sms_logs -> activity_logs ------------------------------------------
    if ($has('sms_logs') && $has('activity_logs')) {
        sportsync_step('migrate sms_logs', function () use ($pdo) {
            $pdo->exec("INSERT INTO activity_logs(user_id,action,details,created_at)
                        SELECT user_id, CONCAT('sms_', message_type),
                               CONCAT('phone=', COALESCE(phone,''), ' status=', COALESCE(status,''), ' msg=', COALESCE(message,'')),
                               created_at
                        FROM sms_logs");
        });
        $drop('sms_logs');
    }

    // -- equipment_assignments -> event_equipment_reservations --------------
    if ($has('equipment_assignments') && $has('event_equipment_reservations')) {
        sportsync_step('migrate equipment_assignments', function () use ($pdo) {
            $pdo->exec("INSERT INTO event_equipment_reservations(event_id,equipment_id,user_id,quantity,start_at,end_at,status,notes,created_at)
                        SELECT a.event_id, a.equipment_id, COALESCE(a.staff_id, (SELECT e.organizer_id FROM events e WHERE e.id=a.event_id), 1),
                               COALESCE(a.quantity,1), COALESCE(a.checked_out_at,NOW()), COALESCE(a.checked_in_at,NOW()),
                               CASE WHEN a.status='returned' THEN 'released' ELSE 'reserved' END, a.notes, COALESCE(a.checked_out_at,NOW())
                        FROM equipment_assignments a WHERE a.event_id IS NOT NULL");
        });
        $drop('equipment_assignments');
    }

    // -- weather_alerts -> announcements -------------------------------------
    if ($has('weather_alerts') && $has('announcements')) {
        sportsync_step('migrate weather_alerts', function () use ($pdo) {
            $pdo->exec("INSERT INTO announcements(title,body,type,audience,event_id,created_by,created_at)
                        SELECT COALESCE(title,'Weather alert'), COALESCE(message,''), 'emergency', 'all', event_id, NULL, created_at
                        FROM weather_alerts");
        });
        $drop('weather_alerts');
    }

    // -- venue_bookings: schedule already lives on events; safe to drop ------
    $drop('venue_bookings');
    // -- system_settings: configuration moved to .env -------------------------
    $drop('system_settings');
}
