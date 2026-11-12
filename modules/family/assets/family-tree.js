(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  function layoutBrackets(root) {
    root.querySelectorAll('.ft-bracket').forEach(function (bracket) {
      var kids = Array.prototype.filter.call(bracket.children, function (el) {
        return el.classList.contains('ft-bracket__slot')
          || el.classList.contains('ft-spouse-branch');
      });

      if (kids.length < 2) {
        bracket.style.removeProperty('--ft-bar-left');
        bracket.style.removeProperty('--ft-bar-right');
        bracket.classList.add('is-single');
        return;
      }

      bracket.classList.remove('is-single');
      var bRect = bracket.getBoundingClientRect();
      if (bRect.width <= 0) return;

      var first = kids[0].getBoundingClientRect();
      var last = kids[kids.length - 1].getBoundingClientRect();
      var left = first.left + first.width / 2 - bRect.left;
      var right = bRect.right - (last.left + last.width / 2);

      bracket.style.setProperty('--ft-bar-left', Math.max(0, left) + 'px');
      bracket.style.setProperty('--ft-bar-right', Math.max(0, right) + 'px');
    });
  }

  ready(function () {
    var root = document.querySelector('[data-fam-tree]');
    if (!root) return;

    var schedule = function () {
      window.requestAnimationFrame(function () {
        layoutBrackets(root);
      });
    };

    schedule();
    window.addEventListener('resize', schedule);
    if (document.fonts && document.fonts.ready) {
      document.fonts.ready.then(schedule).catch(function () {});
    }
    if (typeof ResizeObserver !== 'undefined') {
      var ro = new ResizeObserver(schedule);
      ro.observe(root);
    }
  });
})();
