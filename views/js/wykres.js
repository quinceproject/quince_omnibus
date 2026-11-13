/* ===================================================================
   Quince Omnibus: wykres historii ceny

   Otwiera się z noty pod ceną i pokazuje przebieg, z którego wzięła się
   liczba w tej nocie. Ładuje się tylko wtedy, gdy sklep włączył wykres.
   Strona bez niego nie pobiera ani tego pliku, ani biblioteki wykresów.

   Okno rysujemy tu, a nie w szablonie: bez kliknięcia nie jest do niczego
   potrzebne, a strona ma być kompletna bez ani jednej linijki skryptu.
   =================================================================== */

(function () {
    'use strict';

    var ustawienia = window.quinceOmnibus || {};
    var okno = null;
    var plotno = null;
    var rysunek = null;

    /** Okno powstaje raz, przy pierwszym otwarciu. */
    function przygotujOkno() {
        if (okno) {
            return okno;
        }

        okno = document.createElement('dialog');
        okno.className = 'q-modal q-modal--lg';
        okno.setAttribute('aria-label', ustawienia.tytul || '');

        okno.innerHTML =
            '<div class="q-modal__head">' +
                '<h2 class="q-modal__title"></h2>' +
                '<button class="q-modal__close" type="button" aria-label="' + (ustawienia.zamknij || 'X') + '">' +
                    '<svg width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor" ' +
                    'stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 4 4 12M4 4l8 8"/></svg>' +
                '</button>' +
            '</div>' +
            '<div class="q-modal__body"><canvas height="260"></canvas><p class="q-omnibus__pusto" hidden></p></div>';

        okno.querySelector('.q-modal__title').textContent = ustawienia.tytul || '';
        okno.querySelector('.q-modal__close').addEventListener('click', function () {
            okno.close();
        });

        document.body.appendChild(okno);
        plotno = okno.querySelector('canvas');

        return okno;
    }

    /**
     * Biblioteka wykresów dociąga się przy pierwszym otwarciu, nie przy
     * wczytaniu strony: to 200 kB, których nie potrzebuje nikt, kto w notę
     * nie kliknie.
     */
    function wczytajBiblioteke() {
        if (window.Chart) {
            return Promise.resolve();
        }

        return new Promise(function (gotowe, blad) {
            var skrypt = document.createElement('script');
            skrypt.src = ustawienia.biblioteka;
            skrypt.onload = gotowe;
            skrypt.onerror = blad;
            document.head.appendChild(skrypt);
        });
    }

    function narysuj(dane) {
        var pusto = okno.querySelector('.q-omnibus__pusto');

        if (!dane.punkty || dane.punkty.length === 0) {
            plotno.hidden = true;
            pusto.hidden = false;
            pusto.textContent = ustawienia.brakDanych || '';

            return;
        }

        plotno.hidden = false;
        pusto.hidden = true;

        if (rysunek) {
            rysunek.destroy();
        }

        // Barwy z motywu, nie z ustawień modułu: wykres ma należeć do sklepu,
        // a nie mieć własnej palety obok niego.
        var styl = getComputedStyle(document.documentElement);
        var akcent = (styl.getPropertyValue('--q-color-accent') || '#635bff').trim();
        var siatka = (styl.getPropertyValue('--q-border-color') || 'rgba(0,0,0,.1)').trim();
        var tekst = (styl.getPropertyValue('--q-meta-color') || '#666').trim();

        rysunek = new window.Chart(plotno, {
            type: 'line',
            data: {
                labels: dane.punkty.map(function (p) { return p.data; }),
                datasets: [{
                    data: dane.punkty.map(function (p) { return p.cena; }),
                    borderColor: akcent,
                    backgroundColor: akcent + '22',
                    fill: true,
                    // Schodkowa, nie wygładzona: cena zmienia się skokowo w dniu
                    // zmiany, a krzywa sugerowałaby, że po drodze miała wartości,
                    // których nigdy nie było.
                    stepped: true,
                    pointRadius: 3,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (kontekst) {
                                return dane.punkty[kontekst.dataIndex].opis;
                            },
                        },
                    },
                },
                scales: {
                    x: { grid: { color: siatka }, ticks: { color: tekst } },
                    y: { grid: { color: siatka }, ticks: { color: tekst } },
                },
            },
        });
    }

    function otworz(nota) {
        przygotujOkno();
        okno.showModal();

        var adres = new URL(ustawienia.adres, window.location.origin);
        adres.searchParams.set('id_product', nota.dataset.qOmnibusProdukt);
        adres.searchParams.set('id_product_attribute', nota.dataset.qOmnibusWariant || '0');

        wczytajBiblioteke()
            .then(function () { return fetch(adres, { credentials: 'same-origin' }); })
            .then(function (odpowiedz) { return odpowiedz.json(); })
            .then(narysuj)
            .catch(function () {
                narysuj({ punkty: [] });
            });
    }

    function podepnij(nota) {
        // Wyzwalaczem jest ikona albo cała nota, zależnie od ustawienia sklepu.
        // Szablon oznacza właściwy element, skrypt nie zgaduje.
        var przycisk = nota.querySelector('[data-q-omnibus-otworz]') || nota;

        przycisk.addEventListener('click', function () {
            otworz(nota);
        });

        przycisk.addEventListener('keydown', function (zdarzenie) {
            if (zdarzenie.key === 'Enter' || zdarzenie.key === ' ') {
                zdarzenie.preventDefault();
                otworz(nota);
            }
        });
    }

    if (window.Quince && typeof window.Quince.register === 'function') {
        window.Quince.register('[data-q-omnibus-wykres]', podepnij);
    } else {
        // Motyw bez rejestru wysp: podpinamy się sami po wczytaniu strony.
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('[data-q-omnibus-wykres]').forEach(podepnij);
        });
    }
})();
