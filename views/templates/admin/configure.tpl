<div class="panel">
  {$form_html nofilter}
</div>

<div class="panel">
  <h3>{l s='Sincronización de productos' mod='gsheetsimport'}</h3>

  <div class="row">
    <div class="col-lg-3">
      <div class="alert alert-info">
        <strong>{l s='Total en staging' mod='gsheetsimport'}:</strong>
        <span id="gs-total">{$product_summary.total|intval}</span>
      </div>
    </div>
    <div class="col-lg-3">
      <div class="alert alert-success">
        <strong>{l s='Sincronizados' mod='gsheetsimport'}:</strong>
        <span id="gs-success">{$product_summary.success|intval}</span>
      </div>
    </div>
    <div class="col-lg-3">
      <div class="alert alert-warning">
        <strong>{l s='Pendientes' mod='gsheetsimport'}:</strong>
        <span id="gs-pending">{$product_summary.pending|intval}</span>
      </div>
    </div>
    <div class="col-lg-3">
      <div class="alert alert-danger">
        <strong>{l s='Errores' mod='gsheetsimport'}:</strong>
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
    {l s='Staging de PrestaShop hacia Sheets' mod='gsheetsimport'}:
    {l s='total' mod='gsheetsimport'} {$export_summary.total|intval},
    {l s='pendientes' mod='gsheetsimport'} {$export_summary.pending|intval},
    {l s='errores' mod='gsheetsimport'} {$export_summary.error|intval}
  </p>

  <h4>{l s='Enlaces de cron' mod='gsheetsimport'}</h4>
  <table class="table">
    <thead>
      <tr>
        <th>{l s='Acción' mod='gsheetsimport'}</th>
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
    {l s='Listo para sincronizar productos.' mod='gsheetsimport'}
  </div>

  <div class="form-inline" style="margin-top: 15px; margin-bottom: 10px;">
    <label for="gs-product-filter" style="margin-right: 8px;">{l s='Filtro' mod='gsheetsimport'}</label>
    <select class="form-control" id="gs-product-filter">
      <option value="all">{l s='Todos' mod='gsheetsimport'}</option>
      <option value="pending">{l s='Pendientes' mod='gsheetsimport'}</option>
      <option value="synchronized">{l s='Sincronizados' mod='gsheetsimport'}</option>
    </select>
  </div>

  <table class="table" id="gs-products-table">
    <thead>
      <tr>
        <th>{l s='Referencia' mod='gsheetsimport'}</th>
        <th>{l s='Fila' mod='gsheetsimport'}</th>
        <th>{l s='Estado' mod='gsheetsimport'}</th>
        <th>{l s='Pendiente' mod='gsheetsimport'}</th>
        <th>{l s='Error' mod='gsheetsimport'}</th>
        <th>{l s='Actualizado el' mod='gsheetsimport'}</th>
      </tr>
    </thead>
    <tbody>
      {if $product_rows}
        {foreach from=$product_rows item=row}
          <tr data-filter-status="{if $row.needs_update|intval === 1}pending{elseif $row.status === 'success'}synchronized{else}all{/if}">
            <td>{$row.reference|escape:'htmlall':'UTF-8'}</td>
            <td>{$row.row_number|intval}</td>
            <td>{$row.status|escape:'htmlall':'UTF-8'}</td>
            <td>{if $row.needs_update|intval === 1}{l s='Sí' mod='gsheetsimport'}{else}{l s='No' mod='gsheetsimport'}{/if}</td>
            <td>{$row.error_message|escape:'htmlall':'UTF-8'}</td>
            <td>{$row.updated_at|escape:'htmlall':'UTF-8'}</td>
          </tr>
        {/foreach}
      {else}
        <tr>
          <td colspan="6">{l s='No se encontraron registros en staging.' mod='gsheetsimport'}</td>
        </tr>
      {/if}
    </tbody>
  </table>

  <h4>{l s='Últimos errores de validación' mod='gsheetsimport'}</h4>
  <table class="table">
    <thead>
      <tr>
        <th>{l s='Referencia' mod='gsheetsimport'}</th>
        <th>{l s='Fila' mod='gsheetsimport'}</th>
        <th>{l s='Error' mod='gsheetsimport'}</th>
        <th>{l s='Actualizado el' mod='gsheetsimport'}</th>
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
          <td colspan="4">{l s='No se encontraron errores.' mod='gsheetsimport'}</td>
        </tr>
      {/if}
    </tbody>
  </table>
</div>
