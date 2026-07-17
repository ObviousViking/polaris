<?php
// includes/DbSessionHandler.php
//
// Stores PHP sessions in the `sessions` table instead of container-local
// disk, so a session survives a container restart.

class DbSessionHandler implements SessionHandlerInterface
{
    private mysqli $conn;
    private int $maxLifetime;

    public function __construct(mysqli $conn, int $maxLifetime)
    {
        $this->conn = $conn;
        $this->maxLifetime = $maxLifetime > 0 ? $maxLifetime : 1440;
    }

    public function open($savePath, $sessionName): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read($id): string
    {
        $stmt = @$this->conn->prepare("SELECT data FROM sessions WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return '';
        }
        $stmt->bind_param("s", $id);
        $stmt->execute();
        $stmt->bind_result($data);
        $found = $stmt->fetch();
        $stmt->close();

        return $found && $data !== null ? $data : '';
    }

    public function write($id, $data): bool
    {
        $now = time();
        $stmt = @$this->conn->prepare("
            INSERT INTO sessions (id, data, last_access) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE data = VALUES(data), last_access = VALUES(last_access)
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("ssi", $id, $data, $now);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    public function destroy($id): bool
    {
        $stmt = @$this->conn->prepare("DELETE FROM sessions WHERE id = ?");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("s", $id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    public function gc($max_lifetime): int|false
    {
        $threshold = time() - $this->maxLifetime;
        $stmt = @$this->conn->prepare("DELETE FROM sessions WHERE last_access < ?");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("i", $threshold);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return $affected;
    }
}
