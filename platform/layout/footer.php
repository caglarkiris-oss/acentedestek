
  <script>
  // Column copy functionality
  (function(){
    function fallbackCopy(text){
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly','');
      ta.style.position='fixed';
      ta.style.left='-9999px';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); } catch(e){}
      document.body.removeChild(ta);
    }
    
    function copyText(text){
      if(navigator.clipboard && navigator.clipboard.writeText){
        navigator.clipboard.writeText(text).then(function(){}, function(){ fallbackCopy(text); });
      } else {
        fallbackCopy(text);
      }
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
      var values = [];
      rows.forEach(function(r){
        var cells = r.children;
        if(!cells || colIndex >= cells.length) return;
        var cell = cells[colIndex];
        var inp = cell.querySelector('input,textarea,select');
        var v = '';
        if(inp){ v = (inp.value || '').trim(); } else { v = (cell.innerText || '').trim(); }
        if(v !== '') values.push(v);
      });

      if(values.length === 0){
        showToast('Kopyalanacak veri yok.');
        return;
      }
      copyText(values.join('\n'));
      showToast('"' + (th.innerText||'Kolon') + '" sutunu kopyalandi ('+values.length+').');
    });
  })();
  </script>
</body>
</html>
