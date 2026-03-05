// scripts/table_sort.js
// Uniwersalne sortowanie tabeli po kliknięciu w TH.
//
// Wymagania:
// - tabela ma id, np. id="devicesTable"
// - sortowalne TH mają atrybuty:
//     data-sort-col="N"  (numer kolumny w wierszu, 0-based)
//     data-sort-type="text|number|ip" (opcjonalnie, domyślnie text)
// - w TH może być <span class="sort-indicator"></span> na strzałki
//
// Użycie:
// - dodaj w TH: onclick="tableSort('devicesTable', this)"
// - opcjonalnie: domyślny sort:
//     <table id="devicesTable" data-default-sort-col="2" data-default-sort-asc="1">

(function () {
  function ipToInt(ip) {
    const parts = String(ip).trim().split('.');
    if (parts.length !== 4) return null;
    let n = 0;
    for (let i = 0; i < 4; i++) {
      const p = parts[i];
      if (!/^\d+$/.test(p)) return null;
      const v = parseInt(p, 10);
      if (v < 0 || v > 255) return null;
      n = (n * 256) + v;
    }
    return n;
  }

  function getCellText(tr, colIndex) {
    const td = tr.cells[colIndex];
    if (!td) return '';
    return (td.innerText || td.textContent || '').trim();
  }

  function clearIndicators(table) {
    Array.from(table.querySelectorAll("th")).forEach(th => {
      const span = th.querySelector(".sort-indicator");
      if (span) span.innerHTML = '';
    });
  }

  function setIndicator(th, asc) {
    const span = th.querySelector(".sort-indicator");
    if (!span) return;
    span.innerHTML = asc ? '▲' : '▼';
    span.style.color = 'black';
  }

  function sortRows(table, col, type, asc) {
    const tbody = table.tBodies[0];
    const rows = Array.from(tbody.rows);

    rows.sort((a, b) => {
      let x = getCellText(a, col);
      let y = getCellText(b, col);

      if (type === 'number') {
        const nx = parseFloat(x.replace(',', '.'));
        const ny = parseFloat(y.replace(',', '.'));
        const ax = isNaN(nx) ? null : nx;
        const ay = isNaN(ny) ? null : ny;

        // puste/nie-liczby na dół
        if (ax === null && ay === null) return 0;
        if (ax === null) return 1;
        if (ay === null) return -1;
        return (ax > ay ? 1 : ax < ay ? -1 : 0) * (asc ? 1 : -1);
      }

      if (type === 'ip') {
        const ix = ipToInt(x);
        const iy = ipToInt(y);

        if (ix === null && iy === null) return 0;
        if (ix === null) return 1;
        if (iy === null) return -1;
        return (ix > iy ? 1 : ix < iy ? -1 : 0) * (asc ? 1 : -1);
      }

      // text
      x = x.toLowerCase();
      y = y.toLowerCase();
      return (x > y ? 1 : x < y ? -1 : 0) * (asc ? 1 : -1);
    });

    rows.forEach(r => tbody.appendChild(r));
  }

  // global: wywoływane z onclick w TH
  window.tableSort = function (tableId, th) {
    const table = document.getElementById(tableId);
    if (!table || !th) return;

    const colAttr = th.getAttribute('data-sort-col');
    if (colAttr === null) return;

    const col = parseInt(colAttr, 10);
    if (isNaN(col)) return;

    const type = th.getAttribute('data-sort-type') || 'text';

    // toggle asc/desc per TH
    const asc = th._asc !== undefined ? !th._asc : true;
    th._asc = asc;

    sortRows(table, col, type, asc);
    clearIndicators(table);
    setIndicator(th, asc);
  };

  // auto-default sort on DOM ready
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('table[data-default-sort-col]').forEach(table => {
      const tableId = table.getAttribute('id');
      if (!tableId) return;

      const col = parseInt(table.getAttribute('data-default-sort-col') || '', 10);
      const asc = (table.getAttribute('data-default-sort-asc') || '1') === '1';

      if (isNaN(col)) return;

      // znajdź TH z takim col
      const th = table.querySelector(`th[data-sort-col="${col}"]`);
      if (!th) return;

      // ustaw kierunek domyślny bez “toggle”: w tableSort jest toggle, więc ustawiamy stan przed
      th._asc = !asc; // żeby pierwsze wywołanie dało asc
      window.tableSort(tableId, th);
    });
  });
})();