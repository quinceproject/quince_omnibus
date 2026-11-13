<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Omnibus\Settings;

use Category;
use Configuration;
use Product;
use Quince\Engine\Settings\ConfigStore;
use Validate;

/**
 * Ustawienia dziennika cen.
 *
 * Dwie rzeczy do rozstrzygnięcia przez sklep: co liczymy (okres, rodzaj ceny,
 * kiedy pokazać notę) i jak prowadzimy dziennik (kiedy zapisujemy, jak długo
 * trzymamy, czego nie śledzimy).
 *
 * Wyglądu tu nie ma: notę rysuje motyw na swoich tokenach.
 */
final class OmnibusSettings
{
    private const PREFIX = 'QUINCE_OMNIBUS_';

    /** Klucze wielojęzyczne: wartość trzymana jako JSON `{"1":"…","2":"…"}`. */
    private const TRANSLATABLE = ['LABEL_PRODUCT', 'LABEL_LISTING', 'TOOLTIP'];

    /** Zapis przy każdej zmianie ceny w sklepie. */
    public const LOGGING_IMMEDIATE = 'immediate';

    /** Zapis wyłącznie z harmonogramu. */
    public const LOGGING_CRON = 'cron';

    /** Nota stoi przy cenie zawsze. */
    public const DISPLAY_ALWAYS = 'always';

    /** Nota stoi przy cenie tylko wtedy, gdy produkt jest przeceniony. */
    public const DISPLAY_ON_DISCOUNT = 'on-discount';

    /** Wykres otwiera kliknięcie w ikonę. */
    public const CHART_ICON = 'icon';

    /** Wykres otwiera kliknięcie w dowolne miejsce noty. */
    public const CHART_NOTE = 'note';

    /** @var array<string,string> klucz => wartość domyślna */
    private const SCHEMA = [
        // --- co liczymy ---
        // Trzydzieści dni to minimum z dyrektywy. Pole zostaje otwarte, bo sklep
        // ma prawo pokazywać dłuższy okres. Krótszego nie wolno.
        'DAYS' => '30',
        'PRICE_TYPE' => 'gross',
        'DISPLAY_MODE' => self::DISPLAY_ON_DISCOUNT,
        'LABEL_PRODUCT' => '',
        'LABEL_LISTING' => '',

        // --- co jeszcze pokazać przy nocie ---
        // Wszystkie trzy domyślnie wyłączone: projekt karty produktu przewiduje
        // sam wiersz tekstu, więc instalacja modułu nie zmienia wyglądu karty.
        'SHOW_PERCENT' => '0',
        'PRECISION' => '0',
        'SHOW_ICON' => '0',
        'ICON' => 'info-circle',
        'ICON_POSITION' => 'start',
        'TOOLTIP' => '',
        'SHOW_CHART' => '0',
        'CHART_TRIGGER' => self::CHART_ICON,

        // --- jak prowadzimy dziennik ---
        'LOGGING_MODE' => self::LOGGING_IMMEDIATE,
        'LOG_RETENTION' => '365',
        'EXCLUDED_CATEGORIES' => '[]',

        // --- stan wewnętrzny, nie pole formularza ---
        'CRON_TOKEN' => '',
        'LAST_CLEANUP' => '0',
    ];

    /** Klucze, których formularz nie wysyła. Zapisuje je sam moduł. */
    private const INTERNAL = ['CRON_TOKEN', 'LAST_CLEANUP'];

    /**
     * Klucze przychodzące jako tablica, nie jako pojedyncza wartość. Mają
     * własną obsługę w save(). W pętli dla pól prostych rzutowanie tablicy na
     * tekst kończy się błędem, a nie zapisem.
     */
    private const COMPOSITE = ['EXCLUDED_CATEGORIES'];

    /** Najkrótszy i najdłuższy dopuszczalny okres w dniach. */
    public const DAYS_MIN = 30;
    public const DAYS_MAX = 365;

    /** Granice przechowywania dziennika w dniach. */
    public const RETENTION_MIN = 30;
    public const RETENTION_MAX = 3650;

    /** @return array<string,string> */
    public function all(): array
    {
        $out = [];

        foreach (self::SCHEMA as $key => $default) {
            $value = Configuration::get(self::PREFIX . $key);
            // Pusty ciąg jest wyborem sklepu, a nie brakiem wartości, dlatego
            // na wartość domyślną spadamy wyłącznie przy braku wpisu.
            $out[$key] = ($value === false || $value === null) ? $default : (string) $value;
        }

        return $out;
    }

    /** @return array<string,string> */
    public function defaults(): array
    {
        return self::SCHEMA;
    }

    /** @return string[] */
    public function configurationKeys(): array
    {
        return array_map(static fn (string $k): string => self::PREFIX . $k, array_keys(self::SCHEMA));
    }

    public function days(): int
    {
        return max(self::DAYS_MIN, min(self::DAYS_MAX, (int) $this->all()['DAYS']));
    }

    public function retention(): int
    {
        return max(self::RETENTION_MIN, min(self::RETENTION_MAX, (int) $this->all()['LOG_RETENTION']));
    }

    public function priceType(): string
    {
        return $this->all()['PRICE_TYPE'] === 'net' ? 'net' : 'gross';
    }

    public function logsImmediately(): bool
    {
        return $this->all()['LOGGING_MODE'] === self::LOGGING_IMMEDIATE;
    }

    public function showsAlways(): bool
    {
        return $this->all()['DISPLAY_MODE'] === self::DISPLAY_ALWAYS;
    }

    public function showsPercent(): bool
    {
        return $this->all()['SHOW_PERCENT'] === '1';
    }

    /** Miejsc po przecinku w procencie obniżki. */
    public function precision(): int
    {
        return max(0, min(4, (int) $this->all()['PRECISION']));
    }

    public function showsIcon(): bool
    {
        return $this->all()['SHOW_ICON'] === '1';
    }

    public function iconAtStart(): bool
    {
        return $this->all()['ICON_POSITION'] !== 'end';
    }

    public function showsChart(): bool
    {
        return $this->all()['SHOW_CHART'] === '1';
    }

    /**
     * Co otwiera wykres. Bez ikony zostaje sama nota, inaczej sklep włącza
     * wykres, którego nie da się otworzyć.
     */
    public function chartTrigger(): string
    {
        $wybor = $this->all()['CHART_TRIGGER'];

        if ($wybor === self::CHART_ICON && !$this->showsIcon()) {
            return self::CHART_NOTE;
        }

        return $wybor === self::CHART_NOTE ? self::CHART_NOTE : self::CHART_ICON;
    }

    /** Podpowiedź pod ikoną, z liczbą dni wstawioną w miejsce %s. */
    public function tooltip(int $idLang): string
    {
        $tekst = $this->translate($this->all()['TOOLTIP'], $idLang);

        return str_contains($tekst, '%s') ? sprintf($tekst, $this->days()) : $tekst;
    }

    /**
     * Etykieta noty dla bieżącego języka, z liczbą dni wstawioną w miejsce %s.
     */
    public function label(int $idLang, string $zapasowa, string $klucz = 'LABEL_PRODUCT'): string
    {
        $tekst = $this->translate($this->all()[$klucz], $idLang);

        if (trim($tekst) === '') {
            $tekst = $zapasowa;
        }

        return str_contains($tekst, '%s') ? sprintf($tekst, $this->days()) : $tekst;
    }

    /**
     * Teksty wielojęzyczne do pól formularza.
     *
     * @return array<string,array<int,string>>
     */
    public function translations(): array
    {
        $all = $this->all();
        $out = [];

        foreach (self::TRANSLATABLE as $key) {
            $out[$key] = $this->decode($all[$key]);
        }

        return $out;
    }

    /**
     * Numery kategorii wyłączonych ze śledzenia.
     *
     * @return int[]
     */
    public function excludedCategories(): array
    {
        $zapisane = json_decode($this->all()['EXCLUDED_CATEGORIES'], true);

        return is_array($zapisane) ? array_values(array_filter(array_map('intval', $zapisane))) : [];
    }

    /**
     * Wyłączone kategorie z nazwami, do wypisania w panelu.
     *
     * @return array<int,array{id:int,name:string}>
     */
    public function excludedCategoriesNamed(int $idLang): array
    {
        $out = [];

        foreach ($this->excludedCategories() as $idCategory) {
            $kategoria = new Category($idCategory, $idLang);

            if (Validate::isLoadedObject($kategoria)) {
                $out[] = ['id' => $idCategory, 'name' => (string) $kategoria->name];
            }
        }

        return $out;
    }

    /**
     * Czy produkt jest wyłączony ze śledzenia.
     *
     * Sprawdzamy wszystkie kategorie produktu, nie tylko domyślną: sklep, który
     * wyłączył „Wyprzedaż", chce wyłączyć wszystko, co w niej leży, niezależnie
     * od tego, gdzie produkt ma swoją kategorię główną.
     */
    public function isProductExcluded(int $idProduct): bool
    {
        $wylaczone = $this->excludedCategories();

        if ($wylaczone === []) {
            return false;
        }

        foreach (Product::getProductCategories($idProduct) as $idCategory) {
            if (in_array((int) $idCategory, $wylaczone, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Token adresu dla harmonogramu.
     *
     * Losowy i zapisany, a nie liczony z nazwy sklepu: nazwa sklepu jest jawna,
     * więc token z niej wyliczony zna każdy, kto wejdzie na stronę główną.
     */
    public function cronToken(): string
    {
        $token = $this->all()['CRON_TOKEN'];

        if (trim($token) === '') {
            $token = $this->regenerateCronToken();
        }

        return $token;
    }

    public function regenerateCronToken(): string
    {
        $token = bin2hex(random_bytes(16));
        $this->wszedzie('CRON_TOKEN', $token);

        return $token;
    }

    /**
     * Wartości początkowe przy instalacji.
     *
     * Osobno od save(), bo instalacja idzie z konsoli albo z kontekstu jednego
     * sklepu, a wtedy pozostałe sklepy zostałyby bez wartości. Dla tokena to
     * groźne: odczyt w kontekście „wszystkie sklepy" nie trafiłby
     * w żaden wiersz, więc pierwsze wejście na ekran ustawień wygenerowałoby
     * nowy adres w miejsce tego, który sklep dostał przy instalacji.
     *
     * @param array<string,array<int,string>> $teksty klucz => (id języka => tekst)
     */
    public function seed(array $teksty): void
    {
        foreach (self::TRANSLATABLE as $klucz) {
            if (!empty($teksty[$klucz])) {
                $this->wszedzie($klucz, (string) json_encode($teksty[$klucz]), true);
            }
        }

        $this->wszedzie('CRON_TOKEN', bin2hex(random_bytes(16)));
    }

    /**
     * Zapis do sklepów z kontekstu ORAZ do wiersza globalnego.
     *
     * Wiersz globalny czyta odczyt bez wskazanego sklepu: panel otwarty
     * na „wszystkich sklepach" i harmonogram wołany spoza sklepu.
     * Dostaje go też każdy sklep założony później.
     */
    private function wszedzie(string $key, string $value, bool $html = false): void
    {
        ConfigStore::set(self::PREFIX . $key, $value, $html);
        Configuration::updateGlobalValue(self::PREFIX . $key, $value, $html);
    }

    public function lastCleanup(): int
    {
        return (int) $this->all()['LAST_CLEANUP'];
    }

    public function markCleanup(): void
    {
        ConfigStore::set(self::PREFIX . 'LAST_CLEANUP', (string) time());
    }

    /**
     * Zapis formularza.
     *
     * @param array<string,mixed> $values
     */
    public function save(array $values): void
    {
        foreach (self::SCHEMA as $key => $default) {
            if (in_array($key, self::TRANSLATABLE, true)
                || in_array($key, self::INTERNAL, true)
                || in_array($key, self::COMPOSITE, true)
            ) {
                continue;
            }

            if (!array_key_exists($key, $values)) {
                continue;
            }

            ConfigStore::set(self::PREFIX . $key, $this->sanitize($key, (string) $values[$key]));
        }

        // Kategorie przychodzą jako lista zaznaczeń, a lecą do konfiguracji jako
        // JSON. Brak klucza w wysyłce znaczy „nic nie zaznaczono", więc pusta
        // lista musi się zapisać, inaczej nie dałoby się cofnąć wykluczenia.
        // Znacznik wysyłki ma dwie nazwy: `EXCLUDED_SUBMITTED` i
        // `EXCLUDED_CATEGORIES_SUBMITTED` (komponent drzewa). Obie znaczą
        // „formularz z tym polem naprawdę przyszedł".
        if (array_key_exists('EXCLUDED_CATEGORIES', $values)
            || array_key_exists('EXCLUDED_SUBMITTED', $values)
            || array_key_exists('EXCLUDED_CATEGORIES_SUBMITTED', $values)) {
            $wybrane = $values['EXCLUDED_CATEGORIES'] ?? [];
            $wybrane = is_array($wybrane) ? array_values(array_filter(array_map('intval', $wybrane))) : [];

            ConfigStore::set(self::PREFIX . 'EXCLUDED_CATEGORIES', (string) json_encode($wybrane), true);
        }

        // Pola wielojęzyczne przychodzą jako LABEL_PRODUCT_1, _2 … i lądują
        // w jednym wpisie JSON. isHtml = true, bo bez tego PrestaShop wycina
        // cudzysłowy i zapisany JSON przestaje być JSON-em.
        foreach (self::TRANSLATABLE as $key) {
            $zebrane = [];

            foreach ($values as $name => $value) {
                if (preg_match('/^' . $key . '_(\d+)$/', (string) $name, $m)) {
                    $zebrane[(int) $m[1]] = (string) $value;
                }
            }

            if ($zebrane !== []) {
                ConfigStore::set(self::PREFIX . $key, (string) json_encode($zebrane), true);
            }
        }
    }

    /**
     * Wartości spoza dopuszczalnego zakresu wracają do granicy, a nie do zera:
     * okres „0 dni" wyłączyłby notę bez powiedzenia o tym sklepowi.
     */
    private function sanitize(string $key, string $value): string
    {
        switch ($key) {
            case 'DAYS':
                return (string) max(self::DAYS_MIN, min(self::DAYS_MAX, (int) $value));

            case 'LOG_RETENTION':
                return (string) max(self::RETENTION_MIN, min(self::RETENTION_MAX, (int) $value));

            case 'PRICE_TYPE':
                return $value === 'net' ? 'net' : 'gross';

            case 'DISPLAY_MODE':
                return $value === self::DISPLAY_ALWAYS ? self::DISPLAY_ALWAYS : self::DISPLAY_ON_DISCOUNT;

            case 'LOGGING_MODE':
                return $value === self::LOGGING_CRON ? self::LOGGING_CRON : self::LOGGING_IMMEDIATE;

            case 'PRECISION':
                return (string) max(0, min(4, (int) $value));

            case 'ICON_POSITION':
                return $value === 'end' ? 'end' : 'start';

            case 'CHART_TRIGGER':
                return $value === self::CHART_NOTE ? self::CHART_NOTE : self::CHART_ICON;

            default:
                return $value;
        }
    }

    /** @return array<int,string> */
    private function decode(string $raw): array
    {
        $odczytane = json_decode($raw, true);

        if (is_array($odczytane)) {
            return $odczytane;
        }

        // Wartość sprzed zmiany pola na wielojęzyczne: zwykły tekst zamiast JSON-a.
        // Przypisujemy ją językowi domyślnemu, żeby nie przepadła w migracji.
        if (trim($raw) !== '') {
            return [(int) Configuration::get('PS_LANG_DEFAULT') => $raw];
        }

        return [];
    }

    private function translate(string $raw, int $idLang): string
    {
        $wartosci = $this->decode($raw);

        if (isset($wartosci[$idLang]) && trim((string) $wartosci[$idLang]) !== '') {
            return (string) $wartosci[$idLang];
        }

        // Brak tłumaczenia dla języka nie może zostawić noty bez opisu. Spadamy
        // na pierwszą niepustą wartość, żeby przy cenie stało cokolwiek.
        foreach ($wartosci as $wartosc) {
            if (trim((string) $wartosc) !== '') {
                return (string) $wartosc;
            }
        }

        return '';
    }
}
