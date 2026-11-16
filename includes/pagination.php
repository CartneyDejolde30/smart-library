<?php
declare(strict_types=1);
/** @return array [page, pages, offset, perPage] */
function paginate(int $total, int $perPage = PAGE_SIZE): array
{
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
    return [$page, $pages, ($page - 1) * $perPage, $perPage];
}
function pager(int $page, int $pages): string
{
    if ($pages <= 1) return '';
    $link = function (int $p, string $label, bool $active = false, bool $disabled = false): string {
        $q = $_GET; $q['page'] = $p;
        return '<li class="page-item' . ($active ? ' active' : '') . ($disabled ? ' disabled' : '') . '"><a class="page-link" href="?' . e(http_build_query($q)) . '">' . $label . '</a></li>';
    };
    $h = '<nav class="mt-3 no-print"><ul class="pagination pagination-sm justify-content-center flex-wrap mb-0">';
    $h .= $link(max(1, $page - 1), '&laquo;', false, $page === 1);
    for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++) $h .= $link($i, (string)$i, $i === $page);
    $h .= $link(min($pages, $page + 1), '&raquo;', false, $page === $pages);
    return $h . '</ul></nav>';
}