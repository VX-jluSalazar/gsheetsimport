document.addEventListener('DOMContentLoaded', function () {
  const fetchButton = document.getElementById('gs-fetch-btn');
  const processButton = document.getElementById('gs-process-btn');
  const progressBar = document.getElementById('gs-progress-bar');
  const statusMessage = document.getElementById('gs-status-message');

  let totalRows = parseInt(document.getElementById('gs-total')?.textContent || '0', 10);

  function setStatus(message, type) {
    statusMessage.className = 'alert alert-' + type;
    statusMessage.textContent = message;
  }

  function updateSummary(summary) {
    document.getElementById('gs-total').textContent = summary.total;
    document.getElementById('gs-success').textContent = summary.success;
    document.getElementById('gs-pending').textContent = summary.pending;
    document.getElementById('gs-error').textContent = summary.error;
  }

  function updateProgress(summary) {
    const total = parseInt(summary.total || 0, 10);
    const pending = parseInt(summary.pending || 0, 10);
    const done = total - pending;
    const percentage = total > 0 ? Math.round((done / total) * 100) : 0;

    progressBar.style.width = percentage + '%';
    progressBar.textContent = percentage + '%';
  }

  async function callAjax(url, action) {
    const response = await fetch(url + '&ajax=1&action=' + action, {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    });

    const json = await response.json();

    if (!response.ok || !json.success) {
      throw new Error(json.message || 'Unexpected AJAX error.');
    }

    return json.data;
  }

  async function processLoop(url) {
    const result = await callAjax(url, 'ProcessBatch');

    updateSummary(result.summary);
    updateProgress(result.summary);

    if (result.pending > 0) {
      setStatus('Processing products... Pending: ' + result.pending, 'info');
      return processLoop(url);
    }

    setStatus('Synchronization completed.', 'success');
    window.location.reload();
  }

  if (fetchButton) {
    fetchButton.addEventListener('click', async function () {
      try {
        const url = fetchButton.dataset.url;
        setStatus('Loading rows from Google Sheets...', 'info');
        const result = await callAjax(url, 'FetchSheet');

        totalRows = result.total_rows || 0;
        updateSummary(result.summary);
        updateProgress(result.summary);

        setStatus('Rows loaded into staging successfully: ' + totalRows, 'success');
      } catch (error) {
        setStatus(error.message, 'danger');
      }
    });
  }

  if (processButton) {
    processButton.addEventListener('click', async function () {
      try {
        const url = processButton.dataset.url;
        setStatus('Starting batch synchronization...', 'info');
        await processLoop(url);
      } catch (error) {
        setStatus(error.message, 'danger');
      }
    });
  }
});
