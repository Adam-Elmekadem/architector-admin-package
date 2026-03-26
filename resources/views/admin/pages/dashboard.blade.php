@extends('admin.layout.app')

@section('content')
    <div
        id="dashboard-config"
        data-api-endpoint="{{ config('admin_dashboard.api_endpoint', '') }}"
        data-api-token="{{ config('admin_dashboard.api_token', '') }}"
        data-selected-entity="{{ request()->route('entity') ?? '' }}"
        class="hidden"
    ></div>

    <h1 class="mb-2 text-4xl font-black tracking-tight text-slate-800 md:text-5xl">Dashboard</h1>
    <p id="api-status" class="mb-4 text-xs font-semibold text-slate-500">API: waiting</p>
    <section class="mb-4 rounded-2xl border border-white/70 bg-white/85 p-3 shadow-sm shadow-slate-900/5">
        <div class="mb-2 flex items-center justify-between">
            <h2 class="text-xs font-bold uppercase tracking-wide text-slate-500">Detected Entities</h2>
            <span class="text-xs font-semibold text-slate-700">From migrations</span>
        </div>
        <div class="flex flex-wrap gap-2">
        <span class="rounded-full border border-slate-200 bg-white/80 px-3 py-1 text-[11px] font-semibold text-slate-700">Courses</span>
        <span class="rounded-full border border-slate-200 bg-white/80 px-3 py-1 text-[11px] font-semibold text-slate-700">Stagiaires</span>
        <span class="rounded-full border border-slate-200 bg-white/80 px-3 py-1 text-[11px] font-semibold text-slate-700">Users</span>
        </div>
    </section>

    <div class="grid min-w-0 gap-4">
        <section class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
    <article class="h-36 rounded-xl border border-white/70 bg-white/85 p-4 shadow-sm shadow-slate-900/5">
        <p class="text-[10px] uppercase text-slate-500">Records</p>
        <p id="stat-records" class="text-3xl font-black">0</p>
    </article>
    <article class="h-36 rounded-xl border border-white/70 bg-white/85 p-4 shadow-sm shadow-slate-900/5">
        <p class="text-[10px] uppercase text-slate-500">Fields</p>
        <p id="stat-fields" class="text-3xl font-black">0</p>
    </article>
    <article class="h-36 rounded-xl border border-white/70 bg-white/85 p-4 shadow-sm shadow-slate-900/5">
        <p class="text-[10px] uppercase text-slate-500">Numeric Fields</p>
        <p id="stat-numeric" class="text-3xl font-black">0</p>
    </article>
    <article class="h-36 rounded-xl border border-white/70 bg-white/85 p-4 shadow-sm shadow-slate-900/5">
        <p class="text-[10px] uppercase text-slate-500">Endpoint</p>
        <p id="stat-endpoint" class="truncate text-sm font-bold text-slate-700">N/A</p>
    </article>
</section>
        

        <section class="min-w-0 space-y-4">
            <article class="rounded-2xl border border-white/70 bg-white/85 p-4 shadow-sm shadow-slate-900/5">
    <div class="mb-2 flex items-center justify-between">
        <h2 class="text-sm font-bold">All Records</h2>
        <a href="#" id="record-count" class="text-xs font-semibold text-slate-700"></a>
    </div>

    <div class="max-w-full overflow-x-auto">
        <table class="w-max min-w-max text-left text-sm">
            <thead id="data-columns" class="text-[9px] uppercase tracking-wide text-slate-500"></thead>
            <tbody id="data-rows" class="text-sm font-medium text-slate-700"></tbody>
        </table>
    </div>
</article>
            <article class="rounded-2xl bg-gradient-to-br from-slate-700 to-slate-900 p-5 text-white shadow-2xl shadow-slate-900/25">
    <p class="text-[11px] font-semibold tracking-widest text-cyan-100">SMART NOTE</p>
    <h3 class="mt-2 max-w-[18ch] text-3xl font-black leading-tight">Dashboard adapts automatically to your API schema.</h3>
    <a href="#" class="mt-4 inline-flex rounded-full bg-slate-100 px-4 py-2 text-xs font-bold text-slate-800">Refresh source</a>
</article>
        </section>
    </div>

    <script>
        (function () {
            const defaultEndpoint = "";
            const defaultToken = '';
            const configEl = document.getElementById('dashboard-config');
            const configuredEndpoint = configEl ? (configEl.dataset.apiEndpoint || '') : '';
            const configuredToken = configEl ? (configEl.dataset.apiToken || '') : '';
            const endpoint = configuredEndpoint || defaultEndpoint;
            const token = configuredToken || defaultToken;
            const crudEnabled = false;
            const runtimeCrudEnabled = crudEnabled && !configuredEndpoint;
            const entitySlugs = ["courses","stagiaires","users"];
            const selectedEntityFromRoute = configEl ? (configEl.dataset.selectedEntity || '') : '';
            const statusEl = document.getElementById('api-status');
            let editId = null;

            const selectedEntity = (selectedEntityFromRoute && entitySlugs.includes(selectedEntityFromRoute))
                ? selectedEntityFromRoute
                : (entitySlugs[0] || 'records');

            function crudRecordsEndpoint() {
                return endpoint + '/' + selectedEntity + '/records';
            }

            function apiRecordsEndpoint() {
                if (!endpoint) {
                    return '';
                }

                if (endpoint.includes('{entity}')) {
                    return endpoint.replace('{entity}', selectedEntity);
                }

                if (/\/records\/?$/i.test(endpoint)) {
                    return endpoint;
                }

                return endpoint.replace(/\/+$/, '') + '/' + selectedEntity + '/records';
            }

            function setText(id, value) {
                const node = document.getElementById(id);
                if (!node) {
                    return;
                }
                node.textContent = value;
            }

            function asText(value) {
                if (value === null || value === undefined) {
                    return '-';
                }

                if (typeof value === 'object') {
                    try {
                        const stringified = JSON.stringify(value);
                        return stringified.length > 48 ? stringified.slice(0, 45) + '...' : stringified;
                    } catch (error) {
                        return '[object]';
                    }
                }

                return String(value);
            }

            function normalizePayload(payload) {
                if (Array.isArray(payload)) {
                    return { records: payload, root: {} };
                }

                if (payload && typeof payload === 'object') {
                    if (Array.isArray(payload.data)) {
                        return { records: payload.data, root: payload };
                    }

                    const candidateKeys = ['items', 'results', 'rows', 'users', 'records'];
                    for (const key of candidateKeys) {
                        if (Array.isArray(payload[key])) {
                            return { records: payload[key], root: payload };
                        }
                    }

                    return { records: [payload], root: payload };
                }

                return { records: [], root: {} };
            }

            function renderKeyValues(record) {
                const grid = document.getElementById('kv-grid');
                if (!grid) {
                    return;
                }

                const entries = Object.entries(record || {}).filter(function (pair) {
                    return typeof pair[1] !== 'object' || pair[1] === null;
                }).slice(0, 6);

                if (entries.length === 0) {
                    grid.innerHTML = '<div class="rounded-lg border border-slate-200 bg-white/70 p-2 text-xs text-slate-500">No data found.</div>';
                    return;
                }

                grid.innerHTML = entries.map(function (pair) {
                    const key = pair[0];
                    const value = pair[1];

                    return '<div class="rounded-lg border border-slate-200 bg-white/70 p-2">' +
                        '<p class="text-[9px] uppercase text-slate-500">' + key + '</p>' +
                        '<p class="mt-0.5 text-xs font-semibold text-slate-700 truncate">' + asText(value) + '</p>' +
                    '</div>';
                }).join('');

            }

            function renderNestedTables(record) {
                const container = document.getElementById('nested-tables-container');
                if (!container) return;

                const nestedObjects = Object.entries(record || {}).filter(function (pair) {
                    return pair[1] !== null && typeof pair[1] === 'object' && !Array.isArray(pair[1]);
                });

                container.innerHTML = nestedObjects.map(function (pair) {
                    const key = pair[0];
                    const obj = pair[1];
                    const entries = Object.entries(obj).slice(0, 10);

                    return '<article class="rounded-lg border border-slate-200 bg-white/80 p-3 shadow-sm">' +
                        '<h3 class="mb-2 text-sm font-bold text-slate-800">' + key + ' (Nested)</h3>' +
                        '<div class="grid gap-1.5 sm:grid-cols-2">' +
                        entries.map(function (entry) {
                            return '<div class="rounded border border-slate-100 bg-slate-50 p-2">' +
                                '<p class="text-[8px] uppercase text-slate-500">' + entry[0] + '</p>' +
                                '<p class="mt-0.5 text-xs font-semibold text-slate-700 truncate">' + asText(entry[1]) + '</p>' +
                            '</div>';
                        }).join('') +
                        '</div>' +
                    '</article>';
                }).join('');
            }


            function flattenObject(obj, prefix = '') {
                const result = {};
                Object.entries(obj || {}).forEach(function (pair) {
                    const key = pair[0];
                    const value = pair[1];
                    const fullKey = prefix ? prefix + '_' + key : key;

                    if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
                        Object.assign(result, flattenObject(value, fullKey));
                    } else {
                        result[fullKey] = value;
                    }
                });
                return result;
            }

            function renderTable(records) {
                const thead = document.getElementById('data-columns');
                const tbody = document.getElementById('data-rows');
                const recordCountEl = document.getElementById('record-count');

                if (!thead || !tbody) {
                    return;
                }

                if (!Array.isArray(records) || records.length === 0) {
                    thead.innerHTML = '<th class="px-2 py-1.5">Data</th>';
                    tbody.innerHTML = '<tr><td class="px-2 py-2 text-xs text-slate-500">No records found in API response.</td></tr>';
                    if (recordCountEl) recordCountEl.textContent = 'No data';
                    return;
                }

                if (recordCountEl) recordCountEl.textContent = records.length + ' rows';

                const first = records[0] && typeof records[0] === 'object' ? records[0] : { value: records[0] };
                const flattened = flattenObject(first);
                const columns = Object.keys(flattened);
                const safeColumns = columns.length > 0 ? columns : ['value'];

                thead.innerHTML = safeColumns.map(function (col) {
                    return '<th class="px-2 py-1.5 font-semibold whitespace-nowrap">' + col + '</th>';
                }).join('');

                tbody.innerHTML = records.slice(0, 10).map(function (row) {
                    const item = (row && typeof row === 'object') ? row : { value: row };
                    const flatItem = flattenObject(item);
                    const tds = safeColumns.map(function (col) {
                        const cellValue = flatItem[col];
                        return '<td class="px-2 py-1.5 whitespace-nowrap text-sm">' + asText(cellValue) + '</td>';
                    }).join('');

                    return '<tr class="border-t border-slate-100 hover:bg-slate-50/50">' + tds + '</tr>';
                }).join('');
            }

            function renderCrudTable(records) {
                const thead = document.getElementById('data-columns');
                const tbody = document.getElementById('data-rows');
                const recordCountEl = document.getElementById('record-count');

                if (!thead || !tbody) {
                    return;
                }

                const safeRecords = Array.isArray(records) ? records : [];

                if (safeRecords.length === 0) {
                    thead.innerHTML = '<th class="px-2 py-1.5">Data</th>';
                    tbody.innerHTML = '<tr><td class="px-2 py-2 text-xs text-slate-500">No records yet. Add one using the form above.</td></tr>';
                    if (recordCountEl) {
                        recordCountEl.textContent = '0 rows';
                    }
                    return;
                }

                const first = safeRecords[0] && typeof safeRecords[0] === 'object' ? safeRecords[0] : { value: safeRecords[0] };
                const columns = Object.keys(flattenObject(first));
                const safeColumns = columns.length > 0 ? columns : ['value'];

                thead.innerHTML = safeColumns.map(function (col) {
                    return '<th class="px-2 py-1.5 font-semibold whitespace-nowrap">' + col + '</th>';
                }).join('') + '<th class="px-2 py-1.5 font-semibold whitespace-nowrap">Actions</th>';

                tbody.innerHTML = safeRecords.map(function (row) {
                    const item = (row && typeof row === 'object') ? row : { value: row };
                    const flatItem = flattenObject(item);
                    const cells = safeColumns.map(function (col) {
                        return '<td class="px-2 py-1.5 whitespace-nowrap text-sm">' + asText(flatItem[col]) + '</td>';
                    }).join('');

                    const rowId = Number(row && row.id);

                    const actions = '<td class="px-2 py-1.5 whitespace-nowrap text-xs">' +
                        '<button type="button" class="mr-2 rounded-md bg-slate-100 px-2 py-1 font-semibold text-slate-700 hover:bg-slate-200" data-crud-edit="' + rowId + '">Edit</button>' +
                        '<button type="button" class="rounded-md bg-rose-100 px-2 py-1 font-semibold text-rose-700 hover:bg-rose-200" data-crud-delete="' + rowId + '">Delete</button>' +
                    '</td>';

                    return '<tr class="border-t border-slate-100 hover:bg-slate-50/50">' + cells + actions + '</tr>';
                }).join('');

                if (recordCountEl) {
                    recordCountEl.textContent = safeRecords.length + ' rows';
                }
            }

            function bindCrudActions(records) {
                const tbody = document.getElementById('data-rows');
                if (!tbody) {
                    return;
                }

                tbody.querySelectorAll('[data-crud-edit]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        const id = Number(btn.getAttribute('data-crud-edit'));
                        const row = records.find(function (item) {
                            return Number(item.id) === id;
                        }) || {};
                        const nameInput = document.getElementById('crud-name');
                        const emailInput = document.getElementById('crud-email');
                        const phoneInput = document.getElementById('crud-phone');
                        const statusInput = document.getElementById('crud-status');
                        const submitLabel = document.getElementById('crud-submit-label');

                        if (nameInput) nameInput.value = row.name || '';
                        if (emailInput) emailInput.value = row.email || '';
                        if (phoneInput) phoneInput.value = row.phone || '';
                        if (statusInput) statusInput.value = row.status || '';
                        if (submitLabel) submitLabel.textContent = 'Update record';

                        editId = id;
                    });
                });

                tbody.querySelectorAll('[data-crud-delete]').forEach(function (btn) {
                    btn.addEventListener('click', async function () {
                        const id = Number(btn.getAttribute('data-crud-delete'));

                        try {
                            const response = await fetch(crudRecordsEndpoint() + '/' + id, {
                                method: 'DELETE',
                                headers: { 'Accept': 'application/json' },
                            });

                            if (!response.ok) {
                                throw new Error('HTTP ' + response.status);
                            }

                            await refreshCrud();
                            if (statusEl) {
                                statusEl.textContent = 'CRUD: record deleted';
                            }
                        } catch (error) {
                            if (statusEl) {
                                statusEl.textContent = 'CRUD error: ' + error.message;
                            }
                        }
                    });
                });
            }

            function normalizeCrudPayload(payload) {
                if (Array.isArray(payload)) {
                    return payload;
                }

                if (payload && typeof payload === 'object') {
                    if (Array.isArray(payload.data)) {
                        return payload.data;
                    }
                    if (Array.isArray(payload.records)) {
                        return payload.records;
                    }
                }

                return [];
            }

            async function refreshCrud() {
                const response = await fetch(crudRecordsEndpoint(), { headers: { 'Accept': 'application/json' } });
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                const payload = await response.json();
                const records = normalizeCrudPayload(payload);
                renderSummary(records);
                renderCrudTable(records);
                bindCrudActions(records);

                return records;
            }

            function initCrudMode() {
                const panel = document.getElementById('crud-panel');
                if (!panel) {
                    renderSummary([]);
                    renderTable([]);
                    return;
                }

                const form = document.getElementById('crud-form');
                const resetBtn = document.getElementById('crud-reset');
                const submitLabel = document.getElementById('crud-submit-label');

                function clearForm() {
                    if (!form) {
                        return;
                    }
                    form.reset();
                    editId = null;
                    if (submitLabel) {
                        submitLabel.textContent = 'Add record';
                    }
                }

                if (form) {
                    form.addEventListener('submit', async function (event) {
                        event.preventDefault();
                        const nameInput = document.getElementById('crud-name');
                        const emailInput = document.getElementById('crud-email');
                        const phoneInput = document.getElementById('crud-phone');
                        const statusInput = document.getElementById('crud-status');

                        const payload = {
                            name: nameInput ? nameInput.value.trim() : '',
                            email: emailInput ? emailInput.value.trim() : '',
                            phone: phoneInput ? phoneInput.value.trim() : '',
                            status: statusInput ? statusInput.value.trim() : '',
                        };

                        if (!payload.name || !payload.email) {
                            if (statusEl) {
                                statusEl.textContent = 'CRUD: Name and Email are required.';
                            }
                            return;
                        }

                        try {
                            const method = editId === null ? 'POST' : 'PUT';
                            const target = editId === null ? crudRecordsEndpoint() : (crudRecordsEndpoint() + '/' + editId);
                            const response = await fetch(target, {
                                method: method,
                                headers: {
                                    'Accept': 'application/json',
                                    'Content-Type': 'application/json',
                                },
                                body: JSON.stringify(payload),
                            });

                            if (!response.ok) {
                                throw new Error('HTTP ' + response.status);
                            }

                            const records = await refreshCrud();
                            clearForm();

                            if (statusEl) {
                                statusEl.textContent = 'CRUD: record saved (' + records.length + ' records)';
                            }
                        } catch (error) {
                            if (statusEl) {
                                statusEl.textContent = 'CRUD error: ' + error.message;
                            }
                        }
                    });
                }

                if (resetBtn) {
                    resetBtn.addEventListener('click', function () {
                        clearForm();
                    });
                }

                refreshCrud()
                    .then(function (records) {
                        if (statusEl) {
                            statusEl.textContent = 'CRUD: ' + selectedEntity + ' mode enabled (' + records.length + ' records)';
                        }
                    })
                    .catch(function (error) {
                        if (statusEl) {
                            statusEl.textContent = 'CRUD error: ' + error.message;
                        }
                    });
            }

            function renderSummary(records) {
                const first = records[0] && typeof records[0] === 'object' ? records[0] : {};
                const fields = Object.keys(first);
                const numericFields = fields.filter(function (key) {
                    return Number.isFinite(Number(first[key]));
                });

                setText('stat-records', String(records.length));
                setText('stat-fields', String(fields.length));
                setText('stat-numeric', String(numericFields.length));

                try {
                    setText('stat-endpoint', endpoint ? new URL(endpoint).host : 'N/A');
                } catch (error) {
                    setText('stat-endpoint', endpoint || 'N/A');
                }
            }

            async function loadDashboardData() {
                if (runtimeCrudEnabled) {
                    initCrudMode();
                    return;
                }

                const apiEndpoint = apiRecordsEndpoint();

                if (!apiEndpoint) {

                    if (statusEl) {
                        statusEl.textContent = 'API: disabled (no URL provided)';
                    }

                    renderSummary([]);
                    renderKeyValues({});
                    renderTable([]);
                    return;
                }

                if (statusEl) {
                    statusEl.textContent = 'API: loading data...';
                }

                try {
                    const headers = { 'Accept': 'application/json' };
                    if (token) {
                        headers['Authorization'] = 'Bearer ' + token;
                    }

                    const response = await fetch(apiEndpoint, { headers: headers });

                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }

                    const payload = await response.json();
                    const normalized = normalizePayload(payload);
                    const records = Array.isArray(normalized.records) ? normalized.records : [];
                    const firstRecord = records[0] && typeof records[0] === 'object' ? records[0] : {};

                    renderSummary(records);
                    renderKeyValues(firstRecord);
                    renderTable(records);

                    if (statusEl) {
                        statusEl.textContent = 'API: data loaded (' + records.length + ' records)';
                    }
                } catch (error) {
                    if (statusEl) {
                        statusEl.textContent = 'API error: ' + error.message;
                    }

                    renderSummary([]);
                    renderKeyValues({});
                    renderTable([]);
                }
            }

            loadDashboardData();
        })();
    </script>
@endsection