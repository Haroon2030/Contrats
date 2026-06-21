<?php

if (!function_exists('vcContractEditUrl')) {
    function vcContractEditUrl(array $row): string
    {
        $id = (int)($row['id'] ?? 0);

        if (($row['source'] ?? '') === 'rent') {
            return 'rents.php?id=' . $id;
        }

        return 'add_contract.php?id=' . $id;
    }
}

if (!function_exists('vcRenderRowActions')) {
    function vcRenderRowActions(array $config, string $csrf_token, bool $is_admin): void
    {
        echo '<div class="vc-row-actions">';

        if (!empty($config['view']['href'])) {
            $label = $config['view']['label'] ?? 'عرض';
            echo '<a href="' . htmlspecialchars((string)$config['view']['href'], ENT_QUOTES, 'UTF-8') . '" class="btn btn-view vc-act vc-act-view">'
                . htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') . '</a>';
        }

        if (!empty($config['edit']['href'])) {
            $label = $config['edit']['label'] ?? 'تعديل';
            echo '<a href="' . htmlspecialchars((string)$config['edit']['href'], ENT_QUOTES, 'UTF-8') . '" class="btn btn-edit vc-act vc-act-edit">'
                . htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') . '</a>';
        }

        if ($is_admin && !empty($config['delete'])) {
            $delete = $config['delete'];
            $label = $delete['label'] ?? 'حذف';
            $confirm = $delete['confirm'] ?? 'هل أنت متأكد من الحذف؟';
            $action = (string)($delete['action'] ?? '');

            echo '<form method="POST" onsubmit="return confirm(' . json_encode($confirm, JSON_UNESCAPED_UNICODE) . ');">';
            echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') . '">';
            echo '<input type="hidden" name="action" value="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '">';

            foreach ($delete['fields'] ?? [] as $name => $value) {
                echo '<input type="hidden" name="' . htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8') . '" value="'
                    . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '">';
            }

            echo '<button type="submit" class="btn btn-delete vc-act vc-act-delete">'
                . htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') . '</button>';
            echo '</form>';
        }

        if (!empty($config['extra'])) {
            echo $config['extra'];
        }

        echo '</div>';
    }
}
