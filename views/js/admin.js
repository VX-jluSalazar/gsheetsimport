document.addEventListener('DOMContentLoaded', function () {
  const fetchButton = document.getElementById('gs-fetch-btn');
  const processButton = document.getElementById('gs-process-btn');
  const pushButton = document.getElementById('gs-push-btn');
  const progressBar = document.getElementById('gs-progress-bar');
  const statusMessage = document.getElementById('gs-status-message');
  const productFilter = document.getElementById('gs-product-filter');
  const productsTable = document.getElementById('gs-products-table');

  function setStatus(message, type) {
    if (!statusMessage) {
      return;
    }
    statusMessage.className = 'alert alert-' + type;
    statusMessage.textContent = message;
  }

  function updateSummary(summary) {
    const total = document.getElementById('gs-total');
    const success = document.getElementById('gs-success');
    const pending = document.getElementById('gs-pending');
    const error = document.getElementById('gs-error');

    if (total) total.textContent = summary.total;
    if (success) success.textContent = summary.success;
    if (pending) pending.textContent = summary.pending;
    if (error) error.textContent = summary.error;
  }

  function updateProgress(summary) {
    if (!progressBar) {
      return;
    }

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

    const raw = await response.text();
    let json;

    try {
      json = JSON.parse(raw);
    } catch (error) {
      throw new Error('Invalid server response (expected JSON).');
    }

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

    setStatus('Product synchronization completed.', 'success');
    window.location.reload();
  }

  function applyProductFilter() {
    if (!productsTable || !productFilter) {
      return;
    }

    const filter = productFilter.value;
    const rows = productsTable.querySelectorAll('tbody tr[data-filter-status]');

    rows.forEach(function (row) {
      const rowStatus = row.getAttribute('data-filter-status') || 'all';
      const show = filter === 'all' || rowStatus === filter;
      row.style.display = show ? '' : 'none';
    });
  }

  if (fetchButton) {
    fetchButton.addEventListener('click', async function () {
      try {
        const url = fetchButton.dataset.url;
        setStatus('Loading products from Google Sheets...', 'info');
        const result = await callAjax(url, 'FetchSheet');

        updateSummary(result.summary);
        updateProgress(result.summary);

        setStatus('Products loaded into staging: ' + (result.total_rows || 0), 'success');
        window.location.reload();
      } catch (error) {
        setStatus(error.message, 'danger');
      }
    });
  }

  if (processButton) {
    processButton.addEventListener('click', async function () {
      try {
        const url = processButton.dataset.url;
        setStatus('Starting product create/update...', 'info');
        await processLoop(url);
      } catch (error) {
        setStatus(error.message, 'danger');
      }
    });
  }

  if (pushButton) {
    pushButton.addEventListener('click', async function () {
      try {
        const url = pushButton.dataset.url;
        setStatus('Synchronizing PrestaShop products to Google Sheets...', 'info');
        const result = await callAjax(url, 'PushSheet');

        setStatus(
          'Google Sheets updated. Updated: ' + (result.updated || 0) +
          ', appended: ' + (result.appended || 0) +
          ', errors: ' + (result.errors || 0) + '.',
          result.errors > 0 ? 'warning' : 'success'
        );
        window.location.reload();
      } catch (error) {
        setStatus(error.message, 'danger');
      }
    });
  }

  if (productFilter) {
    productFilter.addEventListener('change', applyProductFilter);
    applyProductFilter();
  }
});
