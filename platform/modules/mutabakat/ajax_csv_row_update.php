

<!-- ✅ BUTONLAR (card içinde position:relative olsun) -->
<div class="actions">
  <button id="editBtn" class="btn-soft btn-soft-primary" type="button">
    <span class="btn-ico">✎</span> Düzenle
  </button>
  <button id="saveBtn" class="btn-soft btn-soft-success" type="button">
    <span class="btn-ico">✔</span> Kaydet
  </button>
</div>

<script>
(function(){
  const editBtn = document.getElementById('editBtn');
  const saveBtn = document.getElementById('saveBtn');

  // ✅ başlık tıkla → kolon kopyala
  async function copyText(txt){
    try{
      await navigator.clipboard.writeText(txt);
      // kısa feedback
      // istersen toast yaparız; şimdilik minik alert yok
    }catch(e){
      // fallback
      const ta = document.createElement('textarea');
      ta.value = txt;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      ta.remove();
    }
  }

  function bindHeaderCopy(){
    document.querySelectorAll('th.copyable').forEach(th=>{
      th.addEventListener('click', ()=>{
        const k = th.dataset.k;
        if(!k) return;
        const lines = [];
        document.querySelectorAll('tbody tr[data-id]').forEach(tr=>{
          const td = tr.querySelector(`td[data-k="${k}"]`);
          if(td) lines.push((td.innerText || '').trim());
        });
        copyText(lines.join("\n"));
      });
    });
  }

  // ✅ edit mode
  function setEditable(on){
    document.querySelectorAll('td.editable').forEach(td=>{
      td.contentEditable = on ? "true" : "false";
    });
  }

  editBtn && (editBtn.onclick = ()=>{
    setEditable(true);
    editBtn.style.display='none';
    saveBtn.style.display='inline-flex';
  });

  saveBtn && (saveBtn.onclick = ()=>{
    saveBtn.disabled = true;

    const changes = [];
    document.querySelectorAll('tr[data-id]').forEach(tr=>{
      const fields = {};
      tr.querySelectorAll('td.editable').forEach(td=>{
        fields[td.dataset.k] = (td.innerText || '').trim();
      });
      changes.push({ id: tr.dataset.id, fields });
    });

    const payload = {
      csrf_token: "<?= $csrf ?>",
      tali_id: <?= (int)$taliId ?>,
      period: "<?= h($selectedPeriod) ?>",
      edit_session_id: (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : null,
      changes
    };

    fetch('ajax_csv_row_update.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    })
    .then(r=>r.json())
    .then(j=>{
      if(!j || !j.ok){ alert((j && j.message) ? j.message : 'Hata'); return; }
      location.reload(); // ✅ DB formatını tekrar bas
    })
    .catch(()=>alert('İstek başarısız'))
    .finally(()=>{ saveBtn.disabled = false; });
  });

  bindHeaderCopy();
})();
</script>
