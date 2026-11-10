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

use Quince\Omnibus\Model\PriceHistory;
use Quince\Omnibus\Model\PriceLog;
use Quince\Omnibus\Settings\OmnibusSettings;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dziennik cen — co i kiedy zostało zapisane.
 *
 * Ekran istnieje po to, żeby dało się sprawdzić, skąd wzięła się kwota w nocie.
 * Bez niego najniższa cena jest liczbą bez uzasadnienia, a przy sporze z klientem
 * albo kontrolą sklep musi umieć pokazać zapis.
 */
class LogController extends OmnibusController
{
    public function __construct(
        private readonly OmnibusSettings $settings,
        private readonly PriceLog $log
    ) {
    }

    public function indexAction(Request $request): Response
    {
        $filtry = $this->filtry($request);
        $strona = max(1, (int) $request->query->get('strona', 1));
        $sortuj = (string) $request->query->get('sortuj', 'captured_at');
        $kierunek = (string) $request->query->get('kierunek', 'DESC');

        $wszystkich = $this->log->count($filtry);
        $stron = max(1, (int) ceil($wszystkich / PriceLog::NA_STRONE));
        $strona = min($strona, $stron);

        return $this->renderPanel(
            '@Modules/quince_omnibus/views/templates/admin/log.html.twig',
            [
                'rows' => $this->log->rows($filtry, $strona, $sortuj, $kierunek),
                'total' => $wszystkich,
                'page' => $strona,
                'pages' => $stron,
                'perPage' => PriceLog::NA_STRONE,
                'sort' => $sortuj,
                'dir' => strtoupper($kierunek) === 'ASC' ? 'ASC' : 'DESC',
                'filters' => $filtry,
                'stats' => $this->log->stats(),
                'retention' => $this->settings->retention(),
            ] + $this->chrome('quince_omnibus_log')
        );
    }

    /**
     * Kasowanie zaznaczonych wpisów.
     *
     * Dwa tryby, bo zaznaczenie „wszystkie pasujące do filtra" obejmuje także
     * strony, których nikt nie oglądał. Wtedy zbiór opisuje filtr, a nie lista
     * numerów — tysiąc pól ukrytych w formularzu i tak nie przeszłoby przez
     * `max_input_vars`.
     */
    public function deleteAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_omnibus_log_delete', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', self::DOMENA));

            return $this->redirectToRoute('quince_omnibus_log');
        }

        if ($request->request->get('wszystkie') === '1') {
            $usuniete = $this->log->deleteByFilter($this->filtry($request));

            $this->addFlash('success', $this->trans(
                'Deleted %d entries matching the filter.',
                self::DOMENA,
                ['%d' => $usuniete]
            ));

            return $this->redirectToRoute('quince_omnibus_log', $this->filtry($request));
        }

        $ids = $request->request->all('ids');
        $ids = is_array($ids) ? $ids : [];

        if (PriceHistory::deleteByIds($ids)) {
            $this->addFlash('success', $this->trans(
                'Deleted %d entries.',
                self::DOMENA,
                ['%d' => count($ids)]
            ));
        } else {
            $this->addFlash('warning', $this->trans('Nothing was selected.', self::DOMENA));
        }

        return $this->redirectToRoute('quince_omnibus_log', $this->filtry($request));
    }

    /**
     * Ręczne sprzątanie wpisów starszych niż okres przechowywania.
     */
    public function cleanAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_omnibus_log_clean', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', self::DOMENA));

            return $this->redirectToRoute('quince_omnibus_log');
        }

        $usuniete = PriceHistory::cleanOlderThan($this->settings->retention());
        $this->settings->markCleanup();

        $this->addFlash('success', $this->trans('Removed %d entries older than the retention period.', self::DOMENA, ['%d' => $usuniete]));

        return $this->redirectToRoute('quince_omnibus_log');
    }

    /**
     * Zapis cen wszystkich produktów naraz.
     *
     * Potrzebne raz — zaraz po instalacji. Bez tego dziennik zaczyna się od
     * pierwszej zmiany ceny, więc produkty, których nikt nie edytuje, przez
     * miesiąc nie mają noty.
     */
    public function recordAllAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_omnibus_log_record_all', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', self::DOMENA));

            return $this->redirectToRoute('quince_omnibus_log');
        }

        $settings = $this->settings;
        $wynik = PriceHistory::recordAll(static fn (int $id): bool => $settings->isProductExcluded($id));

        $this->addFlash('success', $this->trans(
            'Recorded %rows% entries for %products% products, skipped %skipped%.',
            self::DOMENA,
            [
                '%rows%' => $wynik['wiersze'],
                '%products%' => $wynik['produkty'],
                '%skipped%' => $wynik['pominiete'],
            ]
        ));

        return $this->redirectToRoute('quince_omnibus_log');
    }

    public function exportAction(Request $request): Response
    {
        $csv = $this->log->toCsv($this->filtry($request));

        // Znacznik BOM: bez niego arkusz otwiera plik w kodowaniu systemowym
        // i polskie nazwy produktów rozsypują się na pierwszym ogonku.
        return new Response("\xEF\xBB\xBF" . $csv, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="quince-omnibus-' . date('Y-m-d') . '.csv"',
        ]);
    }

    public function importAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_omnibus_log_import', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', self::DOMENA));

            return $this->redirectToRoute('quince_omnibus_log');
        }

        $plik = $request->files->get('csv');

        if ($plik === null || !$plik->isValid()) {
            $this->addFlash('warning', $this->trans('No file was sent.', self::DOMENA));

            return $this->redirectToRoute('quince_omnibus_log');
        }

        $wynik = $this->log->fromCsv((string) $plik->getPathname());

        $this->addFlash('success', $this->trans(
            'Loaded %loaded% entries, skipped %skipped%.',
            self::DOMENA,
            ['%loaded%' => $wynik['wczytane'], '%skipped%' => $wynik['pominiete']]
        ));

        return $this->redirectToRoute('quince_omnibus_log');
    }

    /**
     * @return array{szukaj:string,od:string,do:string}
     */
    private function filtry(Request $request): array
    {
        // Adres przy wypisaniu listy, pola formularza przy kasowaniu — ten sam
        // filtr przychodzi raz jedną, raz drugą drogą.
        $wez = static fn (string $klucz): string => trim(
            (string) ($request->query->get($klucz) ?? $request->request->get($klucz) ?? '')
        );

        return [
            'szukaj' => $wez('szukaj'),
            'od' => $wez('od'),
            'do' => $wez('do'),
        ];
    }
}
