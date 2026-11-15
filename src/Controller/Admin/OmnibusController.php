<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Omnibus\Controller\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Quince\Engine\Controller\Admin\QuinceController;

/**
 * Wspólna podstawa ekranów modułu.
 *
 * Trzyma spis zakładek w jednym miejscu: nowy widok dopisany tylko w jednym
 * kontrolerze dawałby ekran bez zakładki, do którego nie da się wrócić.
 */
abstract class OmnibusController extends QuinceController
{
    protected const DOMENA = 'Modules.Quinceomnibus.Admin';

    /**
     * @return array<int,array{label:string,route:string}>
     */
    protected function tabs(): array
    {
        return [
            ['label' => 'Settings', 'route' => 'quince_omnibus_settings'],
            ['label' => 'Price log', 'route' => 'quince_omnibus_log'],
        ];
    }

    /**
     * Parametry wspólne dla obu widoków: nagłówek, zakładki i podświetlenie
     * pozycji w sidebarze.
     *
     * @return array<string,mixed>
     */
    protected function chrome(string $route): array
    {
        return [
            'layoutTitle' => 'Price history',
            'layoutTitleDomain' => self::DOMENA,
            'quinceTabs' => $this->tabs(),
            'quinceTabsDomain' => self::DOMENA,
            // Sidebar podświetla pozycję modułu, która wskazuje na ustawienia.
            // Dziennik jest jego widokiem, a nie osobną pozycją w menu.
            'quinceRoute' => $route,
            'quinceNavRoute' => 'quince_omnibus_settings',
        ];
    }
}
