<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PoketFlow Database Session Handler
|--------------------------------------------------------------------------
| Stores PHP sessions in MySQL instead of server session files.
|--------------------------------------------------------------------------
*/

final class PoketFlowDatabaseSessionHandler implements
    SessionHandlerInterface,
    SessionUpdateTimestampHandlerInterface
{
    private $pdo;

    private $lifetime = 86400;
    private $lockedSessionId = null;
    private $lockName = null;
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        $this->releaseLock();

        return true;
    }

    private function acquireLock(string $sessionId): bool
    {
        if ($this->lockedSessionId === $sessionId) {
            return true;
        }

        $this->releaseLock();

        $lockName = 'pf_sess_' . substr(
            hash('sha256', $sessionId),
            0,
            56
        );

        $stmt = $this->pdo->prepare(
            'SELECT GET_LOCK(?, 5)'
        );

        $stmt->execute([$lockName]);

        if ((int) $stmt->fetchColumn() !== 1) {
            error_log(
                '[PoketFlow Sessions] Could not acquire session lock.'
            );

            return false;
        }

        $this->lockedSessionId = $sessionId;
        $this->lockName = $lockName;

        return true;
    }

    private function releaseLock(): void
    {
        if ($this->lockName === null) {
            return;
        }

        try {
            $stmt = $this->pdo->prepare(
                'SELECT RELEASE_LOCK(?)'
            );

            $stmt->execute([$this->lockName]);

        } catch (Throwable $e) {
            error_log(
                '[PoketFlow Sessions] Lock release failed: '
                . $e->getMessage()
            );
        }

        $this->lockedSessionId = null;
        $this->lockName = null;
    }

    public function read(string $id)    {
        try {
            if (!$this->acquireLock($id)) {
                return false;
            }

            $stmt = $this->pdo->prepare(
                'SELECT session_data
                 FROM user_sessions
                 WHERE session_id = ?
                 AND expires_at > ?
                 LIMIT 1'
            );

            $stmt->execute([
                $id,
                time()
            ]);

            $data = $stmt->fetchColumn();

            return $data === false
                ? ''
                : (string) $data;

        } catch (Throwable $e) {
            error_log(
                '[PoketFlow Sessions] Read failed: '
                . $e->getMessage()
            );

            return false;
        }
    }

    public function write(
        string $id,
        string $data
    ): bool {
        return $this->saveSession($id, $data);
    }

    public function updateTimestamp(
        string $id,
        string $data
    ): bool {
        return $this->saveSession($id, $data);
    }

    private function saveSession(
        string $id,
        string $data
    ): bool {
        try {
            if (!$this->acquireLock($id)) {
                return false;
            }

            $userId = null;

            if (
                isset($_SESSION['user_id'])
                && (int) $_SESSION['user_id'] > 0
            ) {
                $userId = (int) $_SESSION['user_id'];
            }

            $now = time();
            $expires = $now + $this->lifetime;

            $stmt = $this->pdo->prepare(
                'INSERT INTO user_sessions (
                    session_id,
                    user_id,
                    session_data,
                    last_activity,
                    expires_at
                )
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    user_id = VALUES(user_id),
                    session_data = VALUES(session_data),
                    last_activity = VALUES(last_activity),
                    expires_at = VALUES(expires_at)'
            );

            return $stmt->execute([
                $id,
                $userId,
                $data,
                $now,
                $expires
            ]);

        } catch (Throwable $e) {
            error_log(
                '[PoketFlow Sessions] Write failed: '
                . $e->getMessage()
            );

            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM user_sessions
                 WHERE session_id = ?'
            );

            $result = $stmt->execute([$id]);

            if ($this->lockedSessionId === $id) {
                $this->releaseLock();
            }

            return $result;

        } catch (Throwable $e) {
            error_log(
                '[PoketFlow Sessions] Destroy failed: '
                . $e->getMessage()
            );

            return false;
        }
    }

    public function gc(int $max_lifetime)    {
        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM user_sessions
                 WHERE expires_at <= ?'
            );

            $stmt->execute([time()]);

            return $stmt->rowCount();

        } catch (Throwable $e) {
            error_log(
                '[PoketFlow Sessions] Cleanup failed: '
                . $e->getMessage()
            );

            return false;
        }
    }

    public function validateId(string $id): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT 1
                 FROM user_sessions
                 WHERE session_id = ?
                 AND expires_at > ?
                 LIMIT 1'
            );

            $stmt->execute([
                $id,
                time()
            ]);

            return (bool) $stmt->fetchColumn();

        } catch (Throwable $e) {
            error_log(
                '[PoketFlow Sessions] Validation failed: '
                . $e->getMessage()
            );

            return false;
        }
    }
}
