<?php

if (!function_exists('vcUserCanDeleteAsAdmin')) {
    function vcUserCanDeleteAsAdmin(?array $userRow): bool
    {
        if (empty($userRow)) {
            return false;
        }

        return (int)($userRow['is_admin'] ?? 0) === 1
            || ($userRow['role'] ?? '') === 'admin'
            || ($userRow['job_role'] ?? '') === 'admin';
    }
}

if (!function_exists('vcHardDeleteContractsByIds')) {
    function vcHardDeleteContractsByIds(VcDb $conn, array $cleanIds): bool
    {
        $cleanIds = array_values(array_unique(array_filter(array_map('intval', $cleanIds), fn($v) => $v > 0)));

        if (empty($cleanIds)) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
        $bindTypes = str_repeat('i', count($cleanIds));

        $conn->begin_transaction();

        try {
            foreach (['rents' => 'contract_id', 'annual_discounts' => 'contract_id', 'events' => 'contract_id', 'contract_history' => 'contract_id'] as $table => $column) {
                if (vcColumnExists($conn, $table, $column)) {
                    $stmtDel = $conn->prepare("DELETE FROM {$table} WHERE {$column} IN ({$placeholders})");
                    if ($stmtDel) {
                        $stmtDel->bind_param($bindTypes, ...$cleanIds);
                        $stmtDel->execute();
                        $stmtDel->close();
                    }
                }
            }

            $stmtMain = $conn->prepare("DELETE FROM contracts WHERE id IN ({$placeholders})");
            if ($stmtMain) {
                $stmtMain->bind_param($bindTypes, ...$cleanIds);
                $stmtMain->execute();
                $stmtMain->close();
            }

            $conn->commit();
            return true;
        } catch (Throwable $e) {
            $conn->rollback();
            return false;
        }
    }
}
