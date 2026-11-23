<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Omnibus\Model;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Configuration;
use Db;
use DbQuery;
use ObjectModel;
use Product;
use Shop;

/**
 * Dziennik cen produktów.
 *
 * Jeden wiersz to jedna cena zapisana w jednym momencie, w jednym sklepie,
 * walucie, kraju i grupie klientów. Wymiary są w tabeli dlatego, że ta sama
 * cena katalogowa daje różne kwoty przy różnym podatku i różnej zniżce grupy,
 * a nota o najniższej cenie ma mówić o tej kwocie, którą klient widział.
 *
 * Zapisujemy tylko zmiany: najniższa cena z okresu wychodzi tak samo, a tabela
 * nie rośnie o wiersze z niezmienioną ceną.
 */
class PriceHistory extends ObjectModel
{
    /** @var int */
    public $id_product;

    /** @var int */
    public $id_product_attribute;

    /** @var float */
    public $price_tax_incl;

    /** @var float */
    public $price_tax_excl;

    /** @var int */
    public $id_shop;

    /** @var int */
    public $id_currency;

    /** @var int */
    public $id_country;

    /** @var int */
    public $id_group;

    /** @var string */
    public $captured_at;

    /** @var array<string,mixed> */
    public static $definition = [
        'table' => 'quince_omnibus_price_history',
        'primary' => 'id_history',
        'fields' => [
            'id_product' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_product_attribute' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'price_tax_incl' => ['type' => self::TYPE_FLOAT, 'validate' => 'isPrice', 'required' => true],
            'price_tax_excl' => ['type' => self::TYPE_FLOAT, 'validate' => 'isPrice', 'required' => true],
            'id_shop' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_currency' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_country' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_group' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'captured_at' => ['type' => self::TYPE_DATE, 'validate' => 'isDate', 'required' => true],
        ],
    ];

    /**
     * Ta sama cena zapisana ponownie nie jest zmianą, chyba że wpis jest już
     * starszy niż okres, z którego liczymy najniższą cenę. Wtedy wiersz musi
     * powstać, bo inaczej po przekroczeniu okresu produkt bez zmian ceny
     * zostałby bez historii i nota zniknęłaby ze strony.
     */
    private const SWIEZOSC_DNI = 30;

    /** Cena bieżąca różni się od zapisanej dopiero powyżej tej kwoty. */
    private const EPSILON = 0.00001;

    /**
     * Najniższa cena z okresu.
     *
     * Pyta o wymiary, w których zapisujemy: sklep i jego domyślna waluta.
     * Waluta bieżąca odpada: historia jest prowadzona w walucie
     * sklepu, a przeliczenie na walutę oglądającego jest sprawą wyświetlenia.
     * Pytanie o walutę bieżącą nie trafiłoby w żaden wiersz i nota zniknęłaby
     * każdemu, kto przełączy walutę.
     *
     * @param string $rodzajCeny 'gross' albo 'net'
     */
    public static function lowest(
        int $idProduct,
        int $idProductAttribute,
        int $dni,
        string $rodzajCeny,
        int $idShop,
        int $idGroup
    ): ?float {
        $kolumna = $rodzajCeny === 'net' ? 'price_tax_excl' : 'price_tax_incl';
        $od = date('Y-m-d H:i:s', strtotime('-' . $dni . ' days'));

        $zapytanie = new DbQuery();
        $zapytanie->select('MIN(' . $kolumna . ')');
        $zapytanie->from('quince_omnibus_price_history');
        $zapytanie->where('id_product = ' . $idProduct);

        // Wariant bez własnej historii korzysta z historii produktu: kombinacja
        // dodana wczoraj nie ma swoich wierszy, a cena produktu jest jej ceną.
        $zapytanie->where(
            $idProductAttribute > 0
                ? '(id_product_attribute = ' . $idProductAttribute . ' OR id_product_attribute = 0)'
                : 'id_product_attribute = 0'
        );

        $zapytanie->where('id_shop = ' . $idShop);
        $zapytanie->where('id_currency = ' . self::domyslnaWaluta($idShop));
        $zapytanie->where('id_group = ' . $idGroup);
        $zapytanie->where('captured_at >= "' . pSQL($od) . '"');

        $wynik = Db::getInstance()->getValue($zapytanie);

        return $wynik !== false && (float) $wynik > 0 ? (float) $wynik : null;
    }

    /**
     * Przebieg ceny w okresie: dane do wykresu.
     *
     * Te same wymiary co przy liczeniu najniższej ceny, bo wykres ma pokazywać
     * TĘ historię, z której wzięła się liczba w nocie. Inny dobór wymiarów dałby
     * wykres, na którym najniższej ceny z noty nie widać.
     *
     * @param string $rodzajCeny 'gross' albo 'net'
     *
     * @return array<int,array{data:string,cena:float}>
     */
    public static function series(
        int $idProduct,
        int $idProductAttribute,
        int $dni,
        string $rodzajCeny,
        int $idShop,
        int $idGroup
    ): array {
        $kolumna = $rodzajCeny === 'net' ? 'price_tax_excl' : 'price_tax_incl';
        $od = date('Y-m-d H:i:s', strtotime('-' . $dni . ' days'));

        $warunekWariantu = $idProductAttribute > 0
            ? '(id_product_attribute = ' . $idProductAttribute . ' OR id_product_attribute = 0)'
            : 'id_product_attribute = 0';

        $wiersze = Db::getInstance()->executeS(
            'SELECT captured_at, ' . $kolumna . ' AS cena
             FROM ' . _DB_PREFIX_ . 'quince_omnibus_price_history
             WHERE id_product = ' . $idProduct . '
               AND ' . $warunekWariantu . '
               AND id_shop = ' . $idShop . '
               AND id_currency = ' . self::domyslnaWaluta($idShop) . '
               AND id_group = ' . $idGroup . '
               AND captured_at >= "' . pSQL($od) . '"
             ORDER BY captured_at ASC'
        );

        return array_map(
            static fn (array $w): array => [
                'data' => (string) $w['captured_at'],
                'cena' => (float) $w['cena'],
            ],
            is_array($wiersze) ? $wiersze : []
        );
    }

    /**
     * Zapisuje bieżącą cenę produktu we wszystkich sklepach, do których należy.
     *
     * Sklep jest pętlą, a nie kontekstem. Zapis produktu w kontekście „wszystkie
     * sklepy" ma kontekst jednego sklepu, więc pozostałe zostałyby bez wiersza.
     * Nota czyta historię po numerze sklepu, czyli u nich by nie powstała.
     *
     * @return int liczba dopisanych wierszy
     */
    public static function record(int $idProduct, ?int $tylkoSklep = null): int
    {
        if ($idProduct <= 0) {
            return 0;
        }

        $sklepy = $tylkoSklep !== null ? [$tylkoSklep] : self::sklepyProduktu($idProduct);
        $dopisane = 0;

        foreach ($sklepy as $idShop) {
            $grupy = self::grupySklepu($idShop);

            foreach (self::wariantyProduktu($idProduct, $idShop) as $idAttribute) {
                foreach ($grupy as $idGroup) {
                    $dopisane += self::recordOne($idProduct, $idAttribute, $idShop, $idGroup) ? 1 : 0;
                }
            }
        }

        return $dopisane;
    }

    /**
     * Grupy klientów sklepu.
     *
     * Cenę zapisujemy dla każdej z nich, bo zniżka grupy i cena promocyjna
     * przypisana grupie dają różne kwoty, a nota ma podawać tę, którą widział
     * ten konkretny klient.
     *
     * Zapis dla jednej grupy nie wystarczy: gość na sklepie
     * należy do grupy „Odwiedzający", a nie do grupy domyślnej dla nowych kont.
     * Dziennik prowadzony tylko dla tej drugiej nie ma ani jednego wiersza dla
     * kogoś niezalogowanego, więc nota nie pokazuje się większości kupujących.
     *
     * @return int[]
     */
    private static function grupySklepu(int $idShop): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT id_group FROM ' . _DB_PREFIX_ . 'group_shop WHERE id_shop = ' . $idShop
        );

        $grupy = array_map(
            static fn (array $w): int => (int) $w['id_group'],
            is_array($wiersze) ? $wiersze : []
        );

        return $grupy !== [] ? $grupy : [(int) Configuration::get('PS_CUSTOMER_GROUP', null, null, $idShop)];
    }

    /**
     * Jeden wiersz: produkt, wariant i sklep.
     *
     * Cenę liczymy przez `priceCalculation`, a nie `getPriceStatic`, bo tamta
     * bierze sklep, kraj, walutę i grupę z kontekstu żądania. Tutaj wszystkie
     * cztery są dane z góry: zapisujemy cenę sklepu, a nie cenę tego, kto
     * akurat zapisał produkt w panelu.
     */
    private static function recordOne(int $idProduct, int $idAttribute, int $idShop, int $idGroup): bool
    {
        $idCurrency = self::domyslnaWaluta($idShop);
        $idCountry = (int) Configuration::get('PS_COUNTRY_DEFAULT', null, null, $idShop);

        $brutto = self::cena($idShop, $idProduct, $idAttribute, $idCountry, $idCurrency, $idGroup, true);
        $netto = self::cena($idShop, $idProduct, $idAttribute, $idCountry, $idCurrency, $idGroup, false);

        if ($brutto === null || $netto === null) {
            return false;
        }

        if (!self::zmianaWarta($idProduct, $idAttribute, $idShop, $idCurrency, $idCountry, $idGroup, $brutto)) {
            return false;
        }

        $poprzednia = self::ostatniaCena($idProduct, $idAttribute, $idShop, $idCurrency, $idCountry, $idGroup);

        $zapisane = (bool) Db::getInstance()->insert('quince_omnibus_price_history', [
            'id_product' => $idProduct,
            'id_product_attribute' => $idAttribute,
            'price_tax_incl' => $brutto,
            'price_tax_excl' => $netto,
            'id_shop' => $idShop,
            'id_currency' => $idCurrency,
            'id_country' => $idCountry,
            'id_group' => $idGroup,
            'captured_at' => date('Y-m-d H:i:s'),
        ]);

        // Spadek ceny dla innych modułów (Quince Notifuse: klienci z produktem
        // w ulubionych albo w koszyku). Tylko grupa domyślna dla klientów:
        // jedna zmiana ceny nie może dawać zgłoszenia na każdą grupę.
        if ($zapisane
            && $poprzednia !== null
            && $brutto < $poprzednia - self::EPSILON
            && $idGroup === (int) Configuration::get('PS_CUSTOMER_GROUP', null, null, $idShop)
        ) {
            \Hook::exec('actionQuincePriceDropped', [
                'id_product' => $idProduct,
                'id_product_attribute' => $idAttribute,
                'id_shop' => $idShop,
                'id_currency' => $idCurrency,
                'old_price' => $poprzednia,
                'new_price' => $brutto,
            ]);
        }

        return $zapisane;
    }

    /** Ostatnia zapisana cena brutto dla tych wymiarów albo null, gdy historii nie ma. */
    private static function ostatniaCena(
        int $idProduct,
        int $idAttribute,
        int $idShop,
        int $idCurrency,
        int $idCountry,
        int $idGroup
    ): ?float {
        $wartosc = Db::getInstance()->getValue(
            'SELECT price_tax_incl FROM ' . _DB_PREFIX_ . 'quince_omnibus_price_history
             WHERE id_product = ' . $idProduct . '
               AND id_product_attribute = ' . $idAttribute . '
               AND id_shop = ' . $idShop . '
               AND id_currency = ' . $idCurrency . '
               AND id_country = ' . $idCountry . '
               AND id_group = ' . $idGroup . '
             ORDER BY captured_at DESC'
        );

        return $wartosc === false || $wartosc === null ? null : (float) $wartosc;
    }

    /**
     * Cena produktu dla podanych wymiarów.
     *
     * Bez klienta i bez koszyka: dziennik zapisuje cenę oferty, a nie cenę
     * wyliczoną komuś konkretnemu. Cena zależna od klienta trafiłaby do historii
     * jako cena sklepu i zaniżyła notę wszystkim pozostałym.
     */
    private static function cena(
        int $idShop,
        int $idProduct,
        int $idAttribute,
        int $idCountry,
        int $idCurrency,
        int $idGroup,
        bool $zPodatkiem
    ): ?float {
        $cenaSzczegolna = null;

        $wartosc = Product::priceCalculation(
            $idShop,
            $idProduct,
            $idAttribute,
            $idCountry,
            0,          // województwo: dziennik jest na poziomie kraju
            '',         // kod pocztowy: jw.
            $idCurrency,
            $idGroup,
            1,          // ilość
            $zPodatkiem,
            6,          // miejsca po przecinku
            false,      // nie samą kwotę obniżki
            true,       // z obniżkami: zapisujemy cenę, którą klient widzi
            true,       // z opłatą środowiskową
            $cenaSzczegolna,
            true,       // ze zniżką grupy
            0,          // bez klienta
            false,      // bez ceny przypisanej klientowi
            0,
            0,
            0
        );

        return is_numeric($wartosc) ? (float) $wartosc : null;
    }

    /**
     * Czy bieżąca cena jest zmianą wobec ostatniego wpisu.
     */
    private static function zmianaWarta(
        int $idProduct,
        int $idAttribute,
        int $idShop,
        int $idCurrency,
        int $idCountry,
        int $idGroup,
        float $brutto
    ): bool {
        $ostatni = Db::getInstance()->getRow(
            'SELECT price_tax_incl, captured_at
             FROM ' . _DB_PREFIX_ . 'quince_omnibus_price_history
             WHERE id_product = ' . $idProduct . '
               AND id_product_attribute = ' . $idAttribute . '
               AND id_shop = ' . $idShop . '
               AND id_currency = ' . $idCurrency . '
               AND id_country = ' . $idCountry . '
               AND id_group = ' . $idGroup . '
             ORDER BY captured_at DESC'
        );

        if (!is_array($ostatni) || $ostatni === []) {
            return true;
        }

        $taSama = abs((float) $ostatni['price_tax_incl'] - $brutto) < self::EPSILON;
        $dni = (time() - strtotime((string) $ostatni['captured_at'])) / 86400;

        return !$taSama || $dni >= self::SWIEZOSC_DNI;
    }

    /**
     * Sklepy, w których produkt jest wystawiony.
     *
     * @return int[]
     */
    private static function sklepyProduktu(int $idProduct): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT id_shop FROM ' . _DB_PREFIX_ . 'product_shop WHERE id_product = ' . $idProduct
        );

        $sklepy = array_map(
            static fn (array $w): int => (int) $w['id_shop'],
            is_array($wiersze) ? $wiersze : []
        );

        // Produkt bez wiersza w product_shop nie istnieje w żadnym sklepie.
        // Zapisujemy go w bieżącym, żeby świeżo zakładany produkt miał historię
        // od pierwszej ceny, a nie dopiero od przypisania do sklepu.
        return $sklepy !== [] ? $sklepy : [(int) Shop::getContextShopID() ?: 1];
    }

    /**
     * Produkt i jego warianty. Zero na początku to sam produkt: wariant bez
     * własnej historii sięga po nią przy liczeniu najniższej ceny.
     *
     * @return int[]
     */
    private static function wariantyProduktu(int $idProduct, int $idShop): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT pa.id_product_attribute
             FROM ' . _DB_PREFIX_ . 'product_attribute pa
             INNER JOIN ' . _DB_PREFIX_ . 'product_attribute_shop pas
                 ON pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = ' . $idShop . '
             WHERE pa.id_product = ' . $idProduct
        );

        $warianty = array_map(
            static fn (array $w): int => (int) $w['id_product_attribute'],
            is_array($wiersze) ? $wiersze : []
        );

        return array_merge([0], $warianty);
    }

    /** Domyślna waluta sklepu, w której prowadzimy dziennik. */
    public static function domyslnaWaluta(int $idShop): int
    {
        return (int) Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop);
    }

    /**
     * Zapisuje ceny wszystkich aktywnych produktów.
     *
     * @param callable(int):bool|null $pomijaj zwraca true dla produktu, którego nie logujemy
     *
     * @return array{produkty:int,wiersze:int,pominiete:int}
     */
    public static function recordAll(?callable $pomijaj = null): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT id_product FROM ' . _DB_PREFIX_ . 'product WHERE active = 1 ORDER BY id_product'
        );

        $produkty = 0;
        $dopisane = 0;
        $pominietych = 0;

        foreach (is_array($wiersze) ? $wiersze : [] as $wiersz) {
            $idProduct = (int) $wiersz['id_product'];

            if ($pomijaj !== null && $pomijaj($idProduct)) {
                ++$pominietych;
                continue;
            }

            ++$produkty;
            $dopisane += self::record($idProduct);
        }

        return ['produkty' => $produkty, 'wiersze' => $dopisane, 'pominiete' => $pominietych];
    }

    /** Usuwa wpisy starsze niż podana liczba dni. */
    public static function cleanOlderThan(int $dni): int
    {
        $granica = date('Y-m-d H:i:s', strtotime('-' . $dni . ' days'));

        Db::getInstance()->execute(
            'DELETE FROM ' . _DB_PREFIX_ . 'quince_omnibus_price_history
             WHERE captured_at < "' . pSQL($granica) . '"'
        );

        return (int) Db::getInstance()->Affected_Rows();
    }

    /** @param int[] $ids */
    public static function deleteByIds(array $ids): bool
    {
        $ids = array_filter(array_map('intval', $ids));

        if ($ids === []) {
            return false;
        }

        return (bool) Db::getInstance()->execute(
            'DELETE FROM ' . _DB_PREFIX_ . 'quince_omnibus_price_history
             WHERE id_history IN (' . implode(',', $ids) . ')'
        );
    }
}
