<?php
// assets/php/upload_view_asset.php
/**
 * Reusable File Upload & View Asset Component
 * Switch mode: "1" (single file mode) or "multi" (multiple files allowed, appends counts)
 */
function render_upload_view_column_js($mode = 'multi') {
    return "
    function getUploadColumn() {
        if (!isAdmin && !isOwner) return [];
        return [{
            title: 'Upload', width: 75, hozAlign: 'center', headerSort: false,
            formatter: function(cell) {
                let rowData = cell.getRow().getData();
                if ((rowData.status || 'Pending') === 'Approved') {
                    return '<span style=\"color:#aaa;\" title=\"Locked when approved\">-</span>';
                }
                return '<button class=\"upload-btn\" type=\"button\">📁</button>';
            },
            cellClick: function(e, cell) {
                let row = cell.getRow();
                let rowData = row.getData();
                if ((rowData.status || 'Pending') === 'Approved') return;
                if (!rowData.id) {
                    rowData.id = 'exp' + Math.floor(1000 + Math.random() * 9000);
                    row.update(rowData);
                }
                activeRow = row;
                activeTableInstance = cell.getTable();
                let fileInput = document.getElementById('rowFileInput');
                fileInput.setAttribute('data-mode', '" . $mode . "');
                fileInput.click();
            }
        }];
    }

    function getViewColumn() {
        return [{
            title: 'View', width: 75, hozAlign: 'center', headerSort: false,
            formatter: function(cell) {
                let rowData = cell.getRow().getData();
                let rId = rowData.id;
                let count = rowDocCounts[rId] || 0;
                if (count > 0) {
                    return `<a href=\"?view_row=\${rId}&emp=\${employeeId}&month=\${activeMonth}\" style=\"color:#007bff; font-weight:bold; text-decoration:none;\">View (\${count})</a>`;
                }
                return '<span style=\"color:#aaa;\">-</span>';
            }
        }];
    }
    ";
}
?>