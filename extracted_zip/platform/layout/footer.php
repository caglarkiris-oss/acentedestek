<?php
// Minimal footer (şimdilik)
?>

<script>
(function(){
  // Açık olan tüm custom select'leri kapat
  function closeAllSSelect(exceptWrap){
    document.querySelectorAll('.sselect-wrap.is-open').forEach(w=>{
      if (exceptWrap && w === exceptWrap) return;
      w.classList.remove('is-open');
    });
  }

  function enhanceSelect(sel){
    if(!sel || sel.dataset.sselect === '1') return;
    if(sel.multiple) return; // multiple bozmayalım
    if(sel.hasAttribute('size') && Number(sel.getAttribute('size')) > 1) return; // listbox bozmayalım
    sel.dataset.sselect = '1';

    // wrapper
    const wrap = document.createElement('div');
    wrap.className = 'sselect-wrap';

    // original select'i gizle (form değeri yine buradan gider)
    sel.classList.add('sselect-hidden');

    // button
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'sselect-btn';

    const label = document.createElement('span');
    const chevron = document.createElement('span');
    chevron.className = 'sselect-chevron';
    chevron.innerHTML =
      "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='none'>"+
      "<path d='M6 8l4 4 4-4' stroke='#64748B' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/>"+
      "</svg>";

    btn.appendChild(label);
    btn.appendChild(chevron);

    // menu
    const menu = document.createElement('div');
    menu.className = 'sselect-menu';

    function getSelectedText(){
      const opt = sel.options[sel.selectedIndex];
      const t = (opt && opt.textContent) ? opt.textContent.trim() : '';
      return t || 'Seçiniz';
    }

    function rebuildMenu(){
      menu.innerHTML = '';

      for (let i = 0; i < sel.options.length; i++){
        const o = sel.options[i];

        const item = document.createElement('div');
        item.className = 'sselect-opt';
        item.textContent = (o.textContent || '').trim();

        if (o.disabled) {
          item.classList.add('sselect-muted');
          item.style.pointerEvents = 'none';
        }

        if (i === sel.selectedIndex) item.classList.add('is-selected');

        item.addEventListener('click', function(e){
          e.preventDefault();
          e.stopPropagation();

          sel.selectedIndex = i;
          sel.dispatchEvent(new Event('change', { bubbles:true }));

          wrap.classList.remove('is-open');
          sync();
        });

        menu.appendChild(item);
      }
    }

    function sync(){
      label.textContent = getSelectedText();

      // ✅ disabled state: hem görünüm hem gerçek tıklanabilirlik
      btn.disabled = !!sel.disabled;
      if (sel.disabled) wrap.classList.add('is-disabled');
      else wrap.classList.remove('is-disabled');

      // selected marker
      const items = menu.querySelectorAll('.sselect-opt');
      items.forEach((it, idx) => {
        if (idx === sel.selectedIndex) it.classList.add('is-selected');
        else it.classList.remove('is-selected');
      });
    }

    function open(){
      if (sel.disabled) return;
      wrap.classList.add('is-open');
      rebuildMenu();
      sync();
    }

    function close(){
      wrap.classList.remove('is-open');
    }

    // Button: aç/kapat + diğerlerini kapat
    btn.addEventListener('click', function(e){
      e.preventDefault();
      e.stopPropagation();

      if (sel.disabled) return;

      const willOpen = !wrap.classList.contains('is-open');
      closeAllSSelect(wrap);

      if (willOpen) open();
      else close();
    });

    // Menüye tıklayınca dışarı tıklama gibi sayılmasın
    menu.addEventListener('click', function(e){
      e.stopPropagation();
    });

    // Select değişirse (ajax / js ile)
    sel.addEventListener('change', sync);

    // ✅ disabled/enabled attribute değişince UI sync olsun
    const attrObs = new MutationObserver(() => {
      sync();
    });
    attrObs.observe(sel, { attributes: true, attributeFilter: ['disabled'] });

    // DOM'a yerleştir
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(sel);
    wrap.appendChild(btn);
    wrap.appendChild(menu);

    rebuildMenu();
    sync();
  }

  function run(root){
    const ctx = root || document;
    ctx.querySelectorAll('select').forEach(enhanceSelect);
  }

  // Global kapanma davranışları (TEK SEFER)
  document.addEventListener('pointerdown', function(e){
    const inside = e.target.closest && e.target.closest('.sselect-wrap');
    if (!inside) closeAllSSelect();
  }, true);

  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') closeAllSSelect();
  });

  // ✅ Dinamik eklenen select'leri yakala (ticket-create sağ kolon gibi)
  const observer = new MutationObserver((mutations) => {
    for (const m of mutations) {
      for (const node of m.addedNodes) {
        if (!node || node.nodeType !== 1) continue;

        if (node.matches && node.matches('select')) {
          enhanceSelect(node);
        }
        if (node.querySelectorAll) {
          node.querySelectorAll('select').forEach(enhanceSelect);
        }
      }
    }
  });

  function start(){
    run(document);
    observer.observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }

})();
</script>

</body>
</html>
