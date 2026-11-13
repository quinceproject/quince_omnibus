{**
 * Nota o najniższej cenie z ostatnich dni na stronie produktu.
 *
 * Domyślnie jeden wiersz drobnego tekstu pod blokiem ceny, tak jak w makiecie
 * (`.q-buy__omnibus`). Ikona, podpowiedź, procent i wykres dochodzą wyłącznie
 * wtedy, gdy sklep je włączy. Po instalacji wszystkie są wyłączone.
 *
 * Styl jest w motywie (components/product.css), nie tutaj: moduł dostarcza
 * treść, wygląd należy do silnika.
 *
 * `IconLibrary::svg()` oddaje SAME ścieżki, bez elementu `<svg>`. Opakowanie
 * dokłada szablon, tak jak w pasie informacyjnym i w stopce. Dzięki temu
 * rozmiar i barwa ikony należą do miejsca, w którym stoi, a nie do biblioteki.
 *}
<p class="q-buy__omnibus"
   {if $quinceOmnibusChart}
       data-q-omnibus-wykres
       data-q-omnibus-produkt="{$quinceOmnibusProduct|intval}"
       data-q-omnibus-wariant="{$quinceOmnibusAttribute|intval}"
       {if !$quinceOmnibusChartOnIcon}role="button" tabindex="0"{/if}
   {/if}>

    {if $quinceOmnibusIcon && $quinceOmnibusIconStart}
        <span class="q-omnibus__icon"
              {if $quinceOmnibusTooltip}title="{$quinceOmnibusTooltip|escape:'html':'UTF-8'}"{/if}
              {if $quinceOmnibusChart && $quinceOmnibusChartOnIcon}role="button" tabindex="0" data-q-omnibus-otworz{/if}
        ><svg class="q-omnibus__svg" viewBox="0 0 16 16" fill="currentColor"
              aria-hidden="true">{$quinceOmnibusIcon nofilter}</svg></span>
    {/if}

    {$quinceOmnibusLabel|escape:'html':'UTF-8'} {$quinceOmnibusPrice|escape:'html':'UTF-8'}

    {if $quinceOmnibusPercent !== null}
        {* Znak mówi, w którą stronę poszła cena wobec najniższej zapisanej.
           Minus znaczy „taniej niż kiedykolwiek w tym okresie". *}
        <span class="q-omnibus__procent">({if $quinceOmnibusPercent > 0}+{/if}{$quinceOmnibusPercent}%)</span>
    {/if}

    {if $quinceOmnibusIcon && !$quinceOmnibusIconStart}
        <span class="q-omnibus__icon"
              {if $quinceOmnibusTooltip}title="{$quinceOmnibusTooltip|escape:'html':'UTF-8'}"{/if}
              {if $quinceOmnibusChart && $quinceOmnibusChartOnIcon}role="button" tabindex="0" data-q-omnibus-otworz{/if}
        ><svg class="q-omnibus__svg" viewBox="0 0 16 16" fill="currentColor"
              aria-hidden="true">{$quinceOmnibusIcon nofilter}</svg></span>
    {/if}
</p>
