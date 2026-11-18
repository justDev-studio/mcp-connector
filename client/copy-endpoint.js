(() => {
    const endpoint = document.getElementById('mcp-connector-endpoint');
    const button = document.getElementById('mcp-connector-copy-endpoint');
    const status = document.getElementById('mcp-connector-copy-status');

    if (!endpoint || !button || !status) {
        return;
    }

    button.hidden = false;
    button.addEventListener('click', async () => {
        status.textContent = '';
        try {
            await navigator.clipboard.writeText(endpoint.textContent.trim());
            status.textContent = button.dataset.copied;
        } catch {
            status.textContent = button.dataset.copyError;
        }
    });
})();
