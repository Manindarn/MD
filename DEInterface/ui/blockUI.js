function blockUI(options) {
    options = typeof options === 'undefined' ? {img: ASSET_URL + '/images/loading.gif', msg: 'Loading...'} : options;
    var blockDiv = document.createElement('div');
    blockDiv.id = 'blockDiv';
    blockDiv.style.cssText = 'background: rgb(51, 51, 51) none repeat scroll 0 0;color: rgb(0, 0, 0);cursor: wait;height: 100%;margin: 0;opacity: 0.5;padding: 0;position: fixed;text-align: center;top: 0;left:0;width: 100%;z-index: 99999;';
    var blockMsg = document.createElement('div');
    blockMsg.style.cssText = 'color: white;font-size: 20px;font-weight: normal;left: 25%;right: 25%;margin: 0;padding: 0;position: fixed;text-align: center;top: 40%;z-index: 999999 !important;';
    blockMsg.className = 'blockMsg';
    blockMsg.innerHTML = '<center>' + '<img src="' + ((typeof options.img !== 'undefined' && options.img !== '') ? options.img : ASSET_URL + '/images/loading.gif') + '">' + '<b>' + '<span id="loading-msg">' + ((typeof options.msg !== 'undefined' && options.msg !== '') ? options.msg : 'Loading...') + '</span>' + '</b>' + '</center>';
    blockDiv.appendChild(blockMsg);
    document.body.appendChild(blockDiv);
}

function unblockUI() {
    if (document.getElementById('blockDiv')) {
        document.getElementById('blockDiv').remove();
    }
}