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

use Configuration;
use Context;
use Db;
use Shop;

/**
 * Dziennik cen widziany z panelu.
 *
 * Osobno od `PriceHistory`, bo to inne zadanie: tamta klasa zapisuje i liczy
 * najniższą cenę dla sklepu, ta wypisuje wiersze człowiekowi — z filtrami,
 * stronicowaniem i sortowaniem, których sklep nigdy nie potrzebuje.
 */
final class PriceLog
{
    /** Kolumny, po których wolno sortować. Lista zamknięta, bo nazwa idzie do SQL. */
    private const SORTOWALNE = [
        'id_history' => 'h.id_history',
        'product' => 'pl.name',
        'price_tax_incl' => 'h.price_tax_incl',
        'price_tax_excl' => 'h.price_tax_excl',
        'captured_at' => 'h.captured_at',
    ];

    public const NA_STRONE = 50;

    /**
     * Wiersze dziennika dla bieżącego sklepu.
     *
     * @param array{szukaj?:string,od?:string,do?:string} $filtry
     *
     * @return array<int,array<string,mixed>>
     */
    public function rows(array $filtry, int $strona, string $sortuj, string $kierunek): array
    {
        $idLang = (int) Context::getContext()->language->id;
        $offset = max(0, ($strona - 1) * self::NA_STRONE);

        $wiersze = Db::getInstance()->executeS(
            'SELECT h.*, pl.name AS product_name, c.iso_code AS currency
             FROM ' . _DB_PREFIX_ . 'quince_omnibus_price_history h
             LEFT JOIN ' . _DB_PREFIX_ . 'product_lang pl
                 ON pl.id_product = h.id_product AND pl.id_lang = ' . $idLang . ' AND pl.id_shop = h.id_shop
             LEFT JOIN ' . _DB_PREFIX_ . 'currency c ON c.id_currency = h.id_currency
             ' . $this->warunek($filtry) . '
             ORDER BY ' . $this->kolumna($sortuj) . ' ' . $this->kierunek($kierunek) . '
             LIMIT ' . self::NA_STRONE . ' OFFSET ' . $offset
        );

        return is_array($wiersze) ? $wiersze : [];
    }

    /** @param array{szukaj?:string,od?:string,do?:string} $filtry */
    public function count(array $filtry): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*)
             FROM ' . _DB_PREFIX_ . 'quince_omnibus_price_history h
             LEFT JOIN ' . _DB_PREFIX_ . 'product_lang pl
                 ON pl.id_product = h.id_product AND pl.id_lang = ' . (int) Context::getContext()->language->id . '
                 AND pl.id_shop = h.id_shop
             ' . $this->warunek($filtry)
        );
    }

    /**
     * Liczby na wierzchu ekranu: ile wpisów, ilu produktów dotyczą i od kiedy
     * dziennik sięga. Bez cache'a — jedno zapytanie zbiorcze na wejście na ekran
     * jest tańsze niż wpis w konfiguracji, który przy czterech sklepach i tak
     * musiałby mieć osobny klucz na sklep.
     *
     * @return array{wpisow:int,produktow:int,ostatnie7:int,najstarszy:?string,najnowszy:?string}
     */
    public function stats(): array
    {
        $idShop = $this->idShop();

        $wiersz = Db::getInstance()->getRow(
            'SELECT COUNT(*) AS wpisow,
                    COUNT(DISTINCT id_product) AS produktow,
                    SUM(captured_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS ostatnie7,
                    MIN(captured_at) AS najstarszy,
                    MAX(captured_at) AS najnowszy
             FROM ' . _DB_PREFIX_ . 'quince_omnibus_price_history
             WHERE id_shop = ' . $idShop
        );

        return [
            'wpisow' => (int) ($wiersz['wpisow'] ?? 0),
            'produktow' => (int) ($wiersz['produktow'] ?? 0),
            'ostatnie7' => (int) ($wiersz['ostatnie7'] ?? 0),
            'najstarszy' => $wiersz['najstarszy'] ?? null,
            'najnowszy' => $wiersz['najnowszy'] ?? null,
        ];
    }

    /**
     * Dziennik jako CSV.
     *
     * Średnik, nie przecinek: arkusze w polskiej lokalizacji rozdzielają nim
     * kolumny, a plik ma się otworzyć podwójnym kliknięciem, nie kreatorem.
     *
     * @param array{szukaj?:string,od?:string,do?:string} $filtry
     */
    public function toCsv(array $filtry): string
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT h.*, pl.name AS product_name
             FROM ' . _DB_PREFIX_ . 'quince_omnibus_price_history h
             LEFT JOIN ' . _DB_PREFIX_ . 'product_lang pl
                 ON pl.id_product = h.id_product AND pl.id_lang = ' . (int) Context::getContext()->language->id . '
                 AND pl.id_shop = h.id_shop
             ' . $this->warunek($filtry) . '
             ORDER BY h.captured_at DESC'
        );

        $plik = fopen('php://temp', 'r+');

        fputcsv($plik, [
            'id_history', 'id_product', 'product_name', 'id_product_attribute',
            'price_tax_incl', 'price_tax_excl', 'id_currency', 'id_country',
            'id_group', 'id_shop', 'captured_at',
        ], ';');

        foreach (is_array($wiersze) ? $wiersze : [] as $w) {
            fputcsv($plik, [
                $w['id_history'], $w['id_product'], $w['product_name'], $w['id_product_attribute'],
                $w['price_tax_incl'], $w['price_tax_excl'], $w['id_currency'], $w['id_country'],
                $w['id_group'], $w['id_shop'], $w['captured_at'],
            ], ';');
        }

        rewind($plik);
        $csv = (string) stream_get_contents($plik);
        fclose($plik);

        return $csv;
    }

    /**
     * Wczytanie dziennika z pliku CSV.
     *
     * Służy do przeniesienia historii ze sklepu, który prowadził ją wcześniej —
     * bez tego sklep po wdrożeniu ma pustą historię i przez trzydzieści dni nie
     * może pokazać noty.
     *
     * @return array{wczytane:int,pominiete:int}
     */
    public function fromCsv(string $sciezka): array
    {
        $plik = @fopen($sciezka, 'r');

        if ($plik === false) {
            return ['wczytane' => 0, 'pominiete' => 0];
        }

        // Rozdzielnik z pierwszego wiersza: plik zapisany przez arkusz w innej
        // lokalizacji przyjdzie z przecinkiem i inaczej wpadłby cały do kolumny 1.
        $naglowek = (string) fgets($plik);
        $rozdzielnik = substr_count($naglowek, ';') >= substr_count($naglowek, ',') ? ';' : ',';

        $wczytane = 0;
        $pominiete = 0;

        while (($dane = fgetcsv($plik, 0, $rozdzielnik)) !== false) {
            if (count($dane) < 11 || (int) $dane[1] <= 0) {
                ++$pominiete;
                continue;
            }

            $wpis = new PriceHistory();
            $wpis->id_product = (int) $dane[1];
            $wpis->id_product_attribute = (int) $dane[3];
            $wpis->price_tax_incl = (float) str_replace(',', '.', (string) $dane[4]);
            $wpis->price_tax_excl = (float) str_replace(',', '.', (string) $dane[5]);
            $wpis->id_currency = (int) $dane[6];
            $wpis->id_country = (int) $dane[7];
            $wpis->id_group = (int) $dane[8];
            $wpis->id_shop = (int) $dane[9];
            $wpis->captured_at = (string) $dane[10];

            if ($wpis->add()) {
                ++$wczytane;
            } else {
                ++$pominiete;
            }
        }

        fclose($plik);

        return ['wczytane' => $wczytane, 'pominiete' => $pominiete];
    }

    /**
     * Kasuje wszystko, co pasuje do filtra — także to, czego nie widać na
     * bieżącej stronie.
     *
     * Osobno od kasowania po numerach wierszy, bo zaznaczenie „wszystkie
     * pasujące" dotyczy tysięcy wpisów: przesyłanie ich numerów w formularzu
     * przekroczyłoby limit pól, a i tak opisywałoby ten sam zbiór, który
     * potrafi opisać sam filtr.
     *
     * @param array{szukaj?:string,od?:string,do?:string} $filtry
     */
    public function deleteByFilter(array $filtry): int
    {
        Db::getInstance()->execute(
            'DELETE h FROM ' . _DB_PREFIX_ . 'quince_omnibus_price_history h
             LEFT JOIN ' . _DB_PREFIX_ . 'product_lang pl
                 ON pl.id_product = h.id_product AND pl.id_lang = ' . (int) Context::getContext()->language->id . '
                 AND pl.id_shop = h.id_shop
             ' . $this->warunek($filtry)
        );

        return (int) Db::getInstance()->Affected_Rows();
    }

    /**
     * Warunek WHERE wspólny dla wypisania, liczenia i eksportu — jedna definicja
     * filtra zamiast trzech, które z czasem przestają się zgadzać.
     *
     * @param array{szukaj?:string,od?:string,do?:string} $filtry
     */
    private function warunek(array $filtry): string
    {
        $czesci = ['h.id_shop = ' . $this->idShop()];

        $szukaj = trim((string) ($filtry['szukaj'] ?? ''));
        if ($szukaj !== '') {
            $czesci[] = is_numeric($szukaj)
                ? 'h.id_product = ' . (int) $szukaj
                : 'pl.name LIKE "%' . pSQL($szukaj) . '%"';
        }

        $od = trim((string) ($filtry['od'] ?? ''));
        if ($od !== '') {
            $czesci[] = 'h.captured_at >= "' . pSQL($od) . ' 00:00:00"';
        }

        $do = trim((string) ($filtry['do'] ?? ''));
        if ($do !== '') {
            $czesci[] = 'h.captured_at <= "' . pSQL($do) . ' 23:59:59"';
        }

        return 'WHERE ' . implode(' AND ', $czesci);
    }

    private function kolumna(string $sortuj): string
    {
        return self::SORTOWALNE[$sortuj] ?? self::SORTOWALNE['captured_at'];
    }

    private function kierunek(string $kierunek): string
    {
        return strtoupper($kierunek) === 'ASC' ? 'ASC' : 'DESC';
    }

    /** @return string[] */
    public static function sortowalne(): array
    {
        return array_keys(self::SORTOWALNE);
    }

    /**
     * Sklep, którego dziennik oglądamy.
     *
     * W kontekście „wszystkie sklepy" bierzemy sklep domyślny, a nie sumę
     * wszystkich: wiersze z czterech sklepów w jednej tabeli wyglądałyby jak
     * poczwórne zapisy tej samej ceny.
     */
    private function idShop(): int
    {
        $id = (int) Shop::getContextShopID();

        return $id > 0 ? $id : (int) Configuration::get('PS_SHOP_DEFAULT');
    }
}
