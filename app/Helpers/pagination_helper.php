<?php

declare(strict_types=1);

const VC_TABLE_PER_PAGE = 8;

function vcPaginationState(int $perPage = VC_TABLE_PER_PAGE, string $param = 'pg'): array
{
    $page = max(1, (int) ($_GET[$param] ?? 1));

    return [
        'param' => $param,
        'page' => $page,
        'per_page' => $perPage,
        'limit' => $perPage,
        'offset' => ($page - 1) * $perPage,
    ];
}

function vcPaginationTotalPages(int $totalRows, int $perPage = VC_TABLE_PER_PAGE): int
{
    if ($totalRows <= 0) {
        return 1;
    }

    return max(1, (int) ceil($totalRows / $perPage));
}

function vcPaginationQuery(array $overrides = [], string $param = 'pg'): string
{
    $params = $_GET;
    unset($params['page']);

    foreach ($overrides as $key => $value) {
        if ($value === '' || $value === null) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }

    $query = http_build_query($params);

    return $query === '' ? '?' : '?' . $query;
}

function vcPaginationLink(int $pageNum, string $param = 'pg', array $overrides = []): string
{
    $overrides[$param] = $pageNum;

    return vcPaginationQuery($overrides, $param);
}

function vcRenderPagination(int $currentPage, int $totalPages, string $param = 'pg'): void
{
    if ($totalPages <= 1) {
        return;
    }

    $pagination_page = $currentPage;
    $pagination_total_pages = $totalPages;
    $pagination_param = $param;

    include VC_VIEWS . '/partials/pagination.php';
}

/**
 * عدّ صفوف استعلام بسيط: SELECT COUNT(*) FROM ...
 */
function vcPaginationCount(VcDb $conn, string $fromWhereSql, array $params = [], string $types = ''): int
{
    $sql = 'SELECT COUNT(*) AS c ' . $fromWhereSql;
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }

    if ($params !== []) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $count = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    return $count;
}

/**
 * عدّ صفوف استعلام مجمّع (GROUP BY) عبر subquery.
 */
function vcPaginationCountGrouped(VcDb $conn, string $innerSelectFromWhereGroup, array $params = [], string $types = ''): int
{
    $sql = 'SELECT COUNT(*) AS c FROM (' . $innerSelectFromWhereGroup . ') vc_pg_wrap';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }

    if ($params !== []) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $count = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    return $count;
}

/**
 * @return array{0: array<int, mixed>, 1: string}
 */
function vcPaginationBindLimit(array $params, string $types, int $limit, int $offset): array
{
    $params[] = $limit;
    $params[] = $offset;

    return [$params, $types . 'ii'];
}
