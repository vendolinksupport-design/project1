<?php
/** Phase 2 service configuration data access. */

require_once __DIR__ . '/../config/database.php';

class Service
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function updateConfiguration(int $serviceId, bool $isEnabled, float $coinValue, int $serviceSeconds, ?int $userId = null): bool
    {
        $current = $this->findById($serviceId);
        if ($current) {
            $prevCoin = (float) $current['coin_value'];
            $prevSecs = (int) $current['service_seconds'];
            $prevEnabled = (int) $current['is_enabled'];

            if ($prevCoin !== (float) $coinValue || $prevSecs !== $serviceSeconds || $prevEnabled !== ($isEnabled ? 1 : 0)) {
                $this->db->query(
                    'INSERT INTO configuration_history
                        (service_id, previous_coin_value, new_coin_value, previous_service_seconds, new_service_seconds,
                         previous_is_enabled, new_is_enabled, changed_by_user_id, created_at)
                     VALUES (:sid, :prev_c, :new_c, :prev_s, :new_s, :prev_e, :new_e, :uid, NOW())',
                    [
                        ':sid'    => $serviceId,
                        ':prev_c' => $prevCoin,
                        ':new_c'  => $coinValue,
                        ':prev_s' => $prevSecs,
                        ':new_s'  => $serviceSeconds,
                        ':prev_e' => $prevEnabled,
                        ':new_e'  => $isEnabled ? 1 : 0,
                        ':uid'    => $userId,
                    ]
                );
            }
        }

        return $this->db->query(
            'UPDATE services
             SET is_enabled = :is_enabled, coin_value = :coin_value, service_seconds = :service_seconds
             WHERE id = :id',
            [
                ':is_enabled'      => $isEnabled ? 1 : 0,
                ':coin_value'      => $coinValue,
                ':service_seconds' => $serviceSeconds,
                ':id'              => $serviceId,
            ]
        )->rowCount() > 0;
    }

    public function findById(int $serviceId): ?array
    {
        $service = $this->db->query(
            'SELECT id, is_enabled, coin_value, service_seconds FROM services WHERE id = :id LIMIT 1',
            [':id' => $serviceId]
        )->fetch();

        return $service ?: null;
    }

    public function belongsToPrimaryMachine(int $serviceId, ?int $ownerId = null): bool
    {
        if ($ownerId !== null && $ownerId > 0) {
            return (bool) $this->db->query(
                'SELECT 1 FROM services s 
                 INNER JOIN machines m ON m.id = s.machine_id
                 LEFT JOIN product_keys pk ON (pk.hardware_serial = m.machine_code OR pk.hardware_serial = m.machine_key)
                 WHERE s.id = :id AND (m.owner_id = :owner_id OR pk.owner_id = :owner_id2) LIMIT 1',
                [':id' => $serviceId, ':owner_id' => $ownerId, ':owner_id2' => $ownerId]
            )->fetchColumn();
        }

        return (bool) $this->db->query(
            'SELECT 1 FROM services s INNER JOIN machines m ON m.id = s.machine_id WHERE s.id = :id LIMIT 1',
            [':id' => $serviceId]
        )->fetchColumn();
    }
}
