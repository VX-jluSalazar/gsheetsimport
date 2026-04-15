<div class="panel">
  {$form_html nofilter}
</div>

<div class="panel">
  <h3>{l s='Synchronization dashboard' mod='gsheetsimport'}</h3>

  <div class="row">
    <div class="col-lg-3">
      <div class="alert alert-info">
        <strong>{l s='Total rows in staging' mod='gsheetsimport'}:</strong>
        <span id="gs-total">{$summary.total|intval}</span>
      </div>
    </div>
    <div class="col-lg-3">
      <div class="alert alert-success">
        <strong>{l s='Synchronized' mod='gsheetsimport'}:</strong>
        <span id="gs-success">{$summary.success|intval}</span>
      </div>
    </div>
    <div class="col-lg-3">
      <div class="alert alert-warning">
        <strong>{l s='Pending' mod='gsheetsimport'}:</strong>
        <span id="gs-pending">{$summary.pending|intval}</span>
      </div>
    </div>
    <div class="col-lg-3">
      <div class="alert alert-danger">
        <strong>{l s='Errors' mod='gsheetsimport'}:</strong>
        <span id="gs-error">{$summary.error|intval}</span>
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
  </p>

  <div class="progress">
    <div id="gs-progress-bar" class="progress-bar progress-bar-striped active" role="progressbar" style="width: 0%;">
      0%
    </div>
  </div>

  <div id="gs-status-message" class="alert alert-info" style="margin-top: 15px;">
    {l s='Ready to synchronize.' mod='gsheetsimport'}
  </div>
</div>

<div class="panel">
  <h3>{l s='Validation errors' mod='gsheetsimport'}</h3>

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
      {if $errors}
        {foreach from=$errors item=row}
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
