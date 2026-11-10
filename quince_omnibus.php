<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use Quince\Engine\Ui\IconLibrary;
use Quince\Omnibus\Model\PriceHistory;
use Quince\Omnibus\Settings\OmnibusSettings;

/**
 * Quince Omnibus — historia cen i najniższa cena z ostatnich dni.
 *
 * Dyrektywa Omnibus wymaga, żeby przy każdym ogłoszeniu obniżki stała informacja
 * o najniższej cenie z okresu przed obniżką. Moduł zapisuje każdą zmianę ceny do
 * własnej tabeli i wylicza z niej tę kwotę — bez arkuszy i bez pracy ręcznej.
 *
 * Moduł zgłasza się silnikowi hookiem `actionQuinceRegisterFeature`. Dzięki temu
 * włącznik noty przy cenie na stronie produktu ożywa dopiero wtedy, gdy jest co
 * pokazać: bez zainstalowanego modułu pole stoi w panelu martwe, zamiast obiecywać
 * kwotę, której nikt nie policzy.
 */
class Quince_Omnibus extends Module
{
    public function __construct()
    {
        $this->name = 'quince_omnibus';
        $this->tab = 'pricing_promotion';
        $this->version = '0.1.2';
        $this->author = 'Quince Project';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];

        // Silnik nie jest opcjonalny: to jego panel udostępnia ustawienia, jego
        // mapa strony produktu decyduje o miejscu noty, a jego motyw ją rysuje.
        // Sklep bez silnika ma do tego samego zadania osobny moduł — ClearDeal.
        $this->dependencies = ['quince_engine'];

        // Jeden wpis w gałęzi „Quince project" w menu PrestaShopa, prowadzący
        // na ekran modułu. Podstron nie zgłaszamy: moduł ma je we własnym
        // bocznym pasku, a menu sklepu urosłoby wtedy o kilkanaście pozycji.
        // Kolejność wpisów w gałęzi ustala silnik.
        $this->tabs = [
            [
                'class_name' => 'AdminQuinceOmnibus',
                'visible' => true,
                'name' => 'Price history',
                'parent_class_name' => 'AdminQuinceProject',
                'route_name' => 'quince_omnibus_settings',
                'wording' => 'Price history',
                'wording_domain' => 'Modules.Quinceomnibus.Admin',
            ],
        ];

        parent::__construct();

        $this->displayName = $this->trans('Quince Omnibus', [], 'Modules.Quinceomnibus.Admin');
        $this->description = $this->trans(
            'Records every price change and shows the lowest price of the last days, as the Omnibus Directive requires. Requires Quince Engine.',
            [],
            'Modules.Quinceomnibus.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'Uninstalling deletes the whole price history. The lowest-price note will start from scratch after reinstalling.',
            [],
            'Modules.Quinceomnibus.Admin'
        );
    }

    public function install(): bool
    {
        return parent::install()
            && $this->installDb()
            && $this->registerNoteHook()
            && $this->registerHook($this->hooks())
            && $this->installModuleGroups()
            && $this->setDefaults();
    }

    public function uninstall(): bool
    {
        foreach ((new OmnibusSettings())->configurationKeys() as $key) {
            Configuration::deleteByName($key);
        }

        return $this->uninstallDb() && parent::uninstall();
    }

    /** @return string[] */
    private function hooks(): array
    {
        return [
            'actionQuinceRegisterFeature',

            // Zapis historii — każde miejsce, w którym cena produktu może się zmienić.
            'actionProductAdd',
            'actionProductUpdate',
            'actionObjectSpecificPriceAddAfter',
            'actionObjectSpecificPriceUpdateAfter',
            'actionObjectSpecificPriceDeleteAfter',

            // Nota przy cenie — własne miejsce, ogłoszone niżej.
            self::HOOK_NOTE,

            // Skrypt wykresu, jeśli sklep go włączył.
            'displayHeader',
        ];
    }

    /**
     * Miejsce na notę o najniższej cenie.
     *
     * Własny hook, a nie natywny `displayProductPriceBlock` z typem `after_price`.
     * Tamten wypada W ŚRODKU wiersza z informacją podatkową — w elemencie
     * liniowym, do którego wstawiamy akapit. Nota ma stać w osobnym wierszu pod
     * blokiem ceny, tak jak w makiecie, a o tym decyduje szablon motywu, nie
     * przypadkowe miejsce w cudzym znaczniku.
     */
    public const HOOK_NOTE = 'displayQuinceOmnibus';

    /**
     * Ogłoszenie miejsca.
     *
     * `Hook::add()` przed rejestracją: PrestaShop po cichu pomija rejestrację
     * na hook, którego nie ma jeszcze w tabeli.
     */
    private function registerNoteHook(): bool
    {
        if (Hook::getIdByName(self::HOOK_NOTE)) {
            return true;
        }

        $hook = new Hook();
        $hook->name = self::HOOK_NOTE;
        $hook->title = 'Quince: nota o najniższej cenie';
        $hook->description = 'Wiersz pod blokiem ceny na stronie produktu i pod ceną na karcie '
            . 'w listingu. Motyw podaje parametr `miejsce`: „karta" oznacza sam tekst, '
            . 'bez własnego akapitu, bo akapit rezerwuje motyw.';
        $hook->position = false;

        return (bool) $hook->add();
    }

    private function installDb(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'quince_omnibus_price_history` (
            `id_history` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_product` INT(11) UNSIGNED NOT NULL,
            `id_product_attribute` INT(11) UNSIGNED NOT NULL DEFAULT 0,
            `price_tax_incl` DECIMAL(20, 6) NOT NULL,
            `price_tax_excl` DECIMAL(20, 6) NOT NULL,
            `id_shop` INT(11) UNSIGNED NOT NULL,
            `id_currency` INT(11) UNSIGNED NOT NULL,
            `id_country` INT(11) UNSIGNED NOT NULL,
            `id_group` INT(11) UNSIGNED NOT NULL,
            `captured_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_history`),
            INDEX `idx_product` (`id_product`, `id_product_attribute`),
            INDEX `idx_captured_at` (`captured_at`),
            INDEX `idx_lowest` (`id_product`, `id_product_attribute`, `id_shop`, `id_currency`, `id_group`, `captured_at`),
            INDEX `idx_dedup` (`id_product`, `id_product_attribute`, `id_shop`, `id_currency`, `id_country`, `id_group`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        return (bool) Db::getInstance()->execute($sql);
    }

    private function uninstallDb(): bool
    {
        return (bool) Db::getInstance()->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'quince_omnibus_price_history`'
        );
    }

    /**
     * Wartości początkowe: etykieta noty w językach sklepu i token harmonogramu.
     */
    private function setDefaults(): bool
    {
        $teksty = ['LABEL_PRODUCT' => [], 'LABEL_LISTING' => [], 'TOOLTIP' => []];

        foreach (Language::getLanguages(false) as $jezyk) {
            $idLang = (int) $jezyk['id_lang'];

            $teksty['LABEL_PRODUCT'][$idLang] = $this->trans(
                'Lowest price in the last %s days:',
                [],
                'Modules.Quinceomnibus.Shop'
            );

            // Karta w listingu jest wezsza, wiec zdanie krotsze.
            $teksty['LABEL_LISTING'][$idLang] = $this->trans(
                'Lowest from %s days:',
                [],
                'Modules.Quinceomnibus.Shop'
            );

            $teksty['TOOLTIP'][$idLang] = $this->trans(
                'This is the lowest price of this product in the last %s days before the current promotion.',
                [],
                'Modules.Quinceomnibus.Shop'
            );
        }

        (new OmnibusSettings())->seed($teksty);

        return true;
    }

    /**
     * Wpisy w ps_module_group.
     *
     * Bez nich hook frontendowy w ogóle nie odpala: PrestaShop dokłada do listy
     * modułów warunek na grupę klienta i moduł bez wpisów znika z wyników.
     */
    private function installModuleGroups(): bool
    {
        $grupy = Group::getGroups((int) Context::getContext()->language->id, true);

        foreach (Shop::getShops(true, null, true) as $idShop) {
            foreach ($grupy as $grupa) {
                Db::getInstance()->insert(
                    'module_group',
                    [
                        'id_module' => (int) $this->id,
                        'id_shop' => (int) $idShop,
                        'id_group' => (int) $grupa['id_group'],
                    ],
                    false,
                    true,
                    Db::INSERT_IGNORE
                );
            }
        }

        return true;
    }

    /**
     * Zgłoszenie do silnika: co dostarczamy i gdzie są nasze ustawienia.
     *
     * @return array<int,array<string,string>>
     */
    public function hookActionQuinceRegisterFeature(): array
    {
        return [[
            'type' => 'price_history',
            'id' => 'quince_omnibus',
            'label' => $this->trans('Price history', [], 'Modules.Quinceomnibus.Admin'),
            'route' => 'quince_omnibus_settings',
        ]];
    }

    // =========================================================================
    // Zapis historii
    // =========================================================================

    /** @param array<string,mixed> $params */
    public function hookActionProductAdd(array $params): void
    {
        $this->zapisz((int) ($params['id_product'] ?? 0));
    }

    /** @param array<string,mixed> $params */
    public function hookActionProductUpdate(array $params): void
    {
        $this->zapisz((int) ($params['id_product'] ?? 0));
    }

    /** @param array<string,mixed> $params */
    public function hookActionObjectSpecificPriceAddAfter(array $params): void
    {
        $this->zapisz((int) ($params['object']->id_product ?? 0));
    }

    /** @param array<string,mixed> $params */
    public function hookActionObjectSpecificPriceUpdateAfter(array $params): void
    {
        $this->zapisz((int) ($params['object']->id_product ?? 0));
    }

    /** @param array<string,mixed> $params */
    public function hookActionObjectSpecificPriceDeleteAfter(array $params): void
    {
        $this->zapisz((int) ($params['object']->id_product ?? 0));
    }

    /**
     * Zapis jednej zmiany ceny.
     *
     * W trybie harmonogramu nie robimy nic: sklep, który wybrał zapis nocny,
     * ma powody, żeby import cen nie ciągnął za sobą tysięcy zapisów w bazie.
     */
    private function zapisz(int $idProduct): void
    {
        if ($idProduct <= 0) {
            return;
        }

        $settings = new OmnibusSettings();

        if (!$settings->logsImmediately() || $settings->isProductExcluded($idProduct)) {
            return;
        }

        PriceHistory::record($idProduct);
        $this->maybeClean($settings);
    }

    /**
     * Sprzątanie dziennika raz na dobę, przy okazji zapisu.
     *
     * Znacznik czasu zapisujemy przed usuwaniem, a nie po: dwa równoczesne
     * zapisy produktu weszłyby inaczej w to samo usuwanie dwa razy.
     */
    private function maybeClean(OmnibusSettings $settings): void
    {
        $ostatnie = $settings->lastCleanup();

        if ($ostatnie > 0 && (time() - $ostatnie) < 86400) {
            return;
        }

        $settings->markCleanup();
        PriceHistory::cleanOlderThan($settings->retention());
    }

    /**
     * Skrypt wykresu — wyłącznie na stronie produktu i wyłącznie gdy wykres
     * jest włączony.
     *
     * Sama biblioteka wykresów NIE ładuje się tutaj: dociąga ją skrypt przy
     * pierwszym otwarciu okna. To 200 kB, których nie potrzebuje nikt, kto
     * w notę nie kliknie — a klika ułamek odwiedzających.
     */
    public function hookDisplayHeader(): void
    {
        if (!(new OmnibusSettings())->showsChart()) {
            return;
        }

        if (!($this->context->controller instanceof ProductController)) {
            return;
        }

        $this->context->controller->registerJavascript(
            'quince-omnibus-wykres',
            'modules/' . $this->name . '/views/js/wykres.js',
            ['position' => 'bottom', 'priority' => 100]
        );

        Media::addJsDef(['quinceOmnibus' => [
            'adres' => $this->context->link->getModuleLink($this->name, 'history', [], true),
            // Biblioteka leży u nas, nie w sieci dostawczej — sklep nie pobiera
            // niczego z zewnątrz.
            'biblioteka' => $this->_path . 'views/js/vendor/chart.umd.min.js',
            'tytul' => $this->trans('Price history', [], 'Modules.Quinceomnibus.Shop'),
            'zamknij' => $this->trans('Close', [], 'Modules.Quinceomnibus.Shop'),
            'brakDanych' => $this->trans('No price changes recorded in this period.', [], 'Modules.Quinceomnibus.Shop'),
        ]]);
    }

    // =========================================================================
    // Nota przy cenie
    // =========================================================================

    /**
     * Nota o najniższej cenie pod blokiem ceny na stronie produktu.
     *
     * Miejsce bierze się z szablonu motywu (`after_price`), a nie z własnego
     * haka: mapa strony produktu ustawia kolejność elementów, a nota jest
     * częścią elementu ceny, nie osobnym elementem do przestawiania.
     *
     * @param array<string,mixed> $params
     */
    public function hookDisplayQuinceOmnibus(array $params): string
    {
        $dane = $this->notaDlaProduktu($params['product'] ?? null, ($params['miejsce'] ?? '') === 'karta');

        if ($dane === null) {
            return '';
        }

        $this->smarty->assign($dane);

        // Na karcie w listingu akapit rezerwuje motyw — także dla produktu bez
        // noty, żeby wszystkie karty w rzędzie miały ceny i przyciski w jednej
        // linii. Moduł wkłada tam sam tekst.
        $naKarcie = ($params['miejsce'] ?? '') === 'karta';

        return $this->display(
            __FILE__,
            $naKarcie ? 'views/templates/hook/note-karta.tpl' : 'views/templates/hook/note.tpl'
        );
    }

    /**
     * Treść noty albo null, gdy nie ma czego pokazać.
     *
     * @param mixed $product
     *
     * @return array<string,mixed>|null
     */
    private function notaDlaProduktu($product, bool $naKarcie = false): ?array
    {
        if (!is_array($product) && !($product instanceof ArrayAccess)) {
            return null;
        }

        $idProduct = (int) ($product['id_product'] ?? $product['id'] ?? 0);

        if ($idProduct <= 0) {
            return null;
        }

        $settings = new OmnibusSettings();

        if ($settings->isProductExcluded($idProduct)) {
            return null;
        }

        $idShop = (int) $this->context->shop->id;
        $idGroup = (int) $this->context->customer->id_default_group;
        $rodzaj = $settings->priceType();
        $zPodatkiem = $rodzaj !== 'net';

        $idAttribute = (int) Tools::getValue('id_product_attribute');
        if ($idAttribute <= 0) {
            $idAttribute = (int) ($product['id_product_attribute'] ?? 0);
        }
        if ($idAttribute <= 0) {
            $idAttribute = (int) Product::getDefaultAttribute($idProduct);
        }

        // Nota tylko przy obniżce: porównujemy cenę z obniżkami z ceną bez nich.
        if (!$settings->showsAlways()) {
            $teraz = (float) Product::getPriceStatic($idProduct, $zPodatkiem, $idAttribute, 6, null, false, true);
            $bezObnizki = (float) Product::getPriceStatic($idProduct, $zPodatkiem, $idAttribute, 6, null, false, false);

            if ($teraz >= $bezObnizki - 0.00001) {
                return null;
            }
        }

        $najnizsza = PriceHistory::lowest(
            $idProduct,
            $idAttribute,
            $settings->days(),
            $rodzaj,
            $idShop,
            $idGroup
        );

        // Brak historii to nie jest „najniższa cena równa bieżącej" — to brak
        // odpowiedzi. Nota z ceną bieżącą w roli najniższej byłaby nieprawdą,
        // więc wtedy nie ma jej wcale.
        if ($najnizsza === null) {
            return null;
        }

        // Dziennik prowadzimy w walucie sklepu; klient może oglądać w innej.
        $wWalucieKlienta = Tools::convertPrice($najnizsza, $this->context->currency);

        // Poza żądaniem sklepu (konsola, zadanie w tle) kontekst nie ma ustawionej
        // lokalizacji. Bez sprawdzenia formatowanie kończy się tam błędem.
        $locale = $this->context->getCurrentLocale();
        $waluta = (string) $this->context->currency->iso_code;

        // O ile cena bieżąca odbiega od najniższej zapisanej. Liczone wobec
        // najniższej z dziennika, a nie wobec ceny przekreślonej — to dwie różne
        // liczby i sklep pokazuje obie.
        $procent = null;

        if ($settings->showsPercent() && $najnizsza > 0) {
            $biezaca = (float) Product::getPriceStatic($idProduct, $zPodatkiem, $idAttribute, 6, null, false, true);
            $roznica = $biezaca - $najnizsza;

            if (abs($roznica) > 0.00001) {
                $procent = round(($roznica / $najnizsza) * 100, $settings->precision());
            }
        }

        $ikona = $settings->showsIcon() ? IconLibrary::svg($settings->all()['ICON']) : '';

        $idLang = (int) $this->context->language->id;

        // Karta w listingu jest wezsza od strony produktu, wiec ma wlasne zdanie.
        // Gdy sklep go nie wpisal, wraca do zdania ze strony produktu — lepiej
        // dluzsze niz zadne.
        $etykieta = $naKarcie
            ? $settings->label($idLang, '', 'LABEL_LISTING')
            : '';

        if (trim($etykieta) === '') {
            $etykieta = $settings->label(
                $idLang,
                $this->trans('Lowest price in the last %s days:', [], 'Modules.Quinceomnibus.Shop')
            );
        }

        return [
            'quinceOmnibusLabel' => $etykieta,
            'quinceOmnibusPrice' => $locale !== null
                ? $locale->formatPrice($wWalucieKlienta, $waluta)
                : sprintf('%.2f %s', $wWalucieKlienta, $waluta),
            'quinceOmnibusPercent' => $procent,
            'quinceOmnibusIcon' => $ikona,
            'quinceOmnibusIconStart' => $settings->iconAtStart(),
            'quinceOmnibusTooltip' => $settings->tooltip($idLang),
            // Wykres tylko na stronie produktu: na karcie w listingu nie ma czego
            // otworzyc — miniatura nie zna wariantu, ktory klient oglada.
            'quinceOmnibusChart' => !$naKarcie && $settings->showsChart(),
            'quinceOmnibusChartOnIcon' => $settings->chartTrigger() === OmnibusSettings::CHART_ICON,
            'quinceOmnibusProduct' => $idProduct,
            'quinceOmnibusAttribute' => $idAttribute,
        ];
    }

    /**
     * Przycisk „Konfiguruj" w menedżerze modułów.
     *
     * PrestaShop pokazuje go po samym istnieniu tej metody
     * (`ModuleRepository::__construct`, `method_exists($tmpModule, 'getContent')`),
     * a my przenosimy sprzedawcę na ekran modułu w panelu.
     */
    public function getContent(): void
    {
        \Tools::redirectAdmin($this->get('router')->generate('quince_omnibus_settings'));
    }

}
