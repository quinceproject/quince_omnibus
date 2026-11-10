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
 * Zapis cen z harmonogramu.
 *
 * Sklep, który wybrał tryb nocny, nie zapisuje historii przy każdej zmianie ceny.
 * Import cenników potrafi zapisać kilka tysięcy produktów w kilka minut, a każdy
 * z nich pociągnąłby wtedy zapis do dziennika — razem z przeliczeniem ceny dla
 * każdego wariantu. Ten adres robi to raz, poza godzinami pracy sklepu.
 *
 * Adres jest chroniony losowym tokenem. Token stoi w konfiguracji, a nie jest
 * liczony z nazwy sklepu — nazwa sklepu jest jawna, więc token z niej wyliczony
 * zna każdy, kto wejdzie na stronę główną.
 */
class Quince_OmnibusCronModuleFrontController extends ModuleFrontController
{
    /** @var bool odpowiedź to JSON, nie strona sklepu */
    public $ajax = true;

    public function initContent(): void
    {
        $settings = new OmnibusSettings();
        $podany = (string) Tools::getValue('token');

        // hash_equals, nie ==: porównanie znak po znaku kończy się na pierwszej
        // różnicy, a czas odpowiedzi mówi wtedy, ile znaków tokena się zgadza.
        if ($podany === '' || !hash_equals($settings->cronToken(), $podany)) {
            $this->odpowiedz(['error' => 'forbidden'], 403);
        }

        $akcja = (string) Tools::getValue('action', 'record');

        if ($akcja === 'clean') {
            $usuniete = PriceHistory::cleanOlderThan($settings->retention());
            $settings->markCleanup();

            $this->odpowiedz(['removed' => $usuniete]);
        }

        $wynik = PriceHistory::recordAll(
            static fn (int $idProduct): bool => $settings->isProductExcluded($idProduct)
        );

        // Sprzątanie przy okazji zapisu: harmonogram wywoływany raz na dobę
        // załatwia obie rzeczy jednym wpisem w cronie zamiast dwóch.
        $wynik['removed'] = PriceHistory::cleanOlderThan($settings->retention());
        $settings->markCleanup();

        $this->odpowiedz($wynik);
    }

    /**
     * @param array<string,mixed> $dane
     */
    private function odpowiedz(array $dane, int $status = 200): void
    {
        header('Content-Type: application/json; charset=utf-8', true, $status);
        echo json_encode($dane);
        exit;
    }
}
