// assets/js/print_asset.js
function openCenteredPrintWindow(htmlContent, w = 900, h = 650) {
    const dualScreenLeft = window.screenLeft !== undefined ? window.screenLeft : window.screenX;
    const dualScreenTop = window.screenTop !== undefined ? window.screenTop : window.screenY;
    const width = window.innerWidth ? window.innerWidth : document.documentElement.clientWidth ? document.documentElement.clientWidth : screen.width;
    const height = window.innerHeight ? window.innerHeight : document.documentElement.clientHeight ? document.documentElement.clientHeight : screen.height;
    const left = ((width - w) / 2) + dualScreenLeft;
    const top = ((height - h) / 2) + dualScreenTop;

    let printWin = window.open('', '_blank', `scrollbars=yes, width=${w}, height=${h}, top=${top}, left=${left}`);
    printWin.document.write(htmlContent);
    printWin.document.close();
}