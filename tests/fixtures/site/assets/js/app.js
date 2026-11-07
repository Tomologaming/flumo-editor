(function () {
  'use strict';
  var links = document.querySelectorAll('.nav a');
  links.forEach(function (link) {
    link.addEventListener('click', function () {
      links.forEach(function (l) { l.classList.remove('active'); });
      link.classList.add('active');
    });
  });
})();
