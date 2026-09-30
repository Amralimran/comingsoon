<?php
// /assets/php/staff_selector.php
//
// Staff directory loader for the modal selector component.
//
// By default, it builds the full staff list from staff.json.
// Callers can pre-set $staffJsonList BEFORE including this file to
// provide a filtered list (e.g., rank-based visibility, or excluding
// certain staff). The format must be:
//   [ {no, name, department, title}, ... ]

if (!isset($rootDoc)) {
    $rootDoc = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    if (!is_dir($rootDoc . '/apps') && is_dir('/volume1/web/apps')) {
        $rootDoc = '/volume1/web';
    }
}

if (!isset($staffJsonList)) {
    $staffFile = $rootDoc . '/apps/hr/staff.json';
    $staffData = file_exists($staffFile) ? json_decode(file_get_contents($staffFile), true) : [];

    $staffJsonList = [];
    if (is_array($staffData)) {
        foreach ($staffData as $profile) {
            $no    = str_pad(preg_replace('/[^0-9]/', '', $profile['no'] ?? ''), 3, '0', STR_PAD_LEFT);
            $name  = trim($profile['name'] ?? 'Unknown');
            $dept  = trim($profile['department'] ?? '-');
            $title = trim($profile['title'] ?? '-');
            if ($no) {
                $staffJsonList[] = ["no" => $no, "name" => $name, "department" => $dept, "title" => $title];
            }
        }
    }
}
?>
<!-- Reusable Staff Selector Modal Styles -->
<style>
    .staff-modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
    .staff-modal-content { background: #fff; width: 700px; max-width: 95%; padding: 20px; border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); }
    .staff-modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid #ddd; padding-bottom: 10px; }
    .staff-modal-close { background: none; border: none; font-size: 20px; cursor: pointer; color: #777; }
    .staff-modal-close:hover { color: #000; }
    .staff-select-btn { padding: 4px 10px; background: #28a745; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 12px; }
    .staff-select-btn:hover { background: #218838; }
</style>

<!-- Staff Search Modal HTML -->
<div id="staffModal" class="staff-modal-overlay">
    <div class="staff-modal-content">
        <div class="staff-modal-header">
            <h3 style="margin: 0;">Select Staff Member</h3>
            <button class="staff-modal-close" onclick="closeStaffModal()">&times;</button>
        </div>
        <p style="font-size: 12px; color: #666; margin-top: 0;">Use the filters below to search by ID, name, or department. Click the [Select] button on any row.</p>
        <div id="staff-modal-table"></div>
    </div>
</div>

<!-- Reusable Staff Selector Script Logic -->
<script>
    const globalStaffDirectory = <?php echo json_encode($staffJsonList); ?>;
    var staffModalTable = null;
    var __staffSelectCallback = null;   // current callback, updated on each open

    function openStaffModal(onSelectCallback) {
        // Always update the current callback
        __staffSelectCallback = (typeof onSelectCallback === 'function') ? onSelectCallback : null;

        document.getElementById('staffModal').style.display = 'flex';

        if (!staffModalTable) {
            staffModalTable = new Tabulator("#staff-modal-table", {
                height: "380px",
                layout: "fitColumns",
                data: globalStaffDirectory,
                pagination: "local",
                paginationSize: 10,
                paginationSizeSelector: [10, 25, 50],
                columns: [
                    {title: "ID", field: "no", width: 80, hAlign: "center", headerFilter: "input"},
                    {title: "Staff Name", field: "name", headerFilter: "input", headerFilterPlaceholder: "Search name..."},
                    {title: "Department", field: "department", headerFilter: "input", width: 130},
                    {title: "Title", field: "title", width: 130},
                    {
                        title: "Action",
                        width: 85,
                        hAlign: "center",
                        headerSort: false,
                        formatter: () => `<button class="staff-select-btn" type="button">Select</button>`,
                        cellClick: function(e, cell) {
                            let data = cell.getRow().getData();
                            triggerStaffSelection(data);
                        }
                    }
                ],
                rowClick: function(e, row) {
                    let data = row.getData();
                    triggerStaffSelection(data);
                }
            });
        } else {
            staffModalTable.redraw(true);
        }
    }

    function triggerStaffSelection(data) {
        closeStaffModal();
        if (typeof __staffSelectCallback === 'function') {
            __staffSelectCallback(data.no, data);
        } else {
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('emp', data.no);
            window.location.search = urlParams.toString();
        }
    }

    function closeStaffModal() {
        document.getElementById('staffModal').style.display = 'none';
    }

    window.addEventListener('click', function(event) {
        let modal = document.getElementById('staffModal');
        if (event.target === modal) {
            closeStaffModal();
        }
    });
</script>