// Wskaznik ladowania na czas kontroli zdjecia (kilka sekund) - bez niego
// uczen mysli, ze strona sie zawiesila, i klika drugi raz.
// Plik jest zewnetrzny, bo CSP strony nie dopuszcza skryptow inline.

document.querySelectorAll('form[data-busy-label]').forEach(function (form) {
  var buttons = Array.prototype.slice.call(form.querySelectorAll('button'));
  var hasNamedButton = buttons.some(function (button) { return button.name !== ''; });
  var lastClicked = null;

  buttons.forEach(function (button) {
    button.addEventListener('click', function () { lastClicked = button; });
  });

  form.addEventListener('submit', function (event) {
    if (form.dataset.busy === '1') {
      event.preventDefault();
      return;
    }

    // event.submitter nie istnieje w Safari < 15.4 - stad zapamietany klik.
    var clicked = event.submitter || lastClicked;

    // Bez wiedzy, ktory przycisk wyslal formularz, nie wolno blokowac
    // przyciskow z atrybutem name: wylaczony przycisk nie trafia do danych
    // formularza, wiec zgubilaby sie jego wartosc (np. decision=send).
    if (clicked === null && hasNamedButton) {
      return;
    }

    if (clicked && form.dataset.busySkip && clicked.value === form.dataset.busySkip) {
      return;
    }

    if (clicked && clicked.name !== '') {
      var carried = document.createElement('input');
      carried.type = 'hidden';
      carried.name = clicked.name;
      carried.value = clicked.value;
      form.appendChild(carried);
    }

    form.dataset.busy = '1';
    form.classList.add('is-busy');

    buttons.forEach(function (button) {
      if (clicked === null || button === clicked) {
        button.textContent = form.dataset.busyLabel;
        button.insertAdjacentHTML('afterbegin', '<span class="spinner" aria-hidden="true"></span>');
      }
      button.disabled = true;
    });
  });
});

// Powrot przyciskiem "wstecz" przywraca strone z cache razem z zablokowanym
// przyciskiem - bez tego uczen widzi kreciolek, ktory nigdy nie znika.
window.addEventListener('pageshow', function (event) {
  if (event.persisted) {
    window.location.reload();
  }
});
