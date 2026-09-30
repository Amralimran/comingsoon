// /assets/js/dialog.js (Dynamic X, Y Dimension Version)
function showModalDialog(title, contentHtml, buttons = [], onAction = null, align = "center", dimensions = "440px, auto") {
    let existingModal = document.getElementById('globalPortalModal');
    if (existingModal) existingModal.remove();

    if (!buttons || buttons.length === 0) {
        buttons = [{ text: "OK", type: "success", action: "ok" }];
    }

    let buttonsHtml = '';
    buttons.forEach((btn, index) => {
        let btnBg = "rgba(0, 123, 255, 0.85)";
        let btnHoverBg = "rgba(0, 123, 255, 1)";
        if (btn.type === "danger" || btn.type === "delete") { btnBg = "rgba(220, 53, 69, 0.85)"; btnHoverBg = "rgba(220, 53, 69, 1)"; }
        if (btn.type === "success") { btnBg = "rgba(40, 167, 69, 0.85)"; btnHoverBg = "rgba(40, 167, 69, 1)"; }
        if (btn.type === "warning") { btnBg = "rgba(255, 193, 7, 0.85)"; btnHoverBg = "rgba(255, 193, 7, 1)"; }
        let btnColor = (btn.type === "warning") ? "#222" : "#fff";

        buttonsHtml += `<button type="button" id="portalModalBtn_${index}" onmouseover="this.style.background='${btnHoverBg}'; this.style.transform='translateY(-1px)';" onmouseout="this.style.background='${btnBg}'; this.style.transform='translateY(0)';" style="background: ${btnBg}; color: ${btnColor}; border: 1px solid rgba(255,255,255,0.2); padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px; backdrop-filter: blur(4px); box-shadow: 0 4px 10px rgba(0,0,0,0.1); transition: all 0.2s ease;">${btn.text}</button>`;
    });

    let justifyContent = "center";
    if (align === "left") justifyContent = "flex-start";
    if (align === "right") justifyContent = "flex-end";

    // Parse X (width) and Y (height) from dimensions string (e.g., "340px, 500px" or "flex, flex")
    let widthVal = "440px";
    let heightVal = "auto";
    if (dimensions.includes(',')) {
        let parts = dimensions.split(',').map(p => p.trim());
        widthVal = parts[0] === 'flex' ? '90%' : parts[0];
        heightVal = parts[1] === 'flex' ? '85vh' : parts[1];
    } else {
        widthVal = dimensions === 'flex' ? '90%' : dimensions;
    }

    let modalHtml = `
    <div id="globalPortalModal" style="display: flex; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.35); backdrop-filter: blur(5px); -webkit-backdrop-filter: blur(5px); justify-content: center; align-items: center; z-index: 9999;">
        <div style="background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.6); padding: 10px; border-radius: 16px; width: ${widthVal}; height: ${heightVal}; max-width: 95%; max-height: 90vh; color: #222; box-shadow: 0 10px 30px rgba(0,0,0,0.15); font-family: "segoe UI", Arial, sans-serif; display: flex; flex-direction: column; align-items: stretch; overflow-y: auto;">
            
            <!-- TOP CONTAINER: Title & Content Body -->
            <div style="width: 100%; text-align: center;">
                ${title ? `<h3 style="margin-top: 0; color: #111; font-size: 18px; margin-bottom: 15px; font-weight: 700; text-align: center;">${title}</h3>` : ''}
                <div style="font-size: 14px; color: #444; line-height: 1.5; margin-bottom: 20px; text-align: center;">${contentHtml}</div>
            </div>

            <!-- BOTTOM CONTAINER: Button Bar -->
            <div style="width: 100%; display: flex; justify-content: ${justifyContent}; gap: 10px; align-items: center; border-top: 1px solid rgba(0,0,0,0.08); padding-top: 15px; margin-top: auto;">
                ${buttonsHtml}
            </div>

        </div>
    </div>`;

    document.body.insertAdjacentHTML('beforeend', modalHtml);

    buttons.forEach((btn, index) => {
        document.getElementById(`portalModalBtn_${index}`).onclick = function() {
            let keepOpen = false;
            if (typeof onAction === 'function') {
                keepOpen = onAction(btn.action) === false;
            }
            if (!keepOpen) {
                document.getElementById('globalPortalModal').remove();
            }
        };
    });
}