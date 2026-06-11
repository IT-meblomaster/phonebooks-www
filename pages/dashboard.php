<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/dashboard_phonebook.php';

$data = dashboard_phonebook_load($config,$pdo);

$errors = $data['errors'];
$rows = $data['rows'];
$columns = $data['columns'];
$labels = $data['labels'];
$sort = $data['sort'];
$dir = $data['dir'];
$officeFilter = $data['officeFilter'];
$baseParams = $data['baseParams'];
$totalCount = $data['totalCount'] ?? count($rows);
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h1 class="h3 mb-1">Książka telefoniczna</h1>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php else: ?>

    <div class="card shadow-sm border-0">
        <div class="card-body">

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div class="text-muted">
                    <strong>Filtr:</strong>
                    <?php if ($officeFilter !== null): ?>
                        <span class="badge text-bg-primary"><?= e($officeFilter) ?></span>
                    <?php else: ?>
                        <span class="badge text-bg-secondary">wszyscy</span>
                    <?php endif; ?>

                    <span class="ms-2">
                        Widocznych: <strong id="visibleCount"><?= count($rows) ?></strong> / <?= $totalCount ?>
                    </span>
                </div>

                <div>
                    <a class="btn btn-outline-secondary btn-sm" href="index.php?page=dashboard">Pokaż wszystkich</a>
                </div>
            </div>

            <div class="row g-2 align-items-center mb-3">
                <div class="col-12 col-md-7 col-lg-5">
                    <div class="input-group">
                        <span class="input-group-text">Szukaj</span>
                        <input type="text" class="form-control" id="globalSearch" autocomplete="off">
                        <button class="btn btn-outline-secondary" type="button" id="clearSearch">Wyczyść</button>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-striped table-hover align-middle" id="phonebookTable">
                    <thead class="table-light">
                    <tr>
                        <?php foreach ($columns as $c): ?>
                            <?php
                            $k = strtolower($c);
                            $newDir = dashboard_next_dir($k, $sort, $dir);
                            $q = dashboard_build_query($baseParams, ['page' => 'dashboard', 'sort' => $k, 'dir' => $newDir]);
                            $ind = dashboard_sort_indicator($k, $sort, $dir);
                            ?>
                            <th>
                                <a class="text-decoration-none text-reset" href="?<?= e($q) ?>">
                                    <?= e($labels[$k] ?? $c) ?>
                                    <?php if ($ind !== ''): ?>
                                        <span class="ms-1"><?= e($ind) ?></span>
                                    <?php endif; ?>
                                </a>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $hasPhoto = !empty($r['_photo_exists']);
                        $photoName = (string)($r['_photo_name'] ?? '');
                        $photoSrc = $hasPhoto ? ('pages/photo.php?f=' . rawurlencode($photoName)) : '';
                        $tooltipHtml = $hasPhoto
                            ? '<img src="' . e($photoSrc) . '" alt="foto">'
                            : '';
                        ?>
                        <tr>
                            <?php foreach ($columns as $c): ?>
                                <?php
                                $k = strtolower($c);
                                $val = (string)($r[$k] ?? '');
                                ?>

                                <?php if ($k === 'sn' || $k === 'givenname'): ?>
                                    <?php if ($hasPhoto): ?>
                                        <td
                                            data-bs-toggle="tooltip"
                                            data-bs-html="true"
                                            data-bs-placement="top"
                                            data-bs-custom-class="photo-tooltip"
                                            data-bs-title="<?= e($tooltipHtml) ?>"
                                        >
                                            <?= e($val) ?>
                                        </td>
                                    <?php else: ?>
                                        <td><?= e($val) ?></td>
                                    <?php endif; ?>

                                <?php elseif ($k === 'physicaldeliveryofficename'): ?>
                                    <td>
                                        <?php if ($val !== ''): ?>
                                            <a href="?<?= e(dashboard_build_query(['page' => 'dashboard'], ['office' => $val])) ?>">
                                                <?= e($val) ?>
                                            </a>
                                        <?php endif; ?>
                                    </td>

                                <?php elseif ($k === 'mail'): ?>
                                    <td>
                                        <?php if ($val !== ''): ?>
                                            <a href="mailto:<?= e($val) ?>"><?= e($val) ?></a>
                                        <?php endif; ?>
                                    </td>

                                <?php elseif ($k === 'phones'): ?>
                                    <td>
                                        <?php
                                        $phoneLines = array_filter(
                                            array_map('trim', explode("\n", $val)),
                                            fn($x) => $x !== ''
                                        );
                                        foreach ($phoneLines as $idx => $phone):
                                            if ($idx > 0) echo '<br>';
                                            echo e($phone);
                                        endforeach;
                                        ?>
                                    </td>

                                <?php else: ?>
                                    <td><?= e($val) ?></td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const table = document.getElementById('phonebookTable');
        if (!table) return;

        const rows = Array.from(table.querySelectorAll('tbody tr'));
        const visibleCount = document.getElementById('visibleCount');
        const searchInput = document.getElementById('globalSearch');
        const clearBtn = document.getElementById('clearSearch');

        const rowCache = rows.map(function (tr) {
            return (tr.textContent || '').trim().toLowerCase();
        });

        function applySearch() {
            const needle = (searchInput.value || '').trim().toLowerCase();
            let shown = 0;

            rows.forEach(function (tr, idx) {
                const hay = rowCache[idx];
                const ok = needle === '' ? true : hay.includes(needle);
                tr.style.display = ok ? '' : 'none';
                if (ok) shown++;
            });

            if (visibleCount) {
                visibleCount.textContent = String(shown);
            }
        }

        searchInput.addEventListener('input', applySearch);

        clearBtn.addEventListener('click', function () {
            searchInput.value = '';
            applySearch();
            searchInput.focus();
        });

        applySearch();

        const tooltipEls = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipEls.forEach(function (el) {
            new bootstrap.Tooltip(el, {
                html: true,
                sanitize: false,
                container: 'body',
                trigger: 'hover focus'
            });
        });
    });
    </script>

<?php endif; ?>