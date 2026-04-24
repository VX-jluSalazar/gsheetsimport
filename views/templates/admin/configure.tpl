<div class="panel">
  {$form_html nofilter}
</div>

<div class="panel">
  <h3>{l s='Products synchronization' mod='gsheetsimport'}</h3>

  <div class="row">
    <div class="col-lg-3">
      <div class="alert alert-info">
        <strong>{l s='Total in staging' mod='gsheetsimport'}:</strong>
        <span id="gs-total">{$product_summary.total|intval}</span>
      </div>
    </div>
    <div class="col-lg-3">
      <div class="alert alert-success">
        <strong>{l s='Synchronized' mod='gsheetsimport'}:</strong>
        <span id="gs-success">{$product_summary.success|intval}</span>
      </div>
    </div>
    <div class="col-lg-3">
      <div class="alert alert-warning">
        <strong>{l s='Pending' mod='gsheetsimport'}:</strong>
        <span id="gs-pending">{$product_summary.pending|intval}</span>
      </div>
    </div>
    <div class="col-lg-3">
      <div class="alert alert-danger">
        <strong>{l s='Errors' mod='gsheetsimport'}:</strong>
        <span id="gs-error">{$product_summary.error|intval}</span>
      </div>
    </div>
  </div>

  <p>
    <button type="button" class="btn btn-primary" id="gs-fetch-btn" data-url="{$ajax_url|escape:'htmlall':'UTF-8'}">
      {$fetch_label|escape:'htmlall':'UTF-8'}
    </button>
    <button type="button" class="btn btn-success" id="gs-process-btn" data-url="{$ajax_url|escape:'htmlall':'UTF-8'}">
      {$process_label|escape:'htmlall':'UTF-8'}
    </button>
    <button type="button" class="btn btn-default" id="gs-push-btn" data-url="{$ajax_url|escape:'htmlall':'UTF-8'}">
      {$export_label|escape:'htmlall':'UTF-8'}
    </button>
  </p>

  <p class="help-block">
    {l s='PrestaShop to Sheets staging' mod='gsheetsimport'}:
    {l s='total' mod='gsheetsimport'} {$export_summary.total|intval},
    {l s='pending' mod='gsheetsimport'} {$export_summary.pending|intval},
    {l s='errors' mod='gsheetsimport'} {$export_summary.error|intval}
  </p>

  <h4>{l s='Cron links' mod='gsheetsimport'}</h4>
  <table class="table">
    <thead>
      <tr>
        <th>{l s='Action' mod='gsheetsimport'}</th>
        <th>{l s='URL' mod='gsheetsimport'}</th>
      </tr>
    </thead>
    <tbody>
      {foreach from=$cron_links item=cron_link}
        <tr>
          <td>{$cron_link.label|escape:'htmlall':'UTF-8'}</td>
          <td><code>{$cron_link.url|escape:'htmlall':'UTF-8'}</code></td>
        </tr>
      {/foreach}
    </tbody>
  </table>

  <div class="progress">
    <div id="gs-progress-bar" class="progress-bar progress-bar-striped active" role="progressbar" style="width: 0%;">0%</div>
  </div>

  <div id="gs-status-message" class="alert alert-info" style="margin-top: 15px;">
    {l s='Ready to synchronize products.' mod='gsheetsimport'}
  </div>

  <div class="form-inline" style="margin-top: 15px; margin-bottom: 10px;">
    <label for="gs-product-filter" style="margin-right: 8px;">{l s='Filter' mod='gsheetsimport'}</label>
    <select class="form-control" id="gs-product-filter">
      <option value="all">{l s='All' mod='gsheetsimport'}</option>
      <option value="pending">{l s='Pending' mod='gsheetsimport'}</option>
      <option value="synchronized">{l s='Synchronized' mod='gsheetsimport'}</option>
    </select>
  </div>

  <table class="table" id="gs-products-table">
    <thead>
      <tr>
        <th>{l s='Reference' mod='gsheetsimport'}</th>
        <th>{l s='Row' mod='gsheetsimport'}</th>
        <th>{l s='Status' mod='gsheetsimport'}</th>
        <th>{l s='Pending' mod='gsheetsimport'}</th>
        <th>{l s='Error' mod='gsheetsimport'}</th>
        <th>{l s='Updated at' mod='gsheetsimport'}</th>
      </tr>
    </thead>
    <tbody>
      {if $product_rows}
        {foreach from=$product_rows item=row}
          <tr data-filter-status="{if $row.needs_update|intval === 1}pending{elseif $row.status === 'success'}synchronized{else}all{/if}">
            <td>{$row.reference|escape:'htmlall':'UTF-8'}</td>
            <td>{$row.row_number|intval}</td>
            <td>{$row.status|escape:'htmlall':'UTF-8'}</td>
            <td>{if $row.needs_update|intval === 1}{l s='Yes' mod='gsheetsimport'}{else}{l s='No' mod='gsheetsimport'}{/if}</td>
            <td>{$row.error_message|escape:'htmlall':'UTF-8'}</td>
            <td>{$row.updated_at|escape:'htmlall':'UTF-8'}</td>
          </tr>
        {/foreach}
      {else}
        <tr>
          <td colspan="6">{l s='No staging records found.' mod='gsheetsimport'}</td>
        </tr>
      {/if}
    </tbody>
  </table>

  <h4>{l s='Latest validation errors' mod='gsheetsimport'}</h4>
  <table class="table">
    <thead>
      <tr>
        <th>{l s='Reference' mod='gsheetsimport'}</th>
        <th>{l s='Row' mod='gsheetsimport'}</th>
        <th>{l s='Error' mod='gsheetsimport'}</th>
        <th>{l s='Updated at' mod='gsheetsimport'}</th>
      </tr>
    </thead>
    <tbody>
      {if $product_errors}
        {foreach from=$product_errors item=row}
          <tr>
            <td>{$row.reference|escape:'htmlall':'UTF-8'}</td>
            <td>{$row.row_number|intval}</td>
            <td>{$row.error_message|escape:'htmlall':'UTF-8'}</td>
            <td>{$row.updated_at|escape:'htmlall':'UTF-8'}</td>
          </tr>
        {/foreach}
      {else}
        <tr>
          <td colspan="4">{l s='No errors found.' mod='gsheetsimport'}</td>
        </tr>
      {/if}
    </tbody>
  </table>
</div>
