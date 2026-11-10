<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

use Quince\Omnibus\Model\PriceHistory;
use Quince\Omnibus\Settings\OmnibusSettings;

/**
 * Przebieg ceny produktu — dane do wykresu.
 *
 * Wąski punkt wejścia, oddający tylko to, co rysuje wykres: pary data–cena
 * i najniższą wartość z okresu. Bez zdjęć, bez opisu, bez reszty strony.
 *
 * Odpowiedź jest zawsze poprawnym JSON-em, także dla produktu bez historii —
 * strona klienta ma jeden kształt do obsłużenia, a nie osobną ścieżkę na
 * każdy przypadek.
 */
class Quince_OmnibusHistoryModuleFrontController extends ModuleFrontController
{
    /** @var bool nie ma tu nic do wyrenderowania poza JSON-em */
    public $ajax = true;

    public function initContent(): void
    {
        $settings = new OmnibusSettings();
        $idProduct = (int) Tools::getValue('id_product');

        // Wykres wyłączony w ustawieniach znaczy, że tych danych nie udostępniamy
        // wcale — inaczej adres oddawałby historię cen sklepu, który świadomie
        // postanowił jej nie pokazywać.
        if (!$settings->showsChart() || $idProduct <= 0 || $settings->isProductExcluded($idProduct)) {
            $this->odpowiedz(['punkty' => []]);
        }

        $punkty = PriceHistory::series(
            $idProduct,
            (int) Tools::getValue('id_product_attribute'),
            $settings->days(),
            $settings->priceType(),
            (int) $this->context->shop->id,
            (int) $this->context->customer->id_default_group
        );

        $locale = $this->context->getCurrentLocale();
        $waluta = (string) $this->context->currency->iso_code;
        $ceny = array_column($punkty, 'cena');

        $this->odpowiedz([
            'punkty' => array_map(
                function (array $punkt) use ($locale, $waluta): array {
                    // Dziennik prowadzimy w walucie sklepu, klient może oglądać w innej.
                    $cena = (float) Tools::convertPrice($punkt['cena'], $this->context->currency);

                    return [
                        'data' => substr($punkt['data'], 0, 10),
                        'cena' => round($cena, 2),
                        'opis' => $locale !== null ? $locale->formatPrice($cena, $waluta) : (string) $cena,
                    ];
                },
                $punkty
            ),
            'dni' => $settings->days(),
            'najnizsza' => $ceny === [] ? null : round(
                (float) Tools::convertPrice(min($ceny), $this->context->currency),
                2
            ),
        ]);
    }

    /**
     * @param array<string,mixed> $dane
     */
    private function odpowiedz(array $dane): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($dane);
        exit;
    }
}
