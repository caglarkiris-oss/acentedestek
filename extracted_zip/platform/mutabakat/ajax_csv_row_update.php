<style>
/* ticket detayla aynı dil */
.actions{position:absolute; top:16px; right:20px; display:flex; gap:8px;}
.btn-soft{
  appearance:none;
  border:1px solid rgba(15,23,42,.12);
  background:rgba(255,255,255,.92);
  color:#0f172a;
  padding:8px 12px;
  border-radius:12px;
  font-weight:700;
  font-size:13px;
  line-height:1;
  cursor:pointer;
  box-shadow:0 10px 25px rgba(15,23,42,.08);
  display:inline-flex;
  align-items:center;
  gap:8px;
  transition:transform .12s ease, box-shadow .12s ease, background .12s ease, border-color .12s ease;
}
.btn-soft:hover{transform:translateY(-1px);box-shadow:0 14px 30px rgba(15,23,42,.10);border-color:rgba(37,99,235,.28);}
.btn-soft:active{transform:translateY(0);box-shadow:0 8px 18px rgba(15,23,42,.08);}
.btn-soft-primary{border-color:rgba(37,99,235,.28);background:linear-gradient(180deg, rgba(59,130,246,.18), rgba(59,130,246,.08));color:#1e40af;}
.btn-soft-success{border-color:rgba(16,185,129,.28);background:linear-gradient(180deg, rgba(16,185,129,.18), rgba(16,185,129,.08));color:#065f46;}
.btn-soft[disabled]{opacity:.6; cursor:not-allowed; transform:none;}
.btn-ico{width:16px;height:16px;display:inline-block}

/* editable */
.editable[contenteditable="true"]{
  background:#fefce8;
  outline:1px dashed #eab308;
  border-radius:4px;
}

/* ✅ kolon kopyalama */
th.copyable{
  cursor:pointer;
  user-select:none;
  position:relative;
}
th.copyable:hover{background:#eef2ff;}
th.copyable:after{
  content:"Kopyala";
  position:absolute;
  right:8px;
  top:50%;
  transform:translateY(-50%);
  font-size:10px;
  opacity:.35;
}
th.copyable:hover:after{opacity:.75;}
</style>

<!-- ✅ BUTONLAR (card içinde position:relative olsun) -->
<div class="actions">
  <button id="editBtn" class="btn-soft btn-soft-primary" type="button">
    <span class="btn-ico">✎</span> Düzenle
  </button>
  <button id="saveBtn" class="btn-soft btn-soft-success" type="button" style="display:none;">
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
