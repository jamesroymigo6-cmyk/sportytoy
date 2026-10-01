<?php
require_once __DIR__ . '/app.php';

/**
 * ============================================================================
 * Database-backed PHP session handler
 * ============================================================================
 * Serverless hosts (Vercel) give each invocation an ephemeral filesystem, so
 * PHP's default file sessions cannot be shared between instances and users
 * would be signed out at random. Storing sessions in MySQL keeps logins,
 * CSRF tokens, and the shopping cart stable across instances.
 *
 * The handler connects lazily with the same environment settings as
 * config/db.php, so it works no matter the include order, and it creates its
 * table on demand (safe to run on every cold boot).
 */

if (!class_exists('SportyNiMigoDbSessionHandler')) {

    class SportyNiMigoDbSessionHandler implements SessionHandlerInterface
    {
        private ?PDO $pdo = null;

        private function db(): PDO
        {
            if ($this->pdo instanceof PDO) return $this->pdo;
            $dsn = 'mysql:host=' . sportsync_env('DB_HOST', '127.0.0.1')
                 . ';port=' . sportsync_env('DB_PORT', '3306')
                 . ';dbname=' . sportsync_env('DB_DATABASE', 'sports_event_system')
                 . ';charset=utf8mb4';
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ];
            if (sportsync_env('DB_SSL', '') === '1') {
                if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
                if ((string)sportsync_env('DB_SSL_CA', '') !== '' && defined('PDO::MYSQL_ATTR_SSL_CA')) $options[PDO::MYSQL_ATTR_SSL_CA] = sportsync_env('DB_SSL_CA', '');
            }
            $this->pdo = new PDO($dsn, sportsync_env('DB_USERNAME', 'root'), sportsync_env('DB_PASSWORD', ''), $options);
            try {
                $offset = (new DateTimeZone(APP_TIMEZONE))->getOffset(new DateTime('now', new DateTimeZone(APP_TIMEZONE)));
                $this->pdo->exec("SET time_zone = '" . sprintf('%s%02d:%02d', $offset < 0 ? '-' : '+', intdiv(abs($offset), 3600), intdiv(abs($offset) % 3600, 60)) . "'");
            } catch (Throwable $e) {
                sportsync_log($e); // alignment is cosmetic for sessions
            }
            return $this->pdo;
        }

        public function open(string $path, string $name): bool
        {
            try {
                $this->db()->exec(
                    'CREATE TABLE IF NOT EXISTS php_sessions (
                        id VARCHAR(128) PRIMARY KEY,
                        data MEDIUMTEXT NOT NULL,
                        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
                );
                return true;
            } catch (Throwable $e) {
                sportsync_log($e);
                return false;
            }
        }

        public function close(): bool
        {
            return true;
        }

        public function read(string $id): string|false
        {
            try {
                $q = $this->db()->prepare('SELECT data FROM php_sessions WHERE id = ?');
                $q->execute([$id]);
                $row = $q->fetch();
                return $row === false ? '' : (string)$row['data'];
            } catch (Throwable $e) {
                sportsync_log($e);
                return '';
            }
        }

        public function write(string $id, string $data): bool
        {
            try {
                $this->db()->prepare('REPLACE INTO php_sessions (id, data) VALUES (?, ?)')->execute([$id, $data]);
                return true;
            } catch (Throwable $e) {
                sportsync_log($e);
                return false;
            }
        }

        public function destroy(string $id): bool
        {
            try {
                $this->db()->prepare('DELETE FROM php_sessions WHERE id = ?')->execute([$id]);
                return true;
            } catch (Throwable $e) {
                sportsync_log($e);
                return false;
            }
        }

        public function gc(int $max_lifetime): int|false
        {
            try {
                return $this->db()->exec('DELETE FROM php_sessions WHERE updated_at < NOW() - INTERVAL 2 DAY');
            } catch (Throwable $e) {
                sportsync_log($e);
                return false;
            }
        }
    }
}
