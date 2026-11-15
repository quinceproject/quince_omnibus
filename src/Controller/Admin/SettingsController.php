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

use Db;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Quince\Engine\Ui\IconLibrary;
use Quince\Omnibus\Settings\OmnibusSettings;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ustawienia dziennika cen.
 *
 * Dziedziczy po klasie z silnika, więc strona dostaje ten sam sidebar, pasek
 * zapisu i wygląd co reszta panelu Quince.
 */
class SettingsController extends OmnibusController
{
    public function __construct(private readonly OmnibusSettings $settings)
    {
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(): Response
    {
        $idLang = (int) $this->getContext()->language->id;

        return $this->renderPanel(
            '@Modules/quince_omnibus/views/templates/admin/settings.html.twig',
            [
                'settings' => $this->settings->all(),
                'translations' => $this->settings->translations(),
                'languages' => $this->languages(),
                'excluded' => $this->settings->excludedCategories(),
                'categories' => $this->categories($idLang),
                'cronUrl' => $this->cronUrl(),
                // Wybierak ikon i jego lista są w silniku. Moduł podaje tylko
                // bieżącą ikonę i adres wyszukiwania.
                'iconSvg' => IconLibrary::svg($this->settings->all()['ICON']),
                'iconsRoute' => 'quince_icons_search',
                'limits' => [
                    'daysMin' => OmnibusSettings::DAYS_MIN,
                    'daysMax' => OmnibusSettings::DAYS_MAX,
                    'retentionMin' => OmnibusSettings::RETENTION_MIN,
                    'retentionMax' => OmnibusSettings::RETENTION_MAX,
                ],
            ] + $this->chrome('quince_omnibus_settings')
        );
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function saveAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_omnibus_settings_save', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', self::DOMENA));

            return $this->redirectToRoute('quince_omnibus_settings');
        }

        $this->settings->save($request->request->all());
        $this->addFlash('success', $this->trans('Settings saved.', self::DOMENA));

        return $this->redirectToRoute('quince_omnibus_settings');
    }

    /**
     * Nowy token adresu dla harmonogramu.
     *
     * Osobne działanie, a nie pole w formularzu: token ma być nie do wpisania
     * ręcznie, a jego wymiana musi unieważnić poprzedni adres od razu, nie
     * dopiero przy zapisie całego ekranu.
     */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function tokenAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_omnibus_token', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', self::DOMENA));

            return $this->redirectToRoute('quince_omnibus_settings');
        }

        $this->settings->regenerateCronToken();
        $this->addFlash('success', $this->trans('New address generated. The previous one stopped working.', self::DOMENA));

        return $this->redirectToRoute('quince_omnibus_settings');
    }

    /**
     * Adres, który sklep wkleja w harmonogram.
     *
     * Składa go PrestaShop, bo zna przepisywanie adresów, język i sklep
     * w wielosklepie. Wpisany na sztywno działałby do pierwszej zmiany
     * ustawień adresów.
     */
    private function cronUrl(): string
    {
        return $this->getContext()->link->getModuleLink(
            'quince_omnibus',
            'cron',
            ['token' => $this->settings->cronToken()],
            true
        );
    }

    /**
     * Drzewo kategorii do wyboru wykluczeń.
     *
     * Wybór z drzewa, a nie wpisywanie numerów: „434,690,691" nie mówi nikomu nic
     * i po zmianie katalogu nikt tego nie zweryfikuje.
     *
     * @return array<int,array{id:int,name:string,depth:int}>
     */
    private function categories(int $idLang): array
    {
        $idShop = (int) $this->getContext()->shop->id;

        // Złączenie po języku ORAZ po sklepie: category_lang ma wiersz na każdą
        // parę język–sklep, więc samo id_lang daje tyle kopii każdej kategorii,
        // ile sklep ma sklepów. Przy czterech sklepach lista rośnie czterokrotnie.
        $wiersze = Db::getInstance()->executeS(
            'SELECT c.id_category, c.level_depth, cl.name
             FROM ' . _DB_PREFIX_ . 'category c
             INNER JOIN ' . _DB_PREFIX_ . 'category_lang cl
                 ON cl.id_category = c.id_category
                 AND cl.id_lang = ' . $idLang . '
                 AND cl.id_shop = ' . $idShop . '
             INNER JOIN ' . _DB_PREFIX_ . 'category_shop cs
                 ON cs.id_category = c.id_category AND cs.id_shop = ' . $idShop . '
             WHERE c.active = 1 AND c.level_depth > 1
             ORDER BY c.nleft'
        );

        return array_map(
            static fn (array $w): array => [
                'id' => (int) $w['id_category'],
                'name' => (string) $w['name'],
                // Poziom liczony od kategorii głównej, żeby wcięcie zaczynało się od zera.
                'depth' => max(0, (int) $w['level_depth'] - 2),
            ],
            is_array($wiersze) ? $wiersze : []
        );
    }
}
