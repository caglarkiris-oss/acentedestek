(function(){
  // Toast
  window.showToast = function(msg, duration){
    var el = document.getElementById('toast');
    if(!el){
      el = document.createElement('div');
      el.id='toast';
      el.className='toast';
      document.body.appendChild(el);
    }
    el.textContent = msg || '';
    el.classList.add('show');
    clearTimeout(el._t);
    el._t = setTimeout(function(){ el.classList.remove('show'); }, duration || 2400);
  };

  // Dropdowns
  document.addEventListener('click', function(e){
    var ddToggle = e.target.closest('[data-dd-toggle]');
    if(ddToggle){
      var dd = ddToggle.closest('.dd');
      if(dd){ dd.classList.toggle('is-open'); }
      e.preventDefault();
      return;
    }
    // close all dropdowns if click outside
    document.querySelectorAll('.dd.is-open').forEach(function(n){
      if(!e.target.closest('.dd')) n.classList.remove('is-open');
    });
  });

  // Sidebar (mobile)
  document.addEventListener('click', function(e){
    var t = e.target.closest('[data-sidebar-toggle]');
    if(!t) return;
    var shell = document.querySelector('.app-shell');
    if(shell) shell.classList.toggle('sidebar-open');
  });

  // Copyable table column
  (function(){
    function fallbackCopy(text){
      var ta=document.createElement('textarea');
      ta.value=text; ta.setAttribute('readonly','');
      ta.style.position='fixed'; ta.style.left='-9999px';
      document.body.appendChild(ta);
      ta.select();
      try{ document.execCommand('copy'); }catch(_){}
      document.body.removeChild(ta);
    }
    function copyText(text){
      if(navigator.clipboard && navigator.clipboard.writeText){
        navigator.clipboard.writeText(text).catch(function(){ fallbackCopy(text); });
      }else fallbackCopy(text);
    }
    document.addEventListener('click', function(e){
      var th = e.target.closest('th');
      if(!th) return;
      var table = th.closest('table.copyable-table');
      if(!table) return;
      var tr = th.parentElement;
      var ths = Array.prototype.slice.call(tr.children);
      var colIndex = ths.indexOf(th);
      if(colIndex < 0) return;
      var rows = table.querySelectorAll('tbody tr');
      var values=[];
      rows.forEach(function(r){
        var cells=r.children;
        if(!cells || colIndex>=cells.length) return;
        var cell=cells[colIndex];
        var inp=cell.querySelector('input,textarea,select');
        var v='';
        if(inp){ v=(inp.value||'').trim(); } else { v=(cell.innerText||'').trim(); }
        if(v!=='') values.push(v);
      });
      if(values.length===0){ showToast('Kopyalanacak veri yok.'); return; }
      copyText(values.join('\n'));
      showToast('"' + ((th.innerText||'Kolon').trim()) + '" sütunu kopyalandı ('+values.length+').');
    });
  })();
})();
