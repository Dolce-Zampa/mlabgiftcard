{extends file='page.tpl'}
{block name='page_title'}Gift card{/block}
{block name='page_content'}
  {if $mlabgc_message}<div class="alert alert-success">{$mlabgc_message|escape:'html':'UTF-8'}</div>{/if}
  <form method="post" action="{$mlabgc_account_url|escape:'html':'UTF-8'}" class="card card-block">
    <input type="hidden" name="gc_token" value="{$mlabgc_token|escape:'html':'UTF-8'}">
    <input type="hidden" name="gc_request_key" value="{$mlabgc_request_key|escape:'html':'UTF-8'}">
    <label for="gc_code">Associa una gift card al tuo account</label>
    <input id="gc_code" type="text" name="gc_code" maxlength="48" autocomplete="off" required class="form-control">
    <p>Una volta associata, la gift card potrà essere utilizzata soltanto da questo account.</p>
    <button type="submit" class="btn btn-primary">Associa gift card</button>
  </form>
  <div class="table-responsive"><table class="table">
    <thead><tr><th>Codice</th><th>Saldo</th><th>Prenotato</th><th>Disponibile</th><th>Stato</th><th>Scadenza UTC</th></tr></thead>
    <tbody>{foreach $mlabgc_cards as $card}
      <tr><td><code>{$card.code|escape:'html':'UTF-8'}</code></td>
        <td>{$card.balance_cents_formatted|escape:'html':'UTF-8'} {$card.currency|escape:'html':'UTF-8'}</td>
        <td>{$card.reserved_cents_formatted|escape:'html':'UTF-8'}</td>
        <td>{$card.available_cents_formatted|escape:'html':'UTF-8'}</td>
        <td>{if $card.expired}Scaduta{else}{$card.status|escape:'html':'UTF-8'}{/if}</td>
        <td>{$card.expires_at|default:'Senza scadenza'|escape:'html':'UTF-8'}</td></tr>
    {foreachelse}<tr><td colspan="6">Nessuna gift card associata.</td></tr>{/foreach}</tbody>
  </table></div>
  {if $mlabgc_previous_url}<a href="{$mlabgc_previous_url|escape:'html':'UTF-8'}">Precedente</a>{/if}
  {if $mlabgc_next_url}<a href="{$mlabgc_next_url|escape:'html':'UTF-8'}">Successiva</a>{/if}
{/block}
